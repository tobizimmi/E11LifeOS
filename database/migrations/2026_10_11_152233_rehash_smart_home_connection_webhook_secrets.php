<?php

use App\Support\Security\ShareToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * webhook_secret was stored in the clear so far (a database leak would hand out every
     * household's working webhook address). It is replaced in place by its HMAC (same 64-char
     * length, used for the lookup in SmartHomeWebhookController); an encrypted copy in the new
     * column lets the app keep showing the configuration.yaml snippet, like a share link
     * (see App\Models\Concerns\HasShareTokens).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('smart_home_connections', 'webhook_secret_encrypted')) {
            Schema::table('smart_home_connections', function (Blueprint $table) {
                $table->text('webhook_secret_encrypted')->nullable()->after('webhook_secret');
            });
        }

        // Only rows that were never hashed by this migration before: running it twice (a retried
        // deploy, a manual re-run) must not hash an already-hashed secret into something nobody
        // can ever match again.
        DB::table('smart_home_connections')->whereNull('webhook_secret_encrypted')->orderBy('id')->each(function (object $row) {
            DB::table('smart_home_connections')->where('id', $row->id)->update([
                'webhook_secret' => ShareToken::hash($row->webhook_secret),
                'webhook_secret_encrypted' => ShareToken::encrypt($row->webhook_secret),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('smart_home_connections')->orderBy('id')->each(function (object $row) {
            $plain = ShareToken::decrypt($row->webhook_secret_encrypted);

            if ($plain !== null) {
                DB::table('smart_home_connections')->where('id', $row->id)->update(['webhook_secret' => $plain]);
            }
        });

        Schema::table('smart_home_connections', function (Blueprint $table) {
            $table->dropColumn('webhook_secret_encrypted');
        });
    }
};
