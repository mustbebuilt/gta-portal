<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\HelpNarrationRecord as Record;
use App\Models\SupportCapability;
use App\Models\User;
use App\Support\HelpCatalogue as Catalogue;
use App\Support\HelpNarration as Narration;
use App\Support\PrivateEvidence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuditFault;
use Tests\TestCase;

class HelpNarrationTest extends TestCase
{
    public function test_optional_context_help_cannot_break_apprentice_page_when_guide_source_is_invalid():void
    {
        $u=$this->user('apprentice');$path=resource_path('data/help-guides.json');$original=file_get_contents($path);
        try {file_put_contents($path,'NOT A VALID CATALOGUE');$this->actingAs($u)->get('/my-next-step')->assertStatus(409)->assertSee('Your profile needs linking');}
        finally {file_put_contents($path,$original);}
    }
    public function test_optional_file_scan_is_bounded_and_reports_unchecked_remainder(): void
    {
        [$u,$first] = $this->upload();
        $g = Catalogue::all()['figures'];
        Narration::upload($u, $u->auth_version, ['guide_id' => 'figures', 'guide_hash' => $g['hash'], 'source_reference' => 'Fictional second source'], UploadedFile::fake()->createWithContent('second.wav', $this->wave()));
        $r = Narration::inspect(true, true, 1);
        $this->assertSame(1, $r['file_probes']);
        $this->assertSame(1, $r['file_probes_skipped']);
        $this->assertFalse($r['file_verification_complete']);
        $this->assertSame(1, $r['counts']['files_verified']);
    }

    use LazilyRefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function wave(): string
    {
        $data = str_repeat("\0", 1600);

        return pack('a4Va4a4VvvVVvva4V', 'RIFF', 36 + strlen($data), 'WAVE', 'fmt ', 16, 1, 1, 8000, 16000, 2, 16, 'data', strlen($data)).$data;
    }

    private function upload(?User $u = null, string $id = 'start'): array
    {
        Storage::fake('local');
        $u ??= $this->user();
        $g = Catalogue::all()[$id];
        $file = UploadedFile::fake()->createWithContent('fictional-silent.wav', $this->wave());
        $r = Narration::upload($u, $u->auth_version, ['guide_id' => $id, 'guide_hash' => $g['hash'], 'source_reference' => 'PRIVATE-RECORDING-SOURCE'], $file);

        return [$u, $r, $g];
    }

    private function approve(Record $r, ?User $u = null): User
    {
        $u ??= $this->user('ceo');
        Narration::change($u, $u->auth_version, $r, ['version' => $r->version, 'action' => 'approve', 'note' => 'FICTIONAL-CHECK-NOTE', 'yorkshire_listened' => true, 'transcript_checked' => true, 'permission_checked' => true]);

        return $u;
    }

    private function invalid(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function audio(Record $r, string $guide = 'start'): string
    {
        return '/help/guides/'.$guide.'/recordings/'.$r->id;
    }

    public function test_catalogue_has_unique_stable_ids_valid_destinations_and_general_transcripts(): void
    {
        $all = Catalogue::all();
        $this->assertCount(41, $all);
        $this->assertCount(41, array_unique(array_keys($all)));
        foreach ($all as $id => $g) {
            $this->assertSame($id, $g['id']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $g['hash']);
            $this->assertSame($g['title'].'. '.$g['body'], $g['transcript']);
        }$this->assertStringNotContainsString('signatures, completion, shared copies and completed-review scheduling are not yet enabled', json_encode($all));
    }

    public function test_role_scoped_help_covers_current_reviews_questionnaires_alerts_and_employer_work(): void
    {
        foreach (['apprentice', 'employer', 'admin', 'inclusion_member'] as $role) {
            $u = $this->user($role);
            $r = $this->actingAs($u)->get('/help')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
            if ($role === 'apprentice') {
                $r->assertSee('Submit work')->assertSee('Complete a portal questionnaire')->assertSee('Choose text and email alerts')->assertSee('Read and sign your review')->assertDontSee('Manage apprentice reminders')->assertDontSee('Follow up a concern');
            }
            if ($role === 'employer') {
                $r->assertSee('Use the employer action centre')->assertSee('Read and sign your review')->assertDontSee('Submit work')->assertDontSee('Manage questionnaire submissions');
            }
            if ($role === 'admin') {
                $r->assertSee('Prepare a review draft')->assertSee('Manage help recordings')->assertSee('Manage questionnaire submissions');
            }
        }
    }

    public function test_search_and_category_filter_only_the_current_visible_general_guides(): void
    {
        $u = $this->user('apprentice');
        $this->actingAs($u)->get('/help?q=qualification')->assertOk()->assertSee('Topics and qualifications')->assertDontSee('Set up your account, staff and assignments');
        $this->get('/help?q=UNMATCHED-SENTINEL')->assertOk()->assertSee('No matching guides');
        $this->get('/help?category=Administration')->assertNotFound();
        $this->get('/help?guide=setup')->assertNotFound();
        $this->get('/help?guide=review-signing')->assertOk()->assertSee('id="help-review-signing" open', false);
    }

    public function test_help_never_reads_apprentice_record_text_and_reads_do_not_write_business_events(): void
    {
        Apprentice::factory()->create(['first_name' => 'PRIVATE-LEARNER-SENTINEL']);
        $u = $this->user('apprentice');
        $before = DB::table('learning_events')->count();
        $this->actingAs($u)->get('/help?q=work')->assertOk()->assertDontSee('PRIVATE-LEARNER-SENTINEL')->assertSee('never apprentice records');
        $this->assertSame($before, DB::table('learning_events')->count());
        $this->assertDatabaseCount('help_narration_events', 0);
    }

    public function test_context_help_maps_known_page_to_a_visible_stable_guide_without_personal_parameters(): void
    {
        $u = $this->user('apprentice');
        $r = Request::create('/fictional');
        $route = (new Route(['GET'], '/fictional', fn () => null))->name('electronic-reviews.show')->bind($r);
        $r->setRouteResolver(fn () => $route);
        $g = Catalogue::context($u, $r);
        $this->assertSame('review-signing', $g['id']);
        $this->assertStringNotContainsString('apprentice_id', json_encode($g));
        $this->actingAs($u)->get('/my-next-step')->assertSee('Help with this page: Your next step');
    }

    public function test_added_inclusion_duty_reveals_only_appropriate_guides_and_revocation_hides_them(): void
    {
        $u = $this->user('tutor');
        $this->assertArrayNotHasKey('support', Catalogue::visible($u));
        $c = SupportCapability::create(['user_id' => $u->id, 'duty' => 'member', 'scope' => 'assigned', 'apprentice_ids' => [], 'group_ids' => []]);
        $this->assertArrayHasKey('support', Catalogue::visible($u));
        $c->duty = 'staff';
        $c->save();
        $this->assertArrayNotHasKey('support', Catalogue::visible($u));
    }

    public function test_no_recording_is_promised_and_device_voice_remains_optional_without_audio_asset(): void
    {
        $this->actingAs($this->user('apprentice'))->get('/help?guide=start')->assertOk()->assertSee('Read the guide below')->assertSee('Device read-aloud is separate')->assertDontSee('data-recording-url=', false)->assertDontSee('autoplay', false);
    }

    public function test_management_upload_requires_current_manager_and_real_audio_mime_extension_and_size(): void
    {
        $g = Catalogue::all()['start'];
        foreach (['tutor', 'apprentice', 'employer', 'inclusion_lead'] as $role) {
            $this->actingAs($this->user($role))->get('/help-recordings')->assertForbidden();
        }
        $u = $this->user();
        Storage::fake('local');
        $this->actingAs($u)->post('/help-recordings', ['guide_id' => 'start', 'guide_hash' => $g['hash'], 'source_reference' => 'Fictional source', 'generic_content_checked' => 1, 'file' => UploadedFile::fake()->createWithContent('fake.wav', 'NOT-AUDIO')])->assertSessionHasErrors();
        $this->invalid(fn () => Narration::upload($u, $u->auth_version, ['guide_id' => 'start', 'guide_hash' => $g['hash'], 'source_reference' => 'Fictional'], UploadedFile::fake()->createWithContent('mismatch.mp3', $this->wave())));
        $this->invalid(fn () => Narration::upload($u, $u->auth_version, ['guide_id' => 'start', 'guide_hash' => $g['hash'], 'source_reference' => 'Fictional'], UploadedFile::fake()->create('large.wav', 20481, 'audio/wav')));
        $this->assertDatabaseCount('help_narration_records', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('help-narrations'));
    }

    public function test_uploaded_record_has_exact_transcript_private_source_and_no_publication_until_review(): void
    {
        [$u,$r,$g] = $this->upload();
        $this->assertTrue(Narration::intact($r));
        $this->assertSame($g['transcript'], $r->transcript);
        $this->assertFalse(Narration::approved($r));
        $this->assertArrayNotHasKey('file', $r->toArray());
        $this->assertArrayNotHasKey('source_reference', $r->toArray());
        $this->assertStringNotContainsString('PRIVATE-RECORDING-SOURCE', DB::table('help_narration_records')->value('source_reference'));
        $learner = $this->user('apprentice');
        $this->actingAs($learner)->get($this->audio($r))->assertStatus(409);
        $this->get('/help?guide=start')->assertDontSee('data-recording-url=', false);
    }

    public function test_stale_upload_hash_rejected_before_file_storage(): void
    {
        $u = $this->user();
        Storage::fake('local');
        $this->invalid(fn () => Narration::upload($u, $u->auth_version, ['guide_id' => 'start', 'guide_hash' => str_repeat('a', 64), 'source_reference' => 'Fictional'], UploadedFile::fake()->createWithContent('fake.wav', $this->wave())));
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_different_manager_must_listen_check_transcript_permission_and_approve_once(): void
    {
        [$u,$r] = $this->upload();
        $this->invalid(fn () => $this->approve($r, $u));
        $other = $this->user('ceo');
        $this->invalid(fn () => Narration::change($other, $other->auth_version, $r, ['version' => 1, 'action' => 'approve', 'note' => 'Incomplete checks', 'yorkshire_listened' => false, 'transcript_checked' => true, 'permission_checked' => true]));
        $this->approve($r, $other);
        $r->refresh();
        $this->assertTrue(Narration::approved($r));
        $this->assertSame('start', $r->published_guide_key);
        $this->invalid(fn () => $this->approve($r, $other));
        $this->assertDatabaseCount('help_narration_events', 2);
    }

    public function test_public_help_has_explicit_same_origin_recording_control_without_file_path_or_private_notes(): void
    {
        [$u,$r] = $this->upload();
        $this->approve($r);
        $this->actingAs($this->user('apprentice'))->get('/help?guide=start')->assertOk()->assertSee($this->audio($r))->assertSee('Play GTA Yorkshire recording')->assertSee('preload="none"', false)->assertDontSee($r->file['path'])->assertDontSee('PRIVATE-RECORDING-SOURCE')->assertDontSee('FICTIONAL-CHECK-NOTE')->assertDontSee('autoplay', false);
    }

    public function test_authenticated_recording_stream_returns_exact_verified_bytes_and_private_headers(): void
    {
        [$u,$r] = $this->upload();
        $this->approve($r);
        $response = $this->actingAs($this->user('apprentice'))->get($this->audio($r))->assertOk()->assertHeader('Content-Type', 'audio/wav')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Accept-Ranges', 'bytes')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($this->wave(), $response->streamedContent());
    }

    public function test_range_suffix_clamp_head_and_if_range_behavior_are_exact(): void
    {
        [$u,$r] = $this->upload();
        $this->approve($r);
        $url = $this->audio($r);
        $this->actingAs($this->user('apprentice'));
        $bytes = $this->wave();
        $size = strlen($bytes);
        $part = $this->withHeader('Range', 'bytes=0-11')->get($url)->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-11/'.$size)->assertHeader('Content-Length', '12');
        $this->assertSame(substr($bytes, 0, 12), $part->streamedContent());
        $this->assertSame(substr($bytes, -8), $this->withHeader('Range', 'bytes=-8')->get($url)->assertStatus(206)->streamedContent());
        $this->assertSame(substr($bytes, $size - 4), $this->withHeader('Range', 'bytes='.($size - 4).'-999999')->get($url)->assertStatus(206)->streamedContent());
        $this->withHeader('Range', 'bytes=1-3')->head($url)->assertStatus(206)->assertContent('')->assertHeader('Content-Length', '3');
        $this->assertSame($bytes, $this->withHeader('If-Range', 'unknown-validator')->get($url)->assertOk()->streamedContent());
    }

    public function test_invalid_or_multiple_ranges_fail_without_any_recording_body(): void
    {
        [$u,$r] = $this->upload();
        $this->approve($r);
        $this->actingAs($this->user('apprentice'));
        foreach (['bytes=', 'bytes=-0', 'items=0-1', 'bytes=5-2', 'bytes=999999-', 'bytes=0-1,4-5', 'bytes=999999999999999999999999-'] as $range) {
            $this->withHeader('Range', $range)->get($this->audio($r))->assertStatus(416)->assertHeader('Content-Range', 'bytes */'.strlen($this->wave()))->assertContent('');
        }
    }

    public function test_corrupted_missing_or_changed_size_recording_never_releases_unverified_audio_bytes(): void
    {
        [$u,$r] = $this->upload();
        $this->approve($r);
        $this->actingAs($this->user('apprentice'));
        Storage::disk('local')->put($r->file['path'], 'CORRUPT-PRIVATE-FILE');
        $this->get($this->audio($r))->assertStatus(409)->assertDontSee('CORRUPT-PRIVATE-FILE')->assertDontSee($r->file['path']);
        Storage::disk('local')->delete($r->file['path']);
        $this->get($this->audio($r))->assertStatus(409);
    }

    public function test_guide_scope_wrong_guide_guest_and_inactive_access_are_refused_before_streaming(): void
    {
        [$u,$r] = $this->upload(null, 'submit-work');
        $this->approve($r);
        $employer = $this->user('employer');
        $this->actingAs($employer)->get($this->audio($r, 'submit-work'))->assertNotFound();
        $this->get($this->audio($r, 'start'))->assertNotFound();
        $employer->active = false;
        $employer->save();
        $this->actingAs($employer)->get($this->audio($r, 'submit-work'))->assertForbidden();
        $this->actingAs($u)->get('/help-recordings/'.$r->id.'/preview')->assertOk();
    }

    public function test_source_wording_change_hides_old_recording_but_retains_manager_preview_and_history(): void
    {
        [$u,$r] = $this->upload();
        $this->approve($r);
        $path = resource_path('data/help-guides.json');
        $original = file_get_contents($path);
        try {
            $all = json_decode($original, true);
            $all[0]['body'] .= ' Updated source wording.';
            file_put_contents($path, json_encode($all));
            $this->assertTrue(Narration::intact($r->fresh()));
            $this->assertFalse(Narration::current($r->fresh()));
            $this->assertFalse(Narration::approved($r->fresh()));
            $this->actingAs($this->user('apprentice'))->get('/help?guide=start')->assertOk()->assertSee('Updated source wording')->assertDontSee('data-recording-url=', false);
            $this->get($this->audio($r))->assertStatus(409);
            $this->actingAs($u)->get('/help-recordings/'.$r->id.'/preview')->assertOk();
        } finally {
            file_put_contents($path, $original);
        }
    }

    public function test_replacement_requires_explicit_withdrawal_and_old_record_does_not_resurrect(): void
    {
        [$u,$first,$g] = $this->upload();
        $checker = $this->approve($first);
        $second = Narration::upload($u, $u->auth_version, ['guide_id' => 'start', 'guide_hash' => $g['hash'], 'source_reference' => 'SECOND-PRIVATE-SOURCE'], UploadedFile::fake()->createWithContent('second.wav', $this->wave()));
        $this->invalid(fn () => $this->approve($second, $checker));
        Narration::change($u, $u->auth_version, $first->fresh(), ['version' => 2, 'action' => 'withdraw', 'note' => 'PRIVATE-WITHDRAWAL-NOTE']);
        $this->approve($second, $checker);
        $this->assertSame($second->id, Narration::published(['start'])['start']->id);
        $this->assertSame('Withdrawn', $first->fresh()->state);
        $this->actingAs($this->user('apprentice'))->get($this->audio($first))->assertStatus(409);
        $this->assertTrue(PrivateEvidence::exists($first->file));
    }

    public function test_rejection_and_withdrawal_are_versioned_private_and_not_playable_to_users(): void
    {
        [$u,$r] = $this->upload();
        $checker = $this->user('ceo');
        Narration::change($checker, $checker->auth_version, $r, ['version' => 1, 'action' => 'reject', 'note' => 'PRIVATE-REJECTION']);
        $this->assertSame('Rejected', $r->fresh()->state);
        $this->invalid(fn () => $this->approve($r->fresh(), $checker));
        $this->actingAs($this->user('apprentice'))->get('/help?guide=start')->assertOk()->assertDontSee('PRIVATE-REJECTION')->assertDontSee('data-recording-url=', false);
    }

    public function test_content_or_publication_binding_tamper_does_not_publish_or_stream(): void
    {
        [$u,$r] = $this->upload();
        $this->approve($r);
        $r->publication_hash = str_repeat('a', 64);
        $r->save();
        $this->assertFalse(Narration::approved($r));
        $this->actingAs($this->user('apprentice'))->get($this->audio($r))->assertStatus(409);
        $r->transcript .= ' UNEXPECTED';
        $r->save();
        $this->assertFalse(Narration::intact($r));
        $this->actingAs($u)->get('/help-recordings/'.$r->id.'/preview')->assertStatus(409);
    }

    public function test_audit_failure_leaves_no_uploaded_row_or_orphan_clip(): void
    {
        $u = $this->user();
        Storage::fake('local');
        $g = Catalogue::all()['start'];
        AuditFault::install('reject_help_audio', 'help_narration_events', 'Fictional audit failure');
        $this->actingAs($u)->post('/help-recordings', ['guide_id' => 'start', 'guide_hash' => $g['hash'], 'source_reference' => 'Fictional source', 'generic_content_checked' => 1, 'file' => UploadedFile::fake()->createWithContent('sample.wav', $this->wave())])->assertStatus(500);
        $this->assertDatabaseCount('help_narration_records', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('help-narrations'));
    }

    public function test_readiness_cli_is_source_only_by_default_and_never_claims_to_judge_accent(): void
    {
        DB::shouldReceive('connection')->never();
        $r = Narration::inspect();
        $this->assertSame(41, $r['guides']);
        $this->assertFalse($r['records_checked']);
        $this->assertFalse($r['writes_performed']);
        $this->assertFalse($r['accent_automatically_verified']);
        $this->artisan('gta:help-check')->expectsOutputToContain('"guides":41')->assertExitCode(0);
    }

    public function test_record_file_probe_requires_explicit_staging_options_before_database_access(): void
    {
        $this->app['env'] = 'production';
        DB::shouldReceive('connection')->never();
        $this->artisan('gta:help-check', ['--records' => true])->expectsOutputToContain('staging_required')->assertExitCode(1);
        $this->artisan('gta:help-check', ['--verify-files' => true])->expectsOutputToContain('records_option_required')->assertExitCode(1);
    }

    public function test_readiness_counts_missing_audio_and_checks_files_without_writes_or_private_references(): void
    {
        [$u,$r] = $this->upload();
        $this->approve($r);
        $before = DB::table('help_narration_events')->count();
        $report = Narration::inspect(true, true);
        $this->assertSame(1, $report['counts']['approved_current']);
        $this->assertSame(1, $report['counts']['files_verified']);
        $this->assertSame(40, $report['guides_without_current_recording']);
        $this->assertFalse($report['accent_automatically_verified']);
        $this->assertStringNotContainsString('PRIVATE-RECORDING-SOURCE', json_encode($report));
        $this->assertStringNotContainsString($r->file['path'], json_encode($report));
        $this->assertSame($before, DB::table('help_narration_events')->count());
    }

    public function test_no_automatic_external_speech_or_message_calls_or_business_completion(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        [$u,$r] = $this->upload();
        $this->approve($r);
        $this->actingAs($this->user('apprentice'))->get('/help?guide=start')->assertOk();
        foreach (['learning_events', 'signed_review_records', 'survey_receipts', 'qualifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
