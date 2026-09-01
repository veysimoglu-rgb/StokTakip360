<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);

        $user = User::first();
        if ($user && ! $user->hasAnyRole(['Admin', 'Personel'])) {
            $user->assignRole($admin);
        }
    }
}
