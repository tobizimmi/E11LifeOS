<?php

namespace App\Support;

use App\Models\BusinessProfile;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

/**
 * Deadline assistant for collections: date properties with a reminder can also create a task
 * (e.g. "Cancel by" of a contract) once the date comes within the reminder window. Contract
 * entries additionally get a ready-to-send cancellation letter.
 */
class ContractAssistant
{
    /**
     * Creates the deadline tasks that are due and returns how many were created.
     */
    public static function createDeadlineTasks(): int
    {
        $created = 0;
        $today = today()->toDateString();

        Collection::query()->whereNull('system_key')->with('properties')->each(function (Collection $collection) use (&$created, $today) {
            $properties = $collection->properties->filter(fn (CollectionProperty $property) => $property->createsTask());

            if ($properties->isEmpty()) {
                return;
            }

            $entries = Item::query()->where('collection_id', $collection->id)->where('done', false)->get();

            foreach ($properties as $property) {
                $until = today()->addDays($property->remindDays())->toDateString();

                foreach ($entries as $entry) {
                    $date = (string) $entry->propertyValue($property);

                    if ($date === '' || $date < $today || $date > $until || ($entry->content['deadline_tasks'][$property->id] ?? null) === $date) {
                        continue;
                    }

                    self::createTask($collection, $property, $entry, $date);
                    $created++;
                }
            }
        });

        return $created;
    }

    private static function createTask(Collection $collection, CollectionProperty $property, Item $entry, string $date): void
    {
        $tasks = Collection::tasksFor($collection->household_id)->load('properties');
        $isMember = $entry->created_by && HouseholdMember::where('household_id', $collection->household_id)->where('user_id', $entry->created_by)->exists();

        $task = new Item([
            'household_id' => $collection->household_id,
            'collection_id' => $tasks->id,
            'type' => Item::TYPE_TODO,
            'visibility' => $collection->isPrivate() || $entry->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => mb_substr($property->name.': '.$entry->title, 0, 255),
            'content' => ['body' => __('Deadline from ":collection".', ['collection' => $collection->name])."\n\n".route('items.show', $entry)],
            'created_by' => $isMember ? $entry->created_by : null,
            'position' => (int) Item::where('collection_id', $tasks->id)->max('position') + 1,
        ]);

        if ($dueProperty = $tasks->systemProperty('due')) {
            $task->setPropertyValue($dueProperty, $date);
        }

        if ($isMember && ($assigneeProperty = $tasks->systemProperty('assignee'))) {
            $task->setPropertyValue($assigneeProperty, (int) $entry->created_by);
        }

        $task->save();

        $content = $entry->content ?? [];
        $content['deadline_tasks'][$property->id] = $date;
        $entry->forceFill(['content' => $content])->saveQuietly();
    }

    /**
     * Contract collections (made from the template or similar) have a provider or customer number.
     */
    public static function isContractCollection(Collection $collection): bool
    {
        return self::property($collection, ['Provider', 'Customer number']) !== null;
    }

    /**
     * Cancellation letter for a contract entry, ready to edit.
     */
    public static function letter(Collection $collection, Item $entry, User $user): string
    {
        $profile = BusinessProfile::where('user_id', $user->id)->first();
        $address = $profile && $profile->street
            ? trim($profile->street."\n".trim($profile->postal_code.' '.$profile->city))
            : __('[Street and number]')."\n".__('[Postcode and city]');

        $provider = self::value($collection, $entry, ['Provider']) ?: __('[Provider]');
        $customerNumber = self::value($collection, $entry, ['Customer number']);
        $endsOn = self::value($collection, $entry, ['Ends on']);
        $endsOn = $endsOn !== '' && strtotime($endsOn) ? Carbon::parse($endsOn)->translatedFormat('j. F Y') : null;

        $lines = [
            ($profile?->owner_name ?: $user->name)."\n".$address,
            $provider."\n".__('[Provider address]'),
            today()->translatedFormat('j. F Y'),
            __('Cancellation of my contract ":contract"', ['contract' => $entry->title])
                .($customerNumber !== '' ? "\n".__('Customer number').': '.$customerNumber : ''),
            __('Dear Sir or Madam,'),
            ($endsOn
                ? __('I hereby cancel the above contract with effect from :date, or the next possible date.', ['date' => $endsOn])
                : __('I hereby cancel the above contract with effect from the next possible date.'))
                .' '.__('Please confirm the cancellation and the end date in writing.'),
            __('I also object to the use of my data for advertising purposes and withdraw any consent to contact me by phone or e-mail.'),
            __('Kind regards')."\n\n\n".($profile?->owner_name ?: $user->name),
        ];

        return implode("\n\n", $lines);
    }

    public static function pdf(string $text): string
    {
        $html = '<html><head><meta charset="utf-8"><style>@page { margin: 25mm 20mm; } body { font-family: "DejaVu Sans"; font-size: 11pt; line-height: 1.5; }</style></head><body>'
            .nl2br(e($text)).'</body></html>';

        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('DejaVu Sans'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Property by its template name, in English or the current language.
     *
     * @param  array<int, string>  $names
     */
    private static function property(Collection $collection, array $names): ?CollectionProperty
    {
        $candidates = collect($names)->flatMap(fn (string $name) => [mb_strtolower($name), mb_strtolower(__($name))])->all();

        return $collection->properties->first(fn (CollectionProperty $property) => in_array(mb_strtolower($property->name), $candidates, true));
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function value(Collection $collection, Item $entry, array $names): string
    {
        $property = self::property($collection, $names);

        return $property ? trim((string) $entry->propertyValue($property)) : '';
    }
}
