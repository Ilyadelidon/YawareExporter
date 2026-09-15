<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BitrixAccount;
use App\Models\BitrixWorkspace;
use App\Services\BitrixOAuth;
use App\Services\BitrixService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Бітрікс24 підключається у два кроки: адміністратор один раз реєструє на
 * порталі команди локальний застосунок (OAuth 2.0) і зберігає його реквізити,
 * а далі кожен працівник авторизується в цьому застосунку особисто. Чий це
 * акаунт — каже сам Бітрікс у відповідь на виданий токен, тож вибрати чужий
 * акаунт (і читати чужі таски) неможливо.
 */
class BitrixAccountController extends Controller
{
    /** Адреса порталу без шляху: https://team.bitrix24.ua */
    private const PORTAL_PATTERN = '#^https://[\w.\-]+\.[a-z]{2,}/?$#i';

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspace = BitrixWorkspace::active();
        $account = $user->bitrixAccount;

        return response()->json([
            'workspace_connected' => $workspace !== null,
            'portal_url' => $workspace?->portal_url,
            'connected_by' => $workspace?->connectedBy?->name,
            'user_id' => $account?->bitrix_user_id,
            'user_name' => $account?->bitrix_user_name,
            'user_email' => $account?->bitrix_email,
            'connected' => $account !== null && $workspace !== null,
            // Портал підключає лише адміністратор — решті показуємо підказку.
            'can_manage' => $user->isAdmin(),
            // Той самий шлях повернення треба вписати в застосунок на порталі.
            'redirect_uri' => $user->isAdmin() ? $this->redirectUri() : null,
        ]);
    }

    /**
     * Зберігає портал і реквізити локального застосунку. Перевірити їх тут
     * нічим — Бітрікс визнає client_id лише в момент авторизації працівника,
     * тож помилка в реквізитах спливе на першому ж «Підключити».
     */
    public function storeWorkspace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'portal_url' => ['required', 'string', 'max:255', 'regex:'.self::PORTAL_PATTERN],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
        ], [
            'portal_url.regex' => 'Очікується адреса порталу вигляду https://ваш-портал.bitrix24.ua',
        ]);

        $workspace = BitrixWorkspace::connect([
            'portal_url' => rtrim(trim($validated['portal_url']), '/'),
            'client_id' => trim($validated['client_id']),
            'client_secret' => trim($validated['client_secret']),
            'connected_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Портал Бітрікс24 підключено. Тепер кожен працівник авторизується на ньому сам.',
            'portal_url' => $workspace->portal_url,
        ], 201);
    }

    /**
     * Відключає портал команди разом з усіма виданими токенами працівників.
     */
    public function destroyWorkspace(): JsonResponse
    {
        BitrixWorkspace::query()->delete();
        BitrixAccount::query()->delete();

        return response()->json(['message' => 'Портал Бітрікс24 відключено.']);
    }

    /**
     * Починає авторизацію працівника: повертає посилання на сторінку згоди
     * порталу. state одноразовий і зберігає, чия саме це авторизація —
     * callback приходить від браузера без сесії користувача.
     */
    public function startAuthorization(Request $request): JsonResponse
    {
        $oauth = BitrixOAuth::forWorkspace();

        if (! $oauth) {
            return response()->json(['message' => 'Портал Бітрікс24 ще не підключено.'], 409);
        }

        $state = BitrixOAuth::newState();

        Cache::put(
            $this->stateKey($state),
            $request->user()->id,
            now()->addMinutes(BitrixOAuth::STATE_TTL_MINUTES),
        );

        return response()->json([
            'url' => $oauth->authorizationUrl($state, $this->redirectUri()),
        ]);
    }

    /**
     * Повернення з порталу: обмінюємо код на токени працівника і питаємо
     * Бітрікс, кому вони видані. Відкривається в браузері, тож відповідаємо
     * редіректом в інтерфейс, а не JSON.
     */
    public function callback(Request $request): RedirectResponse
    {
        $userId = Cache::pull($this->stateKey((string) $request->query('state')));

        if (! $userId) {
            return $this->backToApp('Посилання авторизації застаріло — почніть підключення заново.');
        }

        if ($request->query('error') || ! $request->query('code')) {
            return $this->backToApp('Бітрікс24 відхилив авторизацію: '.($request->query('error') ?: 'код відсутній').'.');
        }

        $workspace = BitrixWorkspace::active();
        $oauth = BitrixOAuth::forWorkspace($workspace);

        if (! $oauth) {
            return $this->backToApp('Портал Бітрікс24 більше не підключено.');
        }

        try {
            $tokens = $oauth->exchangeCode((string) $request->query('code'), $this->redirectUri());
        } catch (RequestException|RuntimeException $exception) {
            Log::warning("Бітрікс24 не видав токен: {$exception->getMessage()}");

            return $this->backToApp('Бітрікс24 не видав токен — перевірте реквізити застосунку.');
        }

        // Застосунок міг бути встановлений і на іншому порталі: приймаємо
        // авторизацію лише з того, який підключила команда.
        if ($tokens['portal_host'] && mb_strtolower($tokens['portal_host']) !== mb_strtolower($workspace->portalHost())) {
            return $this->backToApp('Це інший портал Бітрікса — авторизуйтесь на '.$workspace->portalHost().'.');
        }

        try {
            $profile = BitrixService::profileWithToken($tokens['client_endpoint'], $tokens['access_token']);
        } catch (RequestException|RuntimeException $exception) {
            Log::warning("Бітрікс24 не віддав профіль: {$exception->getMessage()}");

            return $this->backToApp('Не вдалося отримати ваш профіль на порталі — спробуйте ще раз.');
        }

        if (! $profile['id']) {
            return $this->backToApp('Бітрікс24 не повернув ваш акаунт на порталі.');
        }

        // Пошта й ім'я — з картки користувача: profile часто віддає їх порожніми.
        $card = ['name' => null, 'email' => null];

        try {
            $card = BitrixService::userCardWithToken($tokens['client_endpoint'], $tokens['access_token'], $profile['id']);
        } catch (RequestException|RuntimeException $exception) {
            Log::info("Бітрікс24 не віддав картку користувача: {$exception->getMessage()}");
        }

        // Один акаунт порталу — один наш користувач: інакше двоє отримували б
        // однакові таски, і незрозуміло, чий це насправді робочий день.
        $takenByOther = BitrixAccount::query()
            ->where('bitrix_user_id', $profile['id'])
            ->where('user_id', '!=', $userId)
            ->exists();

        if ($takenByOther) {
            return $this->backToApp('Цей акаунт Бітрікса вже прив’язано до іншого користувача сервісу.');
        }

        BitrixAccount::updateOrCreate(['user_id' => $userId], [
            'bitrix_user_id' => $profile['id'],
            'bitrix_user_name' => $card['name'] ?: ($profile['name'] ?: $card['email'] ?: "Акаунт #{$profile['id']}"),
            'bitrix_email' => $card['email'] ?: $profile['email'],
            'member_id' => $tokens['member_id'],
            'client_endpoint' => $tokens['client_endpoint'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => now()->addSeconds($tokens['expires_in']),
        ]);

        return $this->backToApp(null);
    }

    /**
     * Відв'язує акаунт працівника — токени видаляються, портал команди лишається.
     */
    public function destroyUser(Request $request): JsonResponse
    {
        $request->user()->bitrixAccount?->delete();

        return response()->json(['message' => 'Акаунт Бітрікса відв’язано.']);
    }

    private function stateKey(string $state): string
    {
        return 'bitrix.oauth.state.'.$state;
    }

    /**
     * Той самий redirect_uri має бути вказаний у налаштуваннях застосунку на
     * порталі — Бітрікс звіряє його побайтово.
     */
    private function redirectUri(): string
    {
        return url('/api/bitrix/oauth/callback');
    }

    private function backToApp(?string $error): RedirectResponse
    {
        $app = rtrim(config('services.bitrix.frontend_url') ?: config('app.url'), '/');

        return redirect()->away($app.'/integrations?'.http_build_query(
            $error ? ['bitrix' => 'error', 'bitrix_message' => $error] : ['bitrix' => 'connected'],
        ));
    }
}
