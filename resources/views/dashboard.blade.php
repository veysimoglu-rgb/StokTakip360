<x-app-layout>
    <x-slot name="title">Dashboard</x-slot>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-sm text-gray-500">Aktif Ürün Sayısı</p>
            <p class="text-2xl font-semibold text-gray-800 mt-1">{{ $totalProducts }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-sm text-gray-500">Kritik Stok</p>
            <p class="text-2xl font-semibold text-red-600 mt-1">{{ $lowStockCount }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-sm text-gray-500">Bugünkü Giriş / Çıkış</p>
            <p class="text-2xl font-semibold text-gray-800 mt-1">{{ $todayIn }} / {{ $todayOut }}</p>
        </div>
        @foreach ($stockValueByCurrency as $code => $total)
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Toplam Stok Değeri ({{ $code }})</p>
                <p class="text-2xl font-semibold text-gray-800 mt-1">{{ \App\Support\Currency::formatWithSymbol($total, $code) }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-800">Kritik Stoktaki Ürünler</h3>
                <a href="{{ route('reports.low-stock') }}" class="text-sm text-indigo-600 hover:underline">Tümü</a>
            </div>
            @forelse ($lowStockProducts as $product)
                <div class="flex items-center justify-between py-2 border-b last:border-0 text-sm">
                    <span>{{ $product->name }}</span>
                    <span class="text-red-600 font-medium">{{ $product->current_stock }} {{ $product->unit }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Kritik seviyede ürün yok.</p>
            @endforelse
        </div>

        <div class="bg-white rounded-lg shadow p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-800">Son Hareketler</h3>
                <a href="{{ route('stock-movements.index') }}" class="text-sm text-indigo-600 hover:underline">Tümü</a>
            </div>
            @forelse ($recentMovements as $movement)
                <div class="flex items-center justify-between py-2 border-b last:border-0 text-sm">
                    <span>{{ $movement->product->name }}</span>
                    <span @class(['font-medium', 'text-green-600' => $movement->type === 'in', 'text-red-600' => $movement->type === 'out'])>
                        {{ $movement->type === 'in' ? '+' : '-' }}{{ $movement->quantity }}
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Henüz hareket yok.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
