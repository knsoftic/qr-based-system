<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('TEST_ADMIN_PASSWORD');

        parent::tearDown();
    }

    public function test_deploy_scripts_can_create_the_first_admin_without_a_prompt(): void
    {
        putenv('TEST_ADMIN_PASSWORD=deploy-generated-123');

        $this->artisan('gateway:create-admin', [
            'email' => 'Admin@PKPay.site',
            '--name' => 'Super Admin',
            '--password-env' => 'TEST_ADMIN_PASSWORD',
            '--if-none' => true,
        ])->assertExitCode(0);

        $admin = User::query()->sole();
        $this->assertSame('admin@pkpay.site', $admin->email);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('deploy-generated-123', $admin->password));
    }

    public function test_if_none_never_touches_an_existing_admin(): void
    {
        $existing = User::factory()->create(['email' => 'owner@pkpay.site']);
        putenv('TEST_ADMIN_PASSWORD=another-password-456');

        $this->artisan('gateway:create-admin', [
            'email' => 'admin@pkpay.site',
            '--password-env' => 'TEST_ADMIN_PASSWORD',
            '--if-none' => true,
        ])->assertExitCode(3);

        $this->assertSame(1, User::query()->count());
        $this->assertSame($existing->password, $existing->fresh()->password);
    }

    public function test_short_passwords_are_rejected(): void
    {
        putenv('TEST_ADMIN_PASSWORD=short');

        $this->artisan('gateway:create-admin', ['email' => 'a@pkpay.site', '--password-env' => 'TEST_ADMIN_PASSWORD'])
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }
}
