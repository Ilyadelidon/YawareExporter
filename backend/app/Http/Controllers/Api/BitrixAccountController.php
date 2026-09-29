<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BitrixWorkspace;
use App\Services\BitrixAccountLinker;
use App\Services\BitrixLinkFailed;
use App\Services\BitrixOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Особистий акаунт працівника на порталі Бітрікс24 команди. Портал з
 * реквізитами застосунку підключає адміністратор ([[BitrixWorkspaceController]]),
 * а кожен працівник авторизується в ньому сам.
 */
class BitrixAccountController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $workspace = BitrixWorkspace::active();
        $account = $request->user()->bitrixAccount;

        return response()->json([
            'workspace_connected' => $workspace !== null,
            'portal_url' => $workspace?->portal_url,
            'portal_host' => $workspace?->portalHost(),
            'user_id' => $account?->bitrix_user_id,
            'user_name' => $account?->bitrix_user_name,
            'user_email' => $account?->bitrix_email,
            'connected' => $account !== null && $workspace !== null,
        ]);
    }

    /**
     * Починає авторизацію працівника: повертає посилання на сторінку згоди
     * порталу з одноразовим state, за яким callback упізнає працівника.
     */
    public function startAuthorization(Request $request): JsonResponse
    {
        $oauth = BitrixOAuth::forWorkspace();

        abort_unless($oauth, 409, 'Портал Бітрікс24 ще не підключено.');

        return response()->json([
            'url' => $oauth->authorizationUrl(
                BitrixOAuth::issueState($request->user()->id),
                BitrixOAuth::redirectUri(),
            ),
        ]);
    }

    /**
     * Повернення з порталу. Відкривається в браузері, тож відповідаємо
     * редіректом в інтерфейс, а не JSON.
     */
    public function callback(Request $request, BitrixAccountLinker $linker): RedirectResponse
    {
        $userId = BitrixOAuth::pullStateOwner((string) $request->query('state'));

        if (! $userId) {
            return $this->backToApp('Посилання авторизації застаріло — почніть підключення заново.');
        }

        if ($request->query('error') || ! $request->query('code')) {
            return $this->backToApp('Бітрікс24 відхилив авторизацію: '.($request->query('error') ?: 'код відсутній').'.');
        }

        try {
            $linker->link($userId, (string) $request->query('code'));
        } catch (BitrixLinkFailed $exception) {
            return $this->backToApp($exception->getMessage());
        }

        return $this->backToApp(null);
    }

    /**
     * Відв'язує акаунт працівника — токени видаляються, портал команди лишається.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->bitrixAccount?->delete();

        return response()->json(['message' => 'Акаунт Бітрікса відв’язано.']);
    }

    private function backToApp(?string $error): RedirectResponse
    {
        $app = rtrim(config('services.bitrix.frontend_url') ?: config('app.url'), '/');

        return redirect()->away($app.'/integrations?'.http_build_query(
            $error ? ['bitrix' => 'error', 'bitrix_message' => $error] : ['bitrix' => 'connected'],
        ));
    }
}
