<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LivewireMethodNamesTest extends TestCase
{
    /**
     * Livewire's $wire maps these names to its own helpers ("watch" → $watch …), so a component
     * action with such a name is never called from wire:click.
     */
    private const RESERVED = ['on', 'el', 'id', 'js', 'get', 'set', 'call', 'hook', 'commit', 'watch', 'entangle', 'dispatch', 'dispatchTo', 'dispatchSelf', 'upload', 'uploadMultiple', 'removeUpload', 'cancelUpload', 'tap'];

    public function test_no_component_action_uses_a_name_reserved_by_livewire(): void
    {
        $pattern = '/public function ('.implode('|', self::RESERVED).')\s*\(/';
        $offenders = collect([...File::allFiles(resource_path('views/livewire')), ...File::allFiles(app_path('Livewire'))])
            ->filter(fn ($file) => preg_match($pattern, $file->getContents()) === 1)
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $offenders);
    }
}
