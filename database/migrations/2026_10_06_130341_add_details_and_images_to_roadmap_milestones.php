<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_milestones', function (Blueprint $table) {
            // Longer explanation beyond the short description/goal.
            $table->longText('details')->nullable()->after('description');
        });

        Schema::create('roadmap_milestone_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_milestone_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_milestone_images');

        Schema::table('roadmap_milestones', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
