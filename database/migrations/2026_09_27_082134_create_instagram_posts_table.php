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
        // Posts/videos of a finance Instagram profile with the AI's reading (stock idea, entry, stop loss).
        Schema::create('instagram_posts', function (Blueprint $table) {
            $table->id();
            $table->string('profile', 100)->index();
            $table->string('instagram_id', 100)->unique();
            $table->string('shortcode', 50)->default('');
            $table->text('caption')->nullable();
            $table->string('thumbnail_url', 1000)->nullable();
            $table->string('video_url', 1000)->nullable();
            $table->string('post_url', 500)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedInteger('view_count')->nullable();
            $table->longText('transcript')->nullable();
            $table->text('summary')->nullable();
            $table->json('tickers')->nullable();
            $table->string('stock_name')->nullable();
            // bullish, bearish, neutral, mixed
            $table->string('sentiment', 10)->nullable();
            $table->json('key_points')->nullable();
            $table->decimal('entry_price', 12, 4)->nullable();
            $table->string('entry_type', 100)->nullable();
            $table->decimal('stop_loss', 12, 4)->nullable();
            $table->string('currency', 3)->default('EUR');
            // Buying in up to three steps (prices, highest first).
            $table->json('tranches')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        Schema::table('portfolio_settings', function (Blueprint $table) {
            $table->boolean('instagram_push')->default(false)->after('news_push_portfolio_only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_settings', function (Blueprint $table) {
            $table->dropColumn('instagram_push');
        });

        Schema::dropIfExists('instagram_posts');
    }
};
