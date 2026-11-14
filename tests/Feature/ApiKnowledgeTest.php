<?php

namespace Tests\Feature;

use App\Models\KnowledgeFavorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the money knowledge: tips, news and favourites.
 */
class ApiKnowledgeTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_tips_are_listed_filtered_read_and_favourited(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $list = $this->getJson('/api/v1/knowledge/tips', $headers)->assertOk()->json('data');
        $this->assertGreaterThan(20, count($list['tips']));
        $this->assertContains('taxes', array_column($list['categories'], 'key'));
        $this->assertArrayNotHasKey('body', $list['tips'][0]);

        $taxes = $this->getJson('/api/v1/knowledge/tips?category=taxes', $headers)->assertOk()->json('data.tips');
        $this->assertNotEmpty($taxes);
        $this->assertSame(['taxes'], array_values(array_unique(array_column($taxes, 'category'))));
        $this->getJson('/api/v1/knowledge/tips?q=zzzzqqqq', $headers)->assertOk()->assertJsonCount(0, 'data.tips');

        $key = $list['tips'][0]['key'];
        $tip = $this->getJson("/api/v1/knowledge/tips/{$key}", $headers)->assertOk()->json('data');
        $this->assertNotEmpty($tip['body']);
        $this->getJson('/api/v1/knowledge/tips/does-not-exist', $headers)->assertNotFound();

        $this->postJson('/api/v1/knowledge/favorites', ['kind' => 'tip', 'key' => $key], $headers)->assertCreated()->assertJsonPath('data.favorite', true);
        $this->assertTrue($this->getJson("/api/v1/knowledge/tips/{$key}", $headers)->json('data.favorite'));
        $favorites = $this->getJson('/api/v1/knowledge/favorites', $headers)->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame($tip['title'], $favorites[0]['title']);

        $this->postJson('/api/v1/knowledge/favorites', ['kind' => 'tip', 'key' => $key], $headers)->assertOk()->assertJsonPath('data.favorite', false);
        $this->postJson('/api/v1/knowledge/favorites', ['kind' => 'tip', 'key' => 'nope'], $headers)->assertNotFound();
        $this->postJson('/api/v1/knowledge/favorites', ['kind' => 'video', 'key' => $key], $headers)->assertStatus(422);
    }

    public function test_news_come_per_topic_and_can_be_favourited(): void
    {
        Http::fake(['news.google.com/*' => Http::response('<?xml version="1.0"?><rss><channel><item><title>Grundfreibetrag steigt 2027</title><link>https://example.com/grundfreibetrag</link><pubDate>Mon, 28 Sep 2026 08:00:00 GMT</pubDate><source url="https://example.com">Tagesschau</source></item></channel></rss>')]);
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $news = $this->getJson('/api/v1/knowledge/news?topic=taxes', $headers)->assertOk()->json('data');
        $this->assertSame('taxes', $news['topic']);
        $this->assertContains('taxes', array_column($news['topics'], 'key'));
        $this->assertSame('Grundfreibetrag steigt 2027', $news['articles'][0]['title']);
        $this->getJson('/api/v1/knowledge/news?topic=weather', $headers)->assertStatus(422);

        $key = $news['articles'][0]['key'];
        $this->postJson('/api/v1/knowledge/favorites', ['kind' => 'news', 'key' => $key, 'topic' => 'taxes'], $headers)->assertCreated();
        $this->assertTrue($this->getJson('/api/v1/knowledge/news?topic=taxes', $headers)->json('data.articles.0.favorite'));
        $id = $this->getJson('/api/v1/knowledge/favorites', $headers)->json('data.0.id');
        $this->deleteJson("/api/v1/knowledge/favorites/{$id}", [], $headers)->assertOk();
        $this->assertSame(0, KnowledgeFavorite::count());
    }

    public function test_favourites_are_personal(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $foreign = KnowledgeFavorite::create(['user_id' => $partner->id, 'kind' => 'tip', 'key' => 'equity-share', 'title' => 'Fremd']);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/knowledge/favorites', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/knowledge/favorites/{$foreign->id}", [], $headers)->assertOk();
        $this->assertNotNull(KnowledgeFavorite::find($foreign->id));
    }
}
