<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Enter must not save data-entry forms by accident. The behaviour itself is
 * client-side JS (layout guard + the order form's own handler); these tests
 * pin the markup contract: the guard ships on every app-layout page, is absent
 * from the guest layout, and only the credential/profile forms opt out.
 */
class EnterGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_the_guard_script_ships_on_every_data_entry_screen(): void
    {
        $account = Account::factory()->create(['type' => 'customer']);

        foreach (['/products/create', '/purchases/create', '/stock-in', '/stock-out', '/accounts/create', "/accounts/{$account->id}", '/cash', '/sales/create', '/categories/create', '/brands/create', '/users/create', '/settings'] as $url) {
            $content = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<script data-enter-guard>', $content, "guard missing on {$url}");
        }
    }

    public function test_the_guard_only_touches_multi_field_post_forms_and_skips_handled_events(): void
    {
        $content = $this->actingAs($this->admin)->get('/products/create')->getContent();

        $this->assertStringContainsString("getAttribute('method')", $content);
        $this->assertStringContainsString("'post'", $content);
        $this->assertStringContainsString('fields.length < 2', $content);
        $this->assertStringContainsString('e.defaultPrevented', $content);
        $this->assertStringContainsString('data-enter-submit', $content);
        $this->assertStringContainsString('e.preventDefault();', $content);
    }

    public function test_the_login_screen_is_not_affected(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('data-enter-guard', false);
    }

    public function test_only_the_profile_credential_forms_opt_out(): void
    {
        $content = $this->actingAs($this->admin)->get('/profile')->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, ' data-enter-submit>'));

        foreach (['/products/create', '/purchases/create', '/stock-in', '/accounts/create', '/sales/create'] as $url) {
            $body = $this->actingAs($this->admin)->get($url)->getContent();
            $this->assertStringNotContainsString(' data-enter-submit>', $body, "unexpected opt-out on {$url}");
        }
    }

    public function test_the_order_form_has_no_submit_button_so_enter_can_never_save_it(): void
    {
        $content = $this->actingAs($this->admin)->get('/sales/create')->getContent();
        $start = strpos($content, '<form method="POST" action="'.route('sales.store').'"');
        $form = substr($content, $start, strpos($content, '</form>', $start) - $start);

        $this->assertStringNotContainsString('type="submit"', $form);
        $this->assertStringContainsString('@keydown.enter="onEnter($event)"', $form);
    }
}
