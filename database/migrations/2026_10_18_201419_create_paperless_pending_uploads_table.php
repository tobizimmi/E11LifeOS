<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents that were scanned/uploaded while the paperless instance was unreachable: the file is
 * kept on disk and the scheduler retries the upload until paperless accepts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paperless_pending_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paperless_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('filename');
            $table->string('path');
            $table->string('tag')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('last_tried_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paperless_pending_uploads');
    }
};
