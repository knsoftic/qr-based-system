<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-managed IP allowlists, kept separately for pay-in (deposit) and
        // pay-out (withdrawal). One IP or CIDR per line. Empty = block (strict):
        // the public API for that side is refused until the admin adds an IP.
        Schema::table('merchants', function (Blueprint $table) {
            $table->text('payin_ip_allowlist')->nullable()->after('callback_url');
            $table->text('payout_ip_allowlist')->nullable()->after('payin_ip_allowlist');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['payin_ip_allowlist', 'payout_ip_allowlist']);
        });
    }
};
