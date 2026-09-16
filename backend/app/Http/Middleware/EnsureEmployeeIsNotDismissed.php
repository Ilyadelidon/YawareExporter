<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Третій рубіж звільнення. Токени відкликаються в мить звільнення, а вхід його
 * перевіряє, — але між видачею токена і натисканням кнопки є вікно в частки
 * секунди, та й токен може прийти з майбутнього шляху, про який ця перевірка
 * не знатиме. Тому кожен запит ще раз питає: чи не звільнили власника токена.
 *
 * Відповідь саме 401: для SPA це «сесії більше немає», і вона сама виходить на
 * сторінку входу (див. frontend/src/api/client.js). 403 вона показала б як
 * помилку на порожній сторінці.
 */
class EnsureEmployeeIsNotDismissed
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user()?->employee;

        if ($employee?->isDismissed()) {
            $token = $request->user()->currentAccessToken();

            // Перевірка на клас, а не просто ?->delete(): під час тестів
            // Sanctum підставляє TransientToken, у якого видаляти нічого.
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json([
                'message' => 'Доступ до сервісу закрито. Зверніться до адміністратора.',
            ], 401);
        }

        return $next($request);
    }
}
