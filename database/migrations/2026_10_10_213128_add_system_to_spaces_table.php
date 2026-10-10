<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            // System spaces (e.g. the "Notes" inbox) are hidden from the normal space pickers.
            $table->boolean('system')->default(false)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('system');
        });
    }
};
