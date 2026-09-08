<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Список пошт замість однієї: керівник часто читає такі листи не в
            // одному місці (особиста скринька, спільна скринька відділу,
            // заступник), і кожна адреса має вмикатись і зніматись окремо.
            $table->json('alert_emails')->nullable()->after('email');
        });

        // Єдина пошта, яку керівник уже вписав, стає першим елементом списку.
        DB::table('users')
            ->whereNotNull('alert_email')
            ->orderBy('id')
            ->each(function (object $user) {
                $email = mb_strtolower(trim((string) $user->alert_email));

                if ($email === '') {
                    return;
                }

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['alert_emails' => json_encode([$email], JSON_UNESCAPED_UNICODE)]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('alert_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('alert_email')->nullable()->after('email');
        });

        // Назад вміщається лише перша адреса — решту стара схема не тримає.
        DB::table('users')
            ->whereNotNull('alert_emails')
            ->orderBy('id')
            ->each(function (object $user) {
                $emails = json_decode((string) $user->alert_emails, true);

                if (! is_array($emails) || $emails === []) {
                    return;
                }

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['alert_email' => (string) reset($emails)]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('alert_emails');
        });
    }
};
