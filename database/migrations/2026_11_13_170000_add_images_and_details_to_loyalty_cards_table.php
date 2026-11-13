<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Makes loyalty cards look like the real thing: photos of the physical card (front/back), a link
     * to the online/app account, and free labelled texts for cards without a photo.
     */
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->string('front_image_path')->nullable()->after('color');
            $table->string('back_image_path')->nullable()->after('front_image_path');
            $table->string('link_url', 500)->nullable()->after('back_image_path');
            // List of {label, value} rows shown on the card / in its detail view.
            $table->json('details')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->dropColumn(['front_image_path', 'back_image_path', 'link_url', 'details']);
        });
    }
};
