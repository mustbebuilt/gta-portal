<?php

use Illuminate\Support\Facades\{Artisan, DB, Schema};

Artisan::command('gta:pack82-check', function () {
    $required = ['ST0499:1.3', 'ST0068:1.5', 'ST0403:1.3', 'ST0448:1.2'];
    $programmes = [];
    foreach ($required as $key) {
        [$code, $version] = explode(':', $key);
        $programme = \App\Models\Programme::where('standard_code', $code)->where('version', $version)->where('active', true)->first();
        $template = $programme ? \App\Models\ProgrammeSetupTemplate68::where('programme_id', $programme->id)->where('active', true)->first() : null;
        $valid = false;
        if ($template) {
            try {
                \App\Support\Rpl68::validateTemplate($template->template);
                $valid = \App\Support\Rpl68::templateMatches($template)
                    && ($template->template['standard_code'] ?? null) === $code
                    && ltrim($template->template['standard_version'] ?? '', 'v') === $version
                    && filled($template->template['source_approval_ref'] ?? null);
            } catch (\Throwable $error) { $valid = false; }
        }
        $programmes[$key] = ['active_programme' => (bool) $programme, 'approved_matching_template' => $valid,
            'date_rules_enabled' => isset(config('gta_rpl_dates', [])[$key])];
    }
    $tables = [];
    foreach (['weekly_retention_checks','weekly_retention_reminders'] as $table) $tables[$table] = Schema::hasTable($table);
    $ready = !in_array(false, $tables, true) && !collect($programmes)->contains(fn ($row) => in_array(false, $row, true));
    $this->line(json_encode(['pack' => '82 revision 3', 'environment' => app()->environment(),
        'programmes' => $programmes, 'tables' => $tables,
        'weekly_retention_schedule_enabled' => (bool) config('weekly_retention.scheduled'),
        'configuration_ready' => $ready,
        'live_verification' => 'Not established by this command. Record Cloud build, schedule execution, phone, storage and controlled mail checks separately.',
        'writes_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $ready ? 0 : 1;
})->purpose('Read-only Pack 82 configuration checks; outputs no apprentice records or credentials');
