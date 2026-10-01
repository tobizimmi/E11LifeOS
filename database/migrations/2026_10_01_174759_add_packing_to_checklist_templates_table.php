<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Own templates can be private and can carry packing items with their category, so the same
     * template works as a packing list, a task with sub-items and a checklist page.
     */
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->string('visibility', 20)->default('shared')->after('icon');
            $table->json('packing')->nullable()->after('tasks');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'packing']);
        });
    }
};
