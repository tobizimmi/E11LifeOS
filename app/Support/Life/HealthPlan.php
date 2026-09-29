<?php

namespace App\Support\Life;

use App\Models\HealthEntry;
use App\Models\HealthProfile;

/**
 * Check-ups and vaccinations recommended in Germany by age and sex (statutory health insurance
 * and STIKO, simplified): which apply to a person and are not in the plan yet.
 */
class HealthPlan
{
    /**
     * @return array<string, array{kind: string, title: string, interval: ?int, min_age: int, max_age: ?int, sex: ?string, hint: string, months?: array{0: int, 1: int}}>
     */
    public static function library(): array
    {
        return [
            // Adults
            'dentist' => ['kind' => HealthEntry::CHECKUP, 'title' => __('Dental check-up'), 'interval' => 6, 'min_age' => 0, 'max_age' => null, 'sex' => null, 'hint' => __('Twice a year; keep the bonus booklet stamped')],
            'health_checkup' => ['kind' => HealthEntry::CHECKUP, 'title' => __('General health check-up'), 'interval' => 36, 'min_age' => 35, 'max_age' => null, 'sex' => null, 'hint' => __('Every three years from 35 (once between 18 and 34)')],
            'skin_screening' => ['kind' => HealthEntry::CHECKUP, 'title' => __('Skin cancer screening'), 'interval' => 24, 'min_age' => 35, 'max_age' => null, 'sex' => null, 'hint' => __('Every two years from 35')],
            'cervical' => ['kind' => HealthEntry::CHECKUP, 'title' => __('Gynaecological screening'), 'interval' => 12, 'min_age' => 20, 'max_age' => null, 'sex' => 'f', 'hint' => __('Yearly from 20; from 35 combined test every three years')],
            'mammography' => ['kind' => HealthEntry::CHECKUP, 'title' => __('Mammography screening'), 'interval' => 24, 'min_age' => 50, 'max_age' => 75, 'sex' => 'f', 'hint' => __('Every two years between 50 and 75 – you get an invitation')],
            'prostate' => ['kind' => HealthEntry::CHECKUP, 'title' => __('Prostate and genital screening'), 'interval' => 12, 'min_age' => 45, 'max_age' => null, 'sex' => 'm', 'hint' => __('Yearly from 45')],
            'colon' => ['kind' => HealthEntry::CHECKUP, 'title' => __('Colorectal cancer screening'), 'interval' => 120, 'min_age' => 50, 'max_age' => null, 'sex' => null, 'hint' => __('Colonoscopy from 50, repeated after ten years; alternatively a stool test')],
            'aorta' => ['kind' => HealthEntry::CHECKUP, 'title' => __('Abdominal aortic ultrasound'), 'interval' => null, 'min_age' => 65, 'max_age' => null, 'sex' => 'm', 'hint' => __('Once for men from 65')],
            'tetanus' => ['kind' => HealthEntry::VACCINATION, 'title' => __('Tetanus, diphtheria, whooping cough'), 'interval' => 120, 'min_age' => 18, 'max_age' => null, 'sex' => null, 'hint' => __('Booster every ten years')],
            'flu' => ['kind' => HealthEntry::VACCINATION, 'title' => __('Flu vaccination'), 'interval' => 12, 'min_age' => 60, 'max_age' => null, 'sex' => null, 'hint' => __('Every autumn from 60')],
            'pneumococcus' => ['kind' => HealthEntry::VACCINATION, 'title' => __('Pneumococcal vaccination'), 'interval' => null, 'min_age' => 60, 'max_age' => null, 'sex' => null, 'hint' => __('Once from 60')],
            'shingles' => ['kind' => HealthEntry::VACCINATION, 'title' => __('Shingles vaccination'), 'interval' => null, 'min_age' => 60, 'max_age' => null, 'sex' => null, 'hint' => __('Two doses from 60')],
            // Children (months of age)
            'u3' => ['kind' => HealthEntry::CHECKUP, 'title' => 'U3', 'interval' => null, 'min_age' => 0, 'max_age' => 1, 'sex' => null, 'hint' => __('4th–5th week of life'), 'months' => [1, 2]],
            'u4' => ['kind' => HealthEntry::CHECKUP, 'title' => 'U4', 'interval' => null, 'min_age' => 0, 'max_age' => 1, 'sex' => null, 'hint' => __('3rd–4th month'), 'months' => [3, 4]],
            'u5' => ['kind' => HealthEntry::CHECKUP, 'title' => 'U5', 'interval' => null, 'min_age' => 0, 'max_age' => 1, 'sex' => null, 'hint' => __('6th–7th month'), 'months' => [6, 7]],
            'u6' => ['kind' => HealthEntry::CHECKUP, 'title' => 'U6', 'interval' => null, 'min_age' => 0, 'max_age' => 2, 'sex' => null, 'hint' => __('10th–12th month'), 'months' => [10, 12]],
            'u7' => ['kind' => HealthEntry::CHECKUP, 'title' => 'U7', 'interval' => null, 'min_age' => 1, 'max_age' => 3, 'sex' => null, 'hint' => __('21st–24th month'), 'months' => [21, 24]],
            'u7a' => ['kind' => HealthEntry::CHECKUP, 'title' => 'U7a', 'interval' => null, 'min_age' => 2, 'max_age' => 4, 'sex' => null, 'hint' => __('34th–36th month'), 'months' => [34, 36]],
            'u8' => ['kind' => HealthEntry::CHECKUP, 'title' => 'U8', 'interval' => null, 'min_age' => 3, 'max_age' => 5, 'sex' => null, 'hint' => __('46th–48th month'), 'months' => [46, 48]],
            'u9' => ['kind' => HealthEntry::CHECKUP, 'title' => 'U9', 'interval' => null, 'min_age' => 4, 'max_age' => 6, 'sex' => null, 'hint' => __('60th–64th month'), 'months' => [60, 64]],
            'j1' => ['kind' => HealthEntry::CHECKUP, 'title' => 'J1', 'interval' => null, 'min_age' => 11, 'max_age' => 15, 'sex' => null, 'hint' => __('12 to 14 years'), 'months' => [144, 168]],
        ];
    }

    /**
     * Recommendations for the person that are not in their plan yet, with the date they are due.
     *
     * @return list<array{key: string, kind: string, title: string, hint: string, interval: ?int, due_on: string}>
     */
    public static function suggestions(HealthProfile $profile): array
    {
        $age = $profile->age();

        if ($age === null) {
            return [];
        }

        $planned = $profile->entries()->pluck('key')->all();
        $suggestions = [];

        foreach (self::library() as $key => $item) {
            if (in_array($key, $planned, true) || $age < $item['min_age'] || ($item['max_age'] !== null && $age > $item['max_age']) || ($item['sex'] !== null && $profile->sex !== $item['sex'])) {
                continue;
            }

            if (isset($item['months'])) {
                $end = $profile->birth_date->copy()->addMonths($item['months'][1]);

                if ($end->lt(today())) {
                    continue;
                }

                $due = $profile->birth_date->copy()->addMonths($item['months'][0]);
            } else {
                $due = today();
            }

            $suggestions[] = ['key' => $key, 'kind' => $item['kind'], 'title' => $item['title'], 'hint' => $item['hint'], 'interval' => $item['interval'], 'due_on' => max($due, today())->toDateString()];
        }

        return $suggestions;
    }
}
