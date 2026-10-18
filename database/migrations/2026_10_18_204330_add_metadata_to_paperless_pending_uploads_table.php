<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the paperless metadata chosen while scanning (tags, correspondent, document type) so a
 * queued upload carries them too when it is retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paperless_pending_uploads', function (Blueprint $table) {
            $table->json('tag_ids')->nullable()->after('tag');
            $table->unsignedBigInteger('correspondent_id')->nullable()->after('tag_ids');
            $table->unsignedBigInteger('document_type_id')->nullable()->after('correspondent_id');
        });
    }

    public function down(): void
    {
        Schema::table('paperless_pending_uploads', function (Blueprint $table) {
            $table->dropColumn(['tag_ids', 'correspondent_id', 'document_type_id']);
        });
    }
};
