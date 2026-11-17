<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class HelpCatalogue
{
    public static function all(): array
    {
        $rows = json_decode(file_get_contents(resource_path('data/help-guides.json')), true, 64, JSON_THROW_ON_ERROR);
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $row['id'] ?? '') || isset($result[$row['id']]) || ! is_array($row['audience'] ?? null) || ! is_string($row['category'] ?? null) || ! is_string($row['title'] ?? null) || ! is_string($row['body'] ?? null) || ! filled($row['body']) || mb_strlen($row['body']) > 4000 || ! is_array($row['links'] ?? null)) {
                throw new \RuntimeException('Help catalogue needs checking.');
            }
            foreach ($row['audience'] as $audience) {
                if (! in_array($audience, ['all', 'manager', 'learner', 'staff', 'tutor', 'mentor', 'topic', 'training', 'review-staff', 'signer', 'inclusion', 'careers', 'employer', 'ceo', 'planner'], true)) {
                    throw new \RuntimeException('Unknown help audience.');
                }
            }
            foreach ($row['links'] as $link) {
                if (! isset($link['label'],$link['route']) || ! Route::has($link['route'])) {
                    throw new \RuntimeException('Help destination needs checking.');
                }
            }
            $row['hash'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $row['transcript'] = $row['title'].'. '.$row['body'];
            $result[$row['id']] = $row;
        }

return $result;
    }

    public static function audiences(User $u): array
    {
        if (! $u->active) {
            return [];
        }$roles = $u->role;
        $manager = LearningAccess::manager($u);
        $mentor = StaffDuties::has($u, 'mentor');
        $permissions = ['planner'=>CentrePlanning::allowed($u),'all' => true, 'manager' => $manager, 'learner' => $roles === 'apprentice', 'tutor' => $roles === 'tutor', 'employer' => $roles === 'employer', 'ceo' => $roles === 'ceo', 'staff' => in_array($roles, [...DirectoryCatalog::STAFF_ROLES, 'admin', 'ceo'], true), 'mentor' => $mentor, 'training' => in_array($roles, ['admin', 'ceo', 'tutor', 'apprentice'], true), 'topic' => in_array($roles, ['admin', 'ceo', 'functional_skills_tutor'], true) || StaffDuties::has($u, 'maths_tutor') || StaffDuties::has($u, 'english_tutor'), 'review-staff' => ReviewReminders::allowed($u), 'signer' => in_array($roles, ['apprentice', 'employer'], true) || $mentor, 'inclusion' => SupportAccess::team($u), 'careers' => $manager || StaffDuties::has($u, 'careers_adviser')];

        return array_keys(array_filter($permissions));
    }

    public static function allowed(User $u, array $guide): bool
    {
        return (bool) array_intersect($guide['audience'], self::audiences($u));
    }

    public static function visible(User $u): array
    {
        $audiences = self::audiences($u);

        return array_filter(self::all(), fn ($guide) => (bool) array_intersect($guide['audience'], $audiences));
    }

    public static function context(User $u, Request $r): ?array
    {
        try {return self::contextGuide($u,$r);} catch(\Throwable) {return null;}
    }

    private static function contextGuide(User $u, Request $r): ?array
    {
        if ($r->routeIs('help.*', 'help-narrations.*')) {
            return null;
        }
        $id = match (true) {
            $r->routeIs('retention.*','destinations.*') && LearningAccess::manager($u) => 'retention-recruitment',
            $r->routeIs('dashboard.index','attention.index') => $u->role==='apprentice'?'submit-work':'marking',
            $r->routeIs('gateway.*') => 'gateway-preparation',
            $r->routeIs('journey.*') => 'journey-progress',
            $r->routeIs('employer-accounts.*','employer-setup.*','employer-grants.*') => 'employer-setup',
            $r->routeIs('apprentice-onboarding.*') => 'add-apprentice',
            $r->routeIs('onboarding.*') => 'setup',
            $r->routeIs('apprentices.*') => $u->role==='apprentice'?'apprentice-home':(LearningAccess::manager($u)?'add-apprentice':'marking'),
            $r->routeIs('marking.*') => 'marking',
            $r->routeIs('directories.*')&&$r->route('kind')==='employers' => 'employer-setup',
            $r->routeIs('annual.*' ,'optimiser.*') => 'timetable-year',
            $r->routeIs('centre-planning.form','centre-planning.save','centre-planning.edit','centre-planning.update','centre-planning.show') => 'timetable-add',
            $r->routeIs('centre-planning.*','timetable-duties.*','timetable-management.*','timetable-governance.*','work-moves.*','occurrence-register.*') => 'timetable-move',
            $r->routeIs('calendar.*','visual-reports.*','quick-actions.*','saved-work.*') => 'quick-actions',
            $r->routeIs('record-checks.*') => 'record-checks',
            $r->routeIs('electronic-reviews.*', 'review-authority.*') => 'review-signing',
            $r->routeIs('review-workspace.*','reviews.*', 'review-briefs.*') => 'review-preparation',
            $r->routeIs('portal-questionnaires.*') => $u->role === 'apprentice' ? 'native-questionnaires' : 'questionnaire-management',
            $r->routeIs('questionnaires.*') => 'external-questionnaires',$r->routeIs('alerts.*') => 'alerts',$r->routeIs('apprentice-notifications.*') => 'reminder-management',
            $r->routeIs('tasks.*', 'learning.*', 'blocks.*') => $u->role === 'apprentice' ? 'submit-work' : 'marking',
            $r->routeIs('setup.*', 'directories.*', 'assignment-checks.*') => 'setup',$r->routeIs('employer-actions.*') => 'employer-actions',
            $r->routeIs('support.*', 'support-review-reminders.*') => 'support',$r->routeIs('em.*', 'topics.*', 'qualifications.*') => 'maths-english',
            $r->routeIs('experience.home') => 'apprentice-home',$r->routeIs('experience.*') => 'help-request',$r->routeIs('staff.*') => 'staff-development',default => null
        };
        $guide = $id ? (self::all()[$id] ?? null) : null;

        return $guide && self::allowed($u,$guide) ? $guide : null;
    }
}
