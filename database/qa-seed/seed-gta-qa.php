<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['portal:', 'batch:', 'week-of:', 'actor:', 'inbox:', 'credentials:', 'apply', 'verified-test-aliases', 'help'], $end);
if ($options === false || $end !== count($argv) || isset($options['help'])) {
    fwrite(STDOUT, "Usage: php seed-gta-qa.php --inbox=ADDRESS [--portal=/path/to/portal] [--batch=GTAQA-250] [--week-of=YYYY-MM-DD] [--actor=ID --credentials=/private/new.csv --apply] [--verified-test-aliases]\n");
    exit(isset($options['help']) ? 0 : 1);
}
foreach (['actor', 'inbox', 'credentials', 'batch', 'week-of', 'portal'] as $name) {
    if (isset($options[$name]) && (!is_string($options[$name]) || $options[$name] === '')) {
        fwrite(STDERR, "Supply exactly one value for --$name.\n");
        exit(1);
    }
}
if (isset($options['actor']) && !filter_var($options['actor'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    fwrite(STDERR, "Supply a positive numeric ID for --actor.\n");
    exit(1);
}

$createdPath = null;
try {
    $root = realpath($options['portal'] ?? getcwd());
    if (!$root || !is_file($root.'/artisan') || !is_file($root.'/vendor/autoload.php')) {
        throw new InvalidArgumentException('Run from the Laravel application root or pass --portal with its absolute path.');
    }
    chdir($root);
    require $root.'/vendor/autoload.php';
    require __DIR__.'/GtaQaFixtures.php';
    require __DIR__.'/GtaQaProgress.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $date = $options['week-of'] ?? null;
    $week = $date ? Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/London')
        : Carbon\CarbonImmutable::now('Europe/London')->startOfWeek(Carbon\CarbonImmutable::MONDAY);
    if (!$week || ($date !== null && $week->toDateString() !== $date)) {
        throw new InvalidArgumentException('Enter the week-of date as YYYY-MM-DD.');
    }

    $apply = array_key_exists('apply', $options);
    $writer = null;
    if ($apply && isset($options['credentials'])) {
        $chosen = $options['credentials'];
        $directory = str_starts_with($chosen, '/') ? realpath(dirname($chosen)) : false;
        $public = realpath($root.'/public');
        if ($directory === false || ($public !== false && ($directory === $public || str_starts_with($directory, $public.'/')))) {
            throw new InvalidArgumentException('Use an absolute credentials path in an existing directory outside public.');
        }
        $path = $directory.'/'.basename($chosen);
        $writer = static function (array $credentials) use ($path, &$createdPath): void {
            $oldMask = umask(0077);
            try {
                $handle = @fopen($path, 'x');
            } finally {
                umask($oldMask);
            }
            if (!$handle) {
                throw new InvalidArgumentException('Credentials file already exists or is not writable. The batch was rolled back.');
            }
            $createdPath = $path;
            try {
                if (!chmod($path, 0600)) {
                    throw new RuntimeException('Could not restrict access to the credentials file.');
                }
                foreach ([['Role', 'Fictional name', 'Sign-in email', 'Password'], ...$credentials] as $row) {
                    if (fputcsv($handle, $row, ',', '"', '') === false) {
                        throw new RuntimeException('Could not write credentials file.');
                    }
                }
                if (!fflush($handle)) {
                    throw new RuntimeException('Could not finish writing credentials file.');
                }
            } finally {
                fclose($handle);
            }
        };
    }

    $result = GtaQaFixtures::run($options['batch'] ?? 'GTAQA-250', isset($options['actor']) ? (int) $options['actor'] : null,
        $options['inbox'] ?? '', $week, $apply, array_key_exists('verified-test-aliases', $options), $writer);
} catch (Throwable $exception) {
    if ($createdPath !== null) {
        @unlink($createdPath);
    }
    if ($exception instanceof InvalidArgumentException) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
    } else {
        fwrite(STDERR, 'Import stopped. Check application logs and the installed schema. Any in-progress batch was rolled back.'.PHP_EOL);
        if (function_exists('report') && isset($app)) {
            report($exception);
        }
    }
    exit(1);
}

fwrite(STDOUT, json_encode(['environment' => $app->environment()] + $result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
exit(in_array($result['status'], ['schema_missing', 'missing_programmes', 'some_references_already_present'], true) ? 1 : 0);
