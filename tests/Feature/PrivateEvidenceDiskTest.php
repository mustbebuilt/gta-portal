<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\Qualification;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Support\PrivateEvidence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PrivateEvidenceDiskTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function disks(): void
    {
        foreach (['evidence_a', 'evidence_b'] as $name) {
            config(['filesystems.disks.'.$name => ['driver' => 'local', 'root' => storage_path('framework/testing/'.$name), 'visibility' => 'private', 'serve' => false, 'throw' => true]]);
            Storage::fake($name);
        }
        Storage::fake('local');
        config(['evidence.disk' => 'evidence_a', 'evidence.legacy_disk' => 'local', 'evidence.private_disks' => ['local', 'evidence_a', 'evidence_b']]);
    }

    private function task(): array
    {
        $learner = User::factory()->create();
        $learner->role = 'apprentice';
        $learner->save();
        $a = Apprentice::factory()->create(['user_id' => $learner->id]);
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id]);

        return [$task, $learner];
    }

    private function upload(LearningTask $task, int $version = 1, array $extra = []): void
    {
        $this->post('/tasks/'.$task->id.'/evidence', [...['version' => $version, 'kind' => 'supporting-evidence', 'file' => UploadedFile::fake()->createWithContent('work.pdf', "%PDF-1.4\nFictional evidence bytes")], ...$extra])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_new_upload_pins_its_disk_and_switching_the_default_keeps_old_downloads(): void
    {
        $this->disks();
        [$task, $learner] = $this->task();
        $this->actingAs($learner);
        $this->upload($task);
        $first = $task->evidence()->firstOrFail();
        $this->assertSame('evidence_a', $first->disk);
        Storage::disk('evidence_a')->assertExists($first->path);
        Storage::disk('local')->assertMissing($first->path);
        config(['evidence.disk' => 'evidence_b']);
        $this->upload($task, 2, ['disk' => 'public', 'path' => 'forged-path']);
        $second = $task->evidence()->orderByDesc('id')->firstOrFail();
        $this->assertSame('evidence_b', $second->disk);
        Storage::disk('evidence_b')->assertExists($second->path);
        $response = $this->get('/tasks/'.$task->id.'/evidence/'.$first->id)->assertOk()->assertDownload();
        $this->assertSame("%PDF-1.4\nFictional evidence bytes", $response->streamedContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_existing_unpinned_task_files_stay_on_the_legacy_disk(): void
    {
        $this->disks();
        [$task, $learner] = $this->task();
        Storage::disk('local')->put('task-evidence/old.pdf', 'Original legacy evidence');
        Storage::disk('evidence_b')->put('task-evidence/old.pdf', 'Wrong disk bytes');
        $file = TaskEvidence::create(['learning_task_id' => $task->id, 'uploaded_by' => $learner->id, 'disk' => null, 'path' => 'task-evidence/old.pdf', 'name' => 'old.pdf', 'mime' => 'application/pdf', 'bytes' => 24, 'sha256' => hash('sha256', 'Original legacy evidence'), 'kind' => 'supporting-evidence']);
        config(['evidence.disk' => 'evidence_b']);
        $response = $this->actingAs($learner)->get('/tasks/'.$task->id.'/evidence/'.$file->id)->assertOk();
        $this->assertSame('Original legacy evidence', $response->streamedContent());
    }

    public function test_json_file_metadata_keeps_its_disk_and_old_json_keeps_the_legacy_location(): void
    {
        $this->disks();
        [$task, $learner] = $this->task();
        $entry = PrivateEvidence::store(UploadedFile::fake()->createWithContent('proof.txt', 'Private qualification proof'), 'qualification-evidence', $learner->id);
        $this->assertSame('evidence_a', $entry['disk']);
        $q = Qualification::factory()->create(['apprentice_id' => $task->apprentice_id, 'files' => [$entry]]);
        config(['evidence.disk' => 'evidence_b']);
        $response = $this->actingAs($learner)->get('/qualifications/'.$q->id.'/files/'.$entry['id'])->assertOk();
        $this->assertSame('Private qualification proof', $response->streamedContent());
        unset($entry['disk']);
        Storage::disk('local')->put($entry['path'], 'Legacy qualification proof');
        $q->files = [$entry];
        $q->save();
        $response = $this->get('/qualifications/'.$q->id.'/files/'.$entry['id'])->assertOk();
        $this->assertSame('Legacy qualification proof', $response->streamedContent());
    }

    public function test_failed_audit_removes_the_new_file_from_its_pinned_disk_even_if_configuration_changes(): void
    {
        $this->disks();
        [$task, $learner] = $this->task();
        DB::listen(function (QueryExecuted $event): void {
            if (str_starts_with(strtolower($event->sql), 'insert') && str_contains($event->sql, 'learning_events')) {
                config(['evidence.disk' => 'evidence_b']);
                throw new \RuntimeException('Fictional audit failure after disk setting changed');
            }
        });
        $this->actingAs($learner)->post('/tasks/'.$task->id.'/evidence', ['version' => 1, 'kind' => 'supporting-evidence', 'file' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nFictional")])->assertStatus(500);
        $this->assertDatabaseCount('task_evidence', 0);
        $this->assertSame([], Storage::disk('evidence_a')->allFiles());
        $this->assertSame([], Storage::disk('evidence_b')->allFiles());
        $this->assertSame(1, $task->fresh()->version);
    }

    #[TestWith(['public-name'])] #[TestWith(['public-visibility'])] #[TestWith(['public-root'])] #[TestWith(['unapproved-disk'])]
    public function test_invalid_storage_configuration_fails_before_writing_any_file(string $case): void
    {
        $this->disks();
        [$task, $learner] = $this->task();
        if ($case === 'public-name') {
            config(['evidence.disk' => 'public', 'evidence.private_disks' => ['public']]);
        } elseif ($case === 'public-visibility') {
            config(['filesystems.disks.evidence_a.visibility' => 'public']);
        } elseif ($case === 'public-root') {
            config(['filesystems.disks.evidence_a.root' => public_path('evidence')]);
        } else {
            config(['evidence.private_disks' => ['local']]);
        }
        $this->actingAs($learner)->post('/tasks/'.$task->id.'/evidence', ['version' => 1, 'kind' => 'supporting-evidence', 'file' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nFictional")])->assertStatus(500);
        $this->assertDatabaseCount('task_evidence', 0);
        $this->assertSame([], Storage::disk('evidence_a')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_missing_file_does_not_fall_back_to_a_different_disk(): void
    {
        $this->disks();
        [$task, $learner] = $this->task();
        $file = TaskEvidence::create(['learning_task_id' => $task->id, 'uploaded_by' => $learner->id, 'disk' => 'evidence_a', 'path' => 'task-evidence/missing.pdf', 'name' => 'missing.pdf', 'mime' => 'application/pdf', 'bytes' => 2, 'sha256' => str_repeat('0', 64), 'kind' => 'supporting-evidence']);
        Storage::disk('evidence_b')->put($file->path, 'Unrelated file');
        config(['evidence.disk' => 'evidence_b']);
        $this->actingAs($learner)->get('/tasks/'.$task->id.'/evidence/'.$file->id)->assertNotFound();
    }

    public function test_private_s3_disk_configuration_uses_the_server_allowlist_and_rejects_a_public_url(): void
    {
        config(['evidence.disk' => 'r2_private', 'evidence.private_disks' => ['local', 'r2_private'], 'filesystems.disks.r2_private' => ['driver' => 's3', 'bucket' => 'fictional-private-bucket']]);
        $this->assertSame('r2_private', PrivateEvidence::diskName());
        $this->artisan('gta:evidence-check')->assertExitCode(class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class) ? 0 : 1);
        config(['filesystems.disks.r2_private.url' => 'https://public.example.test']);
        $this->expectException(\RuntimeException::class);
        PrivateEvidence::diskName();
    }

    public function test_configuration_check_is_read_only_and_does_not_mistake_configuration_for_a_cloud_test(): void
    {
        config(['evidence.disk' => 'local', 'evidence.legacy_disk' => 'local', 'evidence.private_disks' => ['local']]);
        $this->artisan('gta:evidence-check')->expectsOutputToContain('No network or bucket-visibility check')->assertSuccessful();
    }

    public function test_additive_migration_preserves_old_evidence_rows_without_guessing_a_new_disk(): void
    {
        [$task, $learner] = $this->task();
        Schema::table('task_evidence', fn (Blueprint $table) => $table->dropColumn('disk'));
        $id = DB::table('task_evidence')->insertGetId(['learning_task_id' => $task->id, 'uploaded_by' => $learner->id, 'path' => 'old/evidence.pdf', 'name' => 'evidence.pdf', 'mime' => 'application/pdf', 'sha256' => str_repeat('a', 64), 'bytes' => 16, 'kind' => 'supporting-evidence']);
        $before = (array) DB::table('task_evidence')->where('id', $id)->first();
        $migration = require database_path('migrations/2026_11_12_223000_add_disk_to_task_evidence.php');
        $migration->up();
        $after = (array) DB::table('task_evidence')->where('id', $id)->first();
        $this->assertNull($after['disk']);
        unset($after['disk']);
        $this->assertSame($before, $after);
    }
}
