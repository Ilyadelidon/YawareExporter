<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Акаунт працівника на порталі Бітрікса, отриманий через OAuth. Особу
     * підтверджує сам Бітрікс (profile від імені виданого токена), тож
     * підставити чужий bitrix_user_id неможливо.
     */
    public function up(): void
    {
        Schema::create('bitrix_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Один акаунт порталу — рівно один наш користувач.
            $table->string('bitrix_user_id')->unique();
            $table->string('bitrix_user_name')->nullable();
            $table->string('bitrix_email')->nullable();
            // Ідентифікатор порталу з відповіді OAuth — страховка від авторизації
            // на чужому порталі під тим самим застосунком.
            $table->string('member_id')->nullable();
            // База REST саме цього порталу (https://team.bitrix24.ua/rest/).
            $table->string('client_endpoint');
            // text: обидва токени лежать зашифрованими (касти в моделі).
            $table->text('access_token');
            $table->text('refresh_token');
            // Термін життя access_token — година; далі оновлюємо через refresh_token.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix_accounts');
    }
};
