<?php

namespace App\Support;

use App\Models\User;

final class ReportDirectory
{
    public static function links(User $user): array
    {
        $links = [];
        if (in_array($user->role, ['admin', 'ceo', 'tutor', 'apprentice'], true)) {
            $links += ['visual-reports.index' => 'Visual reports', 'dashboard.report' => 'Operational report'];
        }
        if (LearningAccess::manager($user)) {
            $links += [
                'retention.report' => 'Retention and Early Leaver Report',
                'achievers-leavers.index' => 'Achievers and Early Leavers',
                'cohort-insights.index' => 'Apprentice progress and cohort insights',
                'historical-cohorts.index' => 'Historical cohort reports',
                'inspection.index' => 'Inspection evidence overview',
                'management.index' => 'Management packs',
            ];
        }
        if (SupportAccess::team($user)) {
            $links['support.report'] = 'Support and Retention reporting';
        }

        return $links;
    }
}
