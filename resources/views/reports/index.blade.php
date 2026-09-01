<x-app-layout>
    <x-slot name="title">Raporlar</x-slot>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
        <a href="{{ route('reports.low-stock') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-md transition">
            <h3 class="font-semibold text-gray-800">Kritik Stok Raporu</h3>
            <p class="text-sm text-gray-500 mt-1">Minimum stok seviyesinin altındaki ürünler.</p>
        </a>
        <a href="{{ route('stock-movements.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-md transition">
            <h3 class="font-semibold text-gray-800">Stok Hareketleri</h3>
            <p class="text-sm text-gray-500 mt-1">Tüm giriş/çıkış hareketlerinin listesi.</p>
        </a>
    </div>
</x-app-layout>
