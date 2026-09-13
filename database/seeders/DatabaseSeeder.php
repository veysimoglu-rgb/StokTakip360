<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $admin = User::firstOrCreate(
            ['email' => 'admin@stoktakip360.com'],
            ['name' => 'Yönetici', 'password' => bcrypt('password')]
        );
        if (! $admin->hasRole('Admin')) {
            $admin->assignRole('Admin');
        }
    }
}
