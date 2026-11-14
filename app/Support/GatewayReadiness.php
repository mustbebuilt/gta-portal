<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\EmployerApprenticeGrant;
use App\Models\EmployerPortalAction;
use App\Models\ExtraTraining;
use App\Models\GatewayReadinessCheck;
use App\Models\GatewayReadinessPlan;
use App\Models\HistoricalOtjRecord;
use App\Models\LearningTask;
use App\Models\ProgrammeOutcome;
use App\Models\Qualification;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GatewayReadiness
{
    public const CATEGORIES = ['tasks' => 'Training tasks', 'qualifications' => 'Qualifications', 'evidence' => 'Evidence requirements', 'approvals' => 'Approvals / agreement', 'ksbs' => 'KSB coverage', 'otj' => 'OTJ requirements'];

    public static function staff(User $u, Apprentice $a): bool
    {
        return EmployerActions::staff($u, $a);
    }

    public static function allowed(User $u, Apprentice $a): bool
    {
        return self::staff($u, $a) || LearningAccess::learner($u, $a);
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['gateway' => $message]);
    }

    public static function basis(Apprentice $a): array
    {
        $p = $a->programmeRecord()->first();
        $e = $a->employerRecord()->first();

        $status = ProgrammeOutcome::where('apprentice_id', $a->id)->first()?->context ?? [];

        return ['programme_status' => array_intersect_key($status, array_flip(['status', 'sourceRef'])), 'apprentice_id' => $a->id, 'start_date' => $a->start_date?->toDateString(), 'programme' => $p?->only('id', 'standard_code', 'version', 'record_version', 'active'), 'employer' => $e?->only('id', 'record_version', 'active'), 'delivery_plan_hash' => DeliveryPlan::approved($a)['hash'] ?? null];
    }

    public static function hash(GatewayReadinessPlan $p): string
    {
        return ReviewedImports::hash(['apprentice_id' => $p->apprentice_id, 'cohort' => $p->cohort, 'source_reference' => $p->source_reference, 'basis' => $p->basis, 'criteria' => $p->criteria]);
    }

    public static function intact(GatewayReadinessPlan $p): bool
    {
        try {
            return self::hash($p) === $p->source_hash && array_keys(self::CATEGORIES) === array_values(array_intersect(array_keys(self::CATEGORIES), array_unique(array_column($p->criteria, 'category'))));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function prepare(User $actor, int $authVersion, Apprentice $profile, array $data): GatewayReadinessPlan
    {
        return DB::transaction(function () use ($actor, $authVersion, $profile, $data) {
            $a = Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $u = EmployerActions::actor($actor, $authVersion, $a);
            $basis = self::basis($a);
            if (! $basis['programme'] || ! $basis['programme']['active'] || ! $basis['start_date'] || $basis['start_date'] > today()->toDateString()) {
                self::fail('Link the exact active programme/version and recorded start date before preparing criteria.');
            }
            $criteria = [];
            foreach ($data['criteria'] as $item) {
                if (! filled($item['label'] ?? null)) {
                    continue;
                }$kind = $item['kind'];
                $category = $item['category'];
                if (! array_key_exists($category, self::CATEGORIES) || ! in_array($kind, ['manual', 'taskset', 'qualification'], true) || ($kind === 'taskset' && $category !== 'tasks') || ($kind === 'qualification' && $category !== 'qualifications')) {
                    self::fail('Match each criterion category to its source type.');
                }
                $id = $kind === 'qualification' ? (int) ($item['qualification_id'] ?? 0) : null;
                if ($kind === 'qualification' && ! Qualification::whereKey($id)->where('apprentice_id', $a->id)->exists()) {
                    self::fail('Choose a qualification belonging to this apprentice.');
                }
                $criteria[] = ['key' => 'c'.(count($criteria) + 1), 'category' => $category, 'kind' => $kind, 'record_id' => $id, 'label' => $item['label']];
            }
            if (count($criteria) < 6 || count($criteria) > 12 || array_diff(array_keys(self::CATEGORIES), array_column($criteria, 'category'))) {
                self::fail('Define 6–12 sourced criteria, covering all six categories. An inapplicable category needs an explicit sourced manual check.');
            }
            $p = new GatewayReadinessPlan(['apprentice_id' => $a->id, 'created_by' => $u->id, 'cohort' => $data['cohort'], 'source_reference' => $data['source_reference'], 'basis' => $basis, 'criteria' => $criteria, 'state' => 'Draft', 'version' => 1, 'history' => [['event' => 'prepared', 'by' => $u->id, 'at' => now()->toIso8601String()]]]);
            $p->source_hash = self::hash($p);
            $p->save();
            EmployerActions::audit($u, $a, 'gateway_criteria_prepared');

            return $p;
        });
    }

    public static function change(User $actor, int $authVersion, GatewayReadinessPlan $plan, array $data): void
    {
        DB::transaction(function () use ($actor, $authVersion, $plan, $data): void {
            $a = Apprentice::whereKey($plan->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = EmployerActions::actor($actor, $authVersion, $a, $data['state'] === 'Approved');
            $p = GatewayReadinessPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($p->version !== (int) $data['version'] || ! in_array($p->state, ['Draft', 'Approved'], true)) {
                self::fail('Criteria changed or were withdrawn. Reload.');
            }
            if ($data['state'] === 'Approved') {
                if (($p->basis['programme_status']['status'] ?? null) !== 'Active' || ! filled($p->basis['programme_status']['sourceRef'] ?? null) || $p->state !== 'Draft' || $p->created_by === $u->id || ! self::intact($p) || ReviewedImports::hash(self::basis($a)) !== ReviewedImports::hash($p->basis)) {
                    self::fail('A different Admin/CEO must check a current intact draft against the actual programme/cohort source.');
                }
                foreach (GatewayReadinessPlan::where('apprentice_id', $a->id)->where('state', 'Approved')->lockForUpdate()->get() as $old) {
                    $old->state = 'Withdrawn';
                    $old->version++;
                    $old->history = [...$old->history, ['event' => 'superseded', 'by' => $u->id, 'at' => now()->toIso8601String(), 'new_plan' => $p->id]];
                    $old->save();
                }
                $p->approved_by = $u->id;
                $p->approved_at = now();
            }
            $p->state = $data['state'];
            $p->version++;
            $p->history = [...$p->history, ['event' => $p->state, 'by' => $u->id, 'at' => now()->toIso8601String(), 'note' => $data['note']]];
            $p->save();
            EmployerActions::audit($u, $a, 'gateway_criteria_changed');
        });
    }

    public static function binding(Apprentice $a, GatewayReadinessPlan $p): string
    {
        $records = [];
        foreach ([LearningTask::class, Qualification::class, ExtraTraining::class, HistoricalOtjRecord::class, AttendanceMark::class, EmployerApprenticeGrant::class, EmployerPortalAction::class] as $class) {
            $records[$class] = $class::where('apprentice_id', $a->id)->orderBy('id')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get()->map(fn ($x) => ['id' => $x->id, 'hash' => ReviewedImports::hash($x->getAttributes())])->all();
        }
        $records['sessions'] = TrainingSession::where(fn ($q) => $q->whereJsonContains('members', $a->id)->orWhereJsonContains('members', (string) $a->id))->orderBy('id')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get()->map(fn ($x) => ['id' => $x->id, 'hash' => ReviewedImports::hash($x->getAttributes())])->all();
        $records['valid_grants'] = EmployerApprenticeGrant::where('apprentice_id', $a->id)->orderBy('id')->get()->map(fn ($g) => ['id' => $g->id, 'valid' => EmployerActions::valid($g, $a)])->all();

        return ReviewedImports::hash(['plan' => $p->id, 'criteria_hash' => $p->source_hash, 'basis' => self::basis($a), 'records' => $records]);
    }

    public static function taskset(Apprentice $a): array
    {
        $key = TaskCatalogue::allocationKey($a);
        if (! $key) {
            return ['state' => 'Unknown', 'detail' => 'Programme task source not linked; check applicability.'];
        }$expected = array_column(TaskCatalogue::tasks($key), 'id');
        $tasks = LearningTask::where('apprentice_id', $a->id)->get();
        if (count($expected) !== 50 || $tasks->count() !== 50 || array_diff($expected, $tasks->pluck('reference')->all()) || ! $tasks->every(fn ($t) => TaskCatalogue::savedKey($t) === $key && ($t->template['kind'] ?? 'source-pending') !== 'source-pending')) {
            return ['state' => 'Unknown', 'detail' => 'The exact 50 source tasks are not all allocated/mapped.'];
        }
        $approved = $tasks->where('status', 'Approved')->count();

        return ['state' => $approved === 50 ? 'Met' : 'Missing', 'detail' => $approved.' of 50 allocated source tasks approved. KSB/OTJ and source applicability checks remain separate.'];
    }

    public static function position(Apprentice $a): array
    {
        $plans = GatewayReadinessPlan::where('apprentice_id', $a->id)->where('state', 'Approved')->get();
        $p = $plans->count() === 1 ? $plans->first() : null;
        if (! $p || ! self::intact($p) || ReviewedImports::hash(self::basis($a)) !== ReviewedImports::hash($p->basis)) {
            return ['plan' => $p, 'current' => false, 'rows' => [], 'met' => 0, 'total' => 0, 'all_met' => false, 'status' => 'Criteria need checking/approval — Gateway readiness not assessed'];
        }
        $binding = self::binding($a, $p);
        $checks = GatewayReadinessCheck::where('gateway_readiness_plan_id', $p->id)->get()->keyBy('criterion_key');
        $rows = [];
        foreach ($p->criteria as $item) {
            $state = 'Unknown';
            $detail = 'Staff evidence check not yet recorded.';
            $ref = null;
            $note = null;
            $check = $checks->get($item['key']);
            if ($item['kind'] === 'taskset') {
                $taskset = self::taskset($a);
                $state = $taskset['state'];
                $detail = $taskset['detail'];
            } elseif ($item['kind'] === 'qualification') {
                $q = Qualification::whereKey($item['record_id'])->where('apprentice_id', $a->id)->first();
                $state = $q && $q->achieved() && $q->achievement_date && ! $q->achievement_date->gt(today()) ? 'Met' : ($q ? 'Missing' : 'Unknown');
                $detail = $q ? $q->title.' — '.$q->status.'; evidence/required exemption criteria are separate checks.' : 'Qualification source missing.';
            } elseif ($check) {
                if (hash_equals($binding, $check->binding_hash) && in_array($check->state, ['Met', 'Missing', 'Unknown'], true) && ! $check->checked_on->gt(today()) && filled($check->evidence_reference)) {
                    $state = $check->state;
                    $detail = 'Staff check recorded '.$check->checked_on->toDateString();
                    $ref = $check->evidence_reference;
                    $note = $check->note;
                } else {
                    $detail = 'Source records changed. This manual check needs repeating.';
                }
            }
            $url = match ($item['category']) {
                'qualifications' => route('em.index', $a),'otj' => route('otj.show', $a),'approvals' => route('gateway.show', $a).'#gateway-checks',default => route('learning.index', $a)
            };
            $rows[] = ['criterion' => $item, 'state' => $state, 'detail' => $detail, 'evidence_reference' => $ref, 'note' => $note, 'check' => $check, 'url' => $url];
        }
        $met = count(array_filter($rows, fn ($x) => $x['state'] === 'Met'));
        $all = $rows && $met === count($rows);

        return ['plan' => $p, 'current' => true, 'rows' => $rows, 'met' => $met, 'total' => count($rows), 'all_met' => (bool) $all, 'status' => $all ? 'All configured checks met — provider Gateway review still required' : 'Items remain for Gateway preparation'];
    }

    public static function check(User $actor, int $authVersion, GatewayReadinessPlan $plan, array $data): void
    {
        DB::transaction(function () use ($actor, $authVersion, $plan, $data): void {
            $a = Apprentice::whereKey($plan->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = EmployerActions::actor($actor, $authVersion, $a);
            $p = GatewayReadinessPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $position = self::position($a);
            $criterion = collect($p->criteria)->firstWhere('key', $data['criterion_key']);
            if (! $position['current'] || $position['plan']->id !== $p->id || $p->version !== (int) $data['plan_version'] || ! $criterion || $criterion['kind'] !== 'manual') {
                self::fail('Use a manual criterion in the current approved plan.');
            }
            $row = GatewayReadinessCheck::where('gateway_readiness_plan_id', $p->id)->where('criterion_key', $data['criterion_key'])->lockForUpdate()->first();
            if (($row?->version ?? 0) !== (int) $data['version']) {
                self::fail('This check changed; reload before recording.');
            }
            $row ??= new GatewayReadinessCheck(['gateway_readiness_plan_id' => $p->id, 'criterion_key' => $data['criterion_key']]);
            $before = $row->exists ? $row->only('state', 'evidence_reference', 'note', 'checked_on', 'binding_hash') : null;
            $row->fill(collect($data)->only('state', 'evidence_reference', 'note', 'checked_on')->all());
            $row->checked_by = $u->id;
            $row->binding_hash = self::binding($a, $p);
            $row->version = ($row->exists ? $row->version : 0) + 1;
            $row->history = [...($row->history ?? []), ['before' => $before, 'by' => $u->id, 'at' => now()->toIso8601String()]];
            $row->save();
            EmployerActions::audit($u, $a, 'gateway_check_recorded');
        });
    }
}
