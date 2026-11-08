<?php

namespace App\Support;

use Anthropic\Bedrock\MantleClient;
use Anthropic\Client;
use Throwable;

/**
 * Base for Claude API calls whose answers are forced into a JSON schema. Runs either against the
 * Anthropic API (USA) or AWS Bedrock (e.g. an EU region) depending on services.anthropic.provider.
 */
abstract class StructuredAi
{
    /** The AiFeatures switch of the subclass; '' for none. */
    protected const FEATURE = '';

    public function __construct(private ?Client $client = null) {}

    /**
     * Whether a provider is set up and the function of the subclass is not switched off.
     */
    public static function isConfigured(): bool
    {
        if (static::FEATURE !== '' && ! AiFeatures::enabled(static::FEATURE)) {
            return false;
        }

        return self::providerConfigured();
    }

    /**
     * Whether the chosen provider has the credentials it needs: an Anthropic key, or – for
     * Bedrock – a region and model (the AWS credentials may come from the environment).
     */
    public static function providerConfigured(): bool
    {
        if (self::usesBedrock()) {
            return filled(config('services.anthropic.bedrock.region')) && filled(config('services.anthropic.bedrock.model'));
        }

        return filled(config('services.anthropic.key'));
    }

    private static function usesBedrock(): bool
    {
        return config('services.anthropic.provider') === 'bedrock';
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function ask(array $content, array $schema, int $maxTokens, ?string $system = null): array
    {
        if (! self::providerConfigured()) {
            $this->fail(__('The AI is not set up on this server (ANTHROPIC_API_KEY).'));
        }

        if (! static::isConfigured()) {
            $this->fail(__('This AI function has been switched off by the administrator.'));
        }

        try {
            $arguments = [
                'maxTokens' => $maxTokens,
                'messages' => [['role' => 'user', 'content' => $content]],
                'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::strictSchema($schema)]],
            ];

            if ($system !== null) {
                $arguments['system'] = $system;
            }

            if (self::usesBedrock()) {
                $arguments['model'] = (string) config('services.anthropic.bedrock.model');
                $message = $this->bedrockClient()->messages->create(...$arguments);
            } else {
                $arguments['model'] = (string) config('services.anthropic.model');
                // Server-side retry on a substitute model if the request is declined (Anthropic API only).
                $arguments['fallbacks'] = 'default';
                $arguments['betas'] = ['server-side-fallback-2026-07-01'];
                $message = $this->anthropicClient()->beta->messages->create(...$arguments);
            }
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

    /**
     * Anthropic's structured output rejects a schema whose every `object` does not explicitly set
     * `additionalProperties: false`. This walks the schema and adds it wherever it is missing, so
     * each feature only has to declare its own properties.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function strictSchema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = self::strictSchema($value);
            }
        }

        if (($schema['type'] ?? null) === 'object' && ! array_key_exists('additionalProperties', $schema)) {
            $schema['additionalProperties'] = false;
        }

        return $schema;
    }

    private function anthropicClient(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * Bedrock Mantle client; empty access keys fall back to the server's AWS credentials.
     */
    private function bedrockClient(): MantleClient
    {
        $access = (string) config('services.anthropic.bedrock.access_key');
        $secret = (string) config('services.anthropic.bedrock.secret_key');
        $region = (string) config('services.anthropic.bedrock.region');

        return new MantleClient(
            awsAccessKey: $access !== '' ? $access : null,
            awsSecretAccessKey: $secret !== '' ? $secret : null,
            awsRegion: $region !== '' ? $region : null,
        );
    }
}
