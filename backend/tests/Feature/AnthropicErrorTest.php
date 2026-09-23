<?php

namespace Tests\Feature;

use Anthropic\Core\Exceptions\APIStatusException;
use App\Services\Ai\AnthropicProvider;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Відмови Claude API адміністратор читає в картці розбору, тому перевіряється
 * саме текст: замість дампу JSON зі SDK має бути причина людською мовою.
 */
class AnthropicErrorTest extends TestCase
{
    private function exception(int $status, string $type, string $message): APIStatusException
    {
        return APIStatusException::from(
            new Request('POST', 'https://api.anthropic.com/v1/messages'),
            new Response($status, ['Content-Type' => 'application/json'], (string) json_encode([
                'type' => 'error',
                'error' => ['type' => $type, 'message' => $message],
            ])),
        );
    }

    public function test_порожній_баланс_пояснюється_окремо(): void
    {
        $explanation = AnthropicProvider::explain($this->exception(
            400,
            'invalid_request_error',
            'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.',
        ));

        $this->assertSame('На балансі Anthropic немає коштів', $explanation);
    }

    public function test_інша_відмова_показує_текст_api_без_дампу(): void
    {
        $explanation = AnthropicProvider::explain($this->exception(
            400,
            'invalid_request_error',
            'max_tokens: must be less than or equal to 64000',
        ));

        $this->assertStringContainsString('Claude API відхилив запит', $explanation);
        $this->assertStringContainsString('max_tokens', $explanation);
        $this->assertStringNotContainsString('"status"', $explanation);
    }

    public function test_серверна_помилка_показує_код(): void
    {
        $explanation = AnthropicProvider::explain($this->exception(
            529,
            'overloaded_error',
            'Overloaded',
        ));

        $this->assertStringContainsString('529', $explanation);
        $this->assertStringContainsString('Overloaded', $explanation);
    }
}
