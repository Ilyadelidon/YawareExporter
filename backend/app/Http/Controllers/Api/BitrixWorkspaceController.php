<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BitrixWorkspace;
use App\Services\BitrixOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Портал Бітрікс24 команди — його один раз підключає адміністратор:
 * реєструє на порталі локальний застосунок (OAuth 2.0) і зберігає тут його
 * реквізити. Доступ до тасок дає не він, а особистий токен кожного
 * працівника ([[BitrixAccountController]]).
 *
 * Зміни відповідають тим самим станом, що й show, — сторінці не треба
 * перепитувати його окремим запитом.
 */
class BitrixWorkspaceController extends Controller
{
    /** Адреса порталу без шляху: https://team.bitrix24.ua */
    private const PORTAL_PATTERN = '#^https://[\w.\-]+\.[a-z]{2,}/?$#i';

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    /**
     * Зберігає портал і реквізити локального застосунку. Перевірити їх тут
     * нічим — Бітрікс визнає client_id лише в момент авторизації працівника,
     * тож помилка в реквізитах спливе на першому ж «Підключити».
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'portal_url' => ['required', 'string', 'max:255', 'regex:'.self::PORTAL_PATTERN],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
        ], [
            'portal_url.regex' => 'Очікується адреса порталу вигляду https://ваш-портал.bitrix24.ua',
        ]);

        BitrixWorkspace::connect([
            'portal_url' => rtrim(trim($validated['portal_url']), '/'),
            'client_id' => trim($validated['client_id']),
            'client_secret' => trim($validated['client_secret']),
            'connected_by' => $request->user()->id,
        ]);

        return response()->json($this->state() + [
            'message' => 'Портал Бітрікс24 підключено. Тепер кожен працівник авторизується на ньому сам.',
        ], 201);
    }

    /**
     * Відключає портал команди разом з усіма виданими токенами працівників.
     */
    public function destroy(): JsonResponse
    {
        BitrixWorkspace::disconnect();

        return response()->json($this->state() + ['message' => 'Портал Бітрікс24 відключено.']);
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $workspace = BitrixWorkspace::active();

        return [
            'connected' => $workspace !== null,
            'portal_url' => $workspace?->portal_url,
            'portal_host' => $workspace?->portalHost(),
            'connected_by' => $workspace?->connectedBy?->name,
            // Той самий шлях повернення треба вписати в застосунок на порталі.
            'redirect_uri' => BitrixOAuth::redirectUri(),
        ];
    }
}
