<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groups across households: members from any household, contents shared with a group.
     */
    public function up(): void
    {
        Schema::create('share_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon', 16)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invite_token', 64)->nullable()->unique();
            $table->text('invite_token_encrypted')->nullable();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('share_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member');
            $table->timestamps();
            $table->unique(['share_group_id', 'user_id']);
        });

        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_group_id')->constrained()->cascadeOnDelete();
            $table->morphs('shareable');
            $table->foreignId('shared_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['share_group_id', 'shareable_type', 'shareable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shares');
        Schema::dropIfExists('share_group_members');
        Schema::dropIfExists('share_groups');
    }
};
