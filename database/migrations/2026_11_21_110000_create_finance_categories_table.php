<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('user_id');
            // Stable slug stored on transactions.category (e.g. "cat_ab12cd34").
            $table->string('key');
            $table->string('label');
            $table->string('icon', 16)->default('🏷️');
            // 50/30/20 bucket: needs, wants or savings.
            $table->string('group', 16)->default('wants');
            $table->timestamps();

            $table->unique(['household_id', 'key']);
            $table->index('household_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_categories');
    }
};
