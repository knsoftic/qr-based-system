<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_accounts', function (Blueprint $table) {
            // Monthly cap (daily_limit already exists). Null = no limit.
            $table->decimal('monthly_limit', 16, 2)->nullable()->after('daily_limit');
            // Admin can force an account into maintenance regardless of limits.
            $table->boolean('maintenance')->default(false)->after('is_active');
            $table->softDeletes();
        });

        // Per-account monthly history, snapshotted on the 1st of each month.
        Schema::create('account_monthly_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('verified')->default(0);
            $table->unsignedInteger('expired')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->decimal('received', 16, 2)->default(0);
            $table->decimal('fees', 16, 2)->default(0);
            $table->decimal('net', 16, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_account_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_monthly_stats');

        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->dropColumn(['monthly_limit', 'maintenance', 'deleted_at']);
        });
    }
};
