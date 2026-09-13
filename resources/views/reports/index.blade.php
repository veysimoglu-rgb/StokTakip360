<x-app-layout>
    <x-slot name="title">Raporlar</x-slot>

    <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-5">
        <a href="{{ route('reports.low-stock') }}" class="group flex flex-col rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 p-4 sm:p-6 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg">
            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-5 w-5 sm:h-6 sm:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 3.5h.01" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.6 4.6 2.9 18a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.4 4.6a2 2 0 0 0-2.8 0Z" />
                </svg>
            </div>
            <h3 class="mt-2.5 sm:mt-4 text-base font-semibold text-white">Kritik Stok Raporu</h3>
            <p class="text-sm text-white/85 mt-1 leading-snug break-words">Minimum stok seviyesinin altındaki ürünler.</p>
        </a>

        <a href="{{ route('stock-movements.index') }}" class="group flex flex-col rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 p-4 sm:p-6 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg">
            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-5 w-5 sm:h-6 sm:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="3.5" y="8" width="17" height="12" rx="1.5" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 12.5h17M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                </svg>
            </div>
            <h3 class="mt-2.5 sm:mt-4 text-base font-semibold text-white">Stok Hareketleri</h3>
            <p class="text-sm text-white/85 mt-1 leading-snug break-words">Tüm giriş/çıkış hareketlerinin listesi.</p>
        </a>

        <a href="{{ route('reports.movement-value') }}" class="group flex flex-col rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-600 p-4 sm:p-6 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg">
            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-5 w-5 sm:h-6 sm:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="2.5" y="7" width="19" height="10" rx="2" />
                    <circle cx="12" cy="12" r="2.2" />
                    <path stroke-linecap="round" d="M5.5 9.5v5M18.5 9.5v5" />
                </svg>
            </div>
            <h3 class="mt-2.5 sm:mt-4 text-base font-semibold text-white">Stok Hareketi Değer Raporu</h3>
            <p class="text-sm text-white/85 mt-1 leading-snug break-words">Hareketlerin kayıtlı fiyatına göre parasal değeri.</p>
        </a>

        <a href="{{ route('reports.sales') }}" class="group flex flex-col rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 p-4 sm:p-6 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg">
            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-5 w-5 sm:h-6 sm:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4-5 3 3 5-7 4 4" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 20h16" />
                </svg>
            </div>
            <h3 class="mt-2.5 sm:mt-4 text-base font-semibold text-white">Satış Raporu</h3>
            <p class="text-sm text-white/85 mt-1 leading-snug break-words">Tarih, cari ve ödeme durumuna göre satış listesi ve toplamlar.</p>
        </a>

        <a href="{{ route('reports.purchases') }}" class="group flex flex-col rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 p-4 sm:p-6 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg">
            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-5 w-5 sm:h-6 sm:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 8V6a4 4 0 1 1 8 0v2" />
                    <rect x="4" y="8" width="16" height="12" rx="2" />
                </svg>
            </div>
            <h3 class="mt-2.5 sm:mt-4 text-base font-semibold text-white">Alış Raporu</h3>
            <p class="text-sm text-white/85 mt-1 leading-snug break-words">Tarih, cari ve ödeme durumuna göre alış listesi ve toplamlar.</p>
        </a>

        <a href="{{ route('reports.account-statement') }}" class="group flex flex-col rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 p-4 sm:p-6 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg">
            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-5 w-5 sm:h-6 sm:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="9" cy="8.5" r="3" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 19.5a5.5 5.5 0 0 1 11 0" />
                    <circle cx="17.5" cy="9.5" r="2.3" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.2 19.5a4.3 4.3 0 0 1 7.3-3.1" />
                </svg>
            </div>
            <h3 class="mt-2.5 sm:mt-4 text-base font-semibold text-white">Cari Ekstre</h3>
            <p class="text-sm text-white/85 mt-1 leading-snug break-words">Bir carinin tarih aralıklı borç/alacak/bakiye dökümü.</p>
        </a>

        <a href="{{ route('reports.cash') }}" class="group flex flex-col rounded-2xl bg-gradient-to-br from-cyan-500 to-cyan-600 p-4 sm:p-6 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg">
            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-5 w-5 sm:h-6 sm:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="7" width="18" height="12" rx="2" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18" />
                    <circle cx="16.5" cy="14.5" r="1.1" fill="currentColor" stroke="none" />
                </svg>
            </div>
            <h3 class="mt-2.5 sm:mt-4 text-base font-semibold text-white">Kasa Raporu</h3>
            <p class="text-sm text-white/85 mt-1 leading-snug break-words">Tarih aralıklı toplam giriş, çıkış ve net değişim.</p>
        </a>

        <a href="{{ route('reports.profitability') }}" class="group flex flex-col rounded-2xl bg-gradient-to-br from-rose-500 to-rose-600 p-4 sm:p-6 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg">
            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-5 w-5 sm:h-6 sm:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v9h9" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.49 15.5A9 9 0 1 1 12 3" />
                </svg>
            </div>
            <h3 class="mt-2.5 sm:mt-4 text-base font-semibold text-white">Kârlılık Raporu</h3>
            <p class="text-sm text-white/85 mt-1 leading-snug break-words">Tarih aralıklı toplam ciro, maliyet, brüt kâr ve ürün bazlı performans.</p>
        </a>
    </div>
</x-app-layout>
