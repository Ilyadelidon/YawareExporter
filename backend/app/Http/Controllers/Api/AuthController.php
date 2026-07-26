<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckYawareLogin;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Email нормалізується, інакше Ilya@… та ilya@… створять двох користувачів.
        $credentials['email'] = mb_strtolower($credentials['email']);

        $user = User::where('email', $credentials['email'])->first();

        // Швидкий шлях: локальний пароль збігається (адмін або повторний вхід працівника).
        if ($user && Hash::check($credentials['password'], $user->password)) {
            return $this->respondWithToken($user);
        }

        // Локальні адмін-акаунти не перевіряємо через Yaware.
        if ($user && $user->isAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['Невірна пошта або пароль.'],
            ]);
        }

        // Повільний шлях: перевірка кредів у Yaware — асинхронно в черзі logins,
        // фронтенд полить /auth/login/pending/{id} і забирає токен звідти.
        $checkId = (string) Str::uuid();

        CheckYawareLogin::storeResult($checkId, ['status' => 'pending']);
        CheckYawareLogin::dispatch($checkId, $credentials['email'], $credentials['password']);

        return response()->json([
            'status' => 'pending',
            'check_id' => $checkId,
        ], 202);
    }

    /**
     * Стан асинхронної перевірки логіну. Готовий токен видається один раз —
     * після успішного зчитування результат одразу видаляється з кешу.
     */
    public function loginStatus(string $checkId): JsonResponse
    {
        $result = CheckYawareLogin::readResult($checkId);

        if ($result === null) {
            return response()->json([
                'status' => 'expired',
                'message' => 'Перевірка протермінована — спробуйте увійти ще раз.',
            ], 404);
        }

        if (($result['status'] ?? null) === 'ok') {
            cache()->forget(CheckYawareLogin::cacheKey($checkId));
        }

        return response()->json($result);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Вихід виконано.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->apiPayload());
    }

    private function respondWithToken(User $user): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $user->apiPayload(),
        ]);
    }
}
