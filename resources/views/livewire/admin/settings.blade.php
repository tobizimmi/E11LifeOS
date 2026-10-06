<?php

use App\Jobs\RunAdminCommand;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\AiFeatures;
use App\Support\Portfolio\InstagramAi;
use App\Support\Security\VirusScanner;
use App\Support\StructuredAi;
use App\Support\Vpn;
use App\Support\WebPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    /**
     * Plain values; secret fields start empty (empty = keep the saved value).
     *
     * @var array<string, string>
     */
    public array $form = [];

    /**
     * Secret fields to remove on save.
     *
     * @var array<string, bool>
     */
    public array $clear = [];

    /**
     * AI functions that stay switched on (AiFeatures keys).
     *
     * @var list<string>
     */
    public array $aiFeatures = [];

    public string $testRecipient = '';

    public string $vpnTestUrl = '';

    /**
     * @var array{ok: bool, message: string}|null
     */
    public ?array $vpnTestResult = null;

    /**
     * @var array{ok: bool, message: string}|null
     */
    public ?array $realtimeTestResult = null;

    /** @var array{ok: bool, message: string}|null */
    public ?array $inboundTestResult = null;

    /**
     * Per import: "*" = all old accounts, "" = the account with the target's e-mail, else an e-mail.
     *
     * @var array<string, string>
     */
    public array $importSource = ['portfolio' => '*', 'recipes' => '*'];

    /**
     * Per import: LifeOS user id that receives the data.
     *
     * @var array<string, string>
     */
    public array $importTarget = ['portfolio' => '', 'recipes' => ''];

    /**
     * Accounts found in the old databases (loaded on demand).
     *
     * @var array<string, list<array{email: string, name: string}>>
     */
    public array $sourceAccounts = ['portfolio' => [], 'recipes' => []];

    private const IMPORTS = [
        'portfolio' => ['command' => 'portfolio:import-legacy', 'connection' => 'portfolio_legacy', 'source_option' => '--legacy-email', 'name_column' => 'username'],
        'recipes' => ['command' => 'recipes:import-rezepteapp', 'connection' => 'rezepteapp', 'source_option' => '--source-email', 'name_column' => 'name'],
    ];

    public function mount(): void
    {
        $this->authorize('admin');
        $this->fillForm();
        $this->testRecipient = Auth::user()->email;
        $this->importTarget = ['portfolio' => (string) Auth::id(), 'recipes' => (string) Auth::id()];
    }

    private function fillForm(): void
    {
        $stored = AppSettings::stored();
        $this->form = collect(AppSettings::FIELDS)
            ->map(fn (array $field, string $key) => $field['secret'] ? '' : ($stored[$key] ?? ''))
            ->all();
        $this->clear = [];
        $this->aiFeatures = array_values(array_diff(array_keys(AiFeatures::all()), AiFeatures::disabled()));
    }

    public function save(): void
    {
        $this->authorize('admin');

        $this->validate([
            'form.ai_provider' => ['nullable', 'in:anthropic,bedrock'],
            'form.mail_port' => ['nullable', 'integer', 'between:1,65535'],
            'form.mail_scheme' => ['nullable', 'in:smtp,smtps'],
            'form.inbound_mail_encryption' => ['nullable', 'in:ssl,tls,none'],
            'form.inbound_mail_domain' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'form.mail_from_address' => ['nullable', 'email'],
            'form.vapid_subject' => ['nullable', 'regex:/^(mailto:|https:\/\/)/'],
            'form.instagram_limit' => ['nullable', 'integer', 'between:1,100'],
            'form.backup_keep' => ['nullable', 'integer', 'between:1,365'],
            'form.backup_remote_url' => ['nullable', 'url:https'],
            'form.require_two_factor' => ['nullable', 'in:0,1'],
            'form.error_report_email' => ['nullable', 'email'],
            'form.idle_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'form.session_lifetime' => ['nullable', 'integer', 'between:5,43200'],
            'form.api_token_idle_days' => ['nullable', 'integer', 'between:0,3650'],
            'form.log_days' => ['nullable', 'integer', 'between:30,3650'],
            'form.session_driver' => ['nullable', 'in:database,file'],
            'form.session_encrypt' => ['nullable', 'in:0,1'],
            'form.clamav_enabled' => ['nullable', 'in:0,1'],
            'form.clamav_fail_closed' => ['nullable', 'in:0,1'],
            'form.clamav_socket' => ['nullable', 'regex:/^(\/|unix:\/\/\/|tcp:\/\/)[^\s]+$/'],
            'form.backup_remote_keep' => ['nullable', 'integer', 'between:1,365'],
            'form.legacy_db_port' => ['nullable', 'integer', 'between:1,65535'],
            'form.rezepteapp_db_port' => ['nullable', 'integer', 'between:1,65535'],
            'aiFeatures.*' => ['in:'.implode(',', array_keys(AiFeatures::all()))],
            'form.maps_tile_url' => ['nullable', 'regex:/^https:\/\/[^\s"\'<>]+\{z\}[^\s"\'<>]*\{x\}[^\s"\'<>]*\{y\}[^\s"\'<>]*$/'],
            'form.reverb_publish_port' => ['nullable', 'integer', 'between:1,65535'],
            'form.vpn_enabled' => ['nullable', 'in:0,1'],
            'form.vpn_socks_port' => ['nullable', 'integer', 'between:1024,65535'],
            'form.vpn_wireguard_config' => ['nullable', 'string', fn ($attribute, $value, $fail) => filled($value) && Vpn::parse((string) $value) === null ? $fail(__('This is not a complete WireGuard configuration ([Interface] with PrivateKey and Address, [Peer] with PublicKey and Endpoint).')) : null],
            // Length limit for the text fields ("max" would compare the value of numeric ones).
            'form.*' => ['nullable', 'string', fn ($attribute, $value, $fail) => mb_strlen((string) $value) > 2000 ? $fail(__('validation.max.string', ['attribute' => $attribute, 'max' => 2000])) : null],
        ], attributes: ['form.mail_from_address' => __('Sender address'), 'form.vapid_subject' => __('Contact')]);

        // "none" (instead of empty) so that "all switched on" also overrides AI_DISABLED_FEATURES in .env.
        $this->form['ai_disabled_features'] = implode(',', array_diff(array_keys(AiFeatures::all()), $this->aiFeatures)) ?: 'none';
        $values = [];

        foreach (AppSettings::FIELDS as $key => $field) {
            $value = (string) ($this->form[$key] ?? '');

            if ($field['secret']) {
                // Secrets are never shown again: empty keeps the saved value unless "remove" is ticked.
                $values[$key] = ($this->clear[$key] ?? false) ? '' : ($value === '' ? null : $value);
            } else {
                $values[$key] = $value;
            }
        }

        AppSettings::save($values);
        $this->fillForm();
        $this->dispatch('toast', message: __('Settings saved.'));
    }

    /**
     * Creates the key pair for web push (only when none exists, changing it breaks every subscription).
     */
    public function generateVapidKeys(): void
    {
        $this->authorize('admin');

        if (filled(config('services.webpush.public_key'))) {
            $this->dispatch('toast', message: __('Push keys already exist.'));

            return;
        }

        $keys = WebPush::generateKeys();
        AppSettings::save([
            'vapid_public_key' => $keys['publicKey'],
            'vapid_private_key' => $keys['privateKey'],
            'vapid_subject' => AppSettings::stored()['vapid_subject'] ?? 'mailto:'.Auth::user()->email,
        ]);
        $this->fillForm();
        $this->dispatch('toast', message: __('Push keys created.'));
    }

    public function sendTestMail(): void
    {
        $this->authorize('admin');
        $this->validate(['testRecipient' => ['required', 'email']]);

        try {
            Mail::raw(__('This is a test message from :app. Sending e-mails works.', ['app' => config('app.name')]), function ($message) {
                $message->to($this->testRecipient)->subject(__('Test e-mail from :app', ['app' => config('app.name')]));
            });
            $this->dispatch('toast', message: config('mail.default') === 'log' ? __('Mail sending is switched off (log). Enter a mail server first.') : __('Test e-mail sent to :email.', ['email' => $this->testRecipient]));
        } catch (\Throwable $exception) {
            $this->addError('testRecipient', __('Sending failed: :message', ['message' => mb_substr($exception->getMessage(), 0, 300)]));
        }
    }

    /**
     * Asks clamd for its version and lets it scan the harmless EICAR test string.
     */
    public function testVirusScanner(VirusScanner $scanner): void
    {
        $this->authorize('admin');

        $version = $scanner->version();

        if ($version === null) {
            $this->dispatch('toast', message: __('The virus scanner is not reachable at :socket.', ['socket' => config('security.clamav.socket')]));

            return;
        }

        $result = $scanner->scanString(VirusScanner::EICAR);
        $this->dispatch('toast', message: $result['status'] === VirusScanner::INFECTED
            ? __(':version works – the test file was recognised.', ['version' => $version])
            : __(':version answers, but did not recognise the test file.', ['version' => $version]));
    }

    /**
     * Calls an address inside the private network through the VPN.
     */
    /**
     * Sends a test message through Reverb and reports whether it arrived there.
     */
    public function testRealtime(): void
    {
        $this->authorize('admin');

        try {
            Broadcast::connection(config('broadcasting.default'))->broadcast(['admin-test'], 'ping', []);
            $this->realtimeTestResult = ['ok' => true, 'message' => __('Reverb accepted the test message.')];
        } catch (\Throwable $exception) {
            $this->realtimeTestResult = ['ok' => false, 'message' => __('Reverb could not be reached: :message', ['message' => \Illuminate\Support\Str::limit(trim(strip_tags($exception->getMessage())), 160)])];
        }
    }

    /**
     * Logs in to the mailbox of the mail intake and counts the mails waiting there.
     */
    public function testInboundMail(\App\Support\Mail\Mailbox $mailbox): void
    {
        $this->authorize('admin');

        if (! \App\Support\Mail\Mailbox::isConfigured()) {
            $this->inboundTestResult = ['ok' => false, 'message' => __('Domain, user name and password are needed.')];

            return;
        }

        try {
            $count = $mailbox->check();
            $this->inboundTestResult = ['ok' => true, 'message' => trans_choice('Login worked – :count mail is waiting.|Login worked – :count mails are waiting.', $count)];
        } catch (\Throwable $exception) {
            $this->inboundTestResult = ['ok' => false, 'message' => __('The mailbox could not be reached: :message', ['message' => \Illuminate\Support\Str::limit($exception->getMessage(), 200)])];
        }
    }

    public function testVpn(): void
    {
        $this->authorize('admin');
        $this->validate(['vpnTestUrl' => ['required', 'url:http,https', 'max:500']], attributes: ['vpnTestUrl' => __('Address')]);
        $this->vpnTestResult = Vpn::test($this->vpnTestUrl);
    }

    /**
     * Reads the accounts of the old database, so that one can be chosen as source.
     */
    public function loadAccounts(string $name): void
    {
        $this->authorize('admin');
        $import = self::IMPORTS[$name] ?? abort(404);
        $this->resetErrorBag('importSource.'.$name);

        try {
            // Fail fast instead of hanging when the old database is not reachable.
            if (in_array(config("database.connections.{$import['connection']}.driver"), ['mysql', 'mariadb'], true)) {
                config(["database.connections.{$import['connection']}.options" => [\PDO::ATTR_TIMEOUT => 5]]);
                DB::purge($import['connection']);
            }

            $table = DB::connection($import['connection'])->table('users');
            $hasName = DB::connection($import['connection'])->getSchemaBuilder()->hasColumn('users', $import['name_column']);

            $this->sourceAccounts[$name] = $table->orderBy('email')->limit(500)->get()
                ->map(fn (object $row) => ['email' => (string) $row->email, 'name' => $hasName ? (string) ($row->{$import['name_column']} ?? '') : ''])
                ->all();

            if ($this->sourceAccounts[$name] === []) {
                $this->addError('importSource.'.$name, __('The old database contains no accounts.'));
            }
        } catch (\Throwable $exception) {
            $this->sourceAccounts[$name] = [];
            $this->addError('importSource.'.$name, __('The old database is not reachable: :message', ['message' => mb_substr(\Illuminate\Support\Str::before($exception->getMessage(), ' (Connection:'), 0, 300)]));
        }
    }

    /**
     * Starts an import in the background (queue worker started by the scheduler every minute).
     */
    public function startImport(string $name): void
    {
        $this->authorize('admin');
        $import = self::IMPORTS[$name] ?? abort(404);
        $target = User::find((int) ($this->importTarget[$name] ?? 0));

        if (! $target) {
            $this->addError('importTarget.'.$name, __('Choose the account that receives the data.'));

            return;
        }

        $source = (string) ($this->importSource[$name] ?? '*');
        $arguments = ['email' => $target->email];

        if ($source === '*') {
            $arguments['--all'] = true;
        } elseif ($source !== '') {
            $arguments[$import['source_option']] = $source;
        }

        Cache::forever(RunAdminCommand::cacheKey($name), ['status' => 'queued', 'at' => now()->toIso8601String(), 'output' => '']);
        RunAdminCommand::dispatch($name, $import['command'], $arguments);
        $this->dispatch('toast', message: __('Import started. It runs in the background within the next minute.'));
    }

    public function with(): array
    {
        $stored = AppSettings::stored();

        return [
            'stored' => $stored,
            // Values in effect without a saved one (.env or built-in default), shown as placeholder.
            'current' => collect(AppSettings::FIELDS)->filter(fn (array $field, string $key) => ! isset($stored[$key]) && filled(AppSettings::current($key)))->map(fn (array $field, string $key) => (string) AppSettings::current($key))->all(),
            'mailer' => config('mail.default'),
            'imports' => ['portfolio' => Cache::get(RunAdminCommand::cacheKey('portfolio')), 'recipes' => Cache::get(RunAdminCommand::cacheKey('recipes'))],
            'users' => User::orderBy('name')->get(['id', 'name', 'email']),
            'vpnStatus' => Vpn::status(),
            'vpnTunnel' => Vpn::isRunning() ? Vpn::tunnelState() : null,
            'vpnNetworks' => Vpn::networks(),
        ];
    }
}; ?>

@php
    $groups = [
        'ai' => ['title' => __('AI (Claude)'), 'text' => __('For recipe import, reading contracts and bank statements, tag suggestions, “Ask your Second Brain” and the Instagram analysis. Provider “Anthropic API” uses the key from console.anthropic.com; “AWS Bedrock” runs Claude in your chosen AWS region (e.g. Frankfurt) so the data stays in the EU – then enter the Bedrock region and model id, and AWS keys only if the server has none of its own.')],
        'mail' => ['title' => __('E-mail'), 'text' => __('For daily reminders, “Forgot password” and sending invoices, quotes and reminders. Use the SMTP data of your mailbox (e.g. created in Plesk under “Mail”).')],
        'push' => ['title' => __('Web push'), 'text' => __('Key pair for push notifications. Create it once with the button and never change it afterwards, otherwise every device has to switch notifications on again.')],
        'portfolio' => ['title' => __('Portfolio'), 'text' => __('Keys for bank sync, Instagram analysis and optional data sources.')],
        'backup' => ['title' => __('Backups'), 'text' => __('Nightly ZIP backup of the database and uploaded files. With a WebDAV folder and a password, each backup is also encrypted (XChaCha20-Poly1305, key derived from the password) and copied off the server – keep the password safe, without it the backup cannot be opened.')],
        'realtime' => ['title' => __('Live updates (Reverb)'), 'text' => __('Open pages update themselves when someone else changes something. The server sends these updates to the Reverb process; behind the path-based proxy it has to reach it directly. Enter the port Reverb was started with (PM2 process “reverb”, 3021 for staging and 3013 for live). The deploy sets it automatically.')],
        'vpn' => ['title' => __('VPN (WireGuard)'), 'text' => __('Connects the server with a private network, e.g. your home network, so paperless or a NAS there can be reached. Create a WireGuard connection in your router (FritzBox: Internet → Permit Access → VPN (WireGuard) → “Connect a single device”) and paste the configuration file here. Only addresses in the listed networks go through the VPN. Staging and live need a connection and a port of their own.')],
        'google' => ['title' => __('Google Drive'), 'text' => __('Lets members connect their Google Drive under “Network drives”. In the Google Cloud Console create a project, enable the “Google Drive API”, set up the OAuth consent screen (publishing status “In production”, otherwise the connection expires after 7 days) and create an OAuth client of type “Web application” with this redirect URI: :uri', ['uri' => route('drives.google.callback')])],
        'security' => ['title' => __('Security & monitoring'), 'text' => __('Require two-factor authentication for everyone, and get an e-mail when an error happens on the server (at most once per error and hour).').' '.__('Sign-out after inactivity, session length and storage, expiry of app sign-ins and how long the security log is kept.')],
        'virus' => ['title' => __('Virus scan (ClamAV)'), 'text' => __('Checks every uploaded file with the ClamAV virus scanner on the server before it is stored. The server needs the ClamAV daemon (package “clamav-daemon”); enter its socket, e.g. /var/run/clamav/clamd.ctl or tcp://127.0.0.1:3310.')],
        'shopping' => ['title' => __('Shopping list'), 'text' => __('Keys for the supermarket offers (marktguru). Usually not needed – without them the keys are read from the marktguru website.')],
        'inbound' => ['title' => __('Mail intake'), 'text' => __('Every household gets its own address (e.g. familie.x7k2@in.example.com) to forward bookings, invoices and to-dos to. Needed: an MX record of the domain pointing to this server, a mail domain with a catch-all mailbox in Plesk (Mail → the domain → Mail settings → “Catch-all”: forward to the mailbox) and its login here. The mails are fetched every two minutes and deleted from the mailbox.')],
        'maps' => ['title' => __('Trip map'), 'text' => __('Without an entry the map shows OpenStreetMap. For another map style enter the tile address of a map service, e.g. from MapTiler or Stadia Maps, with {z}, {x} and {y} and your key.')],
        'import_portfolio' => ['title' => __('Import: old portfolio manager'), 'text' => __('Access to the MySQL database of the old portfolio manager. Only needed for the one-time import; remove the values afterwards.')],
        'import_recipes' => ['title' => __('Import: RezepteApp2'), 'text' => __('Access to the MySQL database of RezepteApp2. Only needed for the one-time import; remove the values afterwards.')],
    ];
    $labels = [
        'ai_provider' => [__('AI provider'), ''],
        'anthropic_api_key' => [__('API key'), 'sk-ant-…'],
        'anthropic_model' => [__('Model (optional)'), 'claude-opus-5'],
        'bedrock_region' => [__('Bedrock region'), 'eu-central-1'],
        'bedrock_model' => [__('Bedrock model id'), 'eu.anthropic.claude-sonnet-5'],
        'bedrock_access_key' => [__('AWS access key (optional)'), 'AKIA…'],
        'bedrock_secret_key' => [__('AWS secret key (optional)'), ''],
        'ai_disabled_features' => [__('AI functions in use'), ''],
        'mail_host' => [__('SMTP server'), 'zimmimail.de'],
        'mail_port' => [__('Port'), '587'],
        'mail_scheme' => [__('Encryption'), ''],
        'mail_username' => [__('User name'), 'lifeos@zimmimail.de'],
        'mail_password' => [__('Password'), ''],
        'mail_from_address' => [__('Sender address'), 'lifeos@zimmimail.de'],
        'mail_from_name' => [__('Sender name'), 'E11LifeOS'],
        'vapid_public_key' => [__('Public key'), ''],
        'vapid_private_key' => [__('Private key'), ''],
        'vapid_subject' => [__('Contact'), 'mailto:du@example.com'],
        'fints_product_id' => [__('FinTS product ID'), ''],
        'apify_api_key' => [__('Apify API token (Instagram)'), 'apify_api_…'],
        'instagram_profile' => [__('Instagram account'), 'heikothieme.club'],
        'instagram_limit' => [__('Posts per fetch'), '20'],
        'instagram_push_key' => [__('Push key for /api/instagram/push (optional)'), ''],
        'twelve_data_key' => [__('Twelve Data key (optional)'), ''],
        'openfigi_key' => [__('OpenFIGI key (optional)'), ''],
        'pushover_app_token' => [__('Pushover app token (optional)'), ''],
        'backup_path' => [__('Folder (empty = storage/backups)'), '/var/www/vhosts/zimmimail.de/backups/e11lifeos'],
        'backup_keep' => [__('Keep backups'), '14'],
        'backup_remote_url' => [__('WebDAV folder for copies off the server'), 'https://u12345.your-storagebox.de/e11lifeos/'],
        'backup_remote_username' => [__('WebDAV user name'), 'u12345'],
        'backup_remote_password' => [__('WebDAV password'), ''],
        'backup_encryption_password' => [__('Encryption password for the copies'), ''],
        'backup_remote_keep' => [__('Keep copies off the server'), '30'],
        'inbound_mail_domain' => [__('Domain of the addresses'), 'in.zimmimail.de'],
        'inbound_mail_host' => [__('IMAP server'), '127.0.0.1'],
        'inbound_mail_port' => [__('Port'), '993'],
        'inbound_mail_encryption' => [__('Encryption'), ''],
        'inbound_mail_username' => [__('Mailbox (user name)'), 'eingang@in.zimmimail.de'],
        'inbound_mail_password' => [__('Password'), ''],
        'offers_api_key' => [__('marktguru API key (optional)'), ''],
        'offers_client_key' => [__('marktguru client key (optional)'), ''],
        'maps_tile_url' => [__('Tile address (optional)'), 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=…'],
        'require_two_factor' => [__('Two-factor authentication required'), ''],
        'error_report_email' => [__('Send error reports to'), 'admin@example.com'],
        'idle_minutes' => [__('Sign out after inactivity (minutes, 0 = never)'), '30'],
        'session_lifetime' => [__('Maximum session length (minutes)'), '120'],
        'api_token_idle_days' => [__('App sign-ins expire after unused days (0 = never)'), '90'],
        'log_days' => [__('Keep security log (days)'), '365'],
        'session_driver' => [__('Where sessions are stored'), ''],
        'session_encrypt' => [__('Encrypt session data'), ''],
        'clamav_enabled' => [__('Scan uploads for viruses'), ''],
        'clamav_socket' => [__('ClamAV socket'), '/var/run/clamav/clamd.ctl'],
        'clamav_fail_closed' => [__('Block uploads while the scanner is unreachable'), ''],
        'reverb_publish_port' => [__('Port of the local Reverb process'), '3021'],
        'vpn_enabled' => [__('VPN switched on'), ''],
        'vpn_socks_port' => [__('Local port (optional)'), __('empty = own port per installation')],
        'vpn_networks' => [__('Networks through the VPN (empty = AllowedIPs)'), '192.168.178.0/24, fritz.box'],
        'vpn_wireguard_config' => [__('WireGuard configuration'), "[Interface]\nPrivateKey = …\nAddress = 192.168.178.201/24\nDNS = 192.168.178.1\n\n[Peer]\nPublicKey = …\nEndpoint = xyz.myfritz.net:51820\nAllowedIPs = 192.168.178.0/24"],
        'google_client_id' => [__('Client ID'), '1234-abc.apps.googleusercontent.com'],
        'google_client_secret' => [__('Client secret'), ''],
        'legacy_db_host' => [__('Host'), '127.0.0.1'],
        'legacy_db_port' => [__('Port'), '3306'],
        'legacy_db_database' => [__('Database'), 'portfolio_manager'],
        'legacy_db_username' => [__('User name'), ''],
        'legacy_db_password' => [__('Password'), ''],
        'rezepteapp_db_host' => [__('Host'), '127.0.0.1'],
        'rezepteapp_db_port' => [__('Port'), '3306'],
        'rezepteapp_db_database' => [__('Database'), 'rezepteapp2'],
        'rezepteapp_db_username' => [__('User name'), ''],
        'rezepteapp_db_password' => [__('Password'), ''],
        'rezepteapp_uploads_path' => [__('Photo folder on this server'), '/var/www/vhosts/zimmimail.de/httpdocs/RezepteApp2/uploads'],
        'rezepteapp_uploads_url' => [__('Photo address (if the folder is not readable)'), 'https://zimmimail.de/RezepteApp2/uploads'],
    ];
    $importNames = ['import_portfolio' => 'portfolio', 'import_recipes' => 'recipes'];
    $selectOptions = [
        'ai_provider' => ['anthropic' => __('Anthropic API (USA)'), 'bedrock' => __('AWS Bedrock (e.g. EU)')],
        'mail_scheme' => ['' => __('STARTTLS (port 587)'), 'smtps' => __('SSL/TLS (port 465)')],
        'inbound_mail_encryption' => ['' => __('Default (:value)', ['value' => (string) AppSettings::current('inbound_mail_encryption')]), 'ssl' => __('SSL/TLS (port 993)'), 'tls' => __('STARTTLS (port 143)'), 'none' => __('None (only on this server)')],
        'session_driver' => ['' => isset($stored['session_driver']) ? __('Default (.env)') : __('Default (:value)', ['value' => ['database' => __('Database'), 'file' => __('Files')][AppSettings::current('session_driver')] ?? (string) AppSettings::current('session_driver')]), 'database' => __('Database (sessions visible in the profile)'), 'file' => __('Files')],
    ];
    // What "Default" means for yes/no fields: the value in effect while nothing is saved here.
    $yesnoDefaults = collect(AppSettings::FIELDS)->filter(fn (array $field) => $field['type'] === 'yesno')->map(fn (array $field, string $key) => isset($stored[$key]) ? null : (bool) AppSettings::current($key))->all();
    $importStatus = ['queued' => __('waiting for the scheduler'), 'running' => __('running'), 'done' => __('finished'), 'failed' => __('failed')];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Server settings') }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Only visible to administrators. Values are stored encrypted and take precedence over the .env file.') }}</p>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <form wire:submit="save" class="space-y-6">
                @foreach ($groups as $group => $meta)
                    <section wire:key="group-{{ $group }}" class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $meta['title'] }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $meta['text'] }}</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            @foreach (AppSettings::FIELDS as $key => $field)
                                @continue($field['group'] !== $group)
                                <div wire:key="field-{{ $key }}" @class(['sm:col-span-2' => in_array($field['type'], ['textarea', 'features'], true)])>
                                    <x-input-label :for="'setting-'.$key" :value="$labels[$key][0]" />
                                    @if ($field['type'] === 'select')
                                        <select id="setting-{{ $key }}" wire:model="form.{{ $key }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                            @foreach ($selectOptions[$key] as $value => $optionLabel)
                                                <option value="{{ $value }}">{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($field['type'] === 'yesno')
                                        <select id="setting-{{ $key }}" wire:model="form.{{ $key }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                            <option value="">{{ $yesnoDefaults[$key] === null ? __('Default (.env)') : __('Default (:value)', ['value' => $yesnoDefaults[$key] ? __('Yes') : __('No')]) }}</option>
                                            <option value="1">{{ __('Yes') }}</option>
                                            <option value="0">{{ __('No') }}</option>
                                        </select>
                                    @elseif ($field['type'] === 'features')
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Only ticked functions send data to the AI service; everything else keeps working without AI.') }}</p>
                                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                            @foreach (AiFeatures::all() as $feature => $meta)
                                                <label wire:key="ai-feature-{{ $feature }}" class="flex items-start gap-2 rounded-lg border border-gray-200 p-2 text-sm dark:border-gray-700">
                                                    <input type="checkbox" wire:model="aiFeatures" value="{{ $feature }}" class="mt-0.5 rounded-sm border-gray-300 text-indigo-600" />
                                                    <span><span class="block text-gray-900 dark:text-gray-100">{{ $meta['label'] }}</span><span class="block text-xs text-gray-500 dark:text-gray-400">{{ $meta['text'] }}</span></span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @elseif ($field['type'] === 'textarea')
                                        <textarea id="setting-{{ $key }}" rows="8" wire:model="form.{{ $key }}" spellcheck="false" autocomplete="off" class="mt-1 block w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ isset($stored[$key]) ? '•••••••• '.__('(saved – paste a new configuration to replace it)') : $labels[$key][1] }}"></textarea>
                                        @if (isset($stored[$key]))
                                            <label class="mt-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400"><input type="checkbox" wire:model="clear.{{ $key }}" class="rounded-sm border-gray-300 text-red-600" /> {{ __('remove') }}</label>
                                        @endif
                                    @elseif ($field['secret'])
                                        <x-text-input :id="'setting-'.$key" type="password" autocomplete="new-password" wire:model="form.{{ $key }}" class="mt-1 block w-full" :placeholder="isset($stored[$key]) ? '•••••••• '.__('(saved)') : (isset($current[$key]) ? '•••••••• '.__('(from .env)') : $labels[$key][1])" />
                                        @if (isset($stored[$key]))
                                            <label class="mt-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400"><input type="checkbox" wire:model="clear.{{ $key }}" class="rounded-sm border-gray-300 text-red-600" /> {{ __('remove') }}</label>
                                        @endif
                                    @else
                                        <x-text-input :id="'setting-'.$key" :type="$field['type'] === 'number' ? 'number' : ($field['type'] === 'email' ? 'email' : 'text')" wire:model="form.{{ $key }}" class="mt-1 block w-full" :placeholder="isset($current[$key]) ? __('currently: :value', ['value' => $current[$key]]) : $labels[$key][1]" />
                                    @endif
                                    <x-input-error :messages="$errors->get('form.'.$key)" class="mt-1" />
                                </div>
                            @endforeach
                        </div>

                        @if ($group === 'mail')
                            <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                                <div class="min-w-0 flex-1">
                                    <x-input-label for="test-recipient" :value="__('Send a test e-mail to')" />
                                    <x-text-input id="test-recipient" type="email" wire:model="testRecipient" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('testRecipient')" class="mt-1" />
                                </div>
                                <x-secondary-button type="button" wire:click="sendTestMail">{{ __('Send test e-mail') }}</x-secondary-button>
                            </div>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Save first, then test.') }} {{ __('Current mailer: :mailer', ['mailer' => $mailer]) }}</p>
                        @elseif ($group === 'virus')
                            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                                <x-secondary-button type="button" wire:click="testVirusScanner" wire:loading.attr="disabled" wire:target="testVirusScanner">{{ __('Test virus scanner') }}</x-secondary-button>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Save first, then test.') }}</span>
                            </div>
                        @elseif ($group === 'inbound')
                            @php($inboundStatus = \Illuminate\Support\Facades\Cache::get(\App\Console\Commands\FetchInboundMail::STATUS_KEY))
                            <div class="mt-4 space-y-2 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                                @if ($inboundStatus)
                                    <p @class(['text-xs', 'text-gray-600 dark:text-gray-300' => $inboundStatus['ok'], 'text-red-700 dark:text-red-300' => ! $inboundStatus['ok']])>
                                        {{ $inboundStatus['ok'] ? '● '.__('Last fetch :time: :processed filed, :ignored without household.', ['time' => \Illuminate\Support\Carbon::parse($inboundStatus['at'])->diffForHumans(), 'processed' => $inboundStatus['processed'], 'ignored' => $inboundStatus['ignored']]) : '● '.__('Last fetch :time failed: :error', ['time' => \Illuminate\Support\Carbon::parse($inboundStatus['at'])->diffForHumans(), 'error' => $inboundStatus['error']]) }}
                                        @if ($inboundStatus['last_mail_at']) · {{ __('last mail :time', ['time' => \Illuminate\Support\Carbon::parse($inboundStatus['last_mail_at'])->diffForHumans()]) }}@endif
                                    </p>
                                @elseif (\App\Support\Mail\Mailbox::isConfigured())
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Not fetched yet – the scheduler fetches every two minutes.') }}</p>
                                @endif
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-secondary-button type="button" wire:click="testInboundMail" wire:loading.attr="disabled" wire:target="testInboundMail">{{ __('Test mailbox') }}</x-secondary-button>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Save first, then test.') }}</span>
                                </div>
                                @if ($inboundTestResult)
                                    <p @class(['text-sm', 'text-emerald-700 dark:text-emerald-300' => $inboundTestResult['ok'], 'text-red-700 dark:text-red-300' => ! $inboundTestResult['ok']]) role="status">{{ $inboundTestResult['message'] }}</p>
                                @endif
                            </div>
                        @elseif ($group === 'realtime')
                            <div class="mt-4 space-y-2 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Currently sent to: :address', ['address' => config('broadcasting.connections.reverb.options.scheme').'://'.config('broadcasting.connections.reverb.options.host').':'.config('broadcasting.connections.reverb.options.port')]) }}</p>
                                <x-secondary-button type="button" wire:click="testRealtime" wire:loading.attr="disabled" wire:target="testRealtime">{{ __('Test live updates') }}</x-secondary-button>
                                @if ($realtimeTestResult)
                                    <p @class(['text-sm', 'text-emerald-700 dark:text-emerald-300' => $realtimeTestResult['ok'], 'text-red-700 dark:text-red-300' => ! $realtimeTestResult['ok']]) role="status">{{ $realtimeTestResult['message'] }}</p>
                                @endif
                            </div>
                        @elseif ($group === 'vpn')
                            <div class="mt-4 space-y-3 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                                <p class="flex flex-wrap items-center gap-2">
                                    @if (($vpnStatus['state'] ?? null) === 'running' && \Illuminate\Support\Carbon::parse($vpnStatus['at'])->gt(now()->subMinutes(2)))
                                        @if ($vpnTunnel && $vpnTunnel['handshake_at'] && $vpnTunnel['handshake_at'] > now()->subMinutes(3)->getTimestamp())
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">● {{ __('connected') }}</span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Last handshake :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($vpnTunnel['handshake_at'])->diffForHumans()]) }}</span>
                                        @elseif ($vpnTunnel)
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">● {{ __('no handshake') }}</span>
                                            <span class="text-xs text-amber-800 dark:text-amber-200">{{ $vpnTunnel['handshake_at'] ? __('Last handshake :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($vpnTunnel['handshake_at'])->diffForHumans()]) : __('wireproxy runs, but the router has not answered yet.') }}</span>
                                        @else
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">● {{ __('running') }}</span>
                                        @endif
                                    @elseif (($vpnStatus['state'] ?? null) === 'error')
                                        <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-200">● {{ __('error') }}</span>
                                        <span class="text-xs text-red-700 dark:text-red-300">{{ $vpnStatus['message'] }}</span>
                                    @else
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">● {{ __('not connected') }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('The connection is kept up by the PM2 process “vpn” (set up automatically on the next deploy).') }}</span>
                                    @endif
                                </p>
                                @if ($vpnNetworks !== [])
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Through the VPN: :networks', ['networks' => implode(', ', $vpnNetworks)]) }}</p>
                                @endif
                                <div class="flex flex-wrap items-end gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-input-label for="vpn-test-url" :value="__('Test an address in the network')" />
                                        <x-text-input id="vpn-test-url" type="url" wire:model="vpnTestUrl" placeholder="http://192.168.178.20:8000" class="mt-1 block w-full" />
                                        <x-input-error :messages="$errors->get('vpnTestUrl')" class="mt-1" />
                                    </div>
                                    <x-secondary-button type="button" wire:click="testVpn" wire:loading.attr="disabled" wire:target="testVpn">{{ __('Test connection') }}</x-secondary-button>
                                </div>
                                @if ($vpnTestResult)
                                    <p @class(['text-sm', 'text-emerald-700 dark:text-emerald-300' => $vpnTestResult['ok'], 'text-red-700 dark:text-red-300' => ! $vpnTestResult['ok']]) role="status">{{ $vpnTestResult['message'] }}</p>
                                    @if (! empty($vpnTestResult['hints']))
                                        <ul class="list-disc space-y-1 ps-5 text-xs text-gray-700 dark:text-gray-300">
                                            @foreach ($vpnTestResult['hints'] as $hint)
                                                <li>{{ $hint }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                @endif
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Save first, then test. paperless instances and the WebDAV backup with an address inside these networks use the VPN automatically.') }}</p>
                            </div>
                        @elseif ($group === 'push' && blank(config('services.webpush.public_key')))
                            <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                <x-secondary-button type="button" wire:click="generateVapidKeys">🔑 {{ __('Create push keys') }}</x-secondary-button>
                            </div>
                        @elseif (in_array($group, ['import_portfolio', 'import_recipes'], true))
                            <div class="mt-4 grid gap-4 border-t border-gray-100 pt-4 text-sm sm:grid-cols-2 dark:border-gray-700">
                                <div>
                                    <x-input-label :for="'import-source-'.$group" :value="__('Import from')" />
                                    <select id="import-source-{{ $group }}" wire:model="importSource.{{ $importNames[$group] }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                        <option value="*">{{ __('All accounts') }}</option>
                                        <option value="">{{ __('Account with the same e-mail as the target') }}</option>
                                        @foreach ($sourceAccounts[$importNames[$group]] as $account)
                                            <option value="{{ $account['email'] }}">{{ $account['email'] }}{{ $account['name'] !== '' ? ' ('.$account['name'].')' : '' }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="loadAccounts('{{ $importNames[$group] }}')" class="mt-1 text-xs text-indigo-600 underline dark:text-indigo-400">↻ {{ __('Load accounts from the old database') }}</button>
                                    <x-input-error :messages="$errors->get('importSource.'.$importNames[$group])" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label :for="'import-target-'.$group" :value="__('Into LifeOS account')" />
                                    <select id="import-target-{{ $group }}" wire:model="importTarget.{{ $importNames[$group] }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                        @foreach ($users as $user)
                                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('importTarget.'.$importNames[$group])" class="mt-1" />
                                </div>
                                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                                    <x-secondary-button type="button" wire:click="startImport('{{ $importNames[$group] }}')" wire:confirm="{{ __('Start the import? It can safely run again; already imported data is not duplicated.') }}">⤵ {{ __('Start import') }}</x-secondary-button>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $group === 'import_recipes' ? __('Recipes land shared in the household of the chosen account. Recipes imported earlier without photo get their photos when you run the import again. RezepteApp2 is only read, never changed.') : __('Portfolios of all chosen old accounts land in the chosen account.') }} {{ __('Save the access data first. The import runs in the background.') }}</span>
                                </div>
                            </div>
                            @if ($imports[$importNames[$group]])
                                <div class="mt-3 rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900/50">
                                    <p class="font-medium text-gray-700 dark:text-gray-200">{{ $importStatus[$imports[$importNames[$group]]['status']] ?? $imports[$importNames[$group]]['status'] }} · {{ Carbon::parse($imports[$importNames[$group]]['at'])->diffForHumans() }}</p>
                                    @if ($imports[$importNames[$group]]['output'] !== '')
                                        <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-gray-600 dark:text-gray-400">{{ $imports[$importNames[$group]]['output'] }}</pre>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </section>
                @endforeach

                <div class="sticky bottom-4 flex justify-end">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</div>
