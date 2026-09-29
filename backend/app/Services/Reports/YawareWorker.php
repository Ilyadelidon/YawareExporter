<?php

namespace App\Services\Reports;

use App\Models\Report;
use App\Support\WorkerEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Node-воркер (Playwright), який заходить у Yaware кредами працівника,
 * вивантажує звіт за день і збирає з нього Excel + history-data-*.json
 * у теці звіту.
 */
class YawareWorker
{
    /**
     * @param  array<int, array<string, mixed>>  $tasks  знімок тасок звіту (повний формат трекера)
     * @return array<string, mixed> підсумковий JSON воркера: file, dataFile, summary, warnings, outsideSeconds
     *
     * @throws YawareWorkerException
     */
    public function run(Report $report, array $tasks): array
    {
        $outputDirectory = storage_path('app/'.$report->filesDirectory());
        File::ensureDirectoryExists($outputDirectory);

        $employee = $report->employee;

        $process = new Process(
            [config('yaware.node_binary'), config('yaware.worker_script')],
            config('yaware.worker_cwd'),
            WorkerEnvironment::base() + [
                'YAWARE_WORKER' => 'true',
                'YAWARE_EMAIL' => $employee->email,
                'YAWARE_PASSWORD' => $employee->yaware_password,
                'YAWARE_DATE' => $report->report_date->format('d.m.Y'),
                'YAWARE_TARGET_EMAIL' => $employee->email,
                'YAWARE_DOWNLOAD_DIR' => $outputDirectory,
                'YAWARE_HEADLESS' => config('yaware.headless') ? 'true' : 'false',
                // Історична назва змінної воркера: сюди йдуть таски будь-якого
                // трекера — форма однакова, і Excel-скрипт про різницю не знає.
                'YAWARE_TRELLO_TASKS' => json_encode($this->workerTasks($tasks), JSON_UNESCAPED_UNICODE),
            ],
            null,
            (float) config('yaware.timeout'),
        );

        try {
            $process->mustRun();

            return $this->parseResult($process->getOutput());
        } catch (Throwable $exception) {
            throw $this->failure($report, $process, $exception);
        }
    }

    /**
     * Спрощений формат тасок для Excel-воркера (колонка «Завдання» і табличка тасок).
     * Форма однакова для обох трекерів, тож воркер про різницю не знає.
     *
     * @return array<int, array<string, ?string>>
     */
    private function workerTasks(array $tasks): array
    {
        return array_map(fn (array $task) => [
            'name' => $task['name'],
            'comment' => $task['comment'],
            'start' => $task['start'],
            'due' => $task['due'],
        ], $tasks);
    }

    private function parseResult(string $stdout): array
    {
        $result = $this->lastJsonLine($stdout);

        if (! is_array($result) || ($result['status'] ?? null) !== 'ok' || empty($result['file'])) {
            throw new RuntimeException('Воркер завершився без валідного JSON-результату. Stdout: '.mb_substr($stdout, -500));
        }

        if (! is_file($result['file'])) {
            throw new RuntimeException("Воркер повідомив про файл, якого не існує: {$result['file']}");
        }

        return $result;
    }

    /**
     * Помилка від самого воркера: при падінні він віддає останнім рядком
     * stdout JSON {status: 'error', message, code, screenshot}; скріншот
     * сторінки лишається на диску в теці звіту і в UI не показується.
     * Якщо структурованої помилки немає — текст винятку з хвостом stderr.
     */
    private function failure(Report $report, Process $process, Throwable $exception): YawareWorkerException
    {
        $stderrTail = mb_substr(trim($process->getErrorOutput()), -1500);
        $result = $this->lastJsonLine($process->getOutput());

        if (! is_array($result) || ($result['status'] ?? null) !== 'error' || empty($result['message'])) {
            return new YawareWorkerException(
                mb_substr(trim($exception->getMessage()."\n\n".$stderrTail), 0, 2000),
                previous: $exception,
            );
        }

        Log::warning("Воркер звіту #{$report->id} завершився з помилкою: {$result['message']}", [
            'screenshot' => $result['screenshot'] ?? null,
            'stderr' => $stderrTail,
        ]);

        return new YawareWorkerException(
            $result['message'],
            is_string($result['code'] ?? null) ? $result['code'] : null,
            $exception,
        );
    }

    private function lastJsonLine(string $stdout): mixed
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));

        return $lines ? json_decode((string) end($lines), true) : null;
    }
}
