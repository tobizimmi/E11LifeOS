<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Support\AiException;
use App\Support\BrainAi;
use App\Support\BrainSearch;
use App\Support\Links;
use App\Support\NoteMarkdown;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    private const MAX_QUESTIONS_PER_HOUR = 30;

    private const KEPT_ANSWERS = 10;

    #[Url(as: 'q', except: '')]
    public string $question = '';

    /**
     * Questions and answers of this visit, newest first.
     *
     * @var array<int, array{question: string, answer: string, sources: array<int, string>}>
     */
    public array $conversation = [];

    public ?string $failure = null;

    /**
     * Finds the member's most relevant pages, entries and recipes and lets Claude answer from them.
     */
    public function ask(BrainAi $ai): void
    {
        $this->failure = null;
        $this->validate(['question' => ['required', 'string', 'min:3', 'max:1000']]);

        $limiterKey = 'brain-ask:'.$this->currentUser()->id;

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_QUESTIONS_PER_HOUR)) {
            $this->failure = __('Too many questions in a short time. Please try again in a few minutes.');

            return;
        }

        RateLimiter::hit($limiterKey, 3600);
        $question = trim($this->question);
        $documents = BrainSearch::documents($question, $this->currentUser(), (int) $this->householdId);

        if ($documents === []) {
            $this->failure = __('Your Second Brain is still empty – add pages, tasks or entries first.');

            return;
        }

        try {
            $result = $ai->answer($question, $documents);
        } catch (AiException $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        array_unshift($this->conversation, ['question' => $question, 'answer' => $result['answer'], 'sources' => $result['sources']]);
        $this->conversation = array_slice($this->conversation, 0, self::KEPT_ANSWERS);
        $this->reset('question');
    }

    public function with(): array
    {
        $user = $this->currentUser();

        return [
            'aiAvailable' => BrainAi::isConfigured(),
            'answers' => collect($this->conversation)->map(fn (array $turn) => [
                'question' => (string) ($turn['question'] ?? ''),
                'html' => NoteMarkdown::plainToHtml((string) ($turn['answer'] ?? '')),
                'sources' => array_values(Links::nodes(
                    collect((array) ($turn['sources'] ?? []))->map(fn ($key) => Links::parseKey($key))->filter()->all(),
                    $user,
                    (int) $this->householdId,
                )),
            ])->all(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            ✨ {{ __('Ask your Second Brain') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (! $aiAvailable)
                <p class="rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-sm text-amber-800 dark:text-amber-200">{{ __('The AI is not set up on this server (ANTHROPIC_API_KEY).') }}</p>
            @else
                <form wire:submit="ask" class="rounded-lg bg-white dark:bg-gray-800 p-4 shadow-sm space-y-3">
                    <label for="brain-question" class="sr-only">{{ __('Your question') }}</label>
                    <div class="flex flex-wrap sm:flex-nowrap gap-2">
                        <x-text-input wire:model="question" id="brain-question" class="block w-full sm:flex-1" autofocus
                            placeholder="{{ __('e.g. When does the car insurance end? What did we plan for the holiday?') }}" />
                        <x-voice-button for="brain-question" submit />
                        <x-primary-button class="flex-1 sm:flex-none justify-center" wire:loading.attr="disabled" wire:target="ask">{{ __('Ask') }}</x-primary-button>
                    </div>
                    <x-input-error :messages="$errors->get('question')" />
                    <p wire:loading wire:target="ask" class="text-sm text-gray-500 dark:text-gray-400">{{ __('Searching your pages, tasks, entries and recipes …') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Only content you can see is used. The matching pages, entries and recipes are sent to the Claude API (Anthropic) to write the answer.') }}</p>
                </form>

                @if ($failure)
                    <p class="rounded-md bg-red-50 dark:bg-red-900/30 p-3 text-sm text-red-700 dark:text-red-300" role="alert">{{ $failure }}</p>
                @endif

                @foreach ($answers as $index => $turn)
                    <article wire:key="brain-answer-{{ count($answers) - $index }}" class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm space-y-3">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">„{{ $turn['question'] }}“</p>
                        <div class="note-content">{!! $turn['html'] !!}</div>
                        @if ($turn['sources'] !== [])
                            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 dark:border-gray-700 pt-3">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Sources') }}:</span>
                                @foreach ($turn['sources'] as $node)
                                    <a href="{{ $node['url'] }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-gray-100 dark:bg-gray-700 px-2.5 py-1 text-xs text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-gray-600">
                                        <span>{{ $node['icon'] }}</span>
                                        <span class="max-w-[14rem] truncate">{{ $node['title'] }}</span>
                                        <span class="text-gray-400">· {{ $node['kind'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach
            @endif
        </div>
    </div>
</div>
