<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link a staff member (users) or a merchant to a Telegram chat so the bot
     * can push notifications and answer commands there. A chat maps to one
     * owner at a time (enforced in App\Telegram\LinkService).
     */
    public function up(): void
    {
        foreach (['users', 'merchants'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('telegram_chat_id', 32)->nullable()->index();
                $t->string('telegram_chat_type', 16)->nullable(); // private | group | supergroup | channel
                $t->string('telegram_username', 64)->nullable();
                $t->timestamp('telegram_linked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'merchants'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['telegram_chat_id', 'telegram_chat_type', 'telegram_username', 'telegram_linked_at']);
            });
        }
    }
};
