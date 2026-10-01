<?php

namespace App\Support;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Templates for new pages: a small gallery built in, plus every page of the household tagged
 * "#vorlage" (or "#template"). "{{date}}" in a template becomes today's date.
 */
class PageTemplates
{
    public const TAGS = ['vorlage', 'template'];

    /**
     * @return array<string, array{icon: string, title: string, description: string, body: string}>
     */
    public static function builtIn(): array
    {
        return [
            'meeting' => ['icon' => '🗣️', 'title' => __('Meeting notes'), 'description' => __('Agenda, decisions and who does what'), 'body' => '**'.__('Date').":** {{date}}\n**".__('Participants').":** \n\n## ".__('Agenda')."\n- \n\n## ".__('Notes')."\n\n## ".__('Decisions')."\n- \n\n## ".__('To-dos')."\n- [ ] "],
            'week' => ['icon' => '🗓️', 'title' => __('Week planning'), 'description' => __('Goals, appointments and a short review'), 'body' => '## '.__('Goals this week')."\n- [ ] \n- [ ] \n\n## ".__('Appointments')."\n- \n\n## ".__('Review')."\n**".__('What went well?')."** \n**".__('What do I want to change?').'** '],
            'project' => ['icon' => '🚀', 'title' => __('Project'), 'description' => __('Goal, steps, budget and open questions'), 'body' => '## '.__('Goal')."\n\n## ".__('Steps')."\n- [ ] \n- [ ] \n\n## ".__('Budget')."\n| ".__('What').' | '.__('Cost')." |\n|---|---|\n|  |  |\n\n## ".__('Open questions')."\n- "],
            'decision' => ['icon' => '⚖️', 'title' => __('Decision'), 'description' => __('Options with pros and cons'), 'body' => '## '.__('Question')."\n\n## ".__('Option A')."\n**".__('Pros').":** \n**".__('Cons').":** \n\n## ".__('Option B')."\n**".__('Pros').":** \n**".__('Cons').":** \n\n## ".__('Decision')." ({{date}})\n"],
            'journal' => ['icon' => '📔', 'title' => __('Journal'), 'description' => __('Three questions for the day'), 'body' => "## {{date}}\n**".__('I am grateful for').":** \n**".__('Today was good because').":** \n**".__('Tomorrow I want to').':** '],
            'move' => ['icon' => '📦', 'title' => __('Moving house'), 'description' => __('Checklist from notice to address change'), 'body' => '## '.__('Before the move')."\n- [ ] ".__('Give notice on the old flat')."\n- [ ] ".__('Book a removal company or van')."\n- [ ] ".__('Request leave for the moving day')."\n\n## ".__('After the move')."\n- [ ] ".__('Register the new address (within 2 weeks)')."\n- [ ] ".__('Change address at bank, insurances and employer')."\n- [ ] ".__('Set up mail forwarding')."\n- [ ] ".__('Read the meters')],
        ];
    }

    /**
     * Pages of the household tagged as template that the user can see.
     *
     * @return Collection<int, Item>
     */
    public static function own(User $user, int $householdId): Collection
    {
        return Item::query()->forHousehold($householdId)->visibleTo($user)->where('type', Item::TYPE_NOTE)
            ->whereHas('tags', fn ($query) => $query->whereIn('name', self::TAGS))
            ->orderBy('title')->limit(30)->get();
    }

    public static function fill(string $body): string
    {
        return str_replace('{{date}}', now()->format('d.m.Y'), $body);
    }
}
