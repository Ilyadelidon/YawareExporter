<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Пошти керівника для листів про критичні порушення (див. ViolationAlertService).
 *
 * Список персональний: кожен адміністратор веде свої адреси сам, тому контролер
 * працює лише з поточним користувачем і чужих адрес не чіпає.
 */
class AlertSettingsController extends Controller
{
    /** Більше адрес одному керівнику — це вже розсилка, а не сповіщення. */
    private const MAX_EMAILS = 10;

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'alert_emails' => $user->alertEmails(),
            // Скільки адрес отримують ці листи повз поточного адміністратора —
            // щоб керівник бачив, що порушення не залишаться без адресата,
            // коли він прибере свої.
            'others_count' => $this->othersCount($user),
            'max_emails' => self::MAX_EMAILS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Порожній список = вимкнути сповіщення, тому present, а не required.
            'alert_emails' => ['present', 'array', 'max:'.self::MAX_EMAILS],
            'alert_emails.*' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        // Однакові адреси, написані по-різному, зводяться в одну — це не
        // помилка вводу, а звичайна неуважність, і питати про неї нема сенсу.
        $emails = User::normaliseAlertEmails($validated['alert_emails']);

        /** @var User $user */
        $user = $request->user();

        $user->update(['alert_emails' => $emails]);

        return response()->json([
            'alert_emails' => $emails,
            'others_count' => $this->othersCount($user),
            'max_emails' => self::MAX_EMAILS,
            'message' => $emails === []
                ? 'Листи про критичні порушення вимкнено.'
                : 'Листи про критичні порушення йтимуть на '.implode(', ', $emails).'.',
        ]);
    }

    /**
     * Адреси інших адміністраторів, яких не буде в списку поточного.
     */
    private function othersCount(User $user): int
    {
        $mine = $user->alertEmails();

        $others = User::where('role', User::ROLE_ADMIN)
            ->where('id', '!=', $user->id)
            ->whereNotNull('alert_emails')
            ->pluck('alert_emails')
            ->flatMap(fn (mixed $emails) => is_array($emails) ? $emails : [])
            ->all();

        return count(array_diff(User::normaliseAlertEmails($others), $mine));
    }
}
