<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remembers the recurring payments already seen per user, so the subscription radar can notify
     * about a newly appeared subscription or one whose amount went up.
     */
    public function up(): void
    {
        Schema::create('subscription_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('payment_key', 191);
            $table->string('name', 255);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'payment_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_watches');
    }
};
