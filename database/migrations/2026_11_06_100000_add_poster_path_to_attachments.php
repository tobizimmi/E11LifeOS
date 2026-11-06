<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Poster frame of a video (extracted with ffmpeg on upload), for previews and PDF reports.
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('poster_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('poster_path');
        });
    }
};
