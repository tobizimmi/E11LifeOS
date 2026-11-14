<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\KnowledgeFavorite;
use App\Support\Knowledge\FinanceNews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Money knowledge for the app: curated tips, current news per topic and the member's favourites.
 */
class KnowledgeController extends ApiController
{
    /**
     * The tips (without their long text) and which of them are favourites.
     */
    public function tips(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', 'string', 'max:40'], 'q' => ['nullable', 'string', 'max:100']]);
        $favorites = $this->favoriteKeys(KnowledgeFavorite::TIP);
        $term = mb_strtolower(trim((string) ($data['q'] ?? '')));

        $tips = collect($this->allTips())
            ->when(($data['category'] ?? '') !== '', fn ($items) => $items->where('category', $data['category']))
            ->when($term !== '', fn ($items) => $items->filter(fn (array $tip) => str_contains(mb_strtolower($tip['title'].' '.$tip['summary'].' '.implode(' ', $tip['body'])), $term)));

        return $this->ok([
            'categories' => collect($this->categories())->map(fn (array $info, string $key) => ['key' => $key, 'label' => $info['label'], 'icon' => $info['icon'] ?? null])->values()->all(),
            'tips' => $tips->map(fn (array $tip, string $key) => ['key' => $key, 'category' => $tip['category'], 'title' => $tip['title'], 'summary' => $tip['summary'], 'favorite' => isset($favorites[$key])])->values()->all(),
        ]);
    }

    public function tip(string $key): JsonResponse
    {
        $tip = $this->allTips()[$key] ?? abort(404);

        return $this->ok(['key' => $key, 'category' => $tip['category'], 'title' => $tip['title'], 'summary' => $tip['summary'], 'body' => $tip['body'], 'favorite' => isset($this->favoriteKeys(KnowledgeFavorite::TIP)[$key])]);
    }

    public function news(Request $request, FinanceNews $news): JsonResponse
    {
        $topic = (string) $request->query('topic', 'taxes');
        abort_unless(isset(FinanceNews::TOPICS[$topic]), 422, __('Unknown topic.'));
        $favorites = $this->favoriteKeys(KnowledgeFavorite::NEWS);

        return $this->ok([
            'topics' => collect(FinanceNews::labels())->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'topic' => $topic,
            'articles' => collect($news->for($topic))->map(fn (array $article) => [
                'key' => $article['key'],
                'title' => $article['title'],
                'url' => $article['url'],
                'source' => $article['source'],
                'summary' => $article['summary'],
                'published_at' => $article['published_at'] ? Carbon::parse($article['published_at'])->toIso8601String() : null,
                'favorite' => isset($favorites[$article['key']]),
            ])->all(),
        ]);
    }

    public function favorites(): JsonResponse
    {
        return $this->ok(KnowledgeFavorite::where('user_id', $this->user()->id)->latest()->get()->map(fn ($favorite) => [
            'id' => $favorite->getKey(),
            'kind' => $favorite->getAttribute('kind'),
            'key' => $favorite->getAttribute('key'),
            'title' => $favorite->getAttribute('title'),
            'url' => $favorite->getAttribute('url'),
            'summary' => $favorite->getAttribute('summary'),
            'source' => $favorite->getAttribute('source'),
            'category' => $favorite->getAttribute('category'),
        ])->all());
    }

    /**
     * Adds or removes a favourite: a tip by its key, or a news article (key + topic).
     */
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([KnowledgeFavorite::TIP, KnowledgeFavorite::NEWS])],
            'key' => ['required', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:40'],
        ]);
        $userId = $this->user()->id;
        $existing = KnowledgeFavorite::where('user_id', $userId)->where('kind', $data['kind'])->where('key', $data['key'])->first();

        if ($existing) {
            $existing->delete();

            return $this->ok(['favorite' => false]);
        }

        if ($data['kind'] === KnowledgeFavorite::TIP) {
            $tip = $this->allTips()[$data['key']] ?? abort(404);
            $attributes = ['title' => $tip['title'], 'summary' => $tip['summary'], 'category' => $tip['category']];
        } else {
            $topic = $data['topic'] ?? '';
            abort_unless(isset(FinanceNews::TOPICS[$topic]), 422, __('Unknown topic.'));
            $article = collect(app(FinanceNews::class)->for($topic))->firstWhere('key', $data['key']) ?? abort(404);
            $attributes = [
                'title' => mb_substr($article['title'], 0, 255),
                'url' => mb_substr($article['url'], 0, 2000),
                'summary' => $article['summary'],
                'source' => $article['source'] ? mb_substr($article['source'], 0, 255) : null,
                'category' => $topic,
                'published_at' => $article['published_at'],
            ];
        }

        KnowledgeFavorite::create(['user_id' => $userId, 'kind' => $data['kind'], 'key' => $data['key']] + $attributes);

        return $this->ok(['favorite' => true], 201);
    }

    public function destroyFavorite(int $favorite): JsonResponse
    {
        KnowledgeFavorite::where('user_id', $this->user()->id)->whereKey($favorite)->delete();

        return $this->ok();
    }

    /**
     * @return array<string, array{category: string, title: string, summary: string, body: list<string>}>
     */
    private function allTips(): array
    {
        $tips = trans('finance-tips.items', [], 'de');

        return is_array($tips) ? $tips : [];
    }

    /**
     * @return array<string, array{label: string, icon?: string}>
     */
    private function categories(): array
    {
        $categories = trans('finance-tips.categories', [], 'de');

        return is_array($categories) ? $categories : [];
    }

    /**
     * @return array<string, int>
     */
    private function favoriteKeys(string $kind): array
    {
        return KnowledgeFavorite::where('user_id', $this->user()->id)->where('kind', $kind)->pluck('key')->flip()->all();
    }
}
