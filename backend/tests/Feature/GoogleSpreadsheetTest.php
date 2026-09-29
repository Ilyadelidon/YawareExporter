<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Персональна Google Таблиця працівника: створити нову, підключити наявну
 * або відв'язати. Google-акаунт сервісу один — його авторизує адміністратор.
 */
class GoogleSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = '1AbCdEfGhIjKlMnOpQrStUvWxYz';

    private ?string $tokenPath = null;

    protected function tearDown(): void
    {
        if ($this->tokenPath && is_file($this->tokenPath)) {
            unlink($this->tokenPath);
        }

        parent::tearDown();
    }

    /** Google-акаунт сервісу авторизовано: є ключі застосунку й refresh-токен. */
    private function connectGoogleAccount(): void
    {
        $this->tokenPath = tempnam(sys_get_temp_dir(), 'google-token');
        file_put_contents($this->tokenPath, json_encode(['refresh_token' => 'refresh']));

        config()->set('services.google.client_id', 'client');
        config()->set('services.google.client_secret', 'secret');
        config()->set('services.google.token_path', $this->tokenPath);

        Cache::put('google.oauth.access_token', 'test-access-token', now()->addHour());
        Cache::put('google.oauth.account_email', 'owner@example.com', now()->addHour());
    }

    public function test_status_without_service_account(): void
    {
        config()->set('services.google.token_path', null);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/google/status')
            ->assertOk()
            ->assertJson([
                'account_connected' => false,
                'account_email' => null,
                'spreadsheet_id' => null,
                'spreadsheet_url' => null,
                'spreadsheet_title' => null,
            ]);

        Http::assertNothingSent();
    }

    public function test_status_reads_title_of_linked_spreadsheet(): void
    {
        $this->connectGoogleAccount();
        Http::fake(['sheets.googleapis.com/*' => Http::response(['properties' => ['title' => 'Звіти Івана']])]);

        $user = User::factory()->create();
        $user->attachSpreadsheet(self::SHEET);
        Sanctum::actingAs($user);

        $this->getJson('/api/google/status')
            ->assertOk()
            ->assertJson([
                'account_connected' => true,
                'account_email' => 'owner@example.com',
                'spreadsheet_id' => self::SHEET,
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/'.self::SHEET.'/edit',
                'spreadsheet_title' => 'Звіти Івана',
            ]);
    }

    public function test_cannot_attach_without_service_account(): void
    {
        config()->set('services.google.token_path', null);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/google/spreadsheet', ['name' => 'Звіти'])->assertStatus(409);
        $this->postJson('/api/google/spreadsheet/link', ['spreadsheet' => self::SHEET])->assertStatus(409);
    }

    public function test_cannot_attach_second_spreadsheet(): void
    {
        $this->connectGoogleAccount();
        Http::fake();

        $user = User::factory()->create();
        $user->attachSpreadsheet(self::SHEET);
        Sanctum::actingAs($user);

        $this->postJson('/api/google/spreadsheet', ['name' => 'Звіти'])->assertStatus(409);
        $this->postJson('/api/google/spreadsheet/link', ['spreadsheet' => 'інша'])->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_link_rejects_something_that_is_not_a_spreadsheet(): void
    {
        $this->connectGoogleAccount();
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/google/spreadsheet/link', ['spreadsheet' => 'https://example.com/doc'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_detach_keeps_nothing_linked(): void
    {
        $user = User::factory()->create();
        $user->attachSpreadsheet(self::SHEET);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/google/spreadsheet')->assertOk();

        $this->assertFalse($user->refresh()->hasSpreadsheet());
    }
}
