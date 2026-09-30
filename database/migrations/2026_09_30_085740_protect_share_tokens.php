<?php

use App\Support\Security\ShareToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** table => [token column, expiry column or null] */
    private const TOKENS = [
        'shopping_lists' => ['share_token', 'share_expires_at'],
        'recipes' => ['public_token', 'public_expires_at'],
        'collections' => ['public_token', 'public_expires_at'],
        'invoices' => ['public_token', null],
        'real_estate_tenants' => ['portal_token', null],
        'real_estates' => ['applicant_token', null],
        'users' => ['calendar_token', null],
    ];

    /**
     * Link tokens are stored as HMAC plus encrypted copy; user-made share links can expire.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->change();
        });

        foreach (self::TOKENS as $tableName => [$column, $expiry]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $expiry) {
                $table->text($column.'_encrypted')->nullable()->after($column);

                if ($expiry) {
                    $table->timestamp($expiry)->nullable()->after($column.'_encrypted');
                }
            });

            DB::table($tableName)->whereNotNull($column)->orderBy('id')->each(function (object $row) use ($tableName, $column) {
                DB::table($tableName)->where('id', $row->id)->update([
                    $column => ShareToken::hash($row->{$column}),
                    $column.'_encrypted' => ShareToken::encrypt($row->{$column}),
                ]);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TOKENS as $tableName => [$column, $expiry]) {
            DB::table($tableName)->whereNotNull($column.'_encrypted')->orderBy('id')->each(function (object $row) use ($tableName, $column) {
                DB::table($tableName)->where('id', $row->id)->update([$column => ShareToken::decrypt($row->{$column.'_encrypted'})]);
            });

            Schema::table($tableName, function (Blueprint $table) use ($column, $expiry) {
                $table->dropColumn(array_filter([$column.'_encrypted', $expiry]));
            });
        }
    }
};
