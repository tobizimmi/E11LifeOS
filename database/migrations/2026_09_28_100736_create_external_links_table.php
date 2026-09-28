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

        Schema::table('users', function (Blueprint $table) {
            $table->string('paperless_url')->nullable()->after('tax_children');
            $table->text('paperless_token')->nullable()->after('paperless_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_links');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['paperless_url', 'paperless_token']);
        });
    }
};
