<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groups / channels the bot has been seen in (added to, or a message /
     * channel post arrived from). The admin picks one of these from the
     * merchant form to attach notifications to that chat.
     */
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 32)->unique();
            $table->string('type', 16);              // group | supergroup | channel
            $table->string('title')->nullable();
            $table->string('username', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chats');
    }
};
