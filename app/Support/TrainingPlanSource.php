<?php

namespace App\Support;

use App\Models\TrainingPlanWorkspace;
use Illuminate\Validation\ValidationException;

class TrainingPlanSource
{
    public const BASES = ['Programme baseline', 'Individual RPL assessment', 'Manual planning proposal'];

    public const EVIDENCE = ['Supervised diagnostic test', 'Picture/component identification', 'Qualification/certificate', 'Employer confirmation + example', 'Job card/work product', 'Direct observation/practical check', 'Professional discussion + corroboration', 'Multiple sources'];

    public const OMISSIONS = ['No omission - full training retained', 'Knowledge only - practical competence not evidenced; full training retained', 'Partial omission - evidence reference identifies the specific content', 'Full omission - entire published KSB requirement'];

    public const REVIEW_REASONS = ['source' => 'Source / version needs checking', 'evidence' => 'Assessment evidence / reference needs checking', 'omission' => 'Training retained / omitted needs correcting', 'applicability' => 'Individual assessment scope needs checking', 'decision' => 'Earlier review decision needs correcting'];

    public static function load(string $name): array
    {
        return json_decode(file_get_contents(resource_path('data/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function hash(): string
    {
        return hash('sha256', file_get_contents(resource_path('data/training-plan-reference.json')).file_get_contents(resource_path('data/programme-baseline.json')));
    }

    public static function outcomes(): array
    {
        $v = ['zero' => '0% - Not evidenced; full training retained', 'exposure' => '0% - Experience/exposure only; full training retained'];
        foreach (range(10, 90, 10) as $n) {
            $v[(string) $n] = $n.'% - Evidence supports '.$n.'% omission';
        } $v['100'] = '100% - Entire KSB evidenced; omit full KSB unit';

        return $v;
    }

    public static function percent(array $v): int
    {
        $outcome = (string) ($v['outcome'] ?? '');
        $percent = in_array($outcome, ['zero', 'exposure'], true) ? 0 : (int) $outcome;
        if (! array_key_exists($outcome, self::outcomes()) || ! in_array($v['omission'] ?? '', self::OMISSIONS, true)) {
            throw ValidationException::withMessages(['outcome' => 'Choose an assessed outcome and a listed retained/omitted training statement. Blank is not zero.']);
        }
        if (filled($v['source'] ?? null) && ! in_array($v['source'], self::EVIDENCE, true)) {
            throw ValidationException::withMessages(['source' => 'Choose a listed evidence source.']);
        }
        if ($percent > 0) {
            if (! filled($v['source'] ?? null) || ! filled($v['evidence'] ?? null) || ! filled($v['content'] ?? null) || $v['omission'] !== self::OMISSIONS[$percent === 100 ? 3 : 2]) {
                throw ValidationException::withMessages(['omission' => 'A reduction needs its evidence source/reference, specific omitted content and matching partial or full omission.']);
            }
        } elseif (! in_array($v['omission'], array_slice(self::OMISSIONS, 0, 2), true) || filled($v['content'] ?? null)) {
            throw ValidationException::withMessages(['omission' => 'A zero outcome retains full training. Choose a retained-training statement and leave omitted content blank.']);
        }

        return $percent;
    }

    public static function position(?TrainingPlanWorkspace $row): array
    {
        $reference = self::load('training-plan-reference');
        $base = self::load('programme-baseline');
        $codes = array_column($reference['ksbs'], 'code');
        $records = $row?->rpl ?? [];
        $valid = [];
        $issues = [];
        $sourceOk = (! $row || $row->source_hash === self::hash()) && $base['referenceId'] === $reference['id'] && count(array_unique($codes)) === $base['expectedUniqueKsbs'] && count($base['blocks']) === 12 && abs(array_sum(array_column($base['blocks'], 'plannedEligibleHours')) - $base['plannedEligibleHours']) < 0.000001 && ($base['portalAmendment']['id'] ?? null) === ($reference['portalAmendment']['id'] ?? null);
        if (! $sourceOk) {
            $issues[] = 'Programme source/version needs reconciliation.';
        }
        foreach ($records as $code => $v) {
            try {
                if (! in_array($code, $codes, true) || ! isset($v['revision'],$v['by'],$v['at']) || ! is_int($v['revision']) || $v['revision'] < 1 || ! is_int($v['by']) || $v['by'] < 1 || ! is_string($v['at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $v['at']) || strtotime($v['at']) === false || strtotime($v['at']) > now()->timestamp) {
                    throw new \RuntimeException('Source code or assessment version needs checking.');
                } $valid[$code] = self::percent($v);
            } catch (\Throwable $e) {
                $issues[] = $code.' needs checking.';
            }
        }
        $complete = $sourceOk && ! $issues && count($valid) === count($codes);
        $sorted = $records;
        ksort($sorted);
        $binding = hash('sha256', json_encode([$row?->apprentice_id, $row?->source_hash, $sorted], JSON_THROW_ON_ERROR));
        $latest = collect($row?->reviews ?? [])->last();
        $current = $latest && $latest['binding'] === $binding;
        $approved = $current && $latest['status'] === 'Assessment approved' && $complete;
        $units = array_sum($valid) / 100;

        return ['recorded' => count($valid), 'total' => count($codes), 'complete' => $complete, 'source_ok' => $sourceOk, 'issues' => $issues, 'binding' => $binding, 'review' => $latest, 'current' => $current, 'approved' => $approved, 'recognised_hours' => $complete ? $base['plannedEligibleHours'] / count($codes) * $units : null, 'remaining_hours' => $complete ? $base['plannedEligibleHours'] * (1 - $units / count($codes)) : null, 'allocation_reduction' => $complete ? $base['variableAllocation'] / count($codes) * $units : null, 'remaining_allocation' => $complete ? $base['variableAllocation'] * (1 - $units / count($codes)) : null];
    }
}
