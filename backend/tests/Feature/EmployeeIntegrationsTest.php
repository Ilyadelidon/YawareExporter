<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Адміністратор у списку працівників бачить, що кожен із них підключив сам.
 */
class EmployeeIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_connected_integrations_without_secrets(): void
    {
        $user = User::factory()->create(['email' => 'pratsivnyk@example.com']);
        $user->forceFill([
            'trello_token' => 'secret-trello-token',
            'trello_member_username' => 'pratsivnyk',
            'trello_board_id' => 'board123',
            'google_spreadsheet_id' => 'sheet123',
        ])->save();
        Employee::create(['user_id' => $user->id, 'name' => 'Працівник', 'email' => $user->email]);
        Employee::create(['name' => 'Новенький', 'email' => 'new@example.com']);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $response = $this->getJson('/api/employees')->assertOk();

        $response->assertJsonPath('data.0.name', 'Новенький')
            ->assertJsonPath('data.0.integrations.has_account', false)
            ->assertJsonPath('data.1.integrations.has_account', true)
            ->assertJsonPath('data.1.integrations.task_provider', 'trello')
            ->assertJsonPath('data.1.integrations.trello.connected', true)
            ->assertJsonPath('data.1.integrations.trello.board_url', 'https://trello.com/b/board123')
            ->assertJsonPath('data.1.integrations.bitrix.connected', false)
            ->assertJsonPath('data.1.integrations.google.connected', true)
            ->assertJsonPath('data.1.integrations.telegram.connected', false)
            ->assertJsonMissingPath('data.1.user');

        $this->assertStringNotContainsString('secret-trello-token', $response->getContent());
    }

    public function test_employee_cannot_list_employees(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/employees')->assertForbidden();
    }
}
