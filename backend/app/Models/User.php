<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'alert_emails', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'trello_token', 'telegram_chat_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_EMPLOYEE = 'employee';

    public const TASK_PROVIDER_TRELLO = 'trello';

    public const TASK_PROVIDER_BITRIX = 'bitrix';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'alert_emails' => 'array',
            'trello_token' => 'encrypted',
        ];
    }

    /**
     * Пошти цього користувача для листів про критичні порушення.
     *
     * @return list<string>
     */
    public function alertEmails(): array
    {
        return self::normaliseAlertEmails($this->alert_emails ?? []);
    }

    /**
     * Зводить список пошт до одного вигляду: без пробілів, у нижньому регістрі
     * й без повторів. Порівнювати треба саме так — інакше та сама адреса,
     * написана по-різному, отримає два однакові листи.
     *
     * @param  iterable<mixed>  $emails
     * @return list<string>
     */
    public static function normaliseAlertEmails(iterable $emails): array
    {
        $normalised = [];

        foreach ($emails as $email) {
            if (! is_string($email)) {
                continue;
            }

            $email = mb_strtolower(trim($email));

            if ($email !== '' && ! in_array($email, $normalised, true)) {
                $normalised[] = $email;
            }
        }

        return $normalised;
    }

    public function hasTrelloConnected(): bool
    {
        return $this->trello_token !== null;
    }

    /**
     * Портал Бітрікса підключає адміністратор на всю команду, а працівник
     * авторизується на ньому особисто — токен у кожного свій.
     */
    public function hasBitrixConnected(): bool
    {
        return $this->bitrixAccount !== null && BitrixWorkspace::active() !== null;
    }

    /**
     * Активний таск-трекер користувача: trello | bitrix.
     */
    public function taskProvider(): string
    {
        return $this->task_provider === self::TASK_PROVIDER_BITRIX
            ? self::TASK_PROVIDER_BITRIX
            : self::TASK_PROVIDER_TRELLO;
    }

    public function hasTelegramConnected(): bool
    {
        return $this->telegram_chat_id !== null;
    }

    /**
     * Публічні дані користувача для відповідей API (логін, /auth/me).
     */
    public function apiPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'task_provider' => $this->taskProvider(),
            'employee_id' => $this->employee?->id,
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** Особистий доступ до порталу Бітрікса — токени, видані самому працівнику. */
    public function bitrixAccount(): HasOne
    {
        return $this->hasOne(BitrixAccount::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
