<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // The number the customer says they will pay FROM, entered on the
            // checkout page. SMS verification matches the payer number against it.
            $table->string('claimed_phone', 20)->nullable()->after('customer_phone');
            // Human-readable explanation of how/why the payment was verified.
            $table->string('verification_reason', 400)->nullable()->after('verification_reference');

            $table->index('claimed_phone');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['claimed_phone', 'verification_reason']);
        });
    }
};
