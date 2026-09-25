<?php

namespace Tests\Unit;

use App\Support\TaskInput;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskInputTest extends TestCase
{
    private const MEMBERS = [5 => 'Anna Beispiel', 7 => 'Tobi'];

    /**
     * Friday, 25 September 2026.
     */
    private function parse(string $text, array $ignored = []): array
    {
        return TaskInput::parse($text, self::MEMBERS, 7, $ignored, Carbon::parse('2026-09-25'));
    }

    public function test_date_priority_assignee_and_tags_are_taken_out_of_the_title(): void
    {
        $task = $this->parse('Zahnarzt anrufen morgen !hoch @anna #gesundheit');

        $this->assertSame('Zahnarzt anrufen', $task['title']);
        $this->assertSame('2026-09-26', $task['due']);
        $this->assertSame('high', $task['priority']);
        $this->assertSame(5, $task['assignee']);
        $this->assertSame(['gesundheit'], $task['tags']);
    }

    public function test_german_date_expressions_are_understood(): void
    {
        $expectations = [
            'Wäsche heute' => '2026-09-25',
            'Wäsche übermorgen' => '2026-09-27',
            'Wäsche Freitag' => '2026-09-25',
            'Wäsche nächsten Freitag' => '2026-10-02',
            'Wäsche Montag' => '2026-09-28',
            'Wäsche nächste Woche' => '2026-09-28',
            'Wäsche am Wochenende' => '2026-09-26',
            'Wäsche in 3 Tagen' => '2026-09-28',
            'Wäsche in 2 Wochen' => '2026-10-09',
            'Wäsche am 24.12.' => '2026-12-24',
            'Wäsche am 1.3.' => '2027-03-01',
            'Wäsche bis 05.10.2027' => '2027-10-05',
            'Wäsche 2026-11-02' => '2026-11-02',
        ];

        foreach ($expectations as $text => $due) {
            $task = $this->parse($text);
            $this->assertSame($due, $task['due'], $text);
            $this->assertSame('Wäsche', $task['title'], $text);
        }
    }

    public function test_repetitions_set_the_rule_and_the_first_date(): void
    {
        $task = $this->parse('Müll rausbringen jeden Dienstag');
        $this->assertSame(['Müll rausbringen', '2026-09-29', 'weekly'], [$task['title'], $task['due'], $task['repeat']]);

        $task = $this->parse('Blumen gießen täglich');
        $this->assertSame(['Blumen gießen', null, 'daily'], [$task['title'], $task['due'], $task['repeat']]);
    }

    public function test_words_inside_other_words_and_ignored_phrases_stay_in_the_title(): void
    {
        $this->assertSame(['Morgenroutine planen', null], array_values(array_intersect_key($this->parse('Morgenroutine planen'), ['title' => 1, 'due' => 1])));

        $task = $this->parse('Film Heute Abend schauen', ['heute']);
        $this->assertSame(['Film Heute Abend schauen', null], [$task['title'], $task['due']]);

        $task = $this->parse('Party nächste Woche!');
        $this->assertSame(['Party!', '2026-09-28', null], [$task['title'], $task['due'], $task['priority']]);

        $task = $this->parse('Geschenk für @unbekannt kaufen');
        $this->assertSame(['Geschenk für @unbekannt kaufen', null], [$task['title'], $task['assignee']]);
    }
}
