{{-- Collection settings: details, properties and form builder (variables from collections/show). --}}
            {{-- Settings: collection details + properties --}}
            @if ($showSettings)
                <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-5 space-y-5">
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
                            <select wire:model="collectionDefaultView" id="collectionDefaultView" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-xs">
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

                    @unless ($collection->isTasks())
                        <div class="rounded-md bg-emerald-50 p-3 text-sm dark:bg-emerald-900/30">
                            @if ($collection->public_token)
                                <p class="text-gray-700 dark:text-gray-300">🔗 {{ __('Anyone with this link can view the entries (read-only, without private entries, comments and files).') }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                                    <x-text-input readonly value="{{ route('collections.public', $collection->public_token) }}" class="block min-w-0 flex-1 text-sm" aria-label="{{ __('Public link') }}" x-on:focus="$el.select()" />
                                    <x-secondary-button type="button" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from(route('collections.public', $collection->public_token)) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"><span x-text="copied ? @js(__('Copied!')) : @js(__('Copy'))"></span></x-secondary-button>
                                    <button type="button" wire:click="revokeCollectionLink" wire:confirm="{{ __('Turn off the public link?') }}" class="text-red-600 underline dark:text-red-400">{{ __('Turn off link') }}</button>
                                </div>
                                <x-share-expiry :expires="$collection->public_expires_at" action="setCollectionLinkExpiry" class="mt-2" />
                            @else
                                <button type="button" wire:click="createCollectionLink" class="text-emerald-700 underline dark:text-emerald-300">🔗 {{ __('Share read-only via link') }}</button>
                            @endif
                        </div>
                    @endunless

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
                                            <button type="button" wire:click="moveProperty({{ $property->id }}, -1)" @disabled($loop->first) class="rounded-sm px-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700" aria-label="{{ __('Move up') }}">↑</button>
                                            <button type="button" wire:click="moveProperty({{ $property->id }}, 1)" @disabled($loop->last) class="rounded-sm px-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700" aria-label="{{ __('Move down') }}">↓</button>
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
                                                <input type="checkbox" @checked($property->required) wire:change="setPropertyRequired({{ $property->id }}, $event.target.checked)" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
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
                                        @if ($property->remindDays() !== null)
                                            <label class="flex items-center gap-2 ps-2 text-xs text-gray-600 dark:text-gray-400">
                                                <input type="checkbox" wire:click="togglePropertyTask({{ $property->id }})" @checked($property->createsTask()) class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-900">
                                                ✅ {{ __('Also create a task') }}
                                            </label>
                                        @endif
                                    @endif
                                    @if ($property->type === \App\Models\CollectionProperty::TYPE_FORMULA)
                                        @php
                                            $numericColumns = $properties
                                                ->filter(fn ($p) => in_array($p->type, [\App\Models\CollectionProperty::TYPE_NUMBER, \App\Models\CollectionProperty::TYPE_RATING, \App\Models\CollectionProperty::TYPE_PROGRESS], true))
                                                ->pluck('name');
                                        @endphp
                                        <div class="flex flex-col gap-1 ps-2">
                                            <label class="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                                ƒ {{ __('Formula') }}
                                                <input type="text" value="{{ $property->formulaExpression() }}" wire:change="setPropertyFormula({{ $property->id }}, $event.target.value)" placeholder="{{ __('e.g. {Price} * {Quantity}') }}" aria-label="{{ __('Formula') }}" class="min-w-[14rem] flex-1 rounded-md border-gray-300 py-0.5 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                            </label>
                                            <p class="text-xs text-gray-400">
                                                {{ __('Use + − × ÷ and ( ). Reference columns in braces:') }}
                                                @if ($numericColumns->isNotEmpty())
                                                    <span class="font-mono">{{ $numericColumns->map(fn ($n) => '{'.$n.'}')->implode(' ') }}</span>
                                                @else
                                                    {{ __('(add number, rating or progress columns first)') }}
                                                @endif
                                            </p>
                                        </div>
                                    @endif
                                    @if ($property->type === \App\Models\CollectionProperty::TYPE_ROLLUP)
                                        @php
                                            $relationColumns = $properties->where('type', \App\Models\CollectionProperty::TYPE_RELATION);
                                            $rollup = $property->rollupConfig();
                                        @endphp
                                        <div class="flex flex-col gap-1 ps-2">
                                            @if ($relationColumns->isEmpty())
                                                <p class="text-xs text-amber-700 dark:text-amber-300">{{ __('Add a relation column first; a rollup aggregates linked entries.') }}</p>
                                            @else
                                                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                                    Σ {{ __('Rollup:') }}
                                                    <select wire:change="setRollupOption({{ $property->id }}, 'aggregate', $event.target.value)" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-0.5 text-xs">
                                                        @foreach (['sum' => __('Sum'), 'avg' => __('Average'), 'min' => __('Minimum'), 'max' => __('Maximum'), 'count' => __('Count')] as $value => $label)
                                                            <option value="{{ $value }}" @selected($rollup['aggregate'] === $value)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    {{ __('via') }}
                                                    <select wire:change="setRollupOption({{ $property->id }}, 'relation', $event.target.value)" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-0.5 text-xs">
                                                        <option value="">{{ __('— relation —') }}</option>
                                                        @foreach ($relationColumns as $relation)
                                                            <option value="{{ $relation->id }}" @selected($rollup['relation'] === $relation->id)>{{ $relation->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    @if ($rollup['aggregate'] !== 'count')
                                                        {{ __('of') }}
                                                        <input type="text" value="{{ $rollup['target'] }}" wire:change="setRollupOption({{ $property->id }}, 'target', $event.target.value)" placeholder="{{ __('target column name') }}" aria-label="{{ __('Target column') }}" class="w-36 rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                                    @endif
                                                </div>
                                                <p class="text-xs text-gray-400">{{ __('Aggregates the linked entries of the chosen relation; for sum/average/min/max name a number column on those entries.') }}</p>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($property->type === \App\Models\CollectionProperty::TYPE_NUMBER)
                                        <label class="flex flex-wrap items-center gap-2 ps-2 text-xs text-gray-600 dark:text-gray-400">
                                            Σ {{ __('Summary under the table') }}
                                            <select wire:change="setPropertySummary({{ $property->id }}, $event.target.value)" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-0.5 text-xs">
                                                @foreach (['sum' => __('Sum'), 'avg' => __('Average'), 'min' => __('Minimum'), 'max' => __('Maximum'), 'count' => __('Count')] as $value => $label)
                                                    <option value="{{ $value }}" @selected($property->summaryFunction() === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                    @if ($property->hasOptions())
                                        <div class="flex flex-wrap items-center gap-2 ps-2">
                                            @foreach ($property->selectOptions() as $option)
                                                <span wire:key="option-{{ $property->id }}-{{ $option['id'] }}" class="inline-flex items-center gap-1">
                                                    <x-option-badge :option="$option" />
                                                    <button type="button" x-data x-on:click="const name = window.prompt(@js(__('Rename option:')), @js($option['name'])); if (name) { $wire.renameOption({{ $property->id }}, @js($option['id']), name) }" class="text-xs text-gray-400 hover:text-gray-700" title="{{ __('Rename') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                                                    @unless ($property->isSystem())
                                                        <button type="button" wire:click="deleteOption({{ $property->id }}, @js($option['id']))" wire:confirm="{{ __('Delete this option?') }}" class="text-xs text-gray-400 hover:text-red-600" title="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
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
                                <select wire:model="newPropertyType" id="newPropertyType" class="mt-1 block rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-xs text-sm">
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
