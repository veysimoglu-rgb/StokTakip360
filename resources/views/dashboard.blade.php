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
            <span class="flex h-7 w-7 sm:h-8 sm:w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 mb-1.5 sm:mb-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3h6a2 2 0 0 1 2 2v6a2 2 0 0 1-.6 1.4l-8 8a2 2 0 0 1-2.8 0l-6-6a2 2 0 0 1 0-2.8l8-8A2 2 0 0 1 12 3Z" />
                    <circle cx="16" cy="8" r="1" fill="currentColor" stroke="none" />
                </svg>
            </span>
            <p class="text-xs sm:text-sm text-gray-500">Aktif Ürün Sayısı</p>
            <p class="text-3xl font-semibold text-blue-600 mt-1">{{ $totalProducts }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <span class="flex h-7 w-7 sm:h-8 sm:w-8 items-center justify-center rounded-lg bg-red-50 text-red-600 mb-1.5 sm:mb-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 3.5h.01" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.6 4.6 2.9 18a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.4 4.6a2 2 0 0 0-2.8 0Z" />
                </svg>
            </span>
            <p class="text-xs sm:text-sm text-gray-500">Kritik Stok</p>
            <p class="text-3xl font-semibold text-red-600 mt-1">{{ $lowStockCount }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <span class="flex h-7 w-7 sm:h-8 sm:w-8 items-center justify-center rounded-lg bg-orange-50 text-orange-600 mb-1.5 sm:mb-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 4v11m0 0-3-3m3 3 3-3" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20V9m0 0 3 3m-3-3-3 3" />
                </svg>
            </span>
            <p class="text-xs sm:text-sm text-gray-500">Bugünkü Stok Girişi / Çıkışı</p>
            <p class="text-3xl font-semibold text-orange-600 mt-1">{{ \App\Support\Quantity::format($todayIn) }} / {{ \App\Support\Quantity::format($todayOut) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <span class="flex h-7 w-7 sm:h-8 sm:w-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 mb-1.5 sm:mb-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="2.5" y="7" width="19" height="10" rx="2" />
                    <circle cx="12" cy="12" r="2.2" />
                    <path stroke-linecap="round" d="M5.5 9.5v5M18.5 9.5v5" />
                </svg>
            </span>
            @php
                // Presentational-only ordering: the controller groups by
                // currency with no guaranteed order, so fix the display
                // order to TL → USD → EUR here without touching the query.
                $stockValueByCurrency = collect(['TL', 'USD', 'EUR'])
                    ->filter(fn ($code) => $stockValueByCurrency->has($code))
                    ->mapWithKeys(fn ($code) => [$code => $stockValueByCurrency[$code]]);
            @endphp
            @forelse ($stockValueByCurrency as $code => $total)
                @php
                    // Presentational only: split the existing formatted string
                    // ("₺ 1.234,56") on its one guaranteed space so just the
                    // currency symbol can be recolored — the amount itself is
                    // untouched and still comes from Currency::formatWithSymbol().
                    $formattedStockValue = \App\Support\Currency::formatWithSymbol($total, $code);
                    $stockValueSymbol = \Illuminate\Support\Str::before($formattedStockValue, ' ');
                    $stockValueAmount = \Illuminate\Support\Str::after($formattedStockValue, ' ');
                    $stockValueSymbolColor = match ($code) {
                        'TL' => 'text-red-600',
                        'USD' => 'text-green-600',
                        'EUR' => 'text-blue-600',
                        default => '',
                    };
                @endphp
                <p class="text-xs sm:text-sm text-gray-500">Toplam Stok Değeri ({{ $code }})</p>
                <p class="text-base sm:text-lg font-bold text-indigo-700 leading-tight mb-1 last:mb-0"><span class="{{ $stockValueSymbolColor }}">{{ $stockValueSymbol }}</span> {{ $stockValueAmount }}</p>
            @empty
                <p class="text-xs sm:text-sm text-gray-500">Toplam Stok Değeri</p>
                <p class="text-base font-semibold text-gray-400">—</p>
            @endforelse
        </div>
    </div>

    {{-- ============================= 2. FİNANSAL ÖZET ============================= --}}
    <div class="bg-white rounded-lg shadow p-4 sm:p-5 mb-6">
        <h2 class="text-base sm:text-lg font-bold text-gray-900 mb-4">Finansal Özet</h2>

        {{-- One compact card per currency at every breakpoint: 3-up from
             640px, a safe single column below it (no table, ever). --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
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
                        {{ \App\Support\Quantity::format($product->current_stock) }} {{ $product->unit }}
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
                        {{ $movement->type === 'in' ? '+' : '-' }}{{ \App\Support\Quantity::format($movement->quantity) }}
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Henüz hareket yok.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
