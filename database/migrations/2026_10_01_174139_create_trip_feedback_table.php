<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comments on the trip plan and suggestions for it, from the household or via the trip folder.
     */
    public function up(): void
    {
        Schema::create('trip_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_stop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('trip_participants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 100);
            $table->string('type', 20)->default('comment');
            $table->text('body')->nullable();
            $table->string('title')->nullable();
            $table->string('location')->nullable();
            $table->date('day')->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_feedback');
    }
};
