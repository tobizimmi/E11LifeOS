<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Support\NoteMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PageTableOfContentsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_headings_get_anchors_and_a_toc_marker_becomes_a_table_of_contents(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $body = "# Überblick\n\n[toc]\n\n## Teil A\n\nText.\n\n## Teil B\n\nMehr.";
        $note = Item::factory()->note()->for($household)->create(['content' => ['body' => $body], 'created_by' => $user->id]);

        $html = NoteMarkdown::toHtml($note, $user);

        // Headings carry stable id anchors.
        $this->assertStringContainsString('<h1 id="uberblick">', $html);
        $this->assertStringContainsString('<h2 id="teil-a">', $html);
        $this->assertStringContainsString('<h2 id="teil-b">', $html);

        // The [toc] line is replaced by a navigation linking to those anchors.
        $this->assertStringContainsString('class="page-toc"', $html);
        $this->assertStringContainsString('href="#teil-a"', $html);
        $this->assertStringContainsString('href="#teil-b"', $html);
        $this->assertStringNotContainsString('<p>[toc]</p>', $html);
    }

    public function test_duplicate_headings_get_unique_anchors_and_no_toc_without_marker(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $body = "## Notizen\n\nA\n\n## Notizen\n\nB";
        $note = Item::factory()->note()->for($household)->create(['content' => ['body' => $body], 'created_by' => $user->id]);

        $html = NoteMarkdown::toHtml($note, $user);

        $this->assertStringContainsString('<h2 id="notizen">', $html);
        $this->assertStringContainsString('<h2 id="notizen-2">', $html);
        $this->assertStringNotContainsString('page-toc', $html);
    }
}
