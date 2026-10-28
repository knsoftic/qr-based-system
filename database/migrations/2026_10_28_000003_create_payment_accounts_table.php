<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 32);
            // Uploaded QR image, stored on the private "local" disk.
            $table->string('qr_image_path')->nullable();
            // pending | decoded | failed | unsupported
            $table->string('decode_status', 16)->default('pending');
            $table->text('decode_error')->nullable();
            // Encrypted: the original payload and parsed template never leave the server.
            $table->text('raw_payload')->nullable();
            $table->text('payload_template')->nullable();
            $table->string('payload_hash', 64)->nullable()->unique();
            // Safe-to-display summary (masked identifiers only).
            $table->json('payload_summary')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('priority')->default(100);
            $table->decimal('daily_limit', 14, 2)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('decoded_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'decode_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};
