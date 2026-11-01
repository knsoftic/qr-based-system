<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-use, short-lived tokens for the "Connect Telegram" handshake. The
     * portal/admin shows a deep link https://t.me/<bot>?start=<token>; opening
     * it sends /start <token> to the bot, which binds that chat to the owner.
     */
    public function up(): void
    {
        Schema::create('telegram_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->morphs('linkable'); // linkable_type + linkable_id (Merchant|User)
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_tokens');
    }
};
