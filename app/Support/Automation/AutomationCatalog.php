<?php

namespace App\Support\Automation;

use App\Models\HouseholdMode;

/**
 * The building blocks of automations – triggers, conditions and actions – with their labels,
 * empty values for the editor, a readable description and ready-made templates.
 */
class AutomationCatalog
{
    public const OPERATORS = ['eq' => '=', 'ne' => '≠', 'gt' => '>', 'lt' => '<'];

    /**
     * @return array<string, array{label: string, icon: string, defaults: array<string, mixed>}>
     */
    public static function triggers(): array
    {
        return [
            'calendar' => ['label' => __('Calendar entry, trip or leave'), 'icon' => '🗓️', 'defaults' => ['keyword' => __('Holiday'), 'sources' => [CalendarMatcher::CALENDAR, CalendarMatcher::TRIPS, CalendarMatcher::LEAVE], 'edge' => 'start']],
            'mode' => ['label' => __('Mode switched'), 'icon' => '🏷️', 'defaults' => ['key' => 'vacation', 'edge' => 'on']],
            'time' => ['label' => __('Time of day'), 'icon' => '⏰', 'defaults' => ['at' => '07:00', 'days' => []]],
            'sun' => ['label' => __('Sunrise or sunset'), 'icon' => '🌅', 'defaults' => ['event' => 'sunset', 'offset' => 0]],
            'appointment' => ['label' => __('Appointment begins soon'), 'icon' => '📅', 'defaults' => ['keyword' => '', 'minutes' => 30]],
            'task_due' => ['label' => __('Task becomes due'), 'icon' => '✅', 'defaults' => ['keyword' => '']],
            'budget' => ['label' => __('Budget reached'), 'icon' => '💶', 'defaults' => ['percent' => 100]],
            'presence' => ['label' => __('Coming and going'), 'icon' => '🧑', 'defaults' => ['state' => 'all_away']],
            'entity' => ['label' => __('Home Assistant value'), 'icon' => '🏠', 'defaults' => ['entity_id' => '', 'operator' => 'eq', 'value' => 'on']],
            'entity_for' => ['label' => __('Device stays in a state (e.g. door open 10 min)'), 'icon' => '🚪', 'defaults' => ['entity_id' => '', 'value' => 'on', 'minutes' => 10]],
            'evcc' => ['label' => __('evcc value'), 'icon' => '🔌', 'defaults' => ['metric' => 'pv', 'operator' => 'gt', 'value' => '3000', 'loadpoint' => 1]],
            'weather' => ['label' => __('Weather today'), 'icon' => '🌦️', 'defaults' => ['metric' => 'rain', 'operator' => 'gt', 'value' => '60']],
            'waste' => ['label' => __('Waste collection tomorrow'), 'icon' => '🗑️', 'defaults' => ['keyword' => __('Waste'), 'at' => '19:00']],
            'mail' => ['label' => __('Mail arrives (e.g. parcel notice)'), 'icon' => '📦', 'defaults' => ['keyword' => __('Parcel')]],
            'deadline' => ['label' => __('Deadline coming up (contracts, warranties …)'), 'icon' => '📑', 'defaults' => ['keyword' => '', 'days' => 14, 'at' => '09:00']],
            'birthday' => ['label' => __('Birthday coming up'), 'icon' => '🎂', 'defaults' => ['days' => 7, 'at' => '09:00']],
            'expiry' => ['label' => __('Best-before date coming up'), 'icon' => '🥫', 'defaults' => ['days' => 2, 'at' => '09:00']],
            'balance' => ['label' => __('Account balance'), 'icon' => '🏦', 'defaults' => ['account' => '', 'operator' => 'lt', 'value' => '500']],
            'manual' => ['label' => __('Only by hand'), 'icon' => '👆', 'defaults' => []],
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string, defaults: array<string, mixed>}>
     */
    public static function conditions(): array
    {
        return [
            'mode' => ['label' => __('Mode is on/off'), 'icon' => '🏷️', 'defaults' => ['key' => 'vacation', 'active' => true]],
            'time_window' => ['label' => __('Between times'), 'icon' => '🕒', 'defaults' => ['from' => '18:00', 'until' => '23:00']],
            'weekdays' => ['label' => __('On weekdays'), 'icon' => '📆', 'defaults' => ['days' => [1, 2, 3, 4, 5]]],
            'sun' => ['label' => __('Dark or light outside'), 'icon' => '🌙', 'defaults' => ['state' => 'dark']],
            'presence' => ['label' => __('Who is at home'), 'icon' => '🧑', 'defaults' => ['state' => 'anyone_home']],
            'calendar' => ['label' => __('Calendar entry today'), 'icon' => '🗓️', 'defaults' => ['keyword' => __('Holiday'), 'sources' => [CalendarMatcher::CALENDAR], 'active' => true]],
            'entity' => ['label' => __('Home Assistant value'), 'icon' => '🏠', 'defaults' => ['entity_id' => '', 'operator' => 'eq', 'value' => 'on']],
            'weather' => ['label' => __('Weather today'), 'icon' => '🌦️', 'defaults' => ['metric' => 'rain', 'operator' => 'gt', 'value' => '60']],
            'any' => ['label' => __('One of these (OR)'), 'icon' => '🔀', 'defaults' => ['conditions' => []]],
        ];
    }

    /**
     * Conditions that can stand inside an "one of these" group (no nested groups).
     *
     * @return array<string, array{label: string, icon: string, defaults: array<string, mixed>}>
     */
    public static function groupConditions(): array
    {
        return array_diff_key(self::conditions(), ['any' => true]);
    }

    /**
     * @return array<string, array{label: string, icon: string, defaults: array<string, mixed>}>
     */
    public static function actions(): array
    {
        return [
            'ha_service' => ['label' => __('Home Assistant: call service'), 'icon' => '🏠', 'defaults' => ['service' => 'light.turn_on', 'entity_id' => '', 'data' => '']],
            'presence_start' => ['label' => __('Simulate presence (lights)'), 'icon' => '💡', 'defaults' => ['entities' => [], 'from' => '18:00', 'until' => '23:30']],
            'presence_stop' => ['label' => __('Stop presence simulation'), 'icon' => '⏹️', 'defaults' => []],
            'evcc_mode' => ['label' => __('evcc: charge mode'), 'icon' => '🔌', 'defaults' => ['loadpoint' => 1, 'mode' => 'pv']],
            'evcc_limit' => ['label' => __('evcc: charge limit'), 'icon' => '🔋', 'defaults' => ['loadpoint' => 1, 'soc' => 80]],
            'evcc_plan' => ['label' => __('evcc: charge by a time'), 'icon' => '🗓️', 'defaults' => ['vehicle' => '', 'soc' => 80, 'time' => '07:00']],
            'delay' => ['label' => __('Wait'), 'icon' => '⏳', 'defaults' => ['minutes' => 15]],
            'mode' => ['label' => __('Switch mode'), 'icon' => '🏷️', 'defaults' => ['key' => 'vacation', 'active' => true]],
            'push' => ['label' => __('Send notification'), 'icon' => '🔔', 'defaults' => ['to' => 'me', 'title' => '', 'body' => '', 'urgent' => false]],
            'ask' => ['label' => __('Ask by push (continue only on “Yes”)'), 'icon' => '❓', 'defaults' => ['to' => 'me', 'title' => '', 'body' => '', 'yes' => __('Yes'), 'no' => __('No'), 'hours' => 2]],
            'task' => ['label' => __('Create task'), 'icon' => '✅', 'defaults' => ['title' => '', 'due' => 'today']],
            'event' => ['label' => __('Calendar entry'), 'icon' => '📅', 'defaults' => ['title' => '', 'day' => 'today', 'time' => '']],
            'shopping' => ['label' => __('Put on the shopping list'), 'icon' => '🛒', 'defaults' => ['items' => '']],
            'email' => ['label' => __('Send email'), 'icon' => '✉️', 'defaults' => ['to' => 'me', 'subject' => '', 'body' => '']],
            'tts' => ['label' => __('Speak on a speaker (TTS)'), 'icon' => '🔊', 'defaults' => ['media_player' => '', 'message' => '', 'engine' => 'tts.home_assistant_cloud']],
            'scene_snapshot' => ['label' => __('Remember or restore devices (scene snapshot)'), 'icon' => '📸', 'defaults' => ['mode' => 'save', 'scene' => 'e11_snapshot', 'entities' => '']],
            'webhook' => ['label' => __('Call a webhook'), 'icon' => '🌐', 'defaults' => ['url' => '', 'body' => '']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function evccMetrics(): array
    {
        return [
            'pv' => __('PV power (W)'),
            'home' => __('Home consumption (W)'),
            'grid' => __('Grid power (W, negative = feed-in)'),
            'battery' => __('Home battery power (W)'),
            'batterySoc' => __('Home battery charge (%)'),
            'tariff' => __('Electricity price (€/kWh)'),
            'vehicleSoc' => __('Car charge (%)'),
            'connected' => __('Car plugged in (1/0)'),
            'charging' => __('Car charging (1/0)'),
        ];
    }

    /**
     * Actions and triggers that need the smart home add-on.
     */
    public const SMART_HOME_ONLY = ['entity', 'entity_for', 'evcc', 'presence', 'ha_service', 'presence_start', 'presence_stop', 'evcc_mode', 'evcc_limit', 'evcc_plan', 'tts', 'scene_snapshot'];

    /** Text fields of actions that may hold placeholders like {reason} (see Placeholders). */
    public const TEXT_FIELDS = ['title', 'body', 'subject', 'message', 'items'];

    /**
     * @return array<string, string>
     */
    public static function weatherMetrics(): array
    {
        return ['rain' => __('Chance of rain (%)'), 'max' => __('Highest temperature (°C)'), 'min' => __('Lowest temperature (°C)')];
    }

    /**
     * @return array<string, string>
     */
    public static function evccModes(): array
    {
        return ['off' => __('Off'), 'now' => __('Fast'), 'minpv' => __('Min+PV'), 'pv' => __('PV only')];
    }

    /**
     * @return array<string, string>
     */
    public static function calendarSources(): array
    {
        return [
            CalendarMatcher::CALENDAR => __('Calendar entries with the word'),
            CalendarMatcher::TRIPS => __('Trips'),
            CalendarMatcher::LEAVE => __('Leave (working time)'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function presenceStates(): array
    {
        return ['all_away' => __('everybody has left'), 'anyone_home' => __('somebody is at home'), 'all_home' => __('everybody is at home')];
    }

    /**
     * One line for lists and the run log.
     *
     * @param  array<string, mixed>  $block
     */
    public static function describe(array $block): string
    {
        $operator = self::OPERATORS[$block['operator'] ?? 'eq'] ?? '=';
        $modes = collect(HouseholdMode::defaults())->map(fn (array $mode) => $mode['name']);
        $mode = $modes[$block['key'] ?? ''] ?? (string) ($block['key'] ?? '');

        return match ($block['type'] ?? '') {
            'calendar' => collect((array) ($block['sources'] ?? []))->map(fn (string $source) => $source === CalendarMatcher::CALENDAR ? '„'.($block['keyword'] ?? '').'“' : self::calendarSources()[$source] ?? $source)->implode(' / ')
                .(isset($block['edge']) ? ' · '.($block['edge'] === 'end' ? __('ends') : __('begins')) : (isset($block['active']) && ! $block['active'] ? ' · '.__('not today') : '')),
            'mode' => $mode.(isset($block['edge']) ? ' · '.($block['edge'] === 'off' ? __('off') : __('on')) : ' · '.((bool) ($block['active'] ?? true) ? __('on') : __('off'))),
            'time' => __('at :time', ['time' => $block['at'] ?? '']),
            'time_window' => ($block['from'] ?? '').'–'.($block['until'] ?? ''),
            'weekdays' => collect((array) ($block['days'] ?? []))->map(fn ($day) => now()->startOfWeek()->addDays((int) $day - 1)->translatedFormat('D'))->implode(', '),
            'entity' => ($block['entity_id'] ?? '').' '.$operator.' '.($block['value'] ?? ''),
            'evcc' => (self::evccMetrics()[$block['metric'] ?? ''] ?? '').' '.$operator.' '.($block['value'] ?? ''),
            'manual' => __('Only by hand'),
            'sun' => isset($block['event'])
                ? (($block['event'] === 'sunrise' ? __('Sunrise') : __('Sunset')).((int) ($block['offset'] ?? 0) !== 0 ? ' '.sprintf('%+d', (int) $block['offset']).' min' : ''))
                : (($block['state'] ?? 'dark') === 'dark' ? __('dark outside') : __('light outside')),
            'appointment' => __(':minutes min before appointments', ['minutes' => $block['minutes'] ?? 30]).(filled($block['keyword'] ?? null) ? ' „'.$block['keyword'].'“' : ''),
            'task_due' => __('Task due').(filled($block['keyword'] ?? null) ? ' „'.$block['keyword'].'“' : ''),
            'budget' => __('A budget reaches :percent %', ['percent' => $block['percent'] ?? 100]),
            'presence' => self::presenceStates()[$block['state'] ?? 'all_away'] ?? '',
            'delay' => __('Wait :minutes min', ['minutes' => $block['minutes'] ?? 15]),
            'evcc_plan' => __('Charge :vehicle to :soc % by :time', ['vehicle' => $block['vehicle'] ?? '', 'soc' => $block['soc'] ?? 80, 'time' => $block['time'] ?? '']),
            'ha_service' => ($block['service'] ?? '').(filled($block['entity_id'] ?? null) ? ' → '.$block['entity_id'] : ''),
            'presence_start' => __('Simulate presence with :count lights (:from–:until)', ['count' => count((array) ($block['entities'] ?? [])), 'from' => $block['from'] ?? '', 'until' => $block['until'] ?? '']),
            'presence_stop' => __('Stop presence simulation'),
            'evcc_mode' => __('Charge mode: :mode', ['mode' => self::evccModes()[$block['mode'] ?? ''] ?? '']),
            'evcc_limit' => __('Charge limit: :soc %', ['soc' => $block['soc'] ?? '']),
            'push' => __('Notification: :title', ['title' => $block['title'] ?? '']),
            'ask' => __('Ask: :title', ['title' => $block['title'] ?? '']),
            'task' => __('Task: :title', ['title' => $block['title'] ?? '']),
            'entity_for' => __(':entity is “:value” for :minutes min', ['entity' => $block['entity_id'] ?? '', 'value' => $block['value'] ?? '', 'minutes' => $block['minutes'] ?? 10]),
            'weather' => (self::weatherMetrics()[$block['metric'] ?? ''] ?? '').' '.$operator.' '.($block['value'] ?? ''),
            'waste' => __('At :time when “:keyword” is entered for tomorrow', ['time' => $block['at'] ?? '', 'keyword' => $block['keyword'] ?? '']),
            'mail' => __('Mail with “:keyword”', ['keyword' => $block['keyword'] ?? '']),
            'deadline' => __(':days days before a deadline', ['days' => $block['days'] ?? 14]).(filled($block['keyword'] ?? null) ? ' „'.$block['keyword'].'“' : ''),
            'birthday' => __(':days days before a birthday', ['days' => $block['days'] ?? 7]),
            'expiry' => __(':days days before the best-before date', ['days' => $block['days'] ?? 2]),
            'balance' => __('Balance').(filled($block['account'] ?? null) ? ' „'.$block['account'].'“' : '').' '.$operator.' '.($block['value'] ?? '').' €',
            'any' => collect((array) ($block['conditions'] ?? []))->map(fn ($condition) => is_array($condition) ? self::describe($condition) : '')->implode(' '.__('or').' ') ?: __('One of these (OR)'),
            'event' => __('Calendar entry: :title', ['title' => $block['title'] ?? '']).(filled($block['time'] ?? null) ? ' · '.$block['time'] : ''),
            'shopping' => __('Shopping list: :items', ['items' => $block['items'] ?? '']),
            'email' => __('Email: :subject', ['subject' => $block['subject'] ?? '']),
            'tts' => __('Say on :player: “:message”', ['player' => $block['media_player'] ?? '', 'message' => $block['message'] ?? '']),
            'scene_snapshot' => ($block['mode'] ?? 'save') === 'restore' ? __('Restore snapshot :scene', ['scene' => $block['scene'] ?? '']) : __('Remember devices as :scene', ['scene' => $block['scene'] ?? '']),
            'webhook' => __('Webhook: :host', ['host' => parse_url((string) ($block['url'] ?? ''), PHP_URL_HOST) ?: '']),
            default => (string) ($block['type'] ?? ''),
        };
    }

    /**
     * Ready-made automations to start from.
     *
     * @return array<string, array{name: string, icon: string, description: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}>
     */
    public static function templates(): array
    {
        return [
            'vacation_on' => [
                'name' => __('Holiday begins → holiday mode'), 'icon' => '🏖️',
                'description' => __('When “Holiday”, a trip or leave is entered for today, holiday mode is switched on.'),
                'trigger' => ['type' => 'calendar', 'keyword' => __('Holiday'), 'sources' => [CalendarMatcher::CALENDAR, CalendarMatcher::TRIPS, CalendarMatcher::LEAVE], 'edge' => 'start'],
                'conditions' => [],
                'actions' => [['type' => 'mode', 'key' => 'vacation', 'active' => true]],
            ],
            'vacation_off' => [
                'name' => __('Holiday ends → holiday mode off'), 'icon' => '🏡',
                'description' => __('When nothing is entered any more, holiday mode is switched off again.'),
                'trigger' => ['type' => 'calendar', 'keyword' => __('Holiday'), 'sources' => [CalendarMatcher::CALENDAR, CalendarMatcher::TRIPS, CalendarMatcher::LEAVE], 'edge' => 'end'],
                'conditions' => [],
                'actions' => [['type' => 'mode', 'key' => 'vacation', 'active' => false]],
            ],
            'presence' => [
                'name' => __('Holiday mode → simulate presence'), 'icon' => '💡',
                'description' => __('In holiday mode, lights go on and off in the evening as if someone were at home.'),
                'trigger' => ['type' => 'mode', 'key' => 'vacation', 'edge' => 'on'],
                'conditions' => [],
                'actions' => [['type' => 'presence_start', 'entities' => [], 'from' => '18:00', 'until' => '23:30'], ['type' => 'push', 'to' => 'household', 'title' => __('Holiday mode is on'), 'body' => __('Presence is simulated in the evening.'), 'urgent' => false]],
            ],
            'presence_off' => [
                'name' => __('Holiday mode off → stop simulation'), 'icon' => '⏹️',
                'description' => __('Back home: the presence simulation stops and the lights go off.'),
                'trigger' => ['type' => 'mode', 'key' => 'vacation', 'edge' => 'off'],
                'conditions' => [],
                'actions' => [['type' => 'presence_stop']],
            ],
            'pv_surplus' => [
                'name' => __('PV surplus → notification'), 'icon' => '☀️',
                'description' => __('When the PV system delivers more than 4 kW, you get a tip to switch on the dishwasher or washing machine.'),
                'trigger' => ['type' => 'evcc', 'metric' => 'pv', 'operator' => 'gt', 'value' => '4000'],
                'conditions' => [['type' => 'time_window', 'from' => '09:00', 'until' => '17:00']],
                'actions' => [['type' => 'push', 'to' => 'me', 'title' => __('☀️ Plenty of sun power'), 'body' => __('Good time for the dishwasher or washing machine.'), 'urgent' => false]],
            ],
            'appointment_push' => [
                'name' => __('Appointment in 30 minutes → reminder'), 'icon' => '📅',
                'description' => __('Half an hour before each appointment with a time you get a notification.'),
                'trigger' => ['type' => 'appointment', 'keyword' => '', 'minutes' => 30],
                'conditions' => [],
                'actions' => [['type' => 'push', 'to' => 'me', 'title' => __('📅 Appointment in 30 minutes'), 'body' => '', 'urgent' => true]],
            ],
            'all_away' => [
                'name' => __('Everybody has left → nobody-at-home mode'), 'icon' => '🚪',
                'description' => __('When all people tracked in Home Assistant have left, the mode “Nobody at home” is switched on.'),
                'trigger' => ['type' => 'presence', 'state' => 'all_away'],
                'conditions' => [],
                'actions' => [['type' => 'mode', 'key' => 'away', 'active' => true]],
            ],
            'away_ask_lights' => [
                'name' => __('Everybody has left → ask whether to switch off the lights'), 'icon' => '❓',
                'description' => __('When the last person leaves, you get a push with “Switch off” / “Leave on” – the lights go off only after “Switch off”.'),
                'trigger' => ['type' => 'presence', 'state' => 'all_away'],
                'conditions' => [],
                'actions' => [
                    ['type' => 'ask', 'to' => 'me', 'title' => __('Everybody is out – switch off all lights?'), 'body' => '', 'yes' => __('Switch off'), 'no' => __('Leave on'), 'hours' => 2],
                    ['type' => 'ha_service', 'service' => 'light.turn_off', 'entity_id' => 'all', 'data' => ''],
                ],
            ],
            'sunset_lights' => [
                'name' => __('Sunset while somebody is at home → lights on'), 'icon' => '🌅',
                'description' => __('At sunset a light or scene in Home Assistant is switched on, if somebody is at home.'),
                'trigger' => ['type' => 'sun', 'event' => 'sunset', 'offset' => 0],
                'conditions' => [['type' => 'presence', 'state' => 'anyone_home']],
                'actions' => [['type' => 'ha_service', 'service' => 'light.turn_on', 'entity_id' => '', 'data' => '']],
            ],
            'budget_push' => [
                'name' => __('Budget used up → notification'), 'icon' => '💶',
                'description' => __('When a budget reaches 100 % this month, the whole household gets a notification.'),
                'trigger' => ['type' => 'budget', 'percent' => 100],
                'conditions' => [],
                'actions' => [['type' => 'push', 'to' => 'household', 'title' => __('💶 Budget used up'), 'body' => __('Have a look at the budgets in Finances.'), 'urgent' => false]],
            ],
            'car_night' => [
                'name' => __('Car plugged in in the evening → charge to 80 %'), 'icon' => '🚗',
                'description' => __('When the car is plugged in in the evening, evcc charges it to 80 % with the fast mode.'),
                'trigger' => ['type' => 'evcc', 'metric' => 'connected', 'operator' => 'eq', 'value' => '1'],
                'conditions' => [['type' => 'time_window', 'from' => '18:00', 'until' => '23:59']],
                'actions' => [['type' => 'evcc_limit', 'loadpoint' => 1, 'soc' => 80], ['type' => 'evcc_mode', 'loadpoint' => 1, 'mode' => 'minpv']],
            ],
        ];
    }

    /**
     * A block as it is stored: only the fields of its type (missing ones filled with the
     * defaults), flags as booleans and numbers as integers; null for an unknown type.
     *
     * @param  array<mixed>  $block
     * @param  'trigger'|'condition'|'group'|'action'  $kind
     * @return array<string, mixed>|null
     */
    public static function clean(array $block, string $kind): ?array
    {
        $catalog = match ($kind) {
            'trigger' => self::triggers(),
            'condition' => self::conditions(),
            'group' => self::groupConditions(),
            default => self::actions(),
        };
        $type = is_string($block['type'] ?? null) ? $block['type'] : '';

        if (! isset($catalog[$type])) {
            return null;
        }

        $defaults = $catalog[$type]['defaults'];
        $clean = ['type' => $type];

        foreach ($defaults as $key => $default) {
            $value = $block[$key] ?? $default;
            $clean[$key] = match (true) {
                $key === 'conditions' => array_slice(array_values(array_filter(array_map(fn ($condition) => is_array($condition) ? self::clean($condition, 'group') : null, is_array($value) ? $value : []))), 0, 10),
                is_array($default) => is_array($value) ? array_values($value) : $default,
                is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                is_int($default) => is_numeric($value) ? (int) $value : $default,
                default => is_scalar($value) ? (string) $value : (string) $default,
            };
        }

        return $clean;
    }

    /**
     * Sets of automations installed with one click; blocks with an empty device field are
     * installed paused until a device is chosen.
     *
     * @return array<string, array{name: string, icon: string, description: string, automations: list<array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}>}>
     */
    public static function packages(): array
    {
        $templates = self::templates();
        $pick = fn (string ...$keys) => array_map(fn (string $key) => array_intersect_key($templates[$key], array_flip(['name', 'trigger', 'conditions', 'actions'])), $keys);
        $lightsOff = ['type' => 'ha_service', 'service' => 'light.turn_off', 'entity_id' => 'all', 'data' => ''];

        return [
            'holiday' => [
                'name' => __('Holiday'), 'icon' => '🏖️',
                'description' => __('Holiday mode from the calendar, presence simulation in the evening and a push when it starts and ends.'),
                'automations' => $pick('vacation_on', 'vacation_off', 'presence', 'presence_off'),
            ],
            'morning' => [
                'name' => __('Morning'), 'icon' => '☀️',
                'description' => __('Good-morning push with the weather on weekdays, a rain warning and a push before appointments.'),
                'automations' => [
                    ['name' => __('Good morning'), 'trigger' => ['type' => 'time', 'at' => '06:45', 'days' => [1, 2, 3, 4, 5]], 'conditions' => [], 'actions' => [['type' => 'push', 'to' => 'me', 'title' => __('☀️ Good morning'), 'body' => '{weather}', 'urgent' => false]]],
                    ['name' => __('Rain today → take an umbrella'), 'trigger' => ['type' => 'weather', 'metric' => 'rain', 'operator' => 'gt', 'value' => '60'], 'conditions' => [['type' => 'time_window', 'from' => '06:00', 'until' => '10:00']], 'actions' => [['type' => 'push', 'to' => 'household', 'title' => __('☔ Rain today'), 'body' => '{weather}', 'urgent' => false]]],
                    ...$pick('appointment_push'),
                ],
            ],
            'night' => [
                'name' => __('Good night'), 'icon' => '🌙',
                'description' => __('Asks at half past ten whether all lights should go off, switches on night mode and reminds of the bins the evening before.'),
                'automations' => [
                    ['name' => __('Good night → lights off?'), 'trigger' => ['type' => 'time', 'at' => '22:30', 'days' => []], 'conditions' => [['type' => 'presence', 'state' => 'anyone_home']], 'actions' => [
                        ['type' => 'ask', 'to' => 'me', 'title' => __('Good night – switch off all lights?'), 'body' => '', 'yes' => __('Switch off'), 'no' => __('Leave on'), 'hours' => 1],
                        $lightsOff,
                        ['type' => 'mode', 'key' => 'night', 'active' => true],
                    ]],
                    ['name' => __('Morning → night mode off'), 'trigger' => ['type' => 'time', 'at' => '07:00', 'days' => []], 'conditions' => [], 'actions' => [['type' => 'mode', 'key' => 'night', 'active' => false]]],
                    ['name' => __('Bins out tonight'), 'trigger' => ['type' => 'waste', 'keyword' => __('Waste'), 'at' => '19:00'], 'conditions' => [], 'actions' => [['type' => 'push', 'to' => 'household', 'title' => __('🗑️ Put the bins out'), 'body' => '{reason}', 'urgent' => false]]],
                ],
            ],
            'guests' => [
                'name' => __('Guests'), 'icon' => '🧳',
                'description' => __('Guest mode pauses the presence simulation and the “everybody has left” automations, and greets the household.'),
                'automations' => [
                    ['name' => __('Guests arrive'), 'trigger' => ['type' => 'mode', 'key' => 'guests', 'edge' => 'on'], 'conditions' => [], 'actions' => [['type' => 'presence_stop'], ['type' => 'mode', 'key' => 'away', 'active' => false], ['type' => 'push', 'to' => 'household', 'title' => __('🧳 Guest mode is on'), 'body' => __('Away automations are paused.'), 'urgent' => false]]],
                    ['name' => __('Everybody has left (not with guests)'), 'trigger' => ['type' => 'presence', 'state' => 'all_away'], 'conditions' => [['type' => 'mode', 'key' => 'guests', 'active' => false]], 'actions' => [['type' => 'mode', 'key' => 'away', 'active' => true]]],
                ],
            ],
            'energy' => [
                'name' => __('Saving energy'), 'icon' => '⚡',
                'description' => __('Push when there is plenty of sun power or electricity is cheap, charge the car in the evening and switch off the lights when everybody has left.'),
                'automations' => [
                    ...$pick('pv_surplus', 'car_night', 'away_ask_lights'),
                    ['name' => __('Cheap electricity'), 'trigger' => ['type' => 'evcc', 'metric' => 'tariff', 'operator' => 'lt', 'value' => '0.20', 'loadpoint' => 1], 'conditions' => [], 'actions' => [['type' => 'push', 'to' => 'me', 'title' => __('⚡ Electricity is cheap now'), 'body' => '{reason}', 'urgent' => false]]],
                ],
            ],
            'security' => [
                'name' => __('Security'), 'icon' => '🛡️',
                'description' => __('Push when a door stays open, when a window is open while it rains and when a parcel notice arrives; nobody at home switches on away mode.'),
                'automations' => [
                    ['name' => __('Door open for 10 minutes'), 'trigger' => ['type' => 'entity_for', 'entity_id' => '', 'value' => 'on', 'minutes' => 10], 'conditions' => [], 'actions' => [['type' => 'push', 'to' => 'household', 'title' => __('🚪 Door still open'), 'body' => '{reason}', 'urgent' => true]]],
                    ['name' => __('Rain and a window open'), 'trigger' => ['type' => 'weather', 'metric' => 'rain', 'operator' => 'gt', 'value' => '70'], 'conditions' => [['type' => 'entity', 'entity_id' => '', 'operator' => 'eq', 'value' => 'on']], 'actions' => [['type' => 'push', 'to' => 'household', 'title' => __('🪟 Rain expected – a window is open'), 'body' => '', 'urgent' => true]]],
                    ['name' => __('Parcel notice'), 'trigger' => ['type' => 'mail', 'keyword' => __('Parcel')], 'conditions' => [], 'actions' => [['type' => 'push', 'to' => 'household', 'title' => __('📦 Parcel on its way'), 'body' => '{reason}', 'urgent' => false]]],
                    ...$pick('all_away'),
                ],
            ],
        ];
    }

    /**
     * Whether a block still needs a device chosen (an empty entity field).
     *
     * @param  array<string, mixed>  $block
     */
    public static function needsDevice(array $block): bool
    {
        return (array_key_exists('entity_id', $block) && blank($block['entity_id']) && ($block['type'] ?? '') !== 'ha_service')
            || collect((array) ($block['conditions'] ?? []))->contains(fn ($condition) => is_array($condition) && self::needsDevice($condition));
    }
}
