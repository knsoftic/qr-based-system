<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The agent who owns this merchant (single-level downline). Set when a
        // merchant is created from an approved request.
        Schema::table('merchants', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });
    }
};
