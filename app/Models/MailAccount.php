<?php

namespace App\Models;

use Database\Factories\MailAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A personal IMAP/SMTP mail account of a single member (private, never shared in the household).
 * Proton Mail is reached through a Proton Bridge running on a reachable host; every other provider
 * is a plain IMAP server. Credentials are stored encrypted.
 *
 * @property int $id
 * @property int $user_id
 * @property int $household_id
 * @property string $label
 * @property string $provider
 * @property string $imap_host
 * @property int $imap_port
 * @property string $imap_encryption
 * @property ?string $smtp_host
 * @property ?int $smtp_port
 * @property ?string $smtp_encryption
 * @property bool $validate_cert
 * @property string $username
 * @property string $password
 * @property bool $active
 * @property bool $notify_new_mail
 * @property ?int $uid_validity
 * @property ?array<string, int> $folder_uidvalidity
 * @property ?Carbon $last_synced_at
 * @property ?string $last_error
 * @property ?string $signature
 */
#[Fillable(['user_id', 'household_id', 'label', 'provider', 'imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption', 'validate_cert', 'username', 'password', 'active', 'notify_new_mail', 'uid_validity', 'folder_uidvalidity', 'last_synced_at', 'last_error', 'signature'])]
class MailAccount extends Model
{
    /** @use HasFactory<MailAccountFactory> */
    use HasFactory;

    public const PROVIDER_PROTON_BRIDGE = 'proton_bridge';

    public const PROVIDER_GENERIC = 'generic_imap';

    public const PROVIDERS = [self::PROVIDER_GENERIC, self::PROVIDER_PROTON_BRIDGE];

    /** IMAP/SMTP transport security the raw socket client understands. */
    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    protected function casts(): array
    {
        return [
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'validate_cert' => 'boolean',
            'username' => 'encrypted',
            'password' => 'encrypted',
            'active' => 'boolean',
            'notify_new_mail' => 'boolean',
            'uid_validity' => 'integer',
            'folder_uidvalidity' => 'array',
            'last_synced_at' => 'datetime',
            'last_error' => 'encrypted',
            'signature' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return HasMany<MailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }

    public function isProton(): bool
    {
        return $this->provider === self::PROVIDER_PROTON_BRIDGE;
    }

    /**
     * The IMAP connection settings in the shape the Mailbox client expects.
     *
     * @return array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string}
     */
    public function imapConfig(): array
    {
        return [
            'host' => $this->imap_host,
            'port' => $this->imap_port,
            'encryption' => $this->imap_encryption,
            'validate_cert' => $this->validate_cert,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    /**
     * The member's own accounts.
     *
     * @param  Builder<MailAccount>  $query
     */
    public function scopeForUser(Builder $query, User $user, int $householdId): void
    {
        $query->where('user_id', $user->id)->where('household_id', $householdId);
    }
}
