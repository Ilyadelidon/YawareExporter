<?php

namespace App\Services;

use App\Mail\CriticalViolationMail;
use App\Models\DailyAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Листи керівникам про критичні порушення, знайдені AI-розбором дня.
 *
 * Адресатів беремо не з .env, а зі списку alert_emails адміністраторів: пошти
 * вписує сам керівник в інтерфейсі, і порожній список означає «мені не слати».
 * Якщо адресатів немає — це не помилка, а вимкнена функція.
 */
class ViolationAlertService
{
    /**
     * Розсилає лист за розбором дня, якщо в ньому є критичні порушення.
     * Повторно за той самий день не пише: позначка alerted_at стоїть на
     * розборі, а розбір перезапускається кнопкою «Проаналізувати ще раз».
     */
    public function notify(DailyAnalysis $analysis): void
    {
        if ($analysis->alerted_at !== null) {
            return;
        }

        $violations = $analysis->criticalViolations();

        if ($violations === []) {
            return;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            // Порушення є, а сказати нікому — це варто бачити в логах: інакше
            // мовчання виглядає як «AI нічого не знайшов».
            Log::info(
                "Критичні порушення за {$analysis->date->toDateString()} нікому не надіслано: "
                .'жоден адміністратор не вказав пошту для сповіщень.'
            );

            return;
        }

        $mail = new CriticalViolationMail(
            $analysis,
            $analysis->employee?->name ?: 'Працівник',
            $analysis->date->toDateString(),
            $violations,
        );

        $sent = false;

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send($mail);
                $sent = true;
            } catch (Throwable $exception) {
                // Один зіпсований адресат не має гасити розсилку решті.
                Log::warning("Лист про порушення на {$email} не надіслано: {$exception->getMessage()}");
            }
        }

        if ($sent) {
            $analysis->update(['alerted_at' => now()]);
        }
    }

    /**
     * Пошти всіх адміністраторів, які підписались на сповіщення.
     *
     * @return list<string>
     */
    private function recipients(): array
    {
        $emails = User::where('role', User::ROLE_ADMIN)
            ->whereNotNull('alert_emails')
            ->pluck('alert_emails')
            ->flatMap(fn (mixed $emails) => is_array($emails) ? $emails : [])
            ->all();

        // Спільну скриньку відділу можуть вписати собі кілька керівників —
        // зведення до нижнього регістру й унікальності лишає їй один лист.
        return User::normaliseAlertEmails($emails);
    }
}
