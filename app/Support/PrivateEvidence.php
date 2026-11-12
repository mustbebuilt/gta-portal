<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateEvidence
{
    public static function diskName(): string
    {
        return config('filesystems.private_evidence_disk', 'local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(static::diskName());
    }

    public static function rules(): array
    {
        return ['required', 'file', 'mimes:pdf,docx,png,jpg,jpeg,txt', 'extensions:pdf,docx,png,jpg,jpeg,txt', 'max:20480'];
    }

    public static function store(UploadedFile $file, string $directory, int $actor): array
    {
        if ($file->getSize() <= 0) {
            throw ValidationException::withMessages(['file' => 'Choose a non-empty evidence file.']);
        }
        $path = $file->store($directory, static::diskName());
        if (! $path) {
            throw new \RuntimeException('Private evidence could not be stored.');
        }

        return [
            'id' => (string) Str::uuid(),
            'path' => $path,
            'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 255),
            'mime' => $file->getMimeType(),
            'bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $actor,
            'uploaded_at' => now()->toIso8601String(),
        ];
    }

    public static function exists(?string $path): bool
    {
        return ! empty($path) && static::disk()->exists($path);
    }

    public static function delete(?string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        return static::disk()->delete($path);
    }

    public static function download(string $path, string $name): StreamedResponse
    {
        return static::disk()->download($path, $name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
