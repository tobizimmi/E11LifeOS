@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $statuses = \App\Models\TripParticipant::statuses();
    $fileUrl = fn ($file) => $participant ? route('trips.participant.file', [$participantToken, $file->id]) : route('trips.folder.file', [$token, $file->id]);
    $myCosts = $participant ? ($costs[mb_strtolower(trim($participant->name))] ?? null) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>✈️ {{ $trip->title }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <main class="mx-auto max-w-2xl space-y-5 px-4 py-8">
            <header>
                <h1 class="text-2xl font-semibold">✈️ {{ $trip->title }}</h1>
                <p class="text-sm text-gray-600 dark:text-gray-400">📅 {{ \App\Support\Life\GroupTrip::dates($trip) }}@if ($trip->destination) · 📍 {{ $trip->destination }}@endif</p>
            </header>

            @if ($participant)
                <section class="rounded-2xl bg-white p-5 shadow-xs ring-2 ring-indigo-200 dark:bg-gray-800 dark:ring-indigo-800" aria-labelledby="personal">
                    <h2 id="personal" class="font-semibold">👋 {{ __('Hello :name', ['name' => $participant->name]) }}</h2>
                    @if (session('status'))
                        <p class="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200" role="status">✓ {{ session('status') }}</p>
                    @endif
                    @if (trim((string) $participant->personal_info) !== '')
                        <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $participant->personal_info }}</p>
                    @endif
                    @if ($myCosts)
                        <p class="mt-2 text-sm">
                            {{ __('Your share of the costs: :owed (paid: :paid).', ['owed' => $euro($myCosts['owed']), 'paid' => $euro($myCosts['paid'])]) }}
                            <strong @class(['text-emerald-700 dark:text-emerald-300' => $myCosts['balance'] > 0, 'text-red-700 dark:text-red-300' => $myCosts['balance'] < 0])>
                                {{ abs($myCosts['balance']) < 0.01 ? __('Everything is settled.') : ($myCosts['balance'] > 0 ? __('You get :amount back.', ['amount' => $euro($myCosts['balance'])]) : __('You still owe :amount.', ['amount' => $euro(-$myCosts['balance'])])) }}
                            </strong>
                        </p>
                    @endif
                    <form method="POST" action="{{ route('trips.participant.reply', $participantToken) }}" class="mt-4 space-y-3">
                        @csrf
                        <fieldset>
                            <legend class="text-sm font-medium">{{ __('Are you coming?') }}</legend>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach (['confirmed', 'maybe', 'declined'] as $status)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-gray-300 has-[:checked]:bg-indigo-600 has-[:checked]:text-white has-[:checked]:ring-indigo-600 dark:ring-gray-600">
                                        <input type="radio" name="status" value="{{ $status }}" @checked($participant->status === $status) class="sr-only" required>
                                        {{ $statuses[$status]['icon'] }} {{ $statuses[$status]['label'] }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <label class="block text-sm">{{ __('Message to the organiser (optional)') }}
                            <textarea name="reply" rows="2" maxlength="1000" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900" placeholder="{{ __('e.g. arriving later, vegetarian, bringing a friend') }}">{{ old('reply', $participant->reply) }}</textarea>
                        </label>
                        @error('status')
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('Send reply') }}</button>
                    </form>
                </section>
            @endif

            @if ($trip->meeting_point || $trip->organizer_contact || trim((string) $trip->group_info) !== '')
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h2 class="font-semibold">ℹ️ {{ __('Information') }}</h2>
                    @if ($trip->meeting_point)
                        <p class="mt-2 text-sm">🚩 <strong>{{ __('Meeting point') }}:</strong> {{ $trip->meeting_point }}</p>
                    @endif
                    @if (trim((string) $trip->group_info) !== '')
                        <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $trip->group_info }}</p>
                    @endif
                    @if ($trip->organizer_contact)
                        <p class="mt-2 text-sm">☎️ {{ __('Questions to: :contact', ['contact' => $trip->organizer_contact]) }}</p>
                    @endif
                </section>
            @endif

            @if ($program !== [])
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h2 class="font-semibold">🗓️ {{ __('Programme') }}</h2>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($program as $line)
                            <li>{{ ltrim($line, '• ') }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($plan['days'] !== [])
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="day-plan">
                    <h2 id="day-plan" class="font-semibold">🧭 {{ __('Day plan') }}</h2>
                    <div class="mt-3 space-y-4">
                        @foreach ($plan['days'] as $day)
                            <div>
                                <h3 class="text-sm font-semibold text-indigo-700 dark:text-indigo-300">{{ $day['label'] }}</h3>
                                @include('trips.partials.folder-entries', ['entries' => $day['entries']])
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($plan['ideas'] !== [])
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="ideas">
                    <h2 id="ideas" class="font-semibold">💡 {{ __('Further ideas') }}</h2>
                    @include('trips.partials.folder-entries', ['entries' => $plan['ideas']])
                </section>
            @endif

            @foreach ($pages as $page)
                <details class="group rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 font-semibold">
                        <span>{{ $page->icon ?: \App\Models\Item::DEFAULT_PAGE_ICON }} {{ $page->title }}</span>
                        <span class="text-gray-400 transition group-open:rotate-90" aria-hidden="true">›</span>
                    </summary>
                    <div class="prose prose-sm mt-3 max-w-none dark:prose-invert">{!! \App\Support\Life\GroupTrip::pageHtml($page) !!}</div>
                </details>
            @endforeach

            @if ($links !== [])
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="links">
                    <h2 id="links" class="font-semibold">🔗 {{ __('Web links') }}</h2>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($links as $link)
                            <li><a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 underline dark:text-indigo-400">{{ $link['title'] }} ↗</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($files->isNotEmpty())
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h2 class="font-semibold">📎 {{ __('Documents') }}</h2>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($files as $file)
                            <li><a href="{{ $fileUrl($file) }}" class="text-indigo-600 underline dark:text-indigo-400">{{ $file->title }}</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section id="feedback" class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="feedback-title">
                <h2 id="feedback-title" class="font-semibold">💬 {{ __('Feedback & suggestions') }}</h2>
                @if (session('status') && ! $participant)
                    <p class="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200" role="status">✓ {{ session('status') }}</p>
                @endif

                @if ($comments->get(0, collect())->isNotEmpty())
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($comments->get(0)->take(30) as $comment)
                            <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900/50">
                                <span class="font-medium">{{ $comment->author_name }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $comment->created_at->diffForHumans() }}</span>
                                <span class="block whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $comment->body }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($suggestions->isNotEmpty())
                    <h3 class="mt-4 text-sm font-semibold">💡 {{ __('Suggestions') }}</h3>
                    <ul class="mt-1 space-y-1 text-sm">
                        @foreach ($suggestions as $suggestion)
                            <li class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-medium">{{ $suggestion->title }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('from :name', ['name' => $suggestion->author_name]) }}</span>
                                <span @class(['rounded-full px-2 text-xs', 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200' => $suggestion->status === 'pending', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200' => $suggestion->status === 'approved', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $suggestion->status === 'declined'])>
                                    {{ ['pending' => __('waiting for approval'), 'approved' => __('in the plan'), 'declined' => __('not taken')][$suggestion->status] ?? '' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($errors->hasAny(['name', 'body', 'title', 'type']))
                    <p class="mt-3 text-sm text-red-600 dark:text-red-400" role="alert">{{ $errors->first() }}</p>
                @endif

                <form method="POST" action="{{ $participant ? route('trips.participant.feedback', $participantToken) : route('trips.folder.feedback', $token) }}" class="mt-4 space-y-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                    @csrf
                    <input type="hidden" name="type" value="comment">
                    @unless ($participant)
                        <label class="block text-sm">{{ __('Your name') }}
                            <input type="text" name="name" value="{{ old('name', session('trip_folder.name')) }}" maxlength="100" required class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900">
                        </label>
                    @endunless
                    @if ($feedbackStops !== [])
                        <label class="block text-sm">{{ __('About') }}
                            <select name="stop" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900">
                                <option value="">{{ __('The trip in general') }}</option>
                                @foreach ($feedbackStops as $stop)
                                    <option value="{{ $stop['id'] }}">{{ $stop['title'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="block text-sm">{{ __('Comment') }}
                        <textarea name="body" rows="2" maxlength="2000" required class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900"></textarea>
                    </label>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">💬 {{ __('Send comment') }}</button>
                </form>

                <details class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700" @if (old('type') === 'suggestion') open @endif>
                    <summary class="cursor-pointer text-sm font-semibold text-indigo-700 dark:text-indigo-300">💡 {{ __('Suggest something for the plan') }}</summary>
                    <form method="POST" action="{{ $participant ? route('trips.participant.feedback', $participantToken) : route('trips.folder.feedback', $token) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="type" value="suggestion">
                        <div class="sm:col-span-2">
                    @unless ($participant)
                        <label class="block text-sm">{{ __('Your name') }}
                            <input type="text" name="name" value="{{ old('name', session('trip_folder.name')) }}" maxlength="100" required class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900">
                        </label>
                    @endunless
                        </div>
                        <label class="block text-sm sm:col-span-2">{{ __('What do you suggest?') }}
                            <input type="text" name="title" value="{{ old('title') }}" maxlength="255" required class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900" placeholder="{{ __('e.g. boat trip to the island') }}">
                        </label>
                        <label class="block text-sm">{{ __('Day (optional)') }}
                            <input type="date" name="day" value="{{ old('day') }}" min="{{ $trip->starts_on->toDateString() }}" max="{{ $trip->ends_on->toDateString() }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900">
                        </label>
                        <label class="block text-sm">{{ __('Place (optional)') }}
                            <input type="text" name="location" value="{{ old('location') }}" maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900">
                        </label>
                        <label class="block text-sm sm:col-span-2">{{ __('Why? (optional)') }}
                            <textarea name="body" rows="2" maxlength="2000" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900">{{ old('type') === 'suggestion' ? old('body') : '' }}</textarea>
                        </label>
                        <div class="sm:col-span-2">
                            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('Send suggestion') }}</button>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Suggestions are added to the plan once the organisers approve them.') }}</p>
                        </div>
                    </form>
                </details>
            </section>

            @if ($trip->participants->isNotEmpty())
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h2 class="font-semibold">👥 {{ __('Participants') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count participant confirmed|:count participants confirmed', $trip->participants->where('status', 'confirmed')->count()) }}</p>
                    <ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                        @foreach ($trip->participants as $member)
                            <li>{{ $statuses[$member->status]['icon'] ?? '' }} {{ $member->name }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <p class="text-center text-xs text-gray-400">{{ config('app.name') }}</p>
        </main>
    </body>
</html>
