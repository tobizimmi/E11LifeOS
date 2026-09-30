<?php

/**
 * Merges the values of an incoming .env (from the GitHub secret ENV_FILE_LIVE / ENV_FILE_STAGING)
 * into the server's .env. Runs on the server during "Server einrichten" (see server-setup.yml).
 *
 * - Missing keys are added, existing ones are kept unless "overwrite" is passed.
 * - APP_KEY is never replaced once set: a new key would make encrypted data unreadable.
 * - The previous .env is kept as .env.backup-<date>; only key names are printed, never values.
 *
 * Usage: php merge-env.php <target .env> <incoming file> <overwrite: true|false>
 */
[, $targetPath, $incomingPath, $overwriteFlag] = array_pad($argv, 4, null);

if ($targetPath === null || $incomingPath === null || ! is_file($incomingPath)) {
    fwrite(STDERR, "Usage: php merge-env.php <target .env> <incoming file> <overwrite>\n");
    exit(2);
}

$overwrite = $overwriteFlag === 'true';

/**
 * @return array{0: string|null, 1: string}
 */
function parseLine(string $line): array
{
    if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $match) !== 1) {
        return [null, ''];
    }

    return [$match[1], trim(trim($match[2]), '"\'')];
}

$incomingLines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($incomingPath));
$targetExists = is_file($targetPath);
$targetLines = $targetExists ? preg_split('/\r\n|\n|\r/', rtrim((string) file_get_contents($targetPath), "\r\n")) : [];

$positions = [];
foreach ($targetLines as $index => $line) {
    [$key] = parseLine($line);
    if ($key !== null) {
        $positions[$key] = $index;
    }
}

$added = [];
$updated = [];
$kept = [];
$warnings = [];

foreach ($incomingLines as $line) {
    [$key, $value] = parseLine($line);

    if ($key === null) {
        continue;
    }

    if (! array_key_exists($key, $positions)) {
        $targetLines[] = $line;
        $positions[$key] = count($targetLines) - 1;
        $added[] = $key;

        continue;
    }

    [, $currentValue] = parseLine($targetLines[$positions[$key]]);

    if ($currentValue === $value) {
        continue;
    }

    if ($key === 'APP_KEY' && $currentValue !== '') {
        $warnings[] = 'APP_KEY on the server differs from the secret – the server value was kept (it must never change).';

        continue;
    }

    if ($overwrite || $currentValue === '') {
        $targetLines[$positions[$key]] = $line;
        $updated[] = $key;
    } else {
        $kept[] = $key;
    }
}

$appKeyLine = array_key_exists('APP_KEY', $positions) ? parseLine($targetLines[$positions['APP_KEY']])[1] : '';

if ($appKeyLine === '') {
    fwrite(STDERR, "ERROR: APP_KEY is empty. Add APP_KEY=base64:... to the secret (see docs/live-einrichten.md).\n");
    exit(1);
}

if ($added !== [] || $updated !== []) {
    if ($targetExists) {
        $backupPath = $targetPath.'.backup-'.date('Ymd-His');
        for ($suffix = 2; file_exists($backupPath); $suffix++) {
            $backupPath = $targetPath.'.backup-'.date('Ymd-His').'-'.$suffix;
        }
        copy($targetPath, $backupPath);
        chmod($backupPath, 0600);
        echo 'Previous .env saved as '.basename($backupPath)."\n";
    }

    file_put_contents($targetPath, implode("\n", $targetLines)."\n");
}

if (is_file($targetPath)) {
    chmod($targetPath, 0600);
}

echo $targetExists ? ".env updated.\n" : ".env created.\n";
echo 'Added: '.($added === [] ? '–' : implode(', ', $added))."\n";
echo 'Updated: '.($updated === [] ? '–' : implode(', ', $updated))."\n";

if ($kept !== []) {
    echo 'Kept (differs, run again with "overwrite" to replace): '.implode(', ', $kept)."\n";
}

foreach ($warnings as $warning) {
    echo "WARNING: {$warning}\n";
}
