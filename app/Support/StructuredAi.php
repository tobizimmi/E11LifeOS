<?php

namespace App\Support;

use Anthropic\Client;
use Throwable;

/**
 * Base for Claude API calls whose answers are forced into a JSON schema.
 */
abstract class StructuredAi
{
    /** The AiFeatures switch of the subclass; '' for none. */
    protected const FEATURE = '';

    public function __construct(private ?Client $client = null) {}

    /**
     * Whether the API key is set and the function of the subclass is not switched off.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.anthropic.key')) && (static::FEATURE === '' || AiFeatures::enabled(static::FEATURE));
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function ask(array $content, array $schema, int $maxTokens, ?string $system = null): array
    {
        if (! filled(config('services.anthropic.key'))) {
            $this->fail(__('The AI is not set up on this server (ANTHROPIC_API_KEY).'));
        }

        if (! static::isConfigured()) {
            $this->fail(__('This AI function has been switched off by the administrator.'));
        }

        try {
            $arguments = [
                'maxTokens' => $maxTokens,
                'messages' => [['role' => 'user', 'content' => $content]],
                'model' => (string) config('services.anthropic.model'),
                'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                // Server-side retry on a substitute model if the request is declined.
                'fallbacks' => 'default',
                'betas' => ['server-side-fallback-2026-07-01'],
            ];

            if ($system !== null) {
                $arguments['system'] = $system;
            }

            $message = $this->client()->beta->messages->create(...$arguments);
        } catch (Throwable $exception) {
            report($exception);

            $this->fail(__('The AI service is not reachable right now. Please try again later.'), $exception);
        }

        if ($message->stopReason === 'refusal' || $message->stopReason === 'max_tokens') {
            $this->fail(__('The AI could not process this source.'));
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);

                if (is_array($data)) {
                    return $data;
                }
            }
        }

        $this->fail(__('The AI could not process this source.'));
    }

    protected function fail(string $message, ?Throwable $previous = null): never
    {
        throw new AiException($message, previous: $previous);
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('services.anthropic.key'));
    }
}
