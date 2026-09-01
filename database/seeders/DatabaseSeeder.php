<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
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

        if (Category::count() === 0) {
            $elektronik = Category::create(['name' => 'Elektronik']);
            $gida = Category::create(['name' => 'Gıda']);

            $genel = Brand::create(['name' => 'Genel']);

            Product::create([
                'code' => 'URN-0001',
                'name' => 'Örnek Ürün 1',
                'category_id' => $elektronik->id,
                'brand_id' => $genel->id,
                'unit' => 'Adet',
                'min_stock' => 5,
                'current_stock' => 20,
                'purchase_price' => 50,
                'sale_price' => 75,
                'vat_rate' => 20,
            ]);

            Product::create([
                'code' => 'URN-0002',
                'name' => 'Örnek Ürün 2',
                'category_id' => $gida->id,
                'brand_id' => $genel->id,
                'unit' => 'Kg',
                'min_stock' => 10,
                'current_stock' => 3,
                'purchase_price' => 20,
                'sale_price' => 30,
                'vat_rate' => 10,
            ]);
        }
    }
}
