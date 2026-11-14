<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateEvidence
{
    public static function rules(): array
    {
        return ['required', 'file', 'mimes:pdf,docx,png,jpg,jpeg,txt', 'extensions:pdf,docx,png,jpg,jpeg,txt', 'max:20480'];
    }

    public static function diskName(?array $entry = null): string
    {
        $name = $entry === null ? config('evidence.disk') : ($entry['disk'] ?? config('evidence.legacy_disk'));
        if (! is_string($name) || ! preg_match('/^[A-Za-z0-9_-]{1,80}$/', $name) || $name === 'public' || ! in_array($name, config('evidence.private_disks', []), true)) {
            throw new \RuntimeException('Private evidence disk is not approved in the server configuration.');
        }
        $settings = config('filesystems.disks.'.$name);
        if (! is_array($settings) || ! in_array($settings['driver'] ?? null, ['local', 's3'], true) || ($settings['visibility'] ?? null) === 'public') {
            throw new \RuntimeException('Evidence requires a configured private disk.');
        }
        if (($settings['driver'] ?? null) === 's3' && (! empty($settings['url']) || ! empty($settings['options']['GrantRead']) || ! empty($settings['options']['GrantReadACP']) || !in_array($settings['options']['ACL']??'private',['private','bucket-owner-full-control'],true))) {
            throw new \RuntimeException('Private evidence must not use public URLs or public object grants.');
        }
        if (($settings['driver'] ?? null) === 'local') {
            if (($settings['visibility'] ?? null) !== 'private') {
                throw new \RuntimeException('Local evidence requires private visibility.');
            }
            $root = str_replace('\\', '/', (string) ($settings['root'] ?? ''));
            if ($root === '' || in_array('..', explode('/', $root), true)) {
                throw new \RuntimeException('Private evidence root is invalid.');
            }
            $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/').'/';
            foreach ([public_path(), storage_path('app/public')] as $public) {
                $public = rtrim(str_replace('\\', '/', realpath($public) ?: $public), '/').'/';
                if (str_starts_with($root, $public)) {
                    throw new \RuntimeException('Private evidence cannot use a public directory.');
                }
            }
        }

        return $name;
    }

    private static ?\WeakMap $aclConfigured = null;

    private static function filesystem(string $name): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $disk=Storage::disk($name);
        if(config('filesystems.disks.'.$name.'.driver')==='s3'){
            $client=$disk->getClient();self::$aclConfigured??=new \WeakMap;
            if(!isset(self::$aclConfigured[$client])){
                // R2 controls privacy at bucket level. Flysystem/SDK otherwise default to an ACL.
                $client->getHandlerList()->appendInit(\Aws\Middleware::mapCommand(function(\Aws\CommandInterface $command){
                    if(in_array($command->getName(),['PutObject','CreateMultipartUpload'],true)){
                        foreach(['ACL','GrantFullControl','GrantRead','GrantReadACP','GrantWrite','GrantWriteACP'] as $field){unset($command[$field]);}
                    }
                    return $command;
                }),'gta-private-evidence-no-acl');
                self::$aclConfigured[$client]=true;
            }
        }
        return $disk;
    }

    private static function path(array $entry): string
    {
        $path = $entry['path'] ?? null;
        if (! is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('Private evidence path is invalid.');
        }

        return $path;
    }

    public static function store(UploadedFile $file, string $directory, int $actor): array
    {
        if ($file->getSize() <= 0) {
            throw ValidationException::withMessages(['file' => 'Choose a non-empty evidence file.']);
        }
        $disk = self::diskName();
        // Cloud R2 visibility is bucket-wide; do not add per-object ACL options.
        $options = ['disk' => $disk];
        if (config('filesystems.disks.'.$disk.'.driver') === 'local') {
            $options['visibility'] = 'private';
        }
        unset($options['disk']);
        $path = self::filesystem($disk)->putFile($directory, $file, $options);
        if (! $path) {
            throw new \RuntimeException('Private evidence could not be stored.');
        }

        return ['id' => (string) Str::uuid(), 'disk' => $disk, 'path' => $path, 'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 255), 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $actor, 'uploaded_at' => now()->toIso8601String()];
    }

    public static function exists(array $entry): bool
    {
        return self::filesystem(self::diskName($entry))->fileExists(self::path($entry));
    }

    // Metadata resolution only: safe for configuration/reference inventories without file I/O.
    public static function location(array $entry): array
    {
        return ['disk'=>self::diskName($entry),'path'=>self::path($entry)];
    }

    public static function fingerprint(array $entry): array
    {
        $location=self::location($entry);$disk=self::filesystem($location['disk']);
        if(!$disk->fileExists($location['path'])){return ['exists'=>false];}
        $stream=$disk->readStream($location['path']);
        if(!is_resource($stream)){throw new \RuntimeException('Private evidence could not be read.');}
        try{$hash=hash_init('sha256');$bytes=hash_update_stream($hash,$stream);return ['exists'=>true,'bytes'=>$bytes,'sha256'=>hash_final($hash)];}
        finally{fclose($stream);}
    }

    public static function copyExclusive(array $source,array $destination,int $maxBytes): void
    {
        $from=self::location($source);$to=self::location($destination);
        if($from===$to || !is_int($source['bytes']??null) || $source['bytes']<1 || $source['bytes']>$maxBytes || !is_string($source['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/',$source['sha256'])){throw new \RuntimeException('Invalid bounded copy source.');}
        $input=self::filesystem($from['disk'])->readStream($from['path']);$spool=fopen('php://temp/maxmemory:2097152','w+b');
        if(!is_resource($input) || !is_resource($spool)){if(is_resource($input)){fclose($input);}if(is_resource($spool)){fclose($spool);}throw new \RuntimeException('Copy source unavailable.');}
        try{
            $hash=hash_init('sha256');$bytes=0;
            while(!feof($input)){
                $chunk=fread($input,1048576);if($chunk===false){throw new \RuntimeException('Copy read failed.');}
                $bytes+=strlen($chunk);if($bytes>$source['bytes'] || $bytes>$maxBytes){throw new \RuntimeException('Copy source size changed.');}
                hash_update($hash,$chunk);if(fwrite($spool,$chunk)!==strlen($chunk)){throw new \RuntimeException('Private spool failed.');}
            }
            if($bytes!==$source['bytes'] || !hash_equals($source['sha256'],hash_final($hash))){throw new \RuntimeException('Copy source bytes changed.');}
            rewind($spool);$target=self::filesystem($to['disk']);$driver=config('filesystems.disks.'.$to['disk'].'.driver');
            if($driver==='s3'){
                $target->getClient()->putObject(['Bucket'=>config('filesystems.disks.'.$to['disk'].'.bucket'),'Key'=>$target->path($to['path']),'Body'=>$spool,'ContentLength'=>$bytes,'ContentType'=>'application/octet-stream','IfNoneMatch'=>'*']);
            }else{
                $root=rtrim($target->path(''),DIRECTORY_SEPARATOR);$full=$target->path($to['path']);$parent=dirname($full);$cursor=$root;
                foreach(explode('/',dirname($to['path'])) as $part){$cursor.=DIRECTORY_SEPARATOR.$part;if(is_link($cursor)){throw new \RuntimeException('Copy parent cannot be a symlink.');}}
                $target->makeDirectory(dirname($to['path']));$realRoot=realpath($root);$realParent=realpath($parent);
                if(!$realRoot || !$realParent || !str_starts_with($realParent.DIRECTORY_SEPARATOR,$realRoot.DIRECTORY_SEPARATOR)){throw new \RuntimeException('Copy destination escaped private root.');}
                $output=@fopen($full,'xb');if(!is_resource($output)){throw new \RuntimeException('Destination exists or cannot be created exclusively.');}
                try{if(!chmod($full,0600) || stream_copy_to_stream($spool,$output)!==$bytes){throw new \RuntimeException('Exclusive destination write failed.');}}finally{fclose($output);}
            }
        }finally{fclose($input);fclose($spool);}
    }

    public static function matchesHash(array $entry): bool
    {
        $expected=$entry['sha256']??'';
        if(!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/',$expected) || !self::exists($entry)){return false;}
        $stream=self::filesystem(self::diskName($entry))->readStream(self::path($entry));
        if(!is_resource($stream)){return false;}
        try{$hash=hash_init('sha256');hash_update_stream($hash,$stream);return hash_equals($expected,hash_final($hash));}
        finally{fclose($stream);}
    }

    public static function download(array $entry): StreamedResponse
    {
        return self::filesystem(self::diskName($entry))->download(self::path($entry), $entry['name'], ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public static function delete(array $entry): bool
    {
        return self::filesystem(self::diskName($entry))->delete(self::path($entry));
    }
}
