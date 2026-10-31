<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Short, unguessable code for an anonymous checkout link (/s/{slug}),
        // which renders the checkout directly without exposing the payment_id.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('slug', 16)->nullable()->unique()->after('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
