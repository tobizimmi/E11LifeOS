<?php

namespace App\Support;

use App\Models\AppSetting;
use Throwable;

/**
 * Server settings that can be entered on the admin page instead of in .env (API keys, mail,
 * push keys …). A value saved here overrides the .env value; an empty one falls back to it.
 */
class AppSettings
{
    /**
     * key => [group, config paths, secret?, type]; "yesno" stores "1" or "0", empty keeps the .env value.
     *
     * @var array<string, array{group: string, config: list<string>, secret: bool, type: string}>
     */
    public const FIELDS = [
        'anthropic_api_key' => ['group' => 'ai', 'config' => ['services.anthropic.key'], 'secret' => true, 'type' => 'text'],
        'anthropic_model' => ['group' => 'ai', 'config' => ['services.anthropic.model'], 'secret' => false, 'type' => 'text'],
        'ai_disabled_features' => ['group' => 'ai', 'config' => ['services.anthropic.disabled_features'], 'secret' => false, 'type' => 'features'],

        'mail_host' => ['group' => 'mail', 'config' => ['mail.mailers.smtp.host'], 'secret' => false, 'type' => 'text'],
        'mail_port' => ['group' => 'mail', 'config' => ['mail.mailers.smtp.port'], 'secret' => false, 'type' => 'number'],
        'mail_scheme' => ['group' => 'mail', 'config' => ['mail.mailers.smtp.scheme'], 'secret' => false, 'type' => 'select'],
        'mail_username' => ['group' => 'mail', 'config' => ['mail.mailers.smtp.username'], 'secret' => false, 'type' => 'text'],
        'mail_password' => ['group' => 'mail', 'config' => ['mail.mailers.smtp.password'], 'secret' => true, 'type' => 'text'],
        'mail_from_address' => ['group' => 'mail', 'config' => ['mail.from.address'], 'secret' => false, 'type' => 'email'],
        'mail_from_name' => ['group' => 'mail', 'config' => ['mail.from.name'], 'secret' => false, 'type' => 'text'],

        'vapid_public_key' => ['group' => 'push', 'config' => ['services.webpush.public_key'], 'secret' => false, 'type' => 'text'],
        'vapid_private_key' => ['group' => 'push', 'config' => ['services.webpush.private_key'], 'secret' => true, 'type' => 'text'],
        'vapid_subject' => ['group' => 'push', 'config' => ['services.webpush.subject'], 'secret' => false, 'type' => 'text'],

        'fints_product_id' => ['group' => 'portfolio', 'config' => ['services.fints.product_id'], 'secret' => true, 'type' => 'text'],
        'apify_api_key' => ['group' => 'portfolio', 'config' => ['services.instagram.apify_key'], 'secret' => true, 'type' => 'text'],
        'instagram_profile' => ['group' => 'portfolio', 'config' => ['services.instagram.profile'], 'secret' => false, 'type' => 'text'],
        'instagram_limit' => ['group' => 'portfolio', 'config' => ['services.instagram.limit'], 'secret' => false, 'type' => 'number'],
        'instagram_push_key' => ['group' => 'portfolio', 'config' => ['services.instagram.push_key'], 'secret' => true, 'type' => 'text'],
        'twelve_data_key' => ['group' => 'portfolio', 'config' => ['services.twelve_data.key'], 'secret' => true, 'type' => 'text'],
        'openfigi_key' => ['group' => 'portfolio', 'config' => ['services.openfigi.key'], 'secret' => true, 'type' => 'text'],
        'pushover_app_token' => ['group' => 'portfolio', 'config' => ['services.pushover.token'], 'secret' => true, 'type' => 'text'],

        'backup_path' => ['group' => 'backup', 'config' => ['backup.path'], 'secret' => false, 'type' => 'text'],
        'backup_keep' => ['group' => 'backup', 'config' => ['backup.keep'], 'secret' => false, 'type' => 'number'],
        'backup_remote_url' => ['group' => 'backup', 'config' => ['backup.remote_url'], 'secret' => false, 'type' => 'text'],
        'backup_remote_username' => ['group' => 'backup', 'config' => ['backup.remote_username'], 'secret' => false, 'type' => 'text'],
        'backup_remote_password' => ['group' => 'backup', 'config' => ['backup.remote_password'], 'secret' => true, 'type' => 'text'],
        'backup_encryption_password' => ['group' => 'backup', 'config' => ['backup.encryption_password'], 'secret' => true, 'type' => 'text'],
        'backup_remote_keep' => ['group' => 'backup', 'config' => ['backup.remote_keep'], 'secret' => false, 'type' => 'number'],

        'offers_api_key' => ['group' => 'shopping', 'config' => ['services.offers.api_key'], 'secret' => true, 'type' => 'text'],
        'offers_client_key' => ['group' => 'shopping', 'config' => ['services.offers.client_key'], 'secret' => true, 'type' => 'text'],

        'inbound_mail_domain' => ['group' => 'inbound', 'config' => ['services.inbound_mail.domain'], 'secret' => false, 'type' => 'text'],
        'inbound_mail_host' => ['group' => 'inbound', 'config' => ['services.inbound_mail.host'], 'secret' => false, 'type' => 'text'],
        'inbound_mail_port' => ['group' => 'inbound', 'config' => ['services.inbound_mail.port'], 'secret' => false, 'type' => 'number'],
        'inbound_mail_encryption' => ['group' => 'inbound', 'config' => ['services.inbound_mail.encryption'], 'secret' => false, 'type' => 'select'],
        'inbound_mail_username' => ['group' => 'inbound', 'config' => ['services.inbound_mail.username'], 'secret' => false, 'type' => 'text'],
        'inbound_mail_password' => ['group' => 'inbound', 'config' => ['services.inbound_mail.password'], 'secret' => true, 'type' => 'text'],

        'maps_tile_url' => ['group' => 'maps', 'config' => ['services.maps.tile_url'], 'secret' => false, 'type' => 'text'],

        'require_two_factor' => ['group' => 'security', 'config' => ['security.require_two_factor'], 'secret' => false, 'type' => 'yesno'],
        'error_report_email' => ['group' => 'security', 'config' => ['security.error_report_email'], 'secret' => false, 'type' => 'email'],
        'idle_minutes' => ['group' => 'security', 'config' => ['security.idle_minutes'], 'secret' => false, 'type' => 'number'],
        'session_lifetime' => ['group' => 'security', 'config' => ['session.lifetime'], 'secret' => false, 'type' => 'number'],
        'api_token_idle_days' => ['group' => 'security', 'config' => ['security.api_token_idle_days'], 'secret' => false, 'type' => 'number'],
        'log_days' => ['group' => 'security', 'config' => ['security.log_days'], 'secret' => false, 'type' => 'number'],
        'session_driver' => ['group' => 'security', 'config' => ['session.driver'], 'secret' => false, 'type' => 'select'],
        'session_encrypt' => ['group' => 'security', 'config' => ['session.encrypt'], 'secret' => false, 'type' => 'yesno'],

        'clamav_enabled' => ['group' => 'virus', 'config' => ['security.clamav.enabled'], 'secret' => false, 'type' => 'yesno'],
        'clamav_socket' => ['group' => 'virus', 'config' => ['security.clamav.socket'], 'secret' => false, 'type' => 'text'],
        'clamav_fail_closed' => ['group' => 'virus', 'config' => ['security.clamav.fail_closed'], 'secret' => false, 'type' => 'yesno'],

        'reverb_publish_port' => ['group' => 'realtime', 'config' => ['broadcasting.connections.reverb.options.port'], 'secret' => false, 'type' => 'number'],

        'vpn_enabled' => ['group' => 'vpn', 'config' => ['vpn.enabled'], 'secret' => false, 'type' => 'yesno'],
        'vpn_socks_port' => ['group' => 'vpn', 'config' => ['vpn.socks_port'], 'secret' => false, 'type' => 'number'],
        'vpn_networks' => ['group' => 'vpn', 'config' => ['vpn.networks'], 'secret' => false, 'type' => 'text'],
        'vpn_wireguard_config' => ['group' => 'vpn', 'config' => ['vpn.wireguard_config'], 'secret' => true, 'type' => 'textarea'],

        'google_client_id' => ['group' => 'google', 'config' => ['services.google_drive.client_id'], 'secret' => false, 'type' => 'text'],
        'google_client_secret' => ['group' => 'google', 'config' => ['services.google_drive.client_secret'], 'secret' => true, 'type' => 'text'],

        'legacy_db_host' => ['group' => 'import_portfolio', 'config' => ['database.connections.portfolio_legacy.host'], 'secret' => false, 'type' => 'text'],
        'legacy_db_port' => ['group' => 'import_portfolio', 'config' => ['database.connections.portfolio_legacy.port'], 'secret' => false, 'type' => 'number'],
        'legacy_db_database' => ['group' => 'import_portfolio', 'config' => ['database.connections.portfolio_legacy.database'], 'secret' => false, 'type' => 'text'],
        'legacy_db_username' => ['group' => 'import_portfolio', 'config' => ['database.connections.portfolio_legacy.username'], 'secret' => false, 'type' => 'text'],
        'legacy_db_password' => ['group' => 'import_portfolio', 'config' => ['database.connections.portfolio_legacy.password'], 'secret' => true, 'type' => 'text'],

        'rezepteapp_db_host' => ['group' => 'import_recipes', 'config' => ['database.connections.rezepteapp.host'], 'secret' => false, 'type' => 'text'],
        'rezepteapp_db_port' => ['group' => 'import_recipes', 'config' => ['database.connections.rezepteapp.port'], 'secret' => false, 'type' => 'number'],
        'rezepteapp_db_database' => ['group' => 'import_recipes', 'config' => ['database.connections.rezepteapp.database'], 'secret' => false, 'type' => 'text'],
        'rezepteapp_db_username' => ['group' => 'import_recipes', 'config' => ['database.connections.rezepteapp.username'], 'secret' => false, 'type' => 'text'],
        'rezepteapp_db_password' => ['group' => 'import_recipes', 'config' => ['database.connections.rezepteapp.password'], 'secret' => true, 'type' => 'text'],
        'rezepteapp_uploads_path' => ['group' => 'import_recipes', 'config' => ['services.rezepteapp.uploads_path'], 'secret' => false, 'type' => 'text'],
        'rezepteapp_uploads_url' => ['group' => 'import_recipes', 'config' => ['services.rezepteapp.uploads_url'], 'secret' => false, 'type' => 'text'],
    ];

    /**
     * Saved values (decrypted), keyed like FIELDS.
     *
     * @return array<string, string>
     */
    public static function stored(): array
    {
        return AppSetting::whereIn('key', array_keys(self::FIELDS))->get()
            ->mapWithKeys(fn (AppSetting $setting) => [$setting->key => (string) $setting->value])
            ->all();
    }

    /**
     * Puts the saved values into the configuration. Never fails: before the first migration,
     * without a database or with a changed APP_KEY the .env values simply stay in effect.
     */
    public static function apply(): void
    {
        try {
            $stored = self::stored();
        } catch (Throwable) {
            return;
        }

        foreach ($stored as $key => $value) {
            if ($value === '') {
                continue;
            }

            foreach (self::FIELDS[$key]['config'] as $path) {
                config([$path => match (self::FIELDS[$key]['type']) {
                    'number' => (int) $value,
                    'yesno' => $value === '1',
                    default => $value,
                }]);
            }
        }

        // A mail server entered here switches sending on.
        if (($stored['mail_host'] ?? '') !== '') {
            config(['mail.default' => 'smtp']);
        }

        // The port the deploy started Reverb with wins over a port entered by hand.
        if ((int) config('broadcasting.local_reverb_port') > 0) {
            config(['broadcasting.connections.reverb.options.port' => (int) config('broadcasting.local_reverb_port')]);
        }

        // With the port of the local Reverb process the app sends live updates to it directly.
        if (($stored['reverb_publish_port'] ?? '') !== '' || (int) config('broadcasting.local_reverb_port') > 0) {
            config([
                'broadcasting.connections.reverb.options.host' => '127.0.0.1',
                'broadcasting.connections.reverb.options.scheme' => 'http',
                'broadcasting.connections.reverb.options.useTLS' => false,
            ]);
        }
    }

    /**
     * Saves the given values; null leaves a value unchanged, '' removes it.
     *
     * @param  array<string, ?string>  $values
     */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::FIELDS) || $value === null) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                AppSetting::where('key', $key)->delete();
            } else {
                AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }

        self::apply();
    }

    /**
     * The value in effect (saved or from .env) of a field.
     */
    public static function current(string $key): mixed
    {
        return config(self::FIELDS[$key]['config'][0]);
    }
}
