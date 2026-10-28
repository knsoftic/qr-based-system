<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id', 40)->unique();
            $table->string('transaction_id', 25)->unique();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_id', 64);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            // pending | paid | expired | failed | cancelled
            $table->string('status', 16)->default('pending');
            // Encrypted dynamic QR payload.
            $table->text('qr_payload')->nullable();
            $table->string('description')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('callback_url')->nullable();
            $table->string('return_url')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->string('verification_reference')->nullable();
            // admin:{id} | api | system
            $table->string('verified_by', 32)->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('callback_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'idempotency_key']);
            $table->index(['merchant_id', 'order_id']);
            $table->index(['status', 'expires_at']);
            $table->index(['payment_account_id', 'status', 'amount']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
