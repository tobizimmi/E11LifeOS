<?php

namespace Tests\Unit;

use App\Support\Mentions;
use PHPUnit\Framework\TestCase;

class MentionsTest extends TestCase
{
    public function test_ids_in_text_are_extracted_and_deduplicated(): void
    {
        $text = 'Hallo @[7:Tobi] und @[12:Anna], cc @[7:Tobi]. Kein Treffer: @[x:Foo] oder @Bob.';

        $this->assertSame([7, 12], Mentions::idsInText($text));
    }

    public function test_text_without_mentions_yields_no_ids(): void
    {
        $this->assertSame([], Mentions::idsInText('Nur Text, keine Erwähnung.'));
    }
}
