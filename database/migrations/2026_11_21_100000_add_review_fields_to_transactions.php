<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // When set, this booking is a confirmed duplicate of transaction #id and is excluded
            // from reports and totals (kept, not deleted, so the decision can be undone).
            $table->unsignedBigInteger('duplicate_of_id')->nullable()->after('import_hash');
            // The owner dismissed a transfer/duplicate suggestion for this booking, so it is not
            // suggested again.
            $table->timestamp('review_ignored_at')->nullable()->after('duplicate_of_id');

            $table->index('duplicate_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['duplicate_of_id']);
            $table->dropColumn(['duplicate_of_id', 'review_ignored_at']);
        });
    }
};
