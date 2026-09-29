<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Довідник працівників: створення й правка адміністратором.
 */
class EmployeesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
    }

    public function test_store_keeps_email_in_lower_case(): void
    {
        $this->postJson('/api/employees', ['name' => 'Іван', 'email' => ' Ivan@Example.com '])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ivan@example.com');
    }

    public function test_email_is_unique_regardless_of_case(): void
    {
        Employee::create(['name' => 'Іван', 'email' => 'ivan@example.com']);

        $this->postJson('/api/employees', ['name' => 'Двійник', 'email' => 'IVAN@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_update_changes_only_sent_fields(): void
    {
        $employee = Employee::create(['name' => 'Іван', 'email' => 'ivan@example.com', 'position' => 'QA']);

        $this->patchJson("/api/employees/{$employee->id}", ['position' => 'Розробник'])
            ->assertOk()
            ->assertJsonPath('data.position', 'Розробник')
            ->assertJsonPath('data.email', 'ivan@example.com');
    }

    public function test_update_may_keep_own_email_but_not_take_anothers(): void
    {
        $employee = Employee::create(['name' => 'Іван', 'email' => 'ivan@example.com']);
        Employee::create(['name' => 'Петро', 'email' => 'petro@example.com']);

        $this->patchJson("/api/employees/{$employee->id}", ['email' => 'Ivan@example.com'])->assertOk();
        $this->patchJson("/api/employees/{$employee->id}", ['email' => 'petro@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
