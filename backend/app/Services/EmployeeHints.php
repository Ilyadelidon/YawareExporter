<?php

namespace App\Services;

use App\Models\PlanProject;
use App\Models\PlanTaskDay;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Що працівнику ще треба зробити: за які будні не сформовано звіт і в які
 * не відмічено жодної задачі в «Планах». Дні — від найсвіжішого до
 * давнішого, за останні LOOKBACK_DAYS і не раніше першого входу в сервіс.
 */
class EmployeeHints
{
    public const TIMEZONE = 'Europe/Kyiv';

    /** Скільки календарних днів назад дивимось (разом із сьогодні). */
    private const LOOKBACK_DAYS = 7;

    /**
     * Початок робочого ранку за Києвом. О цій годині йде автогенерація
     * звітів (routes/console.php), і з неї ж нагадуємо про плани за сьогодні:
     * вночі день ще не почався.
     */
    private const MORNING_HOUR = 7;

    /** Звіту за день немає зовсім. */
    public const REPORT_NONE = 'none';

    /** Звіт готовий лише формально — див. Report::isIncomplete(). */
    public const REPORT_INCOMPLETE = 'incomplete';

    /**
     * @return array{today: string, reports: list<array{date: string, status: string}>, plans: list<string>}
     */
    public function forUser(User $user, CarbonImmutable $now): array
    {
        $now = $now->setTimezone(self::TIMEZONE);
        $today = $now->startOfDay();
        $employeeId = $user->employee->id;

        $weekdays = $this->weekdays($user, $today);
        $pastWeekdays = array_values(array_diff($weekdays, [$today->toDateString()]));

        return [
            'today' => $today->toDateString(),
            // Звіт за сьогодні не підказуємо — його сформує ранкова автогенерація.
            'reports' => $this->missingReports($employeeId, $pastWeekdays, $now),
            'plans' => $this->daysWithoutPlanTasks(
                $user,
                $employeeId,
                $now->hour < self::MORNING_HOUR ? $pastWeekdays : $weekdays,
            ),
        ];
    }

    /**
     * Будні вікна від давнішого до сьогодні. Новенькому не нагадуємо про
     * дні до того, як він уперше увійшов у сервіс.
     *
     * @return list<string>
     */
    private function weekdays(User $user, CarbonImmutable $today): array
    {
        $joined = CarbonImmutable::parse($user->created_at)->setTimezone(self::TIMEZONE)->startOfDay();
        $from = $today->subDays(self::LOOKBACK_DAYS - 1)->max($joined);

        $days = [];
        for ($day = $from; $day->lte($today); $day = $day->addDay()) {
            if ($day->isWeekday()) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    /**
     * Будні без готового звіту. Той, що формується, пропуском не є.
     *
     * @param  list<string>  $days
     * @return list<array{date: string, status: string}>
     */
    private function missingReports(int $employeeId, array $days, CarbonImmutable $now): array
    {
        if ($days === []) {
            return [];
        }

        $statuses = Report::where('employee_id', $employeeId)
            ->whereBetween('report_date', [$days[0], end($days)])
            ->get(['report_date', 'status', 'summary', 'tasks'])
            ->mapWithKeys(fn (Report $report) => [
                $report->report_date->toDateString() => $report->isIncomplete() ? self::REPORT_INCOMPLETE : $report->status,
            ]);

        $missing = [];

        foreach (array_reverse($days) as $day) {
            $status = $statuses[$day] ?? self::REPORT_NONE;

            if ($this->needsAction($status, $day, $now)) {
                $missing[] = ['date' => $day, 'status' => $status];
            }
        }

        return $missing;
    }

    /**
     * Неповний чи заблокований звіт працівник виправляє сам. Відсутній чи
     * впалий — лише коли ранкова автогенерація за день уже відбулась (о
     * 07:00 наступного буднього; за пʼятницю — у понеділок): до того його
     * сформують без працівника.
     */
    private function needsAction(string $status, string $day, CarbonImmutable $now): bool
    {
        return match ($status) {
            self::REPORT_INCOMPLETE, Report::STATUS_BLOCKED => true,
            self::REPORT_NONE, Report::STATUS_FAILED => $now->gte(
                CarbonImmutable::parse($day, self::TIMEZONE)->nextWeekday()->setTime(self::MORNING_HOUR, 0),
            ),
            default => false,
        };
    }

    /**
     * Будні, у які працівник не відмітив жодної своєї задачі. Хто не входить
     * у жоден активний проект, тому й відмічати нема де — підказки немає.
     *
     * @param  list<string>  $days
     * @return list<string>
     */
    private function daysWithoutPlanTasks(User $user, int $employeeId, array $days): array
    {
        if ($days === [] || ! PlanProject::visibleTo($user)->whereNull('archived_at')->exists()) {
            return [];
        }

        $marked = PlanTaskDay::whereBetween('date', [$days[0], end($days)])
            ->whereHas('task', fn ($task) => $task->where('employee_id', $employeeId))
            ->pluck('date')
            ->map(fn (CarbonImmutable $date) => $date->toDateString())
            ->all();

        return array_values(array_reverse(array_diff($days, $marked)));
    }
}
