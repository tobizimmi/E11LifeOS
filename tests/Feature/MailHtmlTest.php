<?php

namespace Tests\Feature;

use App\Support\Mail\MailHtml;
use Tests\TestCase;

class MailHtmlTest extends TestCase
{
    public function test_active_content_and_handlers_are_stripped(): void
    {
        $html = '<p onclick="steal()">Hi</p><script>alert(1)</script><a href="javascript:evil()">x</a>';
        $result = MailHtml::render($html, true);

        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('onclick', $result['html']);
        $this->assertStringNotContainsString('javascript:', $result['html']);
        $this->assertStringContainsString('Hi', $result['html']);
    }

    public function test_remote_images_are_blocked_by_default_and_loadable_on_request(): void
    {
        $html = '<p>Hi</p><img src="https://tracker.example/pixel.gif">';

        $blocked = MailHtml::render($html, false);
        $this->assertTrue($blocked['blocked']);
        $this->assertStringNotContainsString('src="https://tracker.example', $blocked['html']);
        $this->assertStringContainsString('data-blocked-src', $blocked['html']);

        $loaded = MailHtml::render($html, true);
        $this->assertFalse($loaded['blocked']);
        $this->assertStringContainsString('src="https://tracker.example/pixel.gif"', $loaded['html']);
    }

    public function test_remote_css_urls_are_neutralised_when_images_are_blocked(): void
    {
        $html = '<div style="background:url(https://tracker.example/bg.png)">x</div>';
        $result = MailHtml::render($html, false);

        $this->assertStringNotContainsString('tracker.example', $result['html']);
        $this->assertTrue($result['blocked']);
    }
}
