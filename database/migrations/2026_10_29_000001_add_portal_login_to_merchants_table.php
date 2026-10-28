<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // Public merchant identifier shown in the portal (not a credential).
            $table->string('merchant_code', 20)->nullable()->unique()->after('id');
            $table->string('username', 50)->nullable()->unique()->after('email');
            $table->string('password')->nullable()->after('username');
            $table->boolean('portal_enabled')->default(false)->after('is_active');
            // Bumped on password reset / disable: every open portal session is signed out.
            $table->unsignedInteger('session_version')->default(0)->after('portal_enabled');
            $table->timestamp('last_login_at')->nullable()->after('session_version');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });

        DB::table('merchants')->whereNull('merchant_code')->orderBy('id')->each(function ($merchant) {
            DB::table('merchants')->where('id', $merchant->id)->update(['merchant_code' => 'MID'.Str::upper(Str::random(9))]);
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropUnique(['merchant_code']);
            $table->dropUnique(['username']);
            $table->dropColumn(['merchant_code', 'username', 'password', 'portal_enabled', 'session_version', 'last_login_at', 'last_login_ip']);
        });
    }
};
