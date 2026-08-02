<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Зовнішній сигнал живості (healthchecks.io, cronitor тощо).
 *
 * Навіщо він поруч із OpsMonitor: монітор працює на цьому ж сервері й
 * доповідає, поки сервер живий. Якщо ляже сам сервер, cron, PHP або мережа —
 * повідомити буде нікому, і мовчання неможливо відрізнити від «усе гаразд».
 *
 * Тому логіка обернена: сервіс регулярно пінгує зовнішній URL, а тривогу
 * піднімає той бік, коли черговий пінг не прийшов вчасно. Єдиний спосіб
 * дізнатися про повну смерть системи — щоб її чекав хтось ззовні.
 *
 * Порожній OPS_HEARTBEAT_URL повністю вимикає механізм.
 */
class HeartbeatService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.ops.heartbeat_url');
    }

    /**
     * Усе гаразд — сервіс живий.
     */
    public function ok(): void
    {
        $this->ping('');
    }

    /**
     * Проблеми є. Суфікс /fail розуміє healthchecks.io: він піднімає тривогу
     * одразу, не чекаючи закінчення інтервалу.
     */
    public function fail(): void
    {
        $this->ping('/fail');
    }

    private function ping(string $suffix): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $url = rtrim((string) config('services.ops.heartbeat_url'), '/').$suffix;

        try {
            Http::timeout(10)->get($url)->throw();
        } catch (Throwable $exception) {
            // Недоступний пінг не має валити перевірку стану — інакше збій
            // стороннього сервісу виглядав би як збій нашого.
            Log::warning("Зовнішній сигнал живості не надіслано: {$exception->getMessage()}");
        }
    }
}
