<?php

namespace App\Services;

use App\Models\BitrixWorkspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OAuth 2.0 локального застосунку Бітрікс24: видає працівникові особистий
 * токен порталу і оновлює його. Токени видає хмара Бітрікса (oauth.bitrix.info),
 * а не сам портал — саме тому endpoint тут спільний для всіх порталів.
 */
class BitrixOAuth
{
    /** Скільки живе state авторизації — стільки ж часу є на вхід у портал. */
    public const STATE_TTL_MINUTES = 15;

    public function __construct(private readonly BitrixWorkspace $workspace) {}

    public static function forWorkspace(?BitrixWorkspace $workspace = null): ?self
    {
        $workspace ??= BitrixWorkspace::active();

        return $workspace && $workspace->client_id && $workspace->client_secret
            ? new self($workspace)
            : null;
    }

    /** Одноразовий state, яким callback упізнає, чия це авторизація. */
    public static function newState(): string
    {
        return Str::random(48);
    }

    /**
     * Сторінка згоди на самому порталі команди: працівник входить під собою,
     * і застосунок отримує доступ рівно з його правами.
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return rtrim($this->workspace->portal_url, '/').'/oauth/authorize/?'.http_build_query([
            'client_id' => $this->workspace->client_id,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Обмінює код авторизації на пару токенів працівника.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, member_id: ?string, client_endpoint: string, portal_host: string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Продовжує доступ. Бітрікс віддає новий refresh_token, тож зберігати
     * треба обидва — старий після цього недійсний.
     */
    public function refresh(string $refreshToken): array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    private function requestToken(array $params): array
    {
        $response = Http::timeout(25)->get(config('services.bitrix.oauth_url'), $params + [
            'client_id' => $this->workspace->client_id,
            'client_secret' => $this->workspace->client_secret,
        ]);

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Бітрікс24 повернув неочікувану відповідь на запит токена.');
        }

        if (isset($body['error'])) {
            throw new RuntimeException(trim($body['error_description'] ?? '') ?: (string) $body['error']);
        }

        $response->throw();

        foreach (['access_token', 'refresh_token', 'client_endpoint'] as $required) {
            if (empty($body[$required])) {
                throw new RuntimeException("Бітрікс24 не повернув {$required} — перевірте налаштування застосунку.");
            }
        }

        $clientEndpoint = rtrim((string) $body['client_endpoint'], '/').'/';

        return [
            'access_token' => (string) $body['access_token'],
            'refresh_token' => (string) $body['refresh_token'],
            'expires_in' => (int) ($body['expires_in'] ?? 3600),
            'member_id' => isset($body['member_id']) ? (string) $body['member_id'] : null,
            'client_endpoint' => $clientEndpoint,
            // Портал беремо саме з client_endpoint: поле domain у відповіді —
            // це домен видавця токенів (oauth.bitrix.info), а не портал команди.
            'portal_host' => (string) parse_url($clientEndpoint, PHP_URL_HOST),
        ];
    }
}
