<?php

namespace App\Support;

use App\Models\ChecklistTemplate;
use App\Models\Collection;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Checklists for things that come up again and again (moving house, a holiday, the tax
 * return …): each creates one or more tasks with sub-items, due relative to a chosen day.
 * Besides the built-in ones the household can save its own from any task.
 */
class ChecklistTemplates
{
    /**
     * @return array<string, array{name: string, icon: string, anchor: string, default_offset: int, tasks: list<array{title: string, offset: int, subtasks: list<string>}>}>
     */
    public static function builtIn(): array
    {
        return [
            'moving' => [
                'name' => __('Moving house'),
                'icon' => '📦',
                'anchor' => __('Moving day'),
                'default_offset' => 60,
                'tasks' => [
                    ['title' => __('Moving: two months before'), 'offset' => -60, 'subtasks' => [__('Give notice on the old flat'), __('Book a removal company or van'), __('Ask friends for help'), __('Apply for special leave'), __('Sort out and sell things')]],
                    ['title' => __('Moving: four weeks before'), 'offset' => -28, 'subtasks' => [__('Move or cancel internet and electricity'), __('Set up mail forwarding'), __('Order boxes and packing material'), __('Apply for a no-parking zone')]],
                    ['title' => __('Moving: one week before'), 'offset' => -7, 'subtasks' => [__('Pack everything not needed daily'), __('Defrost the freezer'), __('Arrange the handover of the old flat'), __('Label the boxes by room')]],
                    ['title' => __('Moving day'), 'offset' => 0, 'subtasks' => [__('Note the meter readings'), __('Handover protocol for the old flat'), __('Keep tools and snacks at hand')]],
                    ['title' => __('Moving: afterwards'), 'offset' => 14, 'subtasks' => [__('Register at the residents office (within two weeks)'), __('Update the address at bank, insurance and employer'), __('Change the vehicle registration'), __('Claim moving costs in the tax return')]],
                ],
            ],
            'holiday' => [
                'name' => __('Holiday'),
                'icon' => '🧳',
                'anchor' => __('Departure'),
                'default_offset' => 14,
                'tasks' => [
                    ['title' => __('Prepare the holiday'), 'offset' => -14, 'subtasks' => [__('Check passports and ID cards'), __('Travel health insurance'), __('Arrange care for plants and pets'), __('Hold the mail or ask the neighbours'), __('Set the out-of-office reply')]],
                    ['title' => __('Pack the suitcase'), 'offset' => -1, 'subtasks' => [__('Documents, tickets, cash'), __('Chargers and adapters'), __('Medicines and first-aid kit'), __('Clothes for every weather'), __('Toiletries'), __('Take out the rubbish and empty the fridge')]],
                ],
            ],
            'tax' => [
                'name' => __('Tax return'),
                'icon' => '🧾',
                'anchor' => __('Deadline'),
                'default_offset' => 60,
                'tasks' => [
                    ['title' => __('Tax return: collect documents'), 'offset' => -30, 'subtasks' => [__('Income tax certificate from the employer'), __('Receipts for work equipment and training'), __('Donations and membership fees'), __('Craftsman and household services'), __('Insurance and pension contributions'), __('Export the tax overview from Finances')]],
                    ['title' => __('Submit the tax return'), 'offset' => 0, 'subtasks' => [__('Fill in ELSTER'), __('Check and submit'), __('File the tax assessment when it arrives')]],
                ],
            ],
            'spring_cleaning' => [
                'name' => __('Spring cleaning'),
                'icon' => '🧽',
                'anchor' => __('Day'),
                'default_offset' => 7,
                'tasks' => [
                    ['title' => __('Spring cleaning'), 'offset' => 0, 'subtasks' => [__('Clean the windows'), __('Wash curtains'), __('Clean behind the furniture'), __('Defrost and clean the fridge'), __('Test the smoke detectors'), __('Sort out the wardrobe')]],
                ],
            ],
            'new_car' => [
                'name' => __('New car'),
                'icon' => '🚗',
                'anchor' => __('Handover'),
                'default_offset' => 21,
                'tasks' => [
                    ['title' => __('New car: before the handover'), 'offset' => -14, 'subtasks' => [__('Compare and take out insurance (eVB number)'), __('Reserve a licence plate'), __('Book a registration appointment'), __('Set up direct debit for the vehicle tax')]],
                    ['title' => __('New car: handover'), 'offset' => 0, 'subtasks' => [__('Check for damage and note it'), __('Take over papers and all keys'), __('Put the service dates in the calendar')]],
                ],
            ],
        ];
    }

    /**
     * Creates the tasks of a template; returns how many.
     *
     * @param  array{name: string, tasks: list<array{title: string, offset: int, subtasks: list<string>}>}  $template
     */
    public static function apply(User $user, int $householdId, array $template, Carbon $anchor): int
    {
        $collection = Collection::tasksFor($householdId)->load('properties');
        $due = $collection->systemProperty('due');
        $position = (int) Item::where('collection_id', $collection->id)->max('position');

        foreach ($template['tasks'] as $definition) {
            $task = new Item([
                'household_id' => $householdId,
                'collection_id' => $collection->id,
                'type' => Item::TYPE_TODO,
                'visibility' => $collection->entryVisibility(),
                'title' => mb_substr($definition['title'], 0, 255),
                'created_by' => $user->id,
                'position' => ++$position,
            ]);

            if ($due) {
                $task->setPropertyValue($due, $due->normalizeValue($anchor->copy()->addDays((int) $definition['offset'])->toDateString(), []));
            }

            $task->setSubtasks(array_map(fn (string $text) => ['id' => Str::random(8), 'text' => mb_substr($text, 0, 255), 'done' => false], $definition['subtasks']));
            $task->save();
        }

        return count($template['tasks']);
    }

    /**
     * Saves a task (title and sub-items) as the household's own template.
     */
    public static function saveFromTask(Item $task, User $user, ?string $name = null): ChecklistTemplate
    {
        return ChecklistTemplate::create([
            'household_id' => $task->household_id,
            'created_by' => $user->id,
            'name' => mb_substr(trim((string) $name) ?: $task->title, 0, 255),
            'icon' => '📋',
            'tasks' => [[
                'title' => $task->title,
                'offset' => 0,
                'subtasks' => array_values(array_map(fn (array $subtask) => (string) $subtask['text'], $task->subtasks())),
            ]],
        ]);
    }
}
