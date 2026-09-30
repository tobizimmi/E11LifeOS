<?php

namespace App\Models;

use App\Support\Drives\DriveClient;
use App\Support\Drives\GoogleDriveClient;
use App\Support\Drives\WebDavClient;
use Database\Factories\NetworkDriveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's network drive (Synology/QNAP NAS, Nextcloud, storage box) reached over WebDAV –
 * addresses in the home network go through the WireGuard VPN – or their Google Drive.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $type webdav|google
 * @property string $url
 * @property ?string $username
 * @property ?string $password
 * @property ?array{access_token?: string, refresh_token?: ?string, expires_at?: int} $token
 * @property ?Carbon $last_checked_at
 * @property ?string $last_error
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'type', 'url', 'username', 'password', 'token', 'last_checked_at', 'last_error'])]
#[Hidden(['password', 'token'])]
class NetworkDrive extends Model
{
    /** @use HasFactory<NetworkDriveFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'username' => 'encrypted',
            'password' => 'encrypted',
            'token' => 'encrypted:array',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isGoogle(): bool
    {
        return $this->type === 'google';
    }

    public function client(): DriveClient
    {
        return $this->isGoogle() ? new GoogleDriveClient($this) : new WebDavClient($this->url, $this->username, $this->password);
    }
}
