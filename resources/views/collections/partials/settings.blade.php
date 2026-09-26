{{-- Collection settings: details, properties and form builder (variables from collections/show). --}}
            {{-- Settings: collection details + properties --}}
            @if ($showSettings)
                <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-5 space-y-5">
                    <form wire:submit="saveCollectionDetails" class="flex flex-wrap items-end gap-3">
                        <div class="w-20">
                            <x-input-label for="collectionIcon" :value="__('Icon')" />
                            <x-text-input wire:model="collectionIcon" id="collectionIcon" class="mt-1 block w-full text-center" maxlength="16" placeholder="{{ \App\Models\Collection::DEFAULT_ICON }}" />
                        </div>
                        <div class="flex-1 min-w-[12rem]">
                            <x-input-label for="collectionName" :value="__('Name')" />
                            <x-text-input wire:model="collectionName" id="collectionName" class="mt-1 block w-full" required />
                        </div>
                        <div class="w-44">
                            <x-input-label for="collectionDefaultView" :value="__('Default view')" />
                            <select wire:model="collectionDefaultView" id="collectionDefaultView" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm">
                                <option value="">{{ __('Automatic') }}</option>
                                @foreach (['cards' => __('Cards'), 'list' => __('List'), 'table' => __('Table'), 'board' => __('Board'), 'calendar' => __('Calendar')] as $viewKey => $viewLabel)
                                    <option value="{{ $viewKey }}">{{ $viewLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <x-secondary-button type="button" x-data x-on:click="const name = window.prompt({{ \Illuminate\Support\Js::from(__('Name of the template:')) }}, {{ \Illuminate\Support\Js::from($collection->name) }}); if (name && name.trim()) { $wire.saveAsTemplate(name.trim()) }">⧉ {{ __('Save as template') }}</x-secondary-button>
                        @if ($collection->system_key === null)
                            <x-danger-button type="button" wire:click="deleteCollection" wire:confirm="{{ __('Delete this collection and all its entries?') }}">{{ __('Delete collection') }}</x-danger-button>
                        @endif
                    </form>
                    <x-input-error :messages="$errors->get('collectionName')" />

                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Properties') }}</h3>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($properties as $property)
                                <li wire:key="property-{{ $property->id }}" class="py-2 space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input
                                            type="text"
                                            value="{{ $property->name }}"
                                            aria-label="{{ __('Property name') }}"
                                            wire:change="renameProperty({{ $property->id }}, $event.target.value)"
                                            class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 text-sm"
                                        />
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $propertyTypes[$property->type] ?? $property->type }}</span>
                                        @if ($property->required)
                                            <span class="rounded-full bg-amber-50 px-1.5 text-[11px] text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ __('required') }}</span>
                                        @endif
                                        <span class="ms-auto inline-flex">
                                            <button type="button" wire:click="moveProperty({{ $property->id }}, -1)" @disabled($loop->first) class="rounded px-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700" aria-label="{{ __('Move up') }}">↑</button>
                                            <button type="button" wire:click="moveProperty({{ $property->id }}, 1)" @disabled($loop->last) class="rounded px-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700" aria-label="{{ __('Move down') }}">↓</button>
                                        </span>
                                        @if ($property->isSystem())
                                            <span class="text-xs text-gray-400">({{ __('built-in') }})</span>
                                        @else
                                            <button type="button" wire:click="deleteProperty({{ $property->id }})" wire:confirm="{{ __('Delete this property and its values?') }}" class="text-xs text-red-600 dark:text-red-400 underline">{{ __('Delete') }}</button>
                                        @endif
                                    </div>
                                    @if ($property->type !== \App\Models\CollectionProperty::TYPE_RELATION)
                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 ps-2 text-xs text-gray-600 dark:text-gray-400">
                                            <label class="flex items-center gap-1.5">
                                                <input type="checkbox" @checked($property->required) wire:change="setPropertyRequired({{ $property->id }}, $event.target.checked)" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                                                {{ __('Required field') }}
                                            </label>
                                            <label class="flex items-center gap-1.5">
                                                {{ __('Default') }}
                                                @switch($property->type)
                                                    @case(\App\Models\CollectionProperty::TYPE_DATE)
                                                        <select wire:change="setPropertyDefault({{ $property->id }}, $event.target.value)" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900">
                                                            <option value="">{{ __('none') }}</option>
                                                            @foreach (['today' => __('today'), 'today+7' => __('in 1 week'), 'today+14' => __('in 2 weeks'), 'today+30' => __('in 30 days'), 'today+365' => __('in 1 year')] as $defaultKey => $defaultLabel)
                                                                <option value="{{ $defaultKey }}" @selected($property->default_value === $defaultKey)>{{ $defaultLabel }}</option>
                                                            @endforeach
                                                        </select>
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_SELECT)
                                                        <select wire:change="setPropertyDefault({{ $property->id }}, $event.target.value)" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900">
                                                            <option value="">{{ __('none') }}</option>
                                                            @foreach ($property->selectOptions() as $option)
                                                                <option value="{{ $option['id'] }}" @selected($property->default_value === $option['id'])>{{ $option['name'] }}</option>
                                                            @endforeach
                                                        </select>
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_MULTI_SELECT)
                                                        <select wire:change="setPropertyDefault({{ $property->id }}, $event.target.value ? [$event.target.value] : null)" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900">
                                                            <option value="">{{ __('none') }}</option>
                                                            @foreach ($property->selectOptions() as $option)
                                                                <option value="{{ $option['id'] }}" @selected(in_array($option['id'], (array) $property->default_value, true))>{{ $option['name'] }}</option>
                                                            @endforeach
                                                        </select>
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_PERSON)
                                                        <select wire:change="setPropertyDefault({{ $property->id }}, $event.target.value)" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900">
                                                            <option value="">{{ __('none') }}</option>
                                                            <option value="me" @selected($property->default_value === 'me')>{{ __('the person creating it') }}</option>
                                                            @foreach ($members as $member)
                                                                <option value="{{ $member->id }}" @selected($property->default_value === $member->id)>{{ $member->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_CHECKBOX)
                                                        <select wire:change="setPropertyDefault({{ $property->id }}, $event.target.value)" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900">
                                                            <option value="">{{ __('not ticked') }}</option>
                                                            <option value="1" @selected($property->default_value === true)>{{ __('ticked') }}</option>
                                                        </select>
                                                        @break
                                                    @default
                                                        <input type="{{ $property->type === \App\Models\CollectionProperty::TYPE_NUMBER ? 'number' : 'text' }}" step="any" value="{{ is_scalar($property->default_value) ? $property->default_value : '' }}" wire:change="setPropertyDefault({{ $property->id }}, $event.target.value)" class="w-36 rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Default') }}">
                                                @endswitch
                                            </label>
                                            <input type="text" value="{{ $property->help }}" wire:change="setPropertyHelp({{ $property->id }}, $event.target.value)" placeholder="{{ __('Hint below the field (optional)') }}" aria-label="{{ __('Hint below the field') }}" class="min-w-[12rem] flex-1 rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                        </div>
                                    @endif
                                    @if ($property->type === \App\Models\CollectionProperty::TYPE_DATE && $property->system_key === null)
                                        <label class="flex flex-wrap items-center gap-2 ps-2 text-xs text-gray-600 dark:text-gray-400">
                                            🔔 {{ __('E-mail reminder') }}
                                            <select wire:change="setPropertyReminder({{ $property->id }}, $event.target.value)" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-0.5 text-xs">
                                                <option value="" @selected($property->remindDays() === null)>{{ __('none') }}</option>
                                                @foreach ([0, 1, 3, 7, 14, 30, 60, 90] as $days)
                                                    <option value="{{ $days }}" @selected($property->remindDays() === $days)>{{ $days === 0 ? __('on the day') : trans_choice(':count day before|:count days before', $days) }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                    @if ($property->hasOptions())
                                        <div class="flex flex-wrap items-center gap-2 ps-2">
                                            @foreach ($property->selectOptions() as $option)
                                                <span wire:key="option-{{ $property->id }}-{{ $option['id'] }}" class="inline-flex items-center gap-1">
                                                    <x-option-badge :option="$option" />
                                                    <button type="button" x-data x-on:click="const name = window.prompt(@js(__('Rename option:')), @js($option['name'])); if (name) { $wire.renameOption({{ $property->id }}, @js($option['id']), name) }" class="text-xs text-gray-400 hover:text-gray-700" title="{{ __('Rename') }}">✎</button>
                                                    @unless ($property->isSystem())
                                                        <button type="button" wire:click="deleteOption({{ $property->id }}, @js($option['id']))" wire:confirm="{{ __('Delete this option?') }}" class="text-xs text-gray-400 hover:text-red-600" title="{{ __('Delete') }}">✕</button>
                                                    @endunless
                                                </span>
                                            @endforeach
                                            @unless ($property->isSystem())
                                                <form x-data="{ name: '' }" x-on:submit.prevent="if (name.trim()) { $wire.addOption({{ $property->id }}, name); name = '' }" class="inline-flex">
                                                    <input x-model="name" type="text" placeholder="+ {{ __('Option') }}" aria-label="{{ __('New option') }}" class="w-28 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 py-0.5 text-xs" />
                                                </form>
                                            @endunless
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        <form wire:submit="addProperty" class="mt-3 flex flex-wrap items-end gap-2">
                            <div>
                                <x-input-label for="newPropertyName" :value="__('New property')" />
                                <x-text-input wire:model="newPropertyName" id="newPropertyName" class="mt-1 block" placeholder="{{ __('e.g. Priority') }}" />
                            </div>
                            <div>
                                <x-input-label for="newPropertyType" :value="__('Type')" />
                                <select wire:model="newPropertyType" id="newPropertyType" class="mt-1 block rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-sm text-sm">
                                    @foreach ($propertyTypes as $type => $typeLabel)
                                        <option value="{{ $type }}">{{ $typeLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-primary-button>{{ __('Add') }}</x-primary-button>
                        </form>
                        <x-input-error :messages="$errors->get('newPropertyName')" class="mt-1" />
                    </div>
                </section>
            @endif
