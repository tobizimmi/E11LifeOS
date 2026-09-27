<?php

namespace App\Http\Controllers;

use App\Models\InstagramPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Receives Instagram videos analysed elsewhere (e.g. transcribed on a Raspberry Pi), same format
 * as the old portfolio manager: POST {videos: [...]} with header X-Push-Key.
 */
class InstagramPushController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) config('services.instagram.push_key');
        abort_if($key === '', 503, 'INSTAGRAM_PUSH_KEY is not configured.');
        abort_unless(hash_equals($key, (string) $request->header('X-Push-Key')), 401);

        $videos = $request->validate(['videos' => ['required', 'array', 'max:200'], 'videos.*.instagram_id' => ['required', 'string', 'max:100'], 'videos.*.shortcode' => ['required', 'string', 'max:50']])['videos'];
        $saved = 0;

        foreach ($request->input('videos') as $index => $video) {
            $post = InstagramPost::firstOrNew(['instagram_id' => (string) $videos[$index]['instagram_id']]);
            $tickers = is_array($video['ai_tickers'] ?? null) ? $video['ai_tickers'] : json_decode((string) ($video['ai_tickers'] ?? '[]'), true);
            $summary = trim((string) ($video['ai_summary'] ?? ''));

            $post->fill(array_filter([
                'profile' => $post->profile ?: (string) config('services.instagram.profile'),
                'shortcode' => (string) $video['shortcode'],
                'caption' => $video['caption'] ?? null,
                'thumbnail_url' => $video['thumbnail_url'] ?? null,
                'video_url' => $video['video_url'] ?? null,
                'post_url' => $video['post_url'] ?? 'https://www.instagram.com/p/'.$video['shortcode'].'/',
                'published_at' => ! empty($video['published_at']) ? Carbon::parse($video['published_at']) : null,
                'duration_sec' => isset($video['duration_sec']) ? (int) $video['duration_sec'] : null,
                'view_count' => isset($video['view_count']) ? (int) $video['view_count'] : null,
                'transcript' => ($video['transcript'] ?? '') !== '' ? $video['transcript'] : null,
                'summary' => $summary !== '' ? $summary : null,
                'tickers' => is_array($tickers) && $tickers !== [] ? array_values(array_map('strtoupper', array_filter($tickers, 'is_string'))) : null,
                'sentiment' => in_array($video['ai_sentiment'] ?? null, InstagramPost::SENTIMENTS, true) ? $video['ai_sentiment'] : null,
            ], fn ($value) => $value !== null));

            // With a transcript but no summary the AI reads it with the next run.
            if ($summary !== '') {
                $post->analyzed_at ??= now();
            } elseif ($post->isDirty('transcript')) {
                $post->analyzed_at = null;
            }

            $post->save();
            $saved++;
        }

        return response()->json(['success' => true, 'saved' => $saved, 'errors' => []]);
    }
}
