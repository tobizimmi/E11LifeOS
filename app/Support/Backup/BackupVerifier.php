<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;
use ZipArchive;

/**
 * Restore test of a backup ZIP: the archive is intact, the SQL dump holds every table with the
 * rows the manifest promises, all files are there – and the dump really loads into an empty
 * database (a temporary SQLite file, or the scratch MySQL database of backup.verify_connection).
 */
class BackupVerifier
{
    /**
     * @return array{ok: bool, problems: list<string>, tables: int, rows: int, files: int, restored: bool}
     */
    public static function verify(string $zipPath): array
    {
        $problems = [];
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            return ['ok' => false, 'problems' => [__('The backup archive is damaged.')], 'tables' => 0, 'rows' => 0, 'files' => 0, 'restored' => false];
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $hasDump = $zip->locateName('database.sql') !== false;
        $files = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            if (str_starts_with((string) $zip->getNameIndex($index), 'files/')) {
                $files++;
            }
        }

        $zip->close();

        if (! $hasDump) {
            return ['ok' => false, 'problems' => [__('The database dump is missing in the backup.')], 'tables' => 0, 'rows' => 0, 'files' => $files, 'restored' => false];
        }

        $expected = is_array($manifest['tables'] ?? null) ? array_map('intval', $manifest['tables']) : null;
        $driver = (string) ($manifest['driver'] ?? '');

        if ($expected === null) {
            $problems[] = __('The backup has no manifest (created before version 1.32) – only the dump was checked.');
        }

        if (isset($manifest['files']) && (int) $manifest['files'] !== $files) {
            $problems[] = __(':found of :expected files are in the backup.', ['found' => $files, 'expected' => (int) $manifest['files']]);
        }

        $restore = self::restoreTarget($driver);
        $found = [];

        try {
            foreach (self::statements('zip://'.$zipPath.'#database.sql', $driver === 'sqlite') as $statement) {
                if (preg_match('/^CREATE TABLE\s+[`"]((?:[^`"]|``|"")+)[`"]/i', $statement, $match)) {
                    $found[str_replace(['``', '""'], ['`', '"'], $match[1])] ??= 0;
                } elseif (preg_match('/^INSERT INTO\s+[`"]((?:[^`"]|``|"")+)[`"]/i', $statement, $match)) {
                    $table = str_replace(['``', '""'], ['`', '"'], $match[1]);
                    $found[$table] = ($found[$table] ?? 0) + 1;
                }

                $restore?->exec($statement);
            }
        } catch (Throwable $exception) {
            $problems[] = __('The dump could not be loaded: :message', ['message' => mb_substr($exception->getMessage(), 0, 200)]);
            $restore = null;
        }

        foreach ($expected ?? [] as $table => $rows) {
            if (! array_key_exists($table, $found)) {
                $problems[] = __('Table :table is missing in the dump.', ['table' => $table]);
            } elseif ($found[$table] !== $rows) {
                $problems[] = __('Table :table: :found of :expected rows in the dump.', ['table' => $table, 'found' => $found[$table], 'expected' => $rows]);
            } elseif ($restore && (int) $restore->query('SELECT COUNT(*) FROM '.self::quote($table, $driver))->fetchColumn() !== $rows) {
                $problems[] = __('Table :table: rows missing after restoring.', ['table' => $table]);
            }
        }

        return [
            'ok' => $expected !== null && $problems === [],
            'problems' => $problems,
            'tables' => count($found),
            'rows' => array_sum($found),
            'files' => $files,
            'restored' => $restore !== null,
        ];
    }

    /**
     * An empty database to load the dump into: a temporary SQLite file for SQLite dumps, the
     * configured scratch connection for MySQL dumps (never the live database), otherwise none.
     */
    private static function restoreTarget(string $driver): ?PDO
    {
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $pdo;
        }

        $connection = (string) config('backup.verify_connection');

        if ($driver === '' || $connection === '' || $connection === config('database.default')) {
            return null;
        }

        $pdo = DB::connection($connection)->getPdo();

        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $driver ? $pdo : null;
    }

    /**
     * The statements of a dump, one by one (statements end with ";" at the end of a line outside
     * of quoted strings; MySQL escapes with backslashes, SQLite only doubles quotes).
     *
     * @return \Generator<int, string>
     */
    public static function statements(string $path, bool $sqlite): \Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        try {
            $buffer = '';
            $quoted = false;

            while (($line = fgets($handle)) !== false) {
                if (! $quoted && $buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                    continue;
                }

                $length = strlen($line);

                for ($position = 0; $position < $length; $position++) {
                    $char = $line[$position];

                    if ($quoted && $char === '\\' && ! $sqlite) {
                        $position++;
                    } elseif ($char === "'") {
                        $quoted = ! $quoted;
                    }
                }

                $buffer .= $line;

                if (! $quoted && str_ends_with(rtrim($line), ';')) {
                    yield rtrim(trim($buffer), ';');
                    $buffer = '';
                }
            }

            if (trim($buffer) !== '') {
                yield rtrim(trim($buffer), ';');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function quote(string $table, string $driver): string
    {
        return $driver === 'sqlite' ? '"'.str_replace('"', '""', $table).'"' : '`'.str_replace('`', '``', $table).'`';
    }
}
