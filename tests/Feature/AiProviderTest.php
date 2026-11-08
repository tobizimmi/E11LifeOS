<?php

namespace Tests\Feature;

use App\Support\BrainAi;
use App\Support\StructuredAi;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    #[Test]
    public function the_schema_gets_additional_properties_false_on_every_object(): void
    {
        // Anthropic structured output rejects objects without additionalProperties:false.
        $strict = StructuredAi::strictSchema([
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'recipes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['name' => ['type' => 'string']],
                    ],
                ],
                'meta' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ]);

        $this->assertFalse($strict['additionalProperties']);
        $this->assertFalse($strict['properties']['recipes']['items']['additionalProperties']);
        // A string property is left untouched; an explicit setting is kept.
        $this->assertArrayNotHasKey('additionalProperties', $strict['properties']['title']);
        $this->assertTrue($strict['properties']['meta']['additionalProperties']);
    }

    #[Test]
    public function the_anthropic_provider_needs_an_api_key(): void
    {
        config(['services.anthropic.provider' => 'anthropic', 'services.anthropic.disabled_features' => '']);

        config(['services.anthropic.key' => null]);
        $this->assertFalse(BrainAi::providerConfigured());
        $this->assertFalse(BrainAi::isConfigured());

        config(['services.anthropic.key' => 'sk-ant-test']);
        $this->assertTrue(BrainAi::providerConfigured());
        $this->assertTrue(BrainAi::isConfigured());
    }

    #[Test]
    public function the_bedrock_provider_needs_a_region_and_model_but_no_anthropic_key(): void
    {
        config([
            'services.anthropic.provider' => 'bedrock',
            'services.anthropic.key' => null,
            'services.anthropic.disabled_features' => '',
            'services.anthropic.bedrock.region' => 'eu-central-1',
            'services.anthropic.bedrock.model' => '',
        ]);

        // Region alone is not enough.
        $this->assertFalse(BrainAi::providerConfigured());

        config(['services.anthropic.bedrock.model' => 'eu.anthropic.claude-sonnet-5']);
        $this->assertTrue(BrainAi::providerConfigured());
        $this->assertTrue(BrainAi::isConfigured());
    }

    #[Test]
    public function a_switched_off_feature_is_never_configured(): void
    {
        config([
            'services.anthropic.provider' => 'bedrock',
            'services.anthropic.bedrock.region' => 'eu-central-1',
            'services.anthropic.bedrock.model' => 'eu.anthropic.claude-sonnet-5',
            'services.anthropic.disabled_features' => 'brain',
        ]);

        $this->assertTrue(BrainAi::providerConfigured());
        $this->assertFalse(BrainAi::isConfigured());
    }
}
