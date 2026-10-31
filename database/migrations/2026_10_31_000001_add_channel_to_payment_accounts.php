<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The payment method / company this account belongs to (easypaisa,
        // JazzCash, Raast, a bank, ...). Accounts are grouped by channel so a
        // whole method can be put into maintenance at once.
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->string('channel', 50)->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
