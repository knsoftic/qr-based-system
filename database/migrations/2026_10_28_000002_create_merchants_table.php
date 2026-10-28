<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('callback_url')->nullable();
            $table->string('api_key', 64)->unique();
            // Encrypted at rest (Laravel "encrypted" cast); needed in plain form to verify HMAC signatures.
            $table->text('api_secret');
            $table->timestamp('secret_rotated_at')->nullable();
            // Null means: use the global fee settings.
            $table->decimal('fee_percent', 5, 2)->nullable();
            $table->decimal('fee_fixed', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
