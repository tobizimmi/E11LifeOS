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
        Schema::table('items', function (Blueprint $table) {
            // Page hierarchy. Deleting a page moves its subpages up one level (ItemObserver);
            // nullOnDelete only covers mass deletes that bypass the observer.
            $table->foreignId('parent_id')->nullable()->after('household_id')->constrained('items')->nullOnDelete();
            // A single emoji shown in front of the page title.
            $table->string('icon', 16)->nullable()->after('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('icon');
        });
    }
};
