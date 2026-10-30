<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merchant withdrawal requests. The balance a merchant can withdraw is
        // their paid net earnings minus pending (frozen) and paid-out payouts.
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('payout_ref', 32)->unique();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('method', 16);               // easypaisa | jazzcash
            $table->string('account_name', 100)->nullable();
            $table->string('account_number', 20);       // receiving mobile number
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('PKR');
            $table->string('status', 12)->default('pending'); // pending | paid | rejected
            $table->string('note', 255)->nullable();     // merchant's note
            $table->string('admin_note', 255)->nullable();
            $table->string('reference', 100)->nullable(); // bank TID once sent
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
