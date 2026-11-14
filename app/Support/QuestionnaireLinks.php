<?php

namespace App\Support;

use App\Http\Controllers\SurveyReceiptController;
use App\Models\Apprentice;
use App\Models\User;

/** Shared responder links only. Never fetches, personalises, or tracks a response. */
class QuestionnaireLinks
{
    public static function canView(User $user): bool
    {
        return $user->active && (($user->role === 'apprentice'
            && Apprentice::where('user_id', $user->id)->count() === 1) || SupportAccess::team($user));
    }

    public static function inspect(): array
    {
        $rows = [];
        foreach (SurveyReceiptController::TYPES as $kind => $title) {
            $url = config("questionnaires.links.$kind.url");
            $hash = is_string($url) && $url !== '' ? hash('sha256', $url) : null;
            $host = null;
            $reason = self::problem($url, $host);
            $review = config("questionnaires.links.$kind.reviewed_sha256");
            if ($reason === null && (! is_string($review) || ! preg_match('/^[a-f0-9]{64}$/D', $review)
                || ! hash_equals($hash, $review))) {
                $reason = 'Responder link needs review';
            }
            if ($reason === null && config('questionnaires.enabled') !== true) {
                $reason = 'Questionnaire links are switched off';
            }
            $rows[$kind] = ['kind' => $kind, 'title' => $title, 'ready' => $reason === null,
                'status' => $reason ?? 'Ready to open', 'host' => $host, 'candidate_sha256' => $hash];
        }

        return ['links' => $rows, 'all_ready' => collect($rows)->every(fn ($row) => $row['ready']),
            'network_requests_performed' => false, 'writes_performed' => false,
            'external_form_verified' => false];
    }

    public static function cards(): array
    {
        $rows = self::inspect()['links'];
        foreach ($rows as $kind => &$row) {
            $row['url'] = $row['ready'] ? config("questionnaires.links.$kind.url") : null;
            unset($row['candidate_sha256']);
        }

        return $rows;
    }

    private static function problem(mixed $url, ?string &$host): ?string
    {
        if ($url === null || $url === '') {
            return 'Responder link not configured';
        }
        // Reject ambiguous encodings and control characters before parsing.
        if (! is_string($url) || strlen($url) > 2048 || preg_match('/[^\x21-\x7E]|[\\\\]|%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)) {
            return 'Responder link format needs correction';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment']) || ! isset($parts['host'])) {
            return 'Use a direct HTTPS responder link';
        }
        $candidate = strtolower($parts['host']);
        $hosts = config('questionnaires.allowed_hosts');
        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $candidate)
            || str_ends_with($candidate, '.localhost') || ! is_array($hosts) || ! in_array($candidate, $hosts, true)) {
            return 'Responder host is not approved';
        }
        // A URL check cannot prove that a form is public or intended for respondents.
        // Block recognisable editor destinations; require a manual review for every exact URL.
        $decoded = rawurldecode(rawurldecode(($parts['path'] ?? '').'?'.($parts['query'] ?? '')));
        if (preg_match('~(?:^|[/&?=])(edit|design(?:page(?:v2)?)?|admin|settings|responses|export)(?:[./&?=]|$)|(?:^|[?&])(?:prefill[^=]*|entry\.[^=]*|email|user_id|apprentice_id|learner_id)=~i', $decoded)) {
            return 'Use a shared responder link without editing or prefilled personal details';
        }
        $host = $candidate;

        return null;
    }
}
