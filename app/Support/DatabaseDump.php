<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Plain-SQL dump of the whole database (MySQL/MariaDB or SQLite) without external tools
 * such as mysqldump: table definitions plus INSERT statements, written in chunks.
 */
class DatabaseDump
{
    private const CHUNK = 500;

    public static function toFile(string $path, ?string $connectionName = null): void
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        try {
            fwrite($handle, '-- '.config('app.name')." backup, {$driver}, ".now()->toIso8601String()."\n");
            fwrite($handle, $driver === 'sqlite' ? "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n" : "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");

            foreach (self::tables($connection) as $table => $createStatement) {
                $quoted = self::quoteIdentifier($table, $driver);
                fwrite($handle, "\nDROP TABLE IF EXISTS {$quoted};\n{$createStatement};\n");

                $connection->table($table)->orderByRaw('1')->chunk(self::CHUNK, function ($rows) use ($handle, $connection, $quoted, $driver) {
                    foreach ($rows as $row) {
                        $row = (array) $row;
                        $columns = implode(', ', array_map(fn ($column) => self::quoteIdentifier($column, $driver), array_keys($row)));
                        $values = implode(', ', array_map(fn ($value) => $value === null ? 'NULL' : (is_int($value) || is_float($value) ? $value : $connection->getPdo()->quote((string) $value)), $row));
                        fwrite($handle, "INSERT INTO {$quoted} ({$columns}) VALUES ({$values});\n");
                    }
                });
            }

            fwrite($handle, $driver === 'sqlite' ? "\nCOMMIT;\n" : "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, string> Create statement by table name.
     */
    private static function tables(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
                ->mapWithKeys(fn ($table) => [$table->name => $table->sql])
                ->all();
        }

        return collect($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->mapWithKeys(fn (string $table) => [$table => array_values((array) $connection->selectOne('SHOW CREATE TABLE '.self::quoteIdentifier($table, 'mysql')))[1]])
            ->all();
    }

    private static function quoteIdentifier(string $name, string $driver): string
    {
        return $driver === 'sqlite' ? '"'.str_replace('"', '""', $name).'"' : '`'.str_replace('`', '``', $name).'`';
    }
}
