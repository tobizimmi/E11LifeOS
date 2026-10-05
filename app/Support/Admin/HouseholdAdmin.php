<?php

namespace App\Support\Admin;

use App\Models\Attachment;
use App\Models\BookkeepingEntry;
use App\Models\CareerEntry;
use App\Models\ExternalLink;
use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\RealEstate;
use App\Models\RealEstateHandover;
use App\Models\Recipe;
use App\Models\Trip;
use App\Models\User;
use App\Support\InAppNotifications;
use App\Support\Security\SecurityLog;
use App\Support\WebPush;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * What an administrator may do with households, following data protection by design: the admin
 * sees names, members and amounts, never the content; exports contain only what the household
 * shares (no private entries, no credentials) and are password protected; every action is written
 * to the admin's security log and the owners are told about exports.
 */
class HouseholdAdmin
{
    /** Tables that hold credentials or access codes and never leave the server. */
    private const SECRET_TABLES = [
        'household_invites', 'smart_home_connections', 'paperless_instances', 'network_drives', 'bank_connections',
        'push_actions', 'push_subscriptions', 'api_tokens', 'shares', 'security_events', 'personal_access_tokens',
    ];

    /** Columns that look like credentials are left out of every exported row. */
    private const SECRET_COLUMNS = '/(token|password|secret|api_key|_key$|credential|pin_hash|code_hash)/i';

    /**
     * A new household; with the e-mail of an account that one becomes the owner, otherwise an
     * invite code is created for the first owner (valid for 14 days).
     *
     * @return array{household: Household, owner: ?User, invite: ?HouseholdInvite}
     */
    public static function create(User $admin, string $name, ?string $ownerEmail): array
    {
        return DB::transaction(function () use ($admin, $name, $ownerEmail) {
            $household = Household::create(['name' => $name]);
            $owner = filled($ownerEmail) ? User::where('email', mb_strtolower(trim((string) $ownerEmail)))->first() : null;
            $invite = null;

            if ($owner) {
                HouseholdMember::create(['household_id' => $household->id, 'user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
            } else {
                $invite = HouseholdInvite::create(['household_id' => $household->id, 'code' => HouseholdInvite::generateCode(), 'created_by' => $admin->id, 'expires_at' => now()->addDays(14)]);
            }

            SecurityLog::record($admin, 'admin_action', details: 'household_created #'.$household->id);

            return ['household' => $household, 'owner' => $owner, 'invite' => $invite];
        });
    }

    /**
     * Amounts per household for the overview (no content).
     *
     * @param  Collection<int, Household>  $households
     * @return array<int, array{entries: int, recipes: int, files: int, bytes: int, last_activity: ?string}>
     */
    public static function stats(Collection $households): array
    {
        $ids = $households->pluck('id');
        $entries = DB::table('items')->whereIn('household_id', $ids)->groupBy('household_id')->selectRaw('household_id, count(*) as total, max(updated_at) as latest')->get()->keyBy('household_id');
        $recipes = Recipe::whereIn('household_id', $ids)->groupBy('household_id')->selectRaw('household_id, count(*) as total')->pluck('total', 'household_id');
        $files = DB::table('attachments')->whereIn('household_id', $ids)->groupBy('household_id')->selectRaw('household_id, count(*) as total, sum(size) as bytes')->get()->keyBy('household_id');

        return $ids->mapWithKeys(fn (int $id) => [$id => [
            'entries' => (int) ($entries[$id]->total ?? 0),
            'recipes' => (int) ($recipes[$id] ?? 0),
            'files' => (int) ($files[$id]->total ?? 0),
            'bytes' => (int) ($files[$id]->bytes ?? 0),
            'last_activity' => isset($entries[$id]) ? (string) $entries[$id]->latest : null,
        ]])->all();
    }

    /**
     * What the household shares, as data per table: private entries of members, personal data
     * without a household (journal, habits …) and credentials stay out.
     *
     * @return array<string, mixed>
     */
    public static function exportData(Household $household): array
    {
        $tables = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::SECRET_TABLES, true) || ! Schema::hasColumn($table, 'household_id')) {
                continue;
            }

            $columns = array_values(array_filter(Schema::getColumnListing($table), fn (string $column) => ! preg_match(self::SECRET_COLUMNS, $column)));
            $query = DB::table($table)->where('household_id', $household->id)->select($columns);

            if (in_array('visibility', $columns, true)) {
                $query->where(fn ($query) => $query->whereNull('visibility')->orWhere('visibility', '!=', Item::VISIBILITY_PRIVATE));
            }

            $rows = $query->orderBy($columns[0])->get();

            if ($rows->isNotEmpty()) {
                $tables[$table] = $rows;
            }
        }

        return [
            'exported_at' => now()->toIso8601String(),
            'household' => $household->only(['id', 'name', 'created_at']),
            'members' => $household->members()->with('user:id,name,email')->get()
                ->map(fn (HouseholdMember $member) => ['name' => $member->user?->name, 'email' => $member->user?->email, 'role' => $member->role, 'joined_at' => $member->joined_at]),
            'note' => 'Shared data of the household. Private entries of members, personal areas (journal, habits, health …) and access credentials are not included; every member can export their own data in their profile.',
            'tables' => $tables,
        ];
    }

    /**
     * The export as a ZIP encrypted with AES-256; the owners are told who exported it.
     */
    public static function export(User $admin, Household $household, string $password): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'e11household');
        $name = 'household-'.$household->id.'.json';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export could not be created.');
        }

        $zip->addFromString($name, (string) json_encode(self::exportData($household), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
        $zip->close();

        SecurityLog::record($admin, 'admin_action', details: 'household_exported #'.$household->id);
        self::owners($household)->each(function (User $owner) use ($admin, $household) {
            SecurityLog::record($owner, 'export', details: 'by administrator '.$admin->name);
            rescue(fn () => app(WebPush::class)->send($owner, __('Your household data was exported'), __('The administrator :name exported the shared data of “:household” (transparency notice).', ['name' => $admin->name, 'household' => $household->name]), route('profile')));
        });

        return $path;
    }

    /**
     * Deletes the household with everything in it, including files; accounts that then belong to
     * no household are deleted too when wanted (never an administrator). Returns the number of
     * deleted accounts.
     */
    public static function delete(User $admin, Household $household, bool $withAccounts): int
    {
        $memberIds = $household->members()->pluck('user_id');
        $id = $household->id;

        // One by one where files hang on the records, so the model events remove them.
        RealEstateHandover::whereIn('real_estate_id', RealEstate::where('household_id', $id)->select('id'))->get()->each->delete();

        foreach ([Item::class, Attachment::class, ExternalLink::class, Recipe::class, Trip::class, RealEstate::class, BookkeepingEntry::class, CareerEntry::class] as $model) {
            $model::where('household_id', $id)->lazyById()->each->delete();
        }

        $household->delete();
        SecurityLog::record($admin, 'admin_action', details: 'household_deleted #'.$id);

        if (! $withAccounts) {
            return 0;
        }

        $orphans = User::whereIn('id', $memberIds)->where('is_admin', false)->whereKeyNot($admin->id)->whereDoesntHave('households')->get();
        $orphans->each->delete();

        return $orphans->count();
    }

    /**
     * @return Collection<int, User>
     */
    public static function owners(Household $household): Collection
    {
        return User::whereIn('id', HouseholdMember::where('household_id', $household->id)->where('role', 'owner')->select('user_id'))->get();
    }

    /**
     * Records a change of members in the admin's security log and tells the member in the app.
     */
    public static function logMemberChange(User $admin, Household $household, User $member, string $change): void
    {
        SecurityLog::record($admin, 'admin_action', details: $change.' #'.$household->id.' user #'.$member->id);
        rescue(fn () => InAppNotifications::record($member, __('Your household membership changed'), __('The administrator :name changed your membership in “:household”.', ['name' => $admin->name, 'household' => $household->name])));
    }
}
