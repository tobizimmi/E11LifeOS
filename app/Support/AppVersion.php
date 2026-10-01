<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The version of the app: the latest entry of CHANGELOG.md plus the build the deploy wrote into
 * build.json (a running number, so every deploy of staging and live counts up).
 */
class AppVersion
{
    public static function version(): string
    {
        return self::releases()[0]['version'] ?? '0.0.0';
    }

    /**
     * @return array{build: ?int, commit: ?string, environment: ?string, date: ?string}
     */
    public static function build(): array
    {
        $file = base_path('build.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return [
            'build' => isset($data['build']) && is_numeric($data['build']) ? (int) $data['build'] : null,
            'commit' => isset($data['commit']) ? mb_substr((string) $data['commit'], 0, 12) : null,
            'environment' => isset($data['environment']) ? (string) $data['environment'] : null,
            'date' => isset($data['date']) ? (string) $data['date'] : null,
        ];
    }

    /**
     * "1.13.0 · Build 512 · Staging" (without build outside a deploy).
     */
    public static function label(): string
    {
        $build = self::build();

        return implode(' · ', array_filter([
            'v'.self::version(),
            $build['build'] !== null ? __('Build :number', ['number' => $build['build']]) : null,
            $build['environment'] === 'staging' ? 'Staging' : null,
        ]));
    }

    /**
     * The releases of CHANGELOG.md, newest first.
     *
     * @return list<array{version: string, date: ?string, sections: list<array{title: string, items: list<string>}>}>
     */
    public static function releases(): array
    {
        $file = base_path('CHANGELOG.md');

        if (! is_file($file)) {
            return [];
        }

        return Cache::remember('changelog:'.md5_file($file), now()->addDay(), fn () => self::parse((string) file_get_contents($file)));
    }

    /**
     * @return list<array{version: string, date: ?string, sections: list<array{title: string, items: list<string>}>}>
     */
    public static function parse(string $markdown): array
    {
        $releases = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^##\s+\[?(\d+\.\d+\.\d+)\]?\s*(?:[–-]\s*(.+))?$/u', $line, $match)) {
                $releases[] = ['version' => $match[1], 'date' => isset($match[2]) ? trim($match[2]) : null, 'sections' => []];
            } elseif ($releases !== [] && preg_match('/^###\s+(.+)$/u', $line, $match)) {
                $releases[count($releases) - 1]['sections'][] = ['title' => trim($match[1]), 'items' => []];
            } elseif ($releases !== [] && preg_match('/^\s*[-*]\s+(.+)$/u', $line, $match)) {
                $last = count($releases) - 1;

                if ($releases[$last]['sections'] === []) {
                    $releases[$last]['sections'][] = ['title' => '', 'items' => []];
                }

                $releases[$last]['sections'][count($releases[$last]['sections']) - 1]['items'][] = trim($match[1]);
            }
        }

        return $releases;
    }
}
