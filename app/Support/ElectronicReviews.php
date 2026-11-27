<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\ElectronicReviewPacket as Packet;
use App\Models\ElectronicReviewSigner as Signer;
use App\Models\EmployerApprenticeGrant as Grant;
use App\Models\IndividualDeliveryPlan;
use App\Models\ProgressReviewDraft as Review;
use App\Models\ReviewSigningAuthority as Authority;
use App\Models\SignedReviewRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ElectronicReviews
{
    public const ROLES = ['apprentice' => 'Apprentice', 'employer' => 'Employer representative', 'mentor' => 'Learning & Progress Mentor'];

    public const INTENT = 'I have read the participant review record shown above and confirm this is my own review sign-off.';

    public const DECLINE = 'I cannot sign this participant record and request GTA to review it.';

    public const FIELDS = ['reviewDate' => 'Actual meeting date', 'trainingFrom' => 'Training period from', 'mentor' => 'Learning & Progress Mentor', 'tutor' => 'Tutor', 'employerRep' => 'Employer representative', 'method' => 'Meeting method', 'attendance' => 'Employer contribution', 'absence' => 'Participation details', 'judgement' => 'Progress judgement', 'learning' => 'Learning discussed', 'progressDiscussion' => 'Progress discussion', 'planChange' => 'Training-plan change decision', 'planChangeNotes' => 'Plan-change notes', 'previousCheck' => 'Previous action check', 'previousNotes' => 'Previous action outcomes', 'summary' => 'Review discussion summary', 'nextReview' => 'Next review due reference (not a booked appointment)'];

    public static function displayFields(array $document):array
    {
        if (($document['layout83']??null)!=='simple83') return self::FIELDS;
        $keys=['reviewDate','trainingFrom','mentor','tutor','employerRep','method','attendance','judgement','summary','planChange','nextReview'];
        foreach(['learning','progressDiscussion','previousNotes'] as $key)if(filled($document['fields'][$key]??null))$keys[]=$key;
        if (($document['fields']['attendance']??null)==='Absent — opportunity to contribute recorded') $keys[]='absence';
        if (($document['fields']['planChange']??null)==='Yes') $keys[]='planChangeNotes';
        return array_intersect_key(self::FIELDS,array_flip($keys));
    }

    public static function staff(User $u, Apprentice $a): bool
    {
        return SignedReviews::allowed($u, $a);
    }

    private static function fail(string $s): never
    {
        throw ValidationException::withMessages(['version' => $s]);
    }

    private static function actor(User $u, int $auth): User
    {
        $x = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
        abort_unless($x->active && $x->auth_version === $auth, 403);

        return $x;
    }

    private static function version($x, int $v): void
    {
        if ($x->version !== $v) {
            self::fail('This record changed. Reopen the current page.');
        }
    }

    private static function name(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }

    public static function digest(array $a): string
    {
        return hash_hmac('sha256', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public static function profile(Apprentice $a): string
    {
        return ReviewedImports::hash($a->only('id', 'reference', 'first_name', 'last_name', 'programme_id', 'employer_id', 'employer', 'start_date', 'mentor_id', 'tutor_id', 'user_id'));
    }

    public static function grantHash(Grant $g): string
    {
        return ReviewedImports::hash($g->only('id', 'version', 'apprentice_id', 'user_id', 'employer_id', 'employer_version', 'identity_hash'));
    }

    public static function authorityValid(Authority $authority, Apprentice $a, bool $lock = false): bool
    {
        $g = Grant::whereKey($authority->employer_apprentice_grant_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();

        return $authority->active && $authority->apprentice_id === $a->id && ! $authority->expires_on->lt(today()) && $g && EmployerActions::valid($g, $a) && hash_equals($authority->grant_binding, self::grantHash($g));
    }

    public static function saveAuthority(User $u, int $auth, Apprentice $profile, Grant $grant, array $d): Authority
    {
        return DB::transaction(function () use ($u, $auth, $profile, $grant, $d) {
            $a = Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            abort_unless(LearningAccess::manager($actor), 403);
            $g = Grant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if ($g->apprentice_id !== $a->id || ! EmployerActions::valid($g, $a)) {
                self::fail('Verify the named current employer account and apprentice access first. Reply access alone is not signing authority.');
            }
            $x = Authority::where('employer_apprentice_grant_id', $g->id)->lockForUpdate()->first();
            if (($x?->version ?? 0) !== (int) $d['version']) {
                self::fail('The signing authority changed. Reload before verifying.');
            }
            $x ??= new Authority(['id' => (string) Str::uuid(), 'apprentice_id' => $a->id, 'employer_apprentice_grant_id' => $g->id]);
            $x->history = [...($x->history ?? []), ['event' => 'authority_verified', 'by' => $actor->id, 'at' => now()->toIso8601String(), 'reference' => $d['authority_reference'], 'expires_on' => $d['expires_on']]];
            $x->fill(['grant_binding' => self::grantHash($g), 'authority_reference' => $d['authority_reference'], 'expires_on' => $d['expires_on'], 'active' => true, 'verified_by' => $actor->id, 'version' => $x->exists ? $x->version + 1 : 1]);
            $x->save();
            self::event($actor, null, $x, 'authority_verified', $x->version);

            return $x;
        });
    }

    public static function revokeAuthority(User $u, int $auth, Authority $authority, int $v, string $reason): void
    {
        DB::transaction(function () use ($u, $auth, $authority, $v, $reason): void {
            Apprentice::whereKey($authority->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            abort_unless(LearningAccess::manager($actor), 403);
            $x = Authority::whereKey($authority->id)->lockForUpdate()->firstOrFail();
            self::version($x, $v);
            if (! $x->active) {
                self::fail('This signing authority is already revoked.');
            }
            $x->active = false;
            $x->version++;
            $x->history = [...$x->history, ['event' => 'revoked', 'by' => $actor->id, 'at' => now()->toIso8601String(), 'reason' => $reason]];
            $x->save();
            self::event($actor, null, $x, 'authority_revoked', $x->version, $reason);
        });
    }

    private static function bindings(Apprentice $a, Review $r, Authority $authority, bool $lock): array
    {
        if ($a->programme_id) {
            $a->programmeRecord()->when($lock, fn ($q) => $q->lockForUpdate())->first();
        }
        IndividualDeliveryPlan::where('apprentice_id', $a->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $plan = DeliveryPlan::approved($a);
        if ($r->apprentice_id !== $a->id || $r->state !== 'Ready for signatures' || ! $a->start_date || ! filled($r->fields['reviewDate'] ?? null) || $r->fields['reviewDate'] < $a->start_date->toDateString() || $r->fields['reviewDate'] > today()->toDateString() || ! $plan || ($r->source_snapshot['delivery_plan']['hash'] ?? null) !== $plan['hash']) {
            self::fail('Use a frozen prepared review with an actual meeting date and current provider-approved delivery plan.');
        }
        ReviewPreparation::requireReady($r->fields ?? [], $r->source_snapshot ?? [], $plan);
        if (! self::authorityValid($authority, $a, $lock)) {
            self::fail('Current named employer signing authority is required.');
        }
        $g = Grant::findOrFail($authority->employer_apprentice_grant_id);
        $ids = ['apprentice' => $a->user_id, 'employer' => $g->user_id, 'mentor' => $a->mentor_id];
        if (count(array_unique(array_values($ids))) !== 3 || in_array(null, $ids, true)) {
            self::fail('Three distinct named apprentice, employer and Learning & Progress Mentor accounts are required.');
        }
        $expected = ['apprentice' => $a->first_name.' '.$a->last_name, 'employer' => $r->fields['employerRep'] ?? '', 'mentor' => $r->fields['mentor'] ?? ''];
        $participants = [];
        foreach ($ids as $role => $id) {
            $u = User::whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
            if (! $u?->active || ! $u->email_verified_at || self::name($u->name) !== self::name($expected[$role]) || in_array(self::name($u->name), ['unknown', 'n/a', 'not recorded'], true) || User::whereRaw('LOWER(email) = ?', [strtolower($u->email)])->count() !== 1 || ($role === 'apprentice' && ($u->role !== 'apprentice' || Apprentice::where('user_id', $u->id)->count() !== 1)) || ($role === 'employer' && $u->role !== 'employer') || ($role === 'mentor' && ! StaffDuties::assigned($u, $a, 'mentor'))) {
                self::fail('Match the frozen participant names to three current verified individual accounts and current duties. Shared/generic/mismatched accounts cannot sign.');
            }
            $participants[$role] = ['user_id' => $u->id, 'name' => $expected[$role], 'identity_hash' => EmployerActions::identity($u), 'auth_version' => $u->auth_version];
            if ($role === 'mentor') {
                $participants[$role]['duty_binding'] = StaffDuties::binding($u, 'mentor');
            }
        }

        return ['apprentice_id' => $a->id, 'profile_hash' => self::profile($a), 'review_id' => $r->id, 'review_version' => $r->version, 'review_hash' => SignedReviews::reviewHash($r), 'plan_hash' => $plan['hash'], 'authority_id' => $authority->id, 'authority_version' => $authority->version, 'grant_hash' => self::grantHash($g), 'participants' => $participants];
    }

    public static function intact(Packet $p): bool
    {
        try {
            $s = $p->snapshot;

            return $s['binding']['apprentice_id'] === $p->apprentice_id && $s['binding']['review_id'] === $p->progress_review_draft_id && $s['expires_on'] === $p->expires_on->toDateString() && $s['intent'] === self::INTENT && hash_equals($p->snapshot_hash, self::digest($s));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function current(Packet $p, bool $lock = false): bool
    {
        try {
            if (! self::intact($p) || $p->expires_on->lt(today())) {
                return false;
            }$a = Apprentice::findOrFail($p->apprentice_id);
            $r = Review::whereKey($p->progress_review_draft_id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
            $authority = Authority::whereKey($p->snapshot['binding']['authority_id'])->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();

            return ReviewedImports::hash($p->snapshot['binding']) === ReviewedImports::hash(self::bindings($a, $r, $authority, $lock));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function prepare(User $u, int $auth, Review $review, array $d): Packet
    {
        return DB::transaction(function () use ($u, $auth, $review, $d) {
            $a = Apprentice::whereKey($review->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            abort_unless(self::staff($actor, $a), 403);
            $r = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            self::version($r, (int) $d['version']);
            if (Packet::where('active_review_id', $r->id)->exists() || SignedReviewRecord::where('progress_review_draft_id', $r->id)->whereNull('withdrawn_at')->exists()) {
                self::fail('This frozen review already has an open signing packet or signed-copy record. Resolve/withdraw it first.');
            }
            $authority = Authority::whereKey($d['authority_id'])->where('apprentice_id', $a->id)->lockForUpdate()->firstOrFail();
            $binding = self::bindings($a, $r, $authority, true);
            $actions = [];
            foreach ($d['action_ids'] ?? [] as $id) {
                $row = collect($r->source_snapshot['action_plan'] ?? [])->firstWhere('id', (int) $id);
                if (! $row) {
                    self::fail('Select only actions captured in this frozen review.');
                }$actions[] = collect($row)->only('id', 'title', 'owner', 'status', 'due_date', 'completion_date')->all();
            }
            $document = ['reference' => $a->reference, 'apprentice_name' => $a->first_name.' '.$a->last_name, 'employer' => $a->employerLabel(), 'review_id' => $r->id, 'review_version' => $r->version, 'fields' => collect($r->fields)->only(array_keys(self::FIELDS))->all(), 'actions' => $actions, 'excluded' => 'Restricted support needs/summary, questionnaires, careers, private feedback, evidence files and internal source snapshots are not included.'];
            if (($r->fields['format83']??null)==='simple83') $document['layout83']='simple83';
            $s = ['document' => $document, 'binding' => $binding, 'expires_on' => $d['expires_on'], 'intent' => self::INTENT, 'declaration_version' => 'gta-participant-review-1'];
            $p = Packet::create(['id' => (string) Str::uuid(), 'apprentice_id' => $a->id, 'progress_review_draft_id' => $r->id, 'active_review_id' => $r->id, 'prepared_by' => $actor->id, 'snapshot' => $s, 'snapshot_hash' => self::digest($s), 'expires_on' => $d['expires_on']]);
            foreach ($binding['participants'] as $role => $person) {
                Signer::create(['id' => (string) Str::uuid(), 'packet_id' => $p->id, 'role' => $role, 'user_id' => $person['user_id'], 'expected_name' => $person['name'], 'identity_hash' => $person['identity_hash'], 'auth_version' => $person['auth_version']]);
            }
            self::event($actor, $p, null, 'packet_prepared', 1);

            return $p;
        });
    }

    public static function release(User $u, int $auth, Packet $packet, int $v): void
    {
        DB::transaction(function () use ($u, $auth, $packet, $v): void {
            $a = Apprentice::whereKey($packet->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            abort_unless(self::staff($actor, $a), 403);
            Review::whereKey($packet->progress_review_draft_id)->lockForUpdate()->firstOrFail();
            $p = Packet::whereKey($packet->id)->lockForUpdate()->firstOrFail();
            self::version($p, $v);
            if ($p->state !== 'Draft' || ! self::current($p, true)) {
                self::fail('This draft packet/source/participant authority changed or expired. Withdraw it and prepare a fresh record.');
            }
            $p->state = 'Released';
            $p->released_at = now();
            $p->released_by = $actor->id;
            $p->version++;
            $p->save();
            self::event($actor, $p, null, 'record_released', $p->version);
        });
    }

    private static function participantView(User $u, Packet $p): bool
    {
        if (! $u->active || ! $u->email_verified_at || $p->state === 'Draft' || ! self::intact($p)) {
            return false;
        }
        $slot = Signer::where('packet_id', $p->id)->where('user_id', $u->id)->first();
        if (! $slot || $slot->identity_hash !== EmployerActions::identity($u)) {
            return false;
        }$a = Apprentice::findOrFail($p->apprentice_id);
        if ($slot->role === 'apprentice') {
            return $u->role === 'apprentice' && $a->user_id === $u->id && Apprentice::where('user_id', $u->id)->count() === 1;
        }
        if ($slot->role === 'mentor') {
            return StaffDuties::assigned($u, $a, 'mentor');
        }
        $authority = Authority::find($p->snapshot['binding']['authority_id']);

        return $u->role === 'employer' && $authority && self::authorityValid($authority, $a) && Grant::find($authority->employer_apprentice_grant_id)?->user_id === $u->id;
    }

    public static function visible(User $u, Packet $p): bool
    {
        return self::staff($u, Apprentice::findOrFail($p->apprentice_id)) || self::participantView($u, $p);
    }

    public static function queue(User $u)
    {
        abort_unless($u->active, 403);
        $ids = Apprentice::get()->filter(fn ($a) => self::staff($u, $a))->pluck('id');

        return Packet::where(fn ($q) => $q->whereIn('apprentice_id', $ids)->orWhereHas('signers', fn ($q) => $q->where('user_id', $u->id)));
    }

    public static function nextSignature(User $user): ?Packet
    {
        return Packet::where('state', 'Released')->whereDate('expires_on', '>=', today()->toDateString())
            ->whereHas('signers', fn ($q) => $q->where('user_id', $user->id)->where('state', 'Pending'))
            ->orderBy('expires_on')->orderBy('id')->cursor()
            ->first(fn (Packet $packet) => self::participantView($user, $packet) && self::current($packet));
    }

    public static function signerIntact(Signer $s, Packet $p): bool
    {
        try {
            $b = $p->snapshot['binding']['participants'][$s->role];
            if ($b['user_id'] !== $s->user_id || $b['name'] !== $s->expected_name || $b['identity_hash'] !== $s->identity_hash || $b['auth_version'] !== $s->auth_version) {
                return false;
            }
            if ($s->state === 'Pending') {
                return $s->attested_at === null && $s->attestation_hash === null && $s->attestation === null;
            }
            $a = $s->attestation;

            return in_array($s->state, ['Signed', 'Declined'], true) && $a['packet_id'] === $p->id && $a['snapshot_hash'] === $p->snapshot_hash && $a['role'] === $s->role && $a['user_id'] === $s->user_id && $a['name'] === $s->expected_name && $a['identity_hash'] === $s->identity_hash && $a['auth_version'] === $s->auth_version && $a['decision'] === $s->state && $a['at'] === $s->attested_at->toIso8601String() && $a['intent'] === ($s->state === 'Signed' ? self::INTENT : self::DECLINE) && hash_equals($s->attestation_hash, self::digest($a));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function respond(User $u, int $auth, Packet $packet, array $d): void
    {
        DB::transaction(function () use ($u, $auth, $packet, $d): void {
            $a = Apprentice::whereKey($packet->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            Review::whereKey($packet->progress_review_draft_id)->lockForUpdate()->firstOrFail();
            $p = Packet::whereKey($packet->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::participantView($actor, $p), 403);
            $slot = Signer::where('packet_id', $p->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            self::version($slot, (int) $d['version']);
            if ($p->state !== 'Released' || ! self::current($p, true) || $slot->state !== 'Pending' || ! self::signerIntact($slot, $p) || ! hash_equals($p->snapshot_hash, $d['document_hash'])) {
                self::fail('The record, participant authority or your signing slot changed or expired. Reopen the current request or contact GTA.');
            }
            if (! Hash::check($d['current_password'], $actor->password)) {
                throw ValidationException::withMessages(['current_password' => 'Confirm your current sign-in password.']);
            }
            if (self::name($d['typed_name']) !== self::name($slot->expected_name) || ! ($d['confirmed'] ?? false)) {
                self::fail('Type your own recorded participant name and explicitly confirm your decision.');
            }
            $decision = $d['decision'];
            if ($decision === 'Declined' && ! filled($d['reason'] ?? null)) {
                self::fail('Explain privately to GTA what needs changing before you can sign.');
            }
            $at = now()->startOfSecond();
            $attestation = ['packet_id' => $p->id, 'snapshot_hash' => $p->snapshot_hash, 'role' => $slot->role, 'user_id' => $actor->id, 'name' => $slot->expected_name, 'identity_hash' => $slot->identity_hash, 'auth_version' => $actor->auth_version, 'decision' => $decision, 'at' => $at->toIso8601String(), 'intent' => $decision === 'Signed' ? self::INTENT : self::DECLINE, 'private_reason' => $d['reason'] ?? null, 'authentication' => 'current authenticated account and password recheck'];
            $slot->state = $decision;
            $slot->attestation = $attestation;
            $slot->attestation_hash = self::digest($attestation);
            $slot->attested_at = $at;
            $slot->version++;
            $slot->save();
            if ($decision === 'Declined') {
                $p->state = 'Declined';
            } elseif (Signer::where('packet_id', $p->id)->where('state', 'Signed')->count() === 3) {
                $p->state = 'All signed';
            }
            $p->version++;
            $p->save();
            self::event($actor, $p, null, $decision === 'Signed' ? 'participant_signed' : 'participant_declined', $p->version);
        });
    }

    public static function completedIntact(Packet $p): bool
    {
        try {
            if (! self::intact($p) || ! $p->checked_at || ! $p->signed_review_record_id || ! in_array($p->state, ['Checked', 'Withdrawn'], true)) {
                return false;
            }$s = Signer::where('packet_id', $p->id)->orderBy('role')->get();
            if ($s->count() !== 3 || $s->pluck('role')->sort()->values()->all() !== ['apprentice', 'employer', 'mentor'] || $s->contains(fn ($x) => $x->state !== 'Signed' || ! self::signerIntact($x, $p))) {
                return false;
            }
            $c = $p->completion;

            return $c['packet_id'] === $p->id && $c['snapshot_hash'] === $p->snapshot_hash && $c['record_id'] === $p->signed_review_record_id && $c['checked_by'] === $p->checked_by && $c['checked_at'] === $p->checked_at->toIso8601String() && $c['signatures'] === $s->map(fn ($x) => ['id' => $x->id, 'hash' => $x->attestation_hash])->all() && hash_equals($p->completion_hash, self::digest($c));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function recordIntact(Packet $p, SignedReviewRecord $r): bool
    {
        try {
            if (! self::completedIntact($p) || $r->apprentice_id !== $p->apprentice_id || $r->progress_review_draft_id !== $p->progress_review_draft_id || $r->review_version !== $p->snapshot['binding']['review_version'] || $r->prepared_by !== $p->prepared_by || $r->checked_by !== $p->checked_by || $r->checked_at?->toIso8601String() !== $p->checked_at->toIso8601String()) {
                return false;
            }
            $hash = ReviewedImports::hash(['id' => $r->progress_review_draft_id, 'version' => $r->review_version, 'fields' => $r->snapshot['fields'], 'source_snapshot' => $r->snapshot['source_snapshot']]);
            if ($hash !== $p->snapshot['binding']['review_hash'] || ($r->snapshot['review_hash'] ?? null) !== $hash) {
                return false;
            }
            $expected = [];
            foreach (Signer::where('packet_id', $p->id)->orderBy('role')->get() as $s) {
                $expected[$s->role.'_name'] = $s->expected_name;
                $expected[$s->role.'_date'] = $s->attested_at->toDateString();
            }

return $expected === $r->snapshot['signatories'];
        } catch (\Throwable) {
            return false;
        }
    }

    public static function confirm(User $u, int $auth, Packet $packet, int $v, string $reference): SignedReviewRecord
    {
        $entry = null;
        $temporary = null;
        try {
            return DB::transaction(function () use ($u, $auth, $packet, $v, $reference, &$entry, &$temporary) {
                $a = Apprentice::whereKey($packet->apprentice_id)->lockForUpdate()->firstOrFail();
                $actor = self::actor($u, $auth);
                abort_unless(LearningAccess::manager($actor), 403);
                $r = Review::whereKey($packet->progress_review_draft_id)->lockForUpdate()->firstOrFail();
                $p = Packet::whereKey($packet->id)->lockForUpdate()->firstOrFail();
                self::version($p, $v);
                $signers = Signer::where('packet_id', $p->id)->orderBy('role')->lockForUpdate()->get();
                if ($p->state !== 'All signed' || $p->prepared_by === $actor->id || $signers->contains('user_id', $actor->id) || ! self::current($p, true) || $signers->count() !== 3 || $signers->contains(fn ($s) => $s->state !== 'Signed' || ! self::signerIntact($s, $p))) {
                    self::fail('A different administrator/CEO must check the current intact three-person signing record, source and authority before confirming.');
                }
                if (SignedReviewRecord::where('progress_review_draft_id', $r->id)->whereNull('withdrawn_at')->exists() || SignedReviewRecord::where('apprentice_id', $a->id)->whereDate('meeting_date', $r->fields['reviewDate'])->whereNotNull('checked_at')->whereNull('withdrawn_at')->exists()) {
                    self::fail('A current signed record already exists for this review or meeting date. Reconcile it before confirming.');
                }
                $checked = now()->startOfSecond();
                $pdf = PortalPdf::response('pdf.electronic-review', ['packet' => $p, 'document' => $p->snapshot['document'], 'signers' => $signers, 'checkedName' => $actor->name, 'checkedAt' => $checked->toIso8601String()], 'GTA-electronic-review-'.$p->id)->getContent();
                $temporary = tempnam(sys_get_temp_dir(), 'gta-review-');
                if ($temporary === false) {
                    throw new \RuntimeException('Private PDF temporary file unavailable.');
                }chmod($temporary, 0600);
                file_put_contents($temporary, $pdf);
                $entry = PrivateEvidence::store(new UploadedFile($temporary, 'GTA-electronic-review.pdf', 'application/pdf', null, true), 'electronic-review-records', $actor->id);
                $signatory = [];
                foreach ($signers as $s) {
                    $signatory[$s->role.'_name'] = $s->expected_name;
                    $signatory[$s->role.'_date'] = $s->attested_at->toDateString();
                }
                $snapshot = ['origin' => 'electronic', 'electronic_packet_id' => $p->id, 'packet_hash' => $p->snapshot_hash, 'review_id' => $r->id, 'review_version' => $r->version, 'review_hash' => SignedReviews::reviewHash($r), 'apprentice_id' => $a->id, 'reference' => $a->reference, 'apprentice_name' => $a->first_name.' '.$a->last_name, 'employer' => $a->employerLabel(), 'profile_binding' => ReviewedImports::hash($a->only('id', 'reference', 'first_name', 'last_name', 'programme_id', 'employer_id', 'employer', 'start_date', 'mentor_id', 'tutor_id')), 'fields' => $r->fields, 'source_snapshot' => $r->source_snapshot, 'signatories' => $signatory, 'source_reference' => 'Electronic participant record '.$p->id, 'identity_reference' => $reference, 'prepared_name' => User::findOrFail($p->prepared_by)->name, 'file_sha256' => $entry['sha256']];
                $record = SignedReviewRecord::create(['apprentice_id' => $a->id, 'progress_review_draft_id' => $r->id, 'review_version' => $r->version, 'meeting_date' => $r->fields['reviewDate'], 'snapshot' => $snapshot, 'snapshot_hash' => ReviewedImports::hash($snapshot), 'file' => $entry, 'prepared_by' => $p->prepared_by, 'checked_by' => $actor->id, 'checked_at' => $checked, 'history' => [['event' => 'electronic_signatures_confirmed', 'packet_id' => $p->id, 'by' => $actor->id, 'at' => $checked->toIso8601String(), 'reference' => $reference]]]);
                $p->state = 'Checked';
                $p->checked_at = $checked;
                $p->checked_by = $actor->id;
                $p->signed_review_record_id = $record->id;
                $p->completion = ['packet_id' => $p->id, 'snapshot_hash' => $p->snapshot_hash, 'record_id' => $record->id, 'file_sha256' => $entry['sha256'], 'checked_by' => $actor->id, 'checked_at' => $checked->toIso8601String(), 'signatures' => $signers->map(fn ($s) => ['id' => $s->id, 'hash' => $s->attestation_hash])->all()];
                $p->completion_hash = self::digest($p->completion);
                $p->version++;
                $p->save();
                DB::table('signed_review_events')->insert(['signed_review_record_id' => $record->id, 'actor_id' => $actor->id, 'event' => 'electronic_signatures_confirmed', 'created_at' => now()]);
                self::event($actor, $p, null, 'independently_checked', $p->version, $reference);

                return $record;
            });
        } catch (\Throwable $e) {
            if ($entry) {
                PrivateEvidence::delete($entry);
            }throw $e;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function withdraw(User $u, int $auth, Packet $packet, int $v, string $reason): void
    {
        DB::transaction(function () use ($u, $auth, $packet, $v, $reason): void {
            $a = Apprentice::whereKey($packet->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            abort_unless(self::staff($actor, $a), 403);
            Review::whereKey($packet->progress_review_draft_id)->lockForUpdate()->firstOrFail();
            $p = Packet::whereKey($packet->id)->lockForUpdate()->firstOrFail();
            self::version($p, $v);
            if ($p->state === 'Withdrawn') {
                self::fail('This packet is already withdrawn.');
            }if ($p->checked_at) {
                abort_unless(LearningAccess::manager($actor), 403);
            }
            if ($p->signed_review_record_id) {
                $record = SignedReviewRecord::whereKey($p->signed_review_record_id)->lockForUpdate()->firstOrFail();
                $record->withdrawn_at = now();
                $record->version++;
                $record->history = [...$record->history, ['event' => 'electronic_record_withdrawn', 'by' => $actor->id, 'reason' => $reason, 'at' => now()->toIso8601String()]];
                $record->save();
                DB::table('signed_review_events')->insert(['signed_review_record_id' => $record->id, 'actor_id' => $actor->id, 'event' => 'electronic_record_withdrawn', 'created_at' => now()]);
            }
            self::markWithdrawn($p, $actor, $reason);
        });
    }

    /** Called only inside the existing manager-authorised signed-record withdrawal transaction. */
    public static function withdrawFromRecord(SignedReviewRecord $record, User $actor, string $reason): void
    {
        $p = Packet::whereKey($record->snapshot['electronic_packet_id'] ?? null)->lockForUpdate()->firstOrFail();
        if ($p->signed_review_record_id !== $record->id || $p->apprentice_id !== $record->apprentice_id) {
            self::fail('Electronic record mapping needs checking.');
        }self::markWithdrawn($p,$actor,$reason);
    }

    private static function markWithdrawn(Packet $p,User $u,string $reason): void
    {
        $p->state = 'Withdrawn';
        $p->active_review_id = null;
        $p->withdrawn_at = now();
        $p->withdrawn_by = $u->id;
        $p->version++;
        $p->save();
        self::event($u,$p,null,'record_withdrawn',$p->version,$reason);
    }

    public static function event(User $u,?Packet $p,?Authority $authority,string $event,int $v,?string $note = null): void
    {
        DB::table('electronic_review_events')->insert(['packet_id' => $p?->id, 'authority_id' => $authority?->id, 'actor_id' => $u->id, 'event' => $event, 'record_version' => $v, 'private_note' => $note === null ? null : encrypt($note), 'created_at' => now()]);
    }
}
