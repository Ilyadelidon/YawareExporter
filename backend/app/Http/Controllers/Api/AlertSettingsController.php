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
        return response()->json($this->state($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Порожній список = вимкнути сповіщення, тому present, а не required.
            'alert_emails' => ['present', 'array', 'max:'.self::MAX_EMAILS],
            'alert_emails.*' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Однакові адреси, написані по-різному, зводяться в одну — це не
        // помилка вводу, а звичайна неуважність, і питати про неї нема сенсу.
        $user->update(['alert_emails' => User::normaliseAlertEmails($validated['alert_emails'])]);

        $emails = $user->alertEmails();

        return response()->json($this->state($user) + [
            'message' => $emails === []
                ? 'Листи про критичні порушення вимкнено.'
                : 'Листи про критичні порушення йтимуть на '.implode(', ', $emails).'.',
        ]);
    }

    /** @return array<string, mixed> */
    private function state(User $user): array
    {
        $mine = $user->alertEmails();

        return [
            'alert_emails' => $mine,
            // Скільки адрес отримують ці листи повз поточного адміністратора —
            // щоб керівник бачив, що порушення не залишаться без адресата,
            // коли він прибере свої.
            'others_count' => count(array_diff(User::adminAlertEmails(except: $user), $mine)),
            'max_emails' => self::MAX_EMAILS,
        ];
    }
}
