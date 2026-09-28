<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlanProject;
use App\Models\PlanTaskDay;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Підказки працівнику в боковому меню: за які будні не сформовано звіт і в
 * які не відмічено жодної задачі в «Планах».
 *
 * Звіт за сьогодні не підказуємо — його сформує ранкова автогенерація.
 * Відмітку в планах за сьогодні підказуємо: її працівник ставить сам.
 */
class HintController extends Controller
{
    /** Скільки календарних днів назад дивимось (разом із сьогодні). */
    private const LOOKBACK_DAYS = 7;

    /** З якої години (за Києвом) нагадуємо відмітити задачі за сьогодні: вночі день ще не почався. */
    private const PLANS_TODAY_FROM_HOUR = 7;

    /** Година ранкової автогенерації (routes/console.php): звіт за будній день зʼявляється о 07:00 наступного буднього. */
    private const REPORTS_AUTOGEN_HOUR = 7;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        // Адміністратор власних звітів і планів не веде.
        if ($user->isAdmin() || ! $employee) {
            return response()->json(['today' => null, 'reports' => [], 'plans' => []]);
        }

        $now = CarbonImmutable::now('Europe/Kyiv');
        $today = $now->startOfDay();

        // Новенькому не нагадуємо про дні до того, як він уперше увійшов у сервіс.
        $joined = CarbonImmutable::parse($user->created_at)->setTimezone('Europe/Kyiv')->startOfDay();
        $from = $today->subDays(self::LOOKBACK_DAYS - 1)->max($joined);

        $weekdays = [];
        for ($day = $from; $day->lte($today); $day = $day->addDay()) {
            if ($day->isWeekday()) {
                $weekdays[] = $day->toDateString();
            }
        }

        return response()->json([
            'today' => $today->toDateString(),
            'reports' => $this->missingReports($employee->id, array_values(array_diff($weekdays, [$today->toDateString()])), $now),
            'plans' => $this->daysWithoutPlanTasks(
                $user,
                $employee->id,
                $now->hour < self::PLANS_TODAY_FROM_HOUR ? array_values(array_diff($weekdays, [$today->toDateString()])) : $weekdays,
            ),
        ]);
    }

    /**
     * Будні без готового звіту: звіту немає, генерація впала, звіт
     * заблоковано через час поза тасками або він готовий лише формально —
     * без тасок чи без вивантаження в Google (incomplete). Той, що
     * формується, пропуском не є.
     *
     * Поки ранкова автогенерація за день ще не відбулась (о 07:00 наступного
     * буднього; за пʼятницю — у понеділок), відсутній чи впалий звіт не
     * підказуємо: його сформують самі.
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
                $report->report_date->toDateString() => $report->isIncomplete() ? 'incomplete' : $report->status,
            ]);

        $missing = [];

        foreach (array_reverse($days) as $day) {
            $status = $statuses[$day] ?? 'none';

            $autogenAt = CarbonImmutable::parse($day, 'Europe/Kyiv')->nextWeekday()->setTime(self::REPORTS_AUTOGEN_HOUR, 0);
            if (in_array($status, ['none', Report::STATUS_FAILED], true) && $now->lt($autogenAt)) {
                continue;
            }

            if (in_array($status, ['none', 'incomplete', Report::STATUS_FAILED, Report::STATUS_BLOCKED], true)) {
                $missing[] = ['date' => $day, 'status' => $status];
            }
        }

        return $missing;
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
            ->unique()
            ->all();

        return array_values(array_reverse(array_diff($days, $marked)));
    }
}
