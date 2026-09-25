<?php

namespace Tests\Feature;

use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class VoiceInputTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_quick_entries_offer_dictation(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertSee("voiceInput('quick-capture-title', true)", false);
        $this->get(route('collections.show', Collection::tasksFor($household->id)))->assertSee("voiceInput('new-entry-title', true)", false);
        $this->get(route('shopping-list.index'))->assertSee("voiceInput('shopping-label', false)", false);
    }
}
