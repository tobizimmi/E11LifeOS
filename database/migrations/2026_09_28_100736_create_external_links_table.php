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
        // Connections to paperless-ngx: each belongs to a member and is either only theirs or
        // shared with the whole household (the others then use its token).
        Schema::create('paperless_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->text('token');
            $table->boolean('shared')->default(false);
            $table->timestamps();
        });

        // Documents that live elsewhere, attached to entries, properties or invoices: paperless-ngx
        // documents (only the id is stored), links to e-mails and – where no link works – a copy of
        // the mail as a file.
        Schema::create('external_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('linkable_type', 30);
            $table->unsignedBigInteger('linkable_id');
            // "paperless", "mail" or "file".
            $table->string('kind', 10);
            $table->foreignId('paperless_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('paperless_document_id')->nullable();
            // Upload still being processed by paperless (consumption task).
            $table->string('paperless_task_id', 64)->nullable();
            $table->string('url', 2000)->nullable();
            $table->string('title');
            $table->string('path')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();

            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_links');
        Schema::dropIfExists('paperless_instances');
    }
};
