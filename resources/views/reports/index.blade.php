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
        <a href="{{ route('reports.movement-value') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-md transition">
            <h3 class="font-semibold text-gray-800">Stok Hareketi Değer Raporu</h3>
            <p class="text-sm text-gray-500 mt-1">Hareketlerin kayıtlı fiyatına göre parasal değeri.</p>
        </a>
        <a href="{{ route('reports.sales') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-md transition">
            <h3 class="font-semibold text-gray-800">Satış Raporu</h3>
            <p class="text-sm text-gray-500 mt-1">Tarih, cari ve ödeme durumuna göre satış listesi ve toplamlar.</p>
        </a>
        <a href="{{ route('reports.purchases') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-md transition">
            <h3 class="font-semibold text-gray-800">Alış Raporu</h3>
            <p class="text-sm text-gray-500 mt-1">Tarih, cari ve ödeme durumuna göre alış listesi ve toplamlar.</p>
        </a>
        <a href="{{ route('reports.account-statement') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-md transition">
            <h3 class="font-semibold text-gray-800">Cari Ekstre</h3>
            <p class="text-sm text-gray-500 mt-1">Bir carinin tarih aralıklı borç/alacak/bakiye dökümü.</p>
        </a>
        <a href="{{ route('reports.cash') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-md transition">
            <h3 class="font-semibold text-gray-800">Kasa Raporu</h3>
            <p class="text-sm text-gray-500 mt-1">Tarih aralıklı toplam giriş, çıkış ve net değişim.</p>
        </a>
        <a href="{{ route('reports.profitability') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-md transition">
            <h3 class="font-semibold text-gray-800">Kârlılık Raporu</h3>
            <p class="text-sm text-gray-500 mt-1">Tarih aralıklı toplam ciro, maliyet, brüt kâr ve ürün bazlı performans.</p>
        </a>
    </div>
</x-app-layout>
