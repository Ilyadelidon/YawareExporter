<?php

namespace Tests\Feature;

use App\Models\ActivityEntry;
use App\Models\Employee;
use App\Models\Report;
use App\Services\AiAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * DeepSeek не має ні strict-схеми, ні гарантій формату, тому тут перевіряється
 * саме розбір відповіді: чисту схему, обгортку в ```json, недоліплені поля
 * і типові помилки API.
 */
class DeepseekAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.deepseek.key', 'test-key');
        config()->set('services.deepseek.model', 'deepseek-chat');
        config()->set('services.deepseek.base_url', 'https://api.deepseek.com');
    }

    private function report(): Report
    {
        $employee = Employee::create([
            'name' => 'Іван',
            'position' => 'Frontend-розробник',
            'email' => 'ivan@example.com',
        ]);

        ActivityEntry::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'name' => 'github.com',
            'productivity' => 'productive',
            'category' => 'Розробка',
            'duration_seconds' => 3600,
        ]);

        return Report::create([
            'employee_id' => $employee->id,
            'report_date' => '2026-07-20',
            'status' => Report::STATUS_COMPLETED,
            'generated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeAnswer(array $overrides = []): array
    {
        return array_merge([
            'summary' => 'День пройшов у роботі над інтерфейсом.',
            'focus_assessment' => 'Висока зосередженість.',
            'task_coverage' => [
                ['task' => 'Верстка форми', 'status' => 'confirmed', 'evidence' => 'github.com — 1 год'],
            ],
            'unclear_activities' => [
                [
                    'name' => 'unknown-domain.io',
                    'duration_seconds' => '420',
                    'verdict' => 'unknown',
                    'reasoning' => 'Домен невідомий, пошуку немає.',
                ],
            ],
            'recommendations' => ['Фіксувати таски в Trello до початку роботи.'],
        ], $overrides);
    }

    private function fakeResponse(string $content): array
    {
        return [
            'model' => 'deepseek-chat',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $content],
            ]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 340],
        ];
    }

    public function test_parses_structured_answer(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(
                $this->fakeResponse(json_encode($this->fakeAnswer(), JSON_UNESCAPED_UNICODE)),
            ),
        ]);

        $outcome = app(AiAnalysisService::class)->analyse($this->report(), 'deepseek');

        $this->assertSame('deepseek', $outcome['provider']);
        $this->assertSame('deepseek-chat', $outcome['model']);
        $this->assertSame(1200, $outcome['input_tokens']);
        $this->assertSame(340, $outcome['output_tokens']);
        $this->assertSame('День пройшов у роботі над інтерфейсом.', $outcome['result']['summary']);
        // Тривалість прийшла рядком — після нормалізації має бути int.
        $this->assertSame(420, $outcome['result']['unclear_activities'][0]['duration_seconds']);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.deepseek.com/chat/completions'
                && $body['model'] === 'deepseek-chat'
                && $body['response_format'] === ['type' => 'json_object']
                // Веб-пошуку немає — модель мають прямо про це попередити.
                && str_contains($body['messages'][0]['content'], 'Пошуку в інтернеті в тебе немає')
                && str_contains($body['messages'][1]['content'], 'github.com');
        });
    }

    public function test_strips_markdown_fence_and_fills_missing_fields(): void
    {
        $answer = $this->fakeAnswer(['recommendations' => null]);
        unset($answer['focus_assessment']);

        Http::fake([
            'api.deepseek.com/*' => Http::response($this->fakeResponse(
                "```json\n".json_encode($answer, JSON_UNESCAPED_UNICODE)."\n```",
            )),
        ]);

        $result = app(AiAnalysisService::class)->analyse($this->report(), 'deepseek')['result'];

        $this->assertSame('', $result['focus_assessment']);
        $this->assertSame([], $result['recommendations']);
        $this->assertCount(1, $result['task_coverage']);
    }

    public function test_rejects_answer_without_summary(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response($this->fakeResponse('{"unclear_activities": []}')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('немає поля summary');

        app(AiAnalysisService::class)->analyse($this->report(), 'deepseek');
    }

    public function test_reports_insufficient_balance(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => ['message' => 'Insufficient Balance']], 402),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('На балансі DeepSeek немає коштів');

        app(AiAnalysisService::class)->analyse($this->report(), 'deepseek');
    }

    public function test_reports_truncated_answer(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [[
                    'finish_reason' => 'length',
                    'message' => ['content' => '{"summary": "обрив'],
                ]],
                'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 8000],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('обірвалася на ліміті токенів');

        app(AiAnalysisService::class)->analyse($this->report(), 'deepseek');
    }
}
