<?php

namespace App\Support;

use App\Models\HelpNarrationRecord as Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HelpNarration
{
    public const MAX_BYTES = 20971520;

    private static function fail(string $s): never
    {
        throw ValidationException::withMessages(['version' => $s]);
    }

    public static function digest(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private static function actor(User $u, int $auth): User
    {
        $x = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
        abort_unless($x->auth_version === $auth && LearningAccess::manager($x), 403);

        return $x;
    }

    private static function content(Record $r): array
    {
        return ['id' => $r->id, 'guide_id' => $r->guide_id, 'guide_hash' => $r->guide_hash, 'transcript' => $r->transcript, 'file' => $r->file, 'source_reference' => $r->source_reference, 'uploaded_by' => $r->uploaded_by];
    }

    public static function intact(Record $r): bool
    {
        try {
            return is_string($r->binding) && hash_equals($r->binding, self::digest(self::content($r))) && in_array($r->file['mime'] ?? null, ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'], true) && is_int($r->file['bytes'] ?? null) && $r->file['bytes'] > 0 && $r->file['bytes'] <= self::MAX_BYTES;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function current(Record $r): bool
    {
        $g = HelpCatalogue::all()[$r->guide_id] ?? null;

        return $g && $r->guide_hash === $g['hash'] && $r->transcript === $g['transcript'];
    }

    public static function approved(Record $r): bool
    {
        try {
            $p = $r->publication;

            return $r->state === 'Approved' && $r->published_guide_key === $r->guide_id && self::intact($r) && self::current($r) && $r->reviewed_by !== $r->uploaded_by && $p['record_id'] === $r->id && $p['binding'] === $r->binding && $p['by'] === $r->reviewed_by && $p['at'] === $r->reviewed_at->toIso8601String() && $p['yorkshire_listened'] === true && $p['transcript_checked'] === true && $p['permission_checked'] === true && hash_equals($r->publication_hash, self::digest($p));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function published(array $guideIds): array
    {
        $result = [];
        foreach (Record::whereIn('published_guide_key', $guideIds)->where('state', 'Approved')->get() as $row) {
            if (self::approved($row)) {
                $result[$row->guide_id] = $row;
            }
        }

        return $result;
    }

    public static function upload(User $u, int $auth, array $d, UploadedFile $file): Record
    {
        $entry = null;
        try {
            return DB::transaction(function () use ($u, $auth, $d, $file, &$entry) {
                $actor = self::actor($u, $auth);
                $g = HelpCatalogue::all()[$d['guide_id']] ?? null;
                if (! $g || $g['hash'] !== $d['guide_hash']) {
                    self::fail('The guide wording changed. Reopen its current recording script.');
                }
                if (Record::where('uploaded_by', $actor->id)->where('created_at', '>=', now()->subDay())->count() >= 100) {
                    self::fail('One hundred narration uploads in the last day. Reuse/check existing recordings before adding more.');
                }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
                $extension = strtolower($file->getClientOriginalExtension());
                $size = $file->getSize();
                if (! is_int($size) || $size < 44 || $size > self::MAX_BYTES || ! in_array($extension, ['mp3', 'wav'], true) || ($extension === 'mp3' && $mime !== 'audio/mpeg') || ($extension === 'wav' && ! in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'], true))) {
                    self::fail('Use a genuine MP3 or WAV recording, up to 20 MiB. Listen and check its content before publication.');
                }
                $entry = PrivateEvidence::store($file, 'help-narrations', $actor->id);
                $r = new Record(['id' => (string) Str::uuid(), 'guide_id' => $g['id'], 'guide_hash' => $g['hash'], 'transcript' => $g['transcript'], 'file' => $entry, 'source_reference' => $d['source_reference'], 'uploaded_by' => $actor->id, 'history' => [['event' => 'uploaded', 'by' => $actor->id, 'at' => now()->toIso8601String()]]]);
                $r->binding = self::digest(self::content($r));
                $r->save();
                self::event($r, $actor, 'uploaded');

                return $r;
            });
        } catch (\Throwable $e) {
            if ($entry) {
                PrivateEvidence::delete($entry);
            }throw $e;
        }
    }

    public static function change(User $u, int $auth, Record $record, array $d): void
    {
        DB::transaction(function () use ($u, $auth, $record, $d): void {
            $actor = self::actor($u, $auth);
            // Lock all existing recordings of this guide before deciding its one current published slot.
            Record::where('guide_id', $record->guide_id)->orderBy('id')->lockForUpdate()->get();
            $r = Record::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($r->version !== (int) $d['version']) {
                self::fail('This recording changed. Reopen its current page.');
            }$action = $d['action'];
            if ($action === 'approve') {
                if ($r->state !== 'Uploaded' || $r->uploaded_by === $actor->id || ! self::intact($r) || ! self::current($r)) {
                    self::fail('A different administrator or CEO must check the current unchanged uploaded recording.');
                }
                if (Record::where('published_guide_key', $r->guide_id)->exists()) {
                    self::fail('Withdraw the existing published recording for this guide before replacing it, even if its wording is stale.');
                }
                if (! self::fileMatches($r)) {
                    self::fail('The recording is missing or its bytes changed. Investigate before publishing.');
                }
                foreach (['yorkshire_listened', 'transcript_checked', 'permission_checked'] as $key) {
                    if (! ($d[$key] ?? false)) {
                        self::fail('Listen to the complete clip and check accent, transcript and permission before approval.');
                    }
                }
                $r->state = 'Approved';
                $r->published_guide_key = $r->guide_id;
                $r->reviewed_by = $actor->id;
                $r->reviewed_at = now()->startOfSecond();
                $r->publication = ['record_id' => $r->id, 'binding' => $r->binding, 'by' => $actor->id, 'at' => $r->reviewed_at->toIso8601String(), 'yorkshire_listened' => true, 'transcript_checked' => true, 'permission_checked' => true];
                $r->publication_hash = self::digest($r->publication);
            } elseif ($action === 'reject') {
                if ($r->state !== 'Uploaded') {
                    self::fail('Only an uploaded recording can be rejected.');
                }$r->state = 'Rejected';
                $r->reviewed_by = $actor->id;
                $r->reviewed_at = now()->startOfSecond();
            } elseif ($action === 'withdraw') {
                if (! in_array($r->state, ['Uploaded', 'Approved'], true)) {
                    self::fail('This recording is already rejected or withdrawn.');
                }$r->state = 'Withdrawn';
                $r->published_guide_key = null;
            } else {
                abort(422);
            }
            $r->version++;
            $r->history = [...$r->history, ['event' => $action, 'by' => $actor->id, 'at' => now()->toIso8601String(), 'note' => $d['note']]];
            $r->save();
            self::event($r, $actor, $action, $d['note']);
        });
    }

    public static function range(?string $header, int $size): ?array
    {
        if ($header === null) {
            return [0, $size - 1, false];
        }
        if (strlen($header) > 100 || ! preg_match('/^bytes=([0-9]*)-([0-9]*)$/D', $header, $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        foreach ([$m[1], $m[2]] as $n) {
            if ($n !== '' && (strlen($n) > 18 || ! ctype_digit($n))) {
                return null;
            }
        }
        if ($m[1] === '') {
            if ((int) $m[2] === 0) {
                return null;
            }$start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            if ($start >= $size || $start > $end) {
                return null;
            }
        }

        return [$start, $end, true];
    }

    /** Spool and verify one bounded snapshot before releasing bytes, including seek/range requests. */
    public static function stream(Record $record, Request $request)
    {
        abort_unless(self::intact($record), 409, 'The recording needs checking.');
        $entry = $record->file;
        $size = $entry['bytes'];
        $range = self::range($request->header('If-Range') === null ? $request->header('Range') : null, $size);
        $common = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'Accept-Ranges' => 'bytes'];
        if ($range === null) {
            return response('', 416, [...$common, 'Content-Range' => 'bytes */'.$size]);
        }
        $source = null;
        $spool = null;
        try {
            $location = PrivateEvidence::location($entry);
            $source = Storage::disk($location['disk'])->readStream($location['path']);
            if (! is_resource($source)) {
                throw new \RuntimeException('Unavailable recording.');
            }$spool = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (! is_resource($spool)) {
                throw new \RuntimeException('Unavailable private spool.');
            }$hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException('Recording read failed.');
                }$bytes += strlen($chunk);
                if ($bytes > $size || $bytes > self::MAX_BYTES) {
                    throw new \RuntimeException('Recording size changed.');
                }hash_update($hash, $chunk);
                if (fwrite($spool, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Recording spool failed.');
                }
            }
            fclose($source);
            $source = null;
            if ($bytes !== $size || ! hash_equals($entry['sha256'], hash_final($hash))) {
                throw new \RuntimeException('Recording bytes changed.');
            }
        } catch (\Throwable) {
            if (is_resource($source)) {
                fclose($source);
            }if (is_resource($spool)) {
                fclose($spool);
            }abort(409, 'The recording is unavailable or changed. Read the transcript and ask GTA to check it.');
        }
        [$start,$end,$partial] = $range;
        $length = $end - $start + 1;
        $mime = $entry['mime'] === 'audio/mpeg' ? 'audio/mpeg' : 'audio/wav';
        $headers = [...$common, 'Content-Type' => $mime, 'Content-Length' => (string) $length, 'Content-Disposition' => 'inline; filename="GTA-help-'.$record->guide_id.($mime === 'audio/mpeg' ? '.mp3' : '.wav').'"'];
        if ($partial) {
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }
        if ($request->isMethod('HEAD')) {
            fclose($spool);

            return response('', $partial ? 206 : 200, $headers);
        }

        return new StreamedResponse(function () use ($spool, $start, $length): void {
            try {
                fseek($spool, $start);
                $left = $length;
                while ($left > 0 && ! feof($spool)) {
                    $chunk = fread($spool, min(65536, $left));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }$left -= strlen($chunk);
                    echo $chunk;
                }
            } finally {
                fclose($spool);
            }
        }, $partial ? 206 : 200, $headers);
    }

    public static function fileMatches(Record $r): bool
    {
        try {
            $actual = PrivateEvidence::fingerprint($r->file, self::MAX_BYTES);

            return ($actual['exists'] ?? false) && $actual['bytes'] === $r->file['bytes'] && hash_equals($r->file['sha256'], $actual['sha256']);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function inspect(bool $records = false, bool $verify = false, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 250) {
            throw new \InvalidArgumentException('Recording verification limit must be between one and 250.');
        }
        $catalogue = HelpCatalogue::all();
        $result = ['guides' => count($catalogue), 'catalogue_hash' => ReviewedImports::hash($catalogue), 'records_checked' => false, 'writes_performed' => false, 'speech_provider_requests' => false, 'accent_automatically_verified' => false];
        if (! $records) {
            return $result;
        }
        $counts = ['uploaded' => 0, 'approved_current' => 0, 'rejected' => 0, 'withdrawn' => 0, 'stale' => 0, 'integrity_checks' => 0, 'files_verified' => 0, 'file_checks' => 0];
        $probes = 0;
        $skipped = 0;
        foreach (Record::orderBy('id')->cursor() as $r) {
            if (! self::intact($r)) {
                $counts['integrity_checks']++;

                continue;
            }if (! self::current($r)) {
                $counts['stale']++;
            }if (self::approved($r)) {
                $counts['approved_current']++;
            } elseif ($r->state === 'Uploaded') {
                $counts['uploaded']++;
            } elseif ($r->state === 'Rejected') {
                $counts['rejected']++;
            } elseif ($r->state === 'Withdrawn') {
                $counts['withdrawn']++;
            } elseif ($r->state === 'Approved' && self::current($r)) {
                $counts['integrity_checks']++;
            }
            if ($verify) {
                if ($probes >= $limit) {
                    $skipped++;

                    continue;
                }$probes++;
                try {
                    if (self::fileMatches($r)) {
                        $counts['files_verified']++;
                    } else {
                        $counts['file_checks']++;
                    }
                } catch (\Throwable) {
                    $counts['file_checks']++;
                }
            }
        }$result['records_checked'] = true;
        $result['counts'] = $counts;
        $result['guides_without_current_recording'] = count($catalogue) - $counts['approved_current'];
        $result['file_verification_requested'] = $verify;
        $result['file_verification_limit'] = $limit;
        $result['file_probes'] = $probes;
        $result['file_probes_skipped'] = $skipped;
        $result['file_verification_complete'] = $verify && $skipped === 0 && $counts['integrity_checks'] === 0 && $counts['file_checks'] === 0;

        return $result;
    }

    private static function event(Record $r, User $u, string $event, ?string $note = null): void
    {
        DB::table('help_narration_events')->insert(['record_id' => $r->id, 'actor_id' => $u->id, 'event' => $event, 'record_version' => $r->version, 'private_note' => $note === null ? null : encrypt($note), 'created_at' => now()]);
    }
}
