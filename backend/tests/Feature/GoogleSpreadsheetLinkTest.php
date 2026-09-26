<?php

namespace Tests\Feature;

use App\Services\GoogleSheetsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Перевірка таблиці перед підключенням має пояснювати справжню причину
 * відмови Google, а не завжди «надайте доступ редактора».
 */
class GoogleSpreadsheetLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('google.oauth.access_token', 'test-access-token', now()->addHour());
        Cache::put('google.oauth.account_email', 'owner@example.com', now()->addHour());
    }

    private function failureMessage(int $status, string $googleMessage): string
    {
        Http::fake(['sheets.googleapis.com/*' => Http::response(
            ['error' => ['code' => $status, 'message' => $googleMessage]],
            $status,
        )]);

        try {
            (new GoogleSheetsService)->verifyEditableSpreadsheet('1AbCdEfGhIjKlMnOpQrStUvWxYz');
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Перевірка мала відмовити.');
    }

    public function test_excel_file_is_explained_as_excel(): void
    {
        $message = $this->failureMessage(400, 'This operation is not supported for this document');

        $this->assertStringContainsString('Excel', $message);
        $this->assertStringContainsString('Зберегти як Google Таблицю', $message);
    }

    public function test_forbidden_names_account_and_corporate_restriction(): void
    {
        $message = $this->failureMessage(403, 'The caller does not have permission');

        $this->assertStringContainsString('owner@example.com', $message);
        $this->assertStringContainsString('корпоративному', $message);
    }

    public function test_missing_spreadsheet_is_reported_as_not_found(): void
    {
        $this->assertStringContainsString('не знайдено', $this->failureMessage(404, 'Requested entity was not found.'));
    }
}
