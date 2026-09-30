<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('network_drives', function (Blueprint $table) {
            $table->string('type', 20)->default('webdav')->after('name');
            $table->text('token')->nullable()->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('network_drives', function (Blueprint $table) {
            $table->dropColumn(['type', 'token']);
        });
    }
};
