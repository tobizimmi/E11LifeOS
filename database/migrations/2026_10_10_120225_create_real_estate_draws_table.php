<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('real_estate_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('real_estate_loans')->nullOnDelete();
            $table->string('source', 20)->default('loan');
            $table->string('label');
            $table->decimal('planned_amount', 12, 2)->default(0);
            $table->date('planned_on')->nullable();
            $table->decimal('actual_amount', 12, 2)->default(0);
            $table->date('actual_on')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['real_estate_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_estate_draws');
    }
};
