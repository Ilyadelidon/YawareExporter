<?php

namespace App\Services;

use App\Support\WorkerEnvironment;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class YawareAuthService
{
    /**
     * Перевіряє креди через Playwright-логін у Yaware.
     *
     * @return array{employee: array{id: string, name: string}|null}|null
     *                                                                    null — логін не вдався.
     */
    public function attemptLogin(string $email, string $password): ?array
    {
        set_time_limit(0);

        $process = new Process(
            [config('yaware.node_binary'), config('yaware.worker_script')],
            config('yaware.worker_cwd'),
            WorkerEnvironment::base() + [
                'YAWARE_WORKER' => 'true',
                'YAWARE_CHECK_LOGIN' => 'true',
                'YAWARE_EMAIL' => $email,
                'YAWARE_PASSWORD' => $password,
                'YAWARE_DATE' => now()->format('d.m.Y'),
                'YAWARE_HEADLESS' => config('yaware.headless') ? 'true' : 'false',
            ],
            null,
            120,
        );

        try {
            $process->mustRun();
        } catch (Throwable $exception) {
            Log::info('Yaware login check failed', [
                'email' => $email,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return null;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
        $result = $lines ? json_decode(end($lines), true) : null;

        if (! is_array($result) || ($result['status'] ?? null) !== 'ok') {
            return null;
        }

        return ['employee' => $result['employee'] ?? null];
    }
}
