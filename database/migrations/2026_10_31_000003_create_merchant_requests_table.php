<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An agent's request to create a merchant account. The main admin
        // (requests.review) approves it, which creates the merchant + portal
        // login with these settings, owned by the agent.
        Schema::create('merchant_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_ref', 32)->unique();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('website', 255)->nullable();
            $table->string('callback_url', 255)->nullable();
            $table->string('username', 50);
            $table->text('password');                       // encrypted desired portal password
            $table->decimal('payin_rate', 5, 2)->nullable();  // pay-in fee %
            $table->decimal('payout_rate', 5, 2)->nullable(); // pay-out fee %
            $table->string('note', 500)->nullable();          // agent's note
            $table->string('status', 12)->default('pending'); // pending | approved | rejected
            $table->string('admin_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_requests');
    }
};
