<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable payment links (/p/{code}) a merchant can share without a
     * website. Each visit that taps "Pay" creates a fresh payment, so a link
     * never goes stale the way a 5-minute QR does.
     */
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('title', 100);
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 12, 2)->nullable(); // null = the customer enters the amount
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_link_id')->nullable()->index()->after('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payment_link_id']);
            $table->dropColumn('payment_link_id');
        });

        Schema::dropIfExists('payment_links');
    }
};
