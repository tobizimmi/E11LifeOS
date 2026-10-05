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
        Schema::create('roadmap_milestones', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('version', 30)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 15)->default('planned');
            $table->date('due_on')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roadmap_milestones');
    }
};
