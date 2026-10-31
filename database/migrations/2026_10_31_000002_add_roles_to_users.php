<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff roles: existing admins become super_admin (full access). Managers
        // and agents get a per-user permission set, an optional supervisor
        // (parent_id) and their pay (salary and/or pay-in / pay-out commission).
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('super_admin')->after('email')->index();
            $table->json('permissions')->nullable()->after('role');
            $table->foreignId('parent_id')->nullable()->after('permissions')->constrained('users')->nullOnDelete();
            $table->decimal('salary', 14, 2)->nullable()->after('parent_id');
            $table->decimal('payin_commission', 5, 2)->nullable()->after('salary');
            $table->decimal('payout_commission', 5, 2)->nullable()->after('payin_commission');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['role', 'permissions', 'salary', 'payin_commission', 'payout_commission']);
        });
    }
};
