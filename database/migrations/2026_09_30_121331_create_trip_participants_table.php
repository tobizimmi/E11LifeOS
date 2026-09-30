<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group trips and club outings: participants with a personal link, and a trip folder that
     * external people open with a link and a password.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique();
            $table->text('share_token_encrypted')->nullable();
            $table->timestamp('share_expires_at')->nullable();
            $table->string('share_password')->nullable();
            $table->boolean('share_references')->default(false);
            $table->string('meeting_point', 255)->nullable();
            $table->string('organizer_contact', 150)->nullable();
            $table->text('group_info')->nullable();
        });

        Schema::create('trip_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->string('status', 12)->default('invited');
            $table->text('personal_info')->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->text('share_token_encrypted')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_participants');

        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['share_token', 'share_token_encrypted', 'share_expires_at', 'share_password', 'share_references', 'meeting_point', 'organizer_contact', 'group_info']);
        });
    }
};
