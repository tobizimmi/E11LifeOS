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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 16)->default('shared');
            $table->string('name');
            $table->string('relation', 64)->nullable();
            $table->date('birthday')->nullable();
            $table->boolean('birth_year_known')->default(true);
            $table->date('anniversary')->nullable();
            $table->string('anniversary_label', 64)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('remind_days')->default(7);
            $table->date('last_contact_on')->nullable();
            $table->timestamps();
        });

        Schema::create('gift_ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('url', 2000)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status', 16)->default('idea');
            $table->string('occasion', 64)->nullable();
            $table->date('given_on')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gift_ideas');
        Schema::dropIfExists('contacts');
    }
};
