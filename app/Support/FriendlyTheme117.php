<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/** Presentation of the existing authorised menu only; never grants a destination. */
class FriendlyTheme117
{
    public static function menu(User $user, Request $request): array
    {
        $source = PortalNavigation::build($user, $request);
        $sections = [['id' => 'other-pages', 'label' => 'Other pages', 'links' => $source['primary']], ...$source['groups']];
        $pool = collect($sections)->pluck('links')->collapse()->unique('href');
        $choices = match ($user->role) {
            'admin','ceo' => ['Needs attention', 'Apprentices', 'Apprentice register', 'Support and Retention Register', 'Employers', 'Reports'],
            'tutor' => ['Needs attention', 'Apprentices', 'Apprentice register', 'Work to mark', 'Reviews'],
            'apprentice' => ['My next step', 'My progress', 'My calendar', 'Review signing'],
            'employer' => ['My employer actions', 'View progress', 'Review appointments', 'Review signing'],
            'inclusion_lead','inclusion_member' => ['Support and Retention Register', 'Reports'],
            'mentor' => ['Needs attention', 'Reviews', 'View progress'],
            'functional_skills_tutor' => ['Maths and English', 'Work to mark'],
            'careers_adviser' => ['Careers', 'Leaver follow-up'],
            default => ['Centre oversight', 'Needs attention', 'Staff development', 'Maths and English', 'Careers'],
        };
        $byLabel = $pool->keyBy('label');
        $daily = collect($choices)->map(fn ($label) => $byLabel->get($label))->filter()->values();
        if ($daily->isEmpty()) {
            $daily = collect($source['primary'])->take(3);
        }
        $current = $pool->firstWhere('current', true) ?? (($source['help']['current'] ?? false) ? $source['help'] : null);
        $urls = $daily->pluck('href')->all();
        foreach ($sections as &$section) {
            $section['links'] = array_values(array_filter($section['links'], fn ($link) => ! in_array($link['href'], $urls, true)));
        }
        unset($section);

        return ['daily' => $daily, 'sections' => array_values(array_filter($sections, fn ($s) => $s['links'])),
            'currentUrl' => $current['href'] ?? null, 'currentTool' => $current && ! in_array($current['href'], $urls, true) ? $current['label'] : null, 'help' => $source['help']];
    }

    public static function label(string $label): string
    {
        return match ($label) {
            'Needs attention' => 'Home','Apprentice register' => 'Registers','Support and Retention Register' => 'Support',default => $label
        };
    }

    public static function icon(string $label): string
    {
        return match ($label) {
            'Needs attention','My next step' => 'home','Apprentices' => 'people','Apprentice register' => 'register',
            'Support and Retention Register' => 'support','Employers','My employer actions' => 'employer','Reports','My progress','View progress' => 'report',
            'My calendar','Review appointments' => 'calendar','Reviews','Review signing' => 'review',default => 'work'
        };
    }
}
