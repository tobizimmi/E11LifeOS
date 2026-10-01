<?php

namespace App\Support\Career;

use App\Models\CareerEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Career development of a member: the CV profile, the timeline of jobs, education, trainings and
 * milestones, hints (expiring certificates, end of the probation period) and job suggestions from
 * the job exchange of the Federal Employment Agency plus search links to the large job portals.
 */
class CareerPlanner
{
    /** Public API of the job exchange (Bundesagentur für Arbeit); the key is the documented public one. */
    private const JOB_API = 'https://rest.arbeitsagentur.de/jobboerse/jobsuche-service/pc/v4/jobs';

    private const JOB_API_KEY = 'jobboerse-jobsuche';

    /**
     * @return array{headline: string, summary: string, skills: string, languages: string, contact: string, desired_role: string, desired_location: string}
     */
    public static function profile(User $user): array
    {
        $profile = collect($user->getAttribute('career_profile'));
        $keys = ['headline', 'summary', 'skills', 'languages', 'contact', 'desired_role', 'desired_location'];

        return array_combine($keys, array_map(fn (string $key) => is_string($profile->get($key)) ? $profile->get($key) : '', $keys));
    }

    /**
     * Skills as a list ("PHP, Laravel; SQL" → three).
     *
     * @return list<string>
     */
    public static function skills(User $user): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', self::profile($user)['skills']) ?: [])));
    }

    /**
     * Jobs, education, trainings and milestones, newest first (current ones on top).
     *
     * @param  Collection<int, CareerEntry>  $entries
     * @return Collection<int, CareerEntry>
     */
    public static function timeline(Collection $entries): Collection
    {
        return $entries->whereIn('type', ['job', 'education', 'training', 'milestone'])
            ->sortByDesc(fn (CareerEntry $entry) => [$entry->isCurrent() ? 1 : 0, ($entry->starts_on ?? $entry->ends_on)?->toDateString() ?? ''])
            ->values();
    }

    /**
     * Things to look at: certificates that expire soon or have expired, the end of the probation period.
     *
     * @param  Collection<int, CareerEntry>  $entries
     * @return list<array{icon: string, text: string, level: string}>
     */
    public static function hints(Collection $entries): array
    {
        $hints = [];

        foreach ($entries->where('type', 'training') as $training) {
            $expires = $training->expiresOn();

            if ($expires && $expires->isPast()) {
                $hints[] = ['icon' => '⚠️', 'text' => __('The certificate “:title” expired on :date.', ['title' => $training->title, 'date' => $expires->format('d.m.Y')]), 'level' => 'warning'];
            } elseif ($expires && $expires->lte(today()->addDays(90))) {
                $hints[] = ['icon' => '⏳', 'text' => __('The certificate “:title” expires on :date – plan the renewal.', ['title' => $training->title, 'date' => $expires->format('d.m.Y')]), 'level' => 'info'];
            }
        }

        foreach ($entries->where('type', 'contract') as $contract) {
            $probation = $contract->detail('probation_until');

            if ($probation && preg_match('/^\d{4}-\d{2}-\d{2}$/', $probation) && today()->lte($probation) && today()->addDays(30)->gte($probation)) {
                $hints[] = ['icon' => '📅', 'text' => __('The probation period at :employer ends on :date.', ['employer' => $contract->organization ?: $contract->title, 'date' => date('d.m.Y', (int) strtotime($probation))]), 'level' => 'info'];
            }
        }

        return $hints;
    }

    /**
     * What to search for: the wished-for job, otherwise the current or latest job title.
     *
     * @param  Collection<int, CareerEntry>  $entries
     * @return array{what: string, where: string}
     */
    public static function searchTerms(User $user, Collection $entries): array
    {
        $profile = self::profile($user);
        $latestJob = $entries->where('type', 'job')->sortByDesc(fn (CareerEntry $entry) => [$entry->isCurrent() ? 1 : 0, $entry->starts_on?->toDateString() ?? ''])->first();

        return [
            'what' => trim($profile['desired_role']) ?: (string) $latestJob?->title,
            'where' => trim($profile['desired_location']) ?: (string) $latestJob?->location,
        ];
    }

    /**
     * Search links to the job portals for the terms.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function searchLinks(string $what, string $where): array
    {
        if (trim($what) === '') {
            return [];
        }

        $q = rawurlencode(trim($what));
        $l = rawurlencode(trim($where));

        return [
            ['label' => 'Jobbörse (Arbeitsagentur)', 'url' => 'https://www.arbeitsagentur.de/jobsuche/suche?was='.$q.($l !== '' ? '&wo='.$l : '')],
            ['label' => 'StepStone', 'url' => 'https://www.stepstone.de/jobs/'.rawurlencode(str_replace(' ', '-', mb_strtolower(trim($what)))).($l !== '' ? '/in-'.rawurlencode(str_replace(' ', '-', mb_strtolower(trim($where)))) : '')],
            ['label' => 'Indeed', 'url' => 'https://de.indeed.com/jobs?q='.$q.($l !== '' ? '&l='.$l : '')],
            ['label' => 'LinkedIn', 'url' => 'https://www.linkedin.com/jobs/search/?keywords='.$q.($l !== '' ? '&location='.$l : '')],
            ['label' => 'XING', 'url' => 'https://www.xing.com/jobs/search?keywords='.$q.($l !== '' ? '&location='.$l : '')],
        ];
    }

    /**
     * Current job offers from the job exchange (cached for six hours); empty when it is not reachable.
     *
     * @return list<array{title: string, employer: string, location: string, url: string, published: ?string, reference: string}>
     */
    public static function jobSuggestions(string $what, string $where, int $limit = 10): array
    {
        if (trim($what) === '') {
            return [];
        }

        $key = 'career-jobs:'.md5(mb_strtolower(trim($what).'|'.trim($where)));

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $response = Http::withHeaders(['X-API-Key' => self::JOB_API_KEY])->timeout(8)
                ->get(self::JOB_API, array_filter(['was' => trim($what), 'wo' => trim($where) ?: null, 'umkreis' => trim($where) !== '' ? 30 : null, 'size' => $limit, 'angebotsart' => 1]));
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $jobs = collect($response->json('stellenangebote') ?? [])->take($limit)->map(fn (array $job) => [
            'title' => (string) ($job['titel'] ?? $job['beruf'] ?? ''),
            'employer' => (string) ($job['arbeitgeber'] ?? ''),
            'location' => trim((string) ($job['arbeitsort']['ort'] ?? '')),
            'url' => isset($job['refnr']) ? 'https://www.arbeitsagentur.de/jobsuche/jobdetail/'.rawurlencode((string) $job['refnr']) : (string) ($job['externeUrl'] ?? ''),
            'published' => isset($job['aktuelleVeroeffentlichungsdatum']) ? (string) $job['aktuelleVeroeffentlichungsdatum'] : null,
            'reference' => (string) ($job['refnr'] ?? ''),
        ])->filter(fn (array $job) => $job['title'] !== '' && $job['url'] !== '')->values()->all();

        Cache::put($key, $jobs, now()->addHours(6));

        return $jobs;
    }
}
