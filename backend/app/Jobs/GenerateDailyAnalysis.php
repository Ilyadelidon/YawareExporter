<?php

namespace App\Jobs;

use App\Models\DailyAnalysis;
use App\Models\Report;
use App\Services\Ai\EmployeeMemoryService;
use App\Services\AiAnalysisService;
use App\Services\ViolationAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI-розбір робочого дня. Ставиться в чергу після успішного звіту, тому до
 * моменту запуску activity_entries і daily_stats за цей день уже заповнені.
 *
 * Окрема черга analysis — щоб десятихвилинний запит до моделі не стояв між
 * звітами: у спільній черзі ранковий прогін по команді розтягувався вдвічі
 * (звіт до 11 хв + розбір до 10 хв послідовно на кожного працівника).
 */
class GenerateDailyAnalysis implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'analysis';

    // Запит із web_search на високому effort може думати кілька хвилин.
    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  ?string  $provider  null — провайдер за замовчуванням із .env.
     * @param  bool  $force  Питати модель навіть тоді, коли дані дня не
     *                       змінились. Так приходить ручна перегенерація:
     *                       адміністратор натиснув кнопку саме щоб дістати
     *                       новий розбір.
     */
    public function __construct(
        public Report $report,
        public ?string $provider = null,
        public bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(
        AiAnalysisService $service,
        EmployeeMemoryService $memory,
        ViolationAlertService $alerts,
    ): void {
        $report = $this->report->fresh('employee');

        if (! $report || ! $report->employee || ! $service->isConfigured($this->provider)) {
            return;
        }

        $date = $report->report_date->toDateString();
        $context = $service->buildContext($report);
        $hash = AiAnalysisService::contextHash($context);

        // Звіт за день перегенеровується часто, а дані дня при цьому зазвичай ті
        // самі — питати модель удруге означає заплатити ще раз за той самий
        // текст. Готовий розбір у такому разі лишаємо як є.
        if (! $this->force && $this->isUpToDate($service, $report->employee_id, $date, $hash)) {
            Log::info("AI-аналіз звіту #{$report->id} пропущено: дані дня не змінилися з минулого розбору.");

            return;
        }

        $analysis = DailyAnalysis::updateOrCreate(
            ['employee_id' => $report->employee_id, 'date' => $date],
            ['status' => DailyAnalysis::STATUS_PROCESSING, 'error_message' => null],
        );

        try {
            $outcome = $service->analyse($report, $this->provider, $context);
        } catch (Throwable $exception) {
            Log::warning("AI-аналіз звіту #{$report->id} не виконано: {$exception->getMessage()}");

            $analysis->update([
                'status' => DailyAnalysis::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return;
        }

        $analysis->update([
            'status' => DailyAnalysis::STATUS_COMPLETED,
            'provider' => $outcome['provider'],
            'result' => $outcome['result'],
            'model' => $outcome['model'],
            'input_tokens' => $outcome['input_tokens'],
            'output_tokens' => $outcome['output_tokens'],
            'context_hash' => $hash,
            'error_message' => null,
            'generated_at' => now(),
        ]);

        // Пам'ять поповнюємо після збереження: якщо впаде вона, готовий розбір
        // уже на місці й дивитись його можна.
        try {
            $memory->remember($report->employee_id, $outcome['result'], $date);
        } catch (Throwable $exception) {
            Log::warning("Пам'ять AI для звіту #{$report->id} не оновлено: {$exception->getMessage()}");
        }

        // Лист керівнику — останнім кроком і теж без права завалити джобу:
        // мертвий SMTP не повинен позначати готовий розбір як невдалий.
        try {
            $alerts->notify($analysis);
        } catch (Throwable $exception) {
            Log::warning("Сповіщення про порушення за звітом #{$report->id} не надіслано: {$exception->getMessage()}");
        }
    }

    /**
     * Чи лежить за цей день готовий розбір саме з цих даних і саме тим
     * провайдером, яким його зараз просять зробити.
     */
    private function isUpToDate(AiAnalysisService $service, int $employeeId, string $date, string $hash): bool
    {
        $analysis = DailyAnalysis::where('employee_id', $employeeId)
            ->where('date', $date)
            ->first();

        return $analysis
            && $analysis->status === DailyAnalysis::STATUS_COMPLETED
            && $analysis->context_hash === $hash
            && $analysis->provider === $service->provider($this->provider)->name();
    }

    public function failed(Throwable $exception): void
    {
        $report = $this->report->fresh();

        if (! $report) {
            return;
        }

        DailyAnalysis::updateOrCreate(
            ['employee_id' => $report->employee_id, 'date' => $report->report_date->toDateString()],
            [
                'status' => DailyAnalysis::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ],
        );
    }
}
