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
        Schema::table('securities', function (Blueprint $table) {
            // Key figures (P/E, debt/equity, growth, margins, analyst rating, profile …).
            $table->json('fundamentals')->nullable()->after('beta');
            $table->timestamp('fundamentals_updated_at')->nullable()->after('fundamentals');
            // Market scan: the Yahoo screeners the security appeared in (losers, undervalued …).
            $table->json('screeners')->nullable()->after('fundamentals_updated_at');
            $table->timestamp('scanned_at')->nullable()->index()->after('screeners');
        });

        // Buy recommendations per user (their strategy), recomputed daily.
        Schema::create('portfolio_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('name')->default('');
            $table->decimal('price', 15, 4);
            $table->string('currency', 3)->nullable();
            $table->decimal('period_high', 15, 4)->nullable();
            $table->decimal('drop_percent', 6, 2);
            $table->decimal('rsi', 5, 1)->nullable();
            $table->decimal('stability', 5, 1)->nullable();
            $table->decimal('score', 5, 1);
            // strong_buy, moderate_buy, watchlist_buy
            $table->string('rating', 16);
            $table->boolean('below_ma200')->default(false);
            $table->boolean('has_dividend')->default(false);
            $table->json('reasons')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('computed_at');
            $table->unique(['user_id', 'symbol']);
            $table->index(['user_id', 'score']);
        });

        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            // Null for general market news.
            $table->string('symbol', 32)->nullable()->index();
            $table->string('title', 600);
            $table->string('url', 1000);
            $table->string('url_hash', 40)->unique();
            $table->string('source', 100)->nullable();
            $table->string('feed', 100)->nullable();
            $table->text('summary')->nullable();
            $table->string('thumbnail', 1000)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_articles');
        Schema::dropIfExists('portfolio_recommendations');

        Schema::table('securities', function (Blueprint $table) {
            $table->dropIndex(['scanned_at']);
            $table->dropColumn(['fundamentals', 'fundamentals_updated_at', 'screeners', 'scanned_at']);
        });
    }
};
