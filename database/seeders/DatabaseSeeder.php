<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the first admin user, taken from
     * ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD in .env.
     */
    public function run(): void
    {
        $email = strtolower((string) env('ADMIN_EMAIL', 'admin@example.com'));

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} already exists.");

            return;
        }

        $password = (string) env('ADMIN_PASSWORD');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        User::query()->create([
            'name' => env('ADMIN_NAME', 'Super Admin'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        $this->command?->info("Admin user created: {$email}");

        if ($generated) {
            $this->command?->warn("Generated password (shown once, change it after login): {$password}");
        }
    }
}
