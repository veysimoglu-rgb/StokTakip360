<x-app-layout>
    <x-slot name="title">Ana Sayfa</x-slot>

    <p class="text-sm text-gray-500 -mt-2 mb-4">Stoklarını bugün kolayca yönet.</p>

    @php
        // Purely presentational reshaping of the already-computed controller
        // output into one list to loop over — no new query, no recomputation.
        // TL is always first/always shown; foreign currencies only appear
        // when foreignCurrencySummaries already decided they have activity.
        $financialRows = collect([[
            'currency' => 'TL',
            'kasa' => $cashBalance,
            'receivable' => $totalReceivable,
            'payable' => $totalPayable,
            'today_sales' => $todaySales,
            'today_purchases' => $todayPurchases,
            'pending_collection' => $pendingCollection,
            'pending_payment' => $pendingPayment,
        ]])->concat($foreignCurrencySummaries->map(fn ($s) => [
            'currency' => $s['currency'],
            'kasa' => $s['kasa'],
            'receivable' => $s['receivable'],
            'payable' => $s['payable'],
            'today_sales' => $s['today_sales'],
            'today_purchases' => $s['today_purchases'],
            'pending_collection' => $s['pending_collection'],
            'pending_payment' => $s['pending_payment'],
        ]));

        $overdueRows = collect([[
            'currency' => 'TL',
            'overdue_sales' => $overdueSalesAmount,
            'overdue_purchases' => $overduePurchasesAmount,
        ]])->concat($foreignCurrencySummaries->map(fn ($s) => [
            'currency' => $s['currency'],
            'overdue_sales' => $s['overdue_sales'],
            'overdue_purchases' => $s['overdue_purchases'],
        ]))->filter(fn ($row) => $row['overdue_sales'] != 0 || $row['overdue_purchases'] != 0)->values();
    @endphp

    {{-- ============================= 1. STOK ÖZETİ ============================= --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <p class="text-xs sm:text-sm text-gray-500">Aktif Ürün Sayısı</p>
            <p class="text-3xl font-semibold text-blue-600 mt-1">{{ $totalProducts }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <p class="text-xs sm:text-sm text-gray-500">Kritik Stok</p>
            <p class="text-3xl font-semibold text-red-600 mt-1">{{ $lowStockCount }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <p class="text-xs sm:text-sm text-gray-500">Bugünkü Stok Girişi / Çıkışı</p>
            <p class="text-3xl font-semibold text-gray-800 mt-1">{{ $todayIn }} / {{ $todayOut }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            @forelse ($stockValueByCurrency as $code => $total)
                <p class="text-xs sm:text-sm text-gray-500">Toplam Stok Değeri ({{ $code }})</p>
                <p class="text-base sm:text-lg font-semibold text-blue-900 leading-tight mb-1 last:mb-0">{{ \App\Support\Currency::formatWithSymbol($total, $code) }}</p>
            @empty
                <p class="text-xs sm:text-sm text-gray-500">Toplam Stok Değeri</p>
                <p class="text-base font-semibold text-gray-400">—</p>
            @endforelse
        </div>
    </div>

    {{-- ============================= 2. FİNANSAL ÖZET ============================= --}}
    <div class="bg-white rounded-lg shadow p-4 sm:p-5 mb-6">
        <h2 class="font-semibold text-gray-800 mb-4">Finansal Özet</h2>

        {{-- One compact card per currency at every breakpoint: 3-up from
             640px, a safe single column below it (no table, ever). --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach ($financialRows as $row)
                <x-currency-summary-card :currency="$row['currency']">
                    <x-metric-stat label="Kasa" :value="\App\Support\Currency::format($row['kasa'], $row['currency'])" :color="$row['kasa'] < 0 ? 'text-red-600' : 'text-blue-600'" />
                    <x-metric-stat label="Satış" :value="\App\Support\Currency::format($row['today_sales'], $row['currency'])" color="text-green-600" />
                    <x-metric-stat label="Alış" :value="\App\Support\Currency::format($row['today_purchases'], $row['currency'])" color="text-blue-600" />
                    <x-metric-stat label="Alacak" :value="\App\Support\Currency::format($row['receivable'], $row['currency'])" color="text-green-600" />
                    <x-metric-stat label="Borç" :value="\App\Support\Currency::format($row['payable'], $row['currency'])" color="text-red-600" />
                    <x-metric-stat label="Tahsilat" :value="\App\Support\Currency::format($row['pending_collection'], $row['currency'])" color="text-amber-600" />
                    <x-metric-stat label="Ödeme" :value="\App\Support\Currency::format($row['pending_payment'], $row['currency'])" color="text-amber-600" />
                </x-currency-summary-card>
            @endforeach
        </div>
    </div>

    {{-- ============================= 3. VADE TAKİBİ ============================= --}}
    @if ($overdueRows->isNotEmpty())
        <div class="bg-white rounded-lg shadow p-4 sm:p-5 mb-6">
            <h2 class="font-semibold text-gray-800 mb-4">Vade Takibi</h2>

            {{-- Only currencies with an actual overdue amount get a card. --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @foreach ($overdueRows as $row)
                    <x-currency-summary-card :currency="$row['currency']">
                        <x-metric-stat label="Vadesi Geçen Satış" :value="\App\Support\Currency::format($row['overdue_sales'], $row['currency'])" color="text-red-600" />
                        <x-metric-stat label="Vadesi Geçen Alış" :value="\App\Support\Currency::format($row['overdue_purchases'], $row['currency'])" color="text-red-600" />
                    </x-currency-summary-card>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ==================== 4. KRİTİK STOK + SON HAREKETLER ==================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
        <div class="bg-white rounded-lg shadow p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-800">Kritik Stoktaki Ürünler</h3>
                <a href="{{ route('reports.low-stock') }}" class="text-sm text-indigo-600 hover:underline">Tümü</a>
            </div>
            @forelse ($lowStockProducts as $product)
                <div class="flex items-center justify-between py-1.5 border-b last:border-0 text-sm">
                    <span class="truncate">{{ $product->name }}</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700 shrink-0 ms-2">
                        {{ $product->current_stock }} {{ $product->unit }}
                    </span>
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
                <div class="flex items-center justify-between py-1.5 border-b last:border-0 text-sm">
                    <span class="truncate">{{ $movement->product->name }}</span>
                    <span @class([
                        'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold shrink-0 ms-2',
                        'bg-green-100 text-green-700' => $movement->type === 'in',
                        'bg-red-100 text-red-700' => $movement->type === 'out',
                    ])>
                        {{ $movement->type === 'in' ? '+' : '-' }}{{ $movement->quantity }}
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Henüz hareket yok.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
