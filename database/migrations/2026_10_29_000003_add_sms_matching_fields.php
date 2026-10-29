<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            // Payer's mobile number and the bank reference / TID read out of the SMS text.
            $table->string('payer_phone', 20)->nullable()->after('sim_name');
            $table->string('bank_ref', 40)->nullable()->after('payer_phone');
            // credit | debit | other: only "credit" SMS can verify a payment.
            $table->string('direction', 8)->nullable()->after('bank_ref');
            $table->foreignId('matched_payment_id')->nullable()->after('direction')->constrained('payments')->nullOnDelete();
            $table->unsignedTinyInteger('match_score')->nullable()->after('matched_payment_id');
            $table->boolean('auto_verified')->default(false)->after('match_score');

            $table->index('matched_payment_id');
            $table->index('payer_phone');
        });

        Schema::table('payments', function (Blueprint $table) {
            // The payer's mobile number, captured from the matched SMS.
            $table->string('payer_phone', 20)->nullable()->after('customer_phone');
            // The SMS that verified this payment (set on auto / SMS verification).
            $table->unsignedBigInteger('sms_message_id')->nullable()->after('verified_by');
            $table->index('payer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_payment_id');
            $table->dropColumn(['payer_phone', 'bank_ref', 'direction', 'match_score', 'auto_verified']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payer_phone', 'sms_message_id']);
        });
    }
};
