<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            // Null: a signed-in device. "quick": a shortcut that may only add things.
            $table->string('scope', 20)->nullable()->after('name');
        });

        // Shortcut tokens issued so far are recognisable by their name only.
        DB::table('api_tokens')->where('name', 'like', 'Siri / %')->update(['scope' => 'quick']);
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
