<x-app-layout>
    <x-slot name="title">{{ $product->name }}</x-slot>

    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-lg font-semibold text-gray-800">{{ $product->name }}</h2>
                @if ($product->active)
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">Aktif</span>
                @else
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Pasif</span>
                @endif
                @php $stockStatus = $product->stockStatusLabel(); @endphp
                <span @class([
                    'inline-flex px-2 py-0.5 rounded-full text-xs',
                    'bg-red-100 text-red-800' => $stockStatus === 'Tükendi',
                    'bg-amber-100 text-amber-800' => $stockStatus === 'Kritik',
                    'bg-blue-100 text-blue-800' => $stockStatus === 'Stokta',
                ])>{{ $stockStatus }}</span>
            </div>
            <p class="text-sm text-gray-500 font-mono mt-1">
                {{ $product->code }}{{ $product->barcode ? ' · '.$product->barcode : '' }}
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 self-center hover:underline">← Ürünler</a>
            @role('Admin')
            <a href="{{ route('products.edit', $product) }}"><x-secondary-button>Düzenle</x-secondary-button></a>
            @endrole
        </div>
    </div>

    {{-- ============================= GENEL / STOK / FİYAT ============================= --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <p class="text-xs sm:text-sm text-gray-500">Kategori / Marka</p>
            <p class="text-base sm:text-lg font-semibold text-gray-800 mt-1">{{ $product->category?->name ?? '-' }}</p>
            <p class="text-xs text-gray-400">{{ $product->brand?->name ?? '-' }} · {{ $product->unit }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <p class="text-xs sm:text-sm text-gray-500">Mevcut Stok</p>
            <p class="text-3xl font-semibold text-gray-800 mt-1">{{ $product->current_stock }}</p>
            <p class="text-xs text-gray-400">Kritik seviye: {{ $product->min_stock }}{{ $product->shelf_location ? ' · '.$product->shelf_location : '' }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <p class="text-xs sm:text-sm text-gray-500">Güncel Satış Fiyatı</p>
            <p class="text-xl sm:text-2xl font-semibold text-green-600 mt-1">{{ \App\Support\Currency::format($product->sale_price, $product->currency) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-3 sm:p-5">
            <p class="text-xs sm:text-sm text-gray-500">Güncel Alış / Maliyet Fiyatı</p>
            <p class="text-xl sm:text-2xl font-semibold text-blue-600 mt-1">{{ \App\Support\Currency::format($product->purchase_price, $product->currency) }}</p>
            <p class="text-xs text-gray-400">Ürünün şu anki referans değeri — geçmiş satışların maliyet snapshot'ını yansıtmaz.</p>
        </div>
    </div>

    {{-- ============================= SATIŞ / ALIŞ ÖZETİ ============================= --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
            <h3 class="font-semibold text-gray-800 mb-3">Satış Özeti</h3>
            @if ($saleSummaryByCurrency->isEmpty())
                <p class="text-sm text-gray-500">Bu ürün henüz satılmadı.</p>
            @else
                <div class="space-y-2">
                    @foreach ($saleSummaryByCurrency as $currency => $row)
                        <x-currency-summary-card :currency="$currency">
                            <x-metric-stat label="Toplam Satılan Adet" :value="(int) $row->qty" />
                            <x-metric-stat label="Toplam Satış Tutarı" :value="\App\Support\Currency::format($row->total, $currency)" color="text-green-600" />
                            <x-metric-stat label="Ortalama Satış Fiyatı" :value="\App\Support\Currency::format($row->avg_price, $currency)" />
                            <x-metric-stat label="Son Satış" :value="\Illuminate\Support\Carbon::parse($row->last_date)->format('d.m.Y')" />
                        </x-currency-summary-card>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="bg-white rounded-lg shadow p-4 sm:p-5">
            <h3 class="font-semibold text-gray-800 mb-3">Alış Özeti</h3>
            @if ($purchaseSummaryByCurrency->isEmpty())
                <p class="text-sm text-gray-500">Bu ürün için henüz alış kaydı yok.</p>
            @else
                <div class="space-y-2">
                    @foreach ($purchaseSummaryByCurrency as $currency => $row)
                        <x-currency-summary-card :currency="$currency">
                            <x-metric-stat label="Toplam Alınan Adet" :value="(int) $row->qty" />
                            <x-metric-stat label="Toplam Alış Tutarı" :value="\App\Support\Currency::format($row->total, $currency)" color="text-blue-600" />
                            <x-metric-stat label="Ortalama Alış Fiyatı" :value="\App\Support\Currency::format($row->avg_price, $currency)" />
                            <x-metric-stat label="Son Alış" :value="\Illuminate\Support\Carbon::parse($row->last_date)->format('d.m.Y')" />
                        </x-currency-summary-card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ============================= SATIŞ GEÇMİŞİ ============================= --}}
    <div class="bg-white rounded-lg shadow overflow-x-auto mb-2">
        <div class="px-4 pt-4">
            <h3 class="font-semibold text-gray-800">Satış Geçmişi</h3>
        </div>
        <table class="min-w-full text-sm mt-2">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Tarih</th>
                    <th class="px-4 py-3">Satış No</th>
                    <th class="hidden sm:table-cell px-4 py-3">Cari</th>
                    <th class="px-4 py-3 text-right">Miktar</th>
                    <th class="hidden sm:table-cell px-4 py-3 text-right">Birim Fiyat</th>
                    <th class="hidden sm:table-cell px-4 py-3 text-right">Maliyet Snapshot</th>
                    <th class="px-4 py-3 text-right">Toplam</th>
                    <th class="px-4 py-3 text-right">Kâr</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($saleItems as $item)
                    <tr @class(['opacity-50' => $item->sale->isCancelled()])>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $item->sale->sale_date->format('d.m.Y') }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('sales.show', $item->sale) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $item->sale->number }}</a>
                            @if ($item->sale->isCancelled())
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 ms-1">İptal</span>
                            @endif
                            <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $item->sale->account?->name ?? 'Genel Müşteri' }}</p>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $item->sale->account?->name ?? 'Genel Müşteri' }}</td>
                        <td class="px-4 py-3 text-right">{{ $item->quantity }}</td>
                        <td class="hidden sm:table-cell px-4 py-3 text-right">{{ \App\Support\Currency::format($item->unit_price, $item->sale->currency) }}</td>
                        <td class="hidden sm:table-cell px-4 py-3 text-right">
                            {{ $item->cost_price !== null ? \App\Support\Currency::format($item->cost_price, $item->sale->currency) : 'Maliyet bilgisi yok' }}
                        </td>
                        <td class="px-4 py-3 text-right font-medium">{{ \App\Support\Currency::format($item->line_total, $item->sale->currency) }}</td>
                        <td class="px-4 py-3 text-right">
                            @php $profit = $item->grossProfit(); @endphp
                            @if ($profit === null)
                                <span class="text-gray-400 text-xs">Maliyet bilgisi yok</span>
                            @else
                                <span @class(['font-medium', 'text-green-600' => $profit >= 0, 'text-red-600' => $profit < 0])>{{ \App\Support\Currency::format($profit, $item->sale->currency) }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">Bu ürün için satış hareketi bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mb-6">{{ $saleItems->links() }}</div>

    {{-- ============================= ALIŞ GEÇMİŞİ ============================= --}}
    <div class="bg-white rounded-lg shadow overflow-x-auto mb-2">
        <div class="px-4 pt-4">
            <h3 class="font-semibold text-gray-800">Alış Geçmişi</h3>
        </div>
        <table class="min-w-full text-sm mt-2">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Tarih</th>
                    <th class="px-4 py-3">Alış No</th>
                    <th class="hidden sm:table-cell px-4 py-3">Tedarikçi</th>
                    <th class="px-4 py-3 text-right">Miktar</th>
                    <th class="hidden sm:table-cell px-4 py-3 text-right">Birim Fiyat</th>
                    <th class="px-4 py-3 text-right">Toplam</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($purchaseItems as $item)
                    <tr @class(['opacity-50' => $item->purchase->isCancelled()])>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $item->purchase->purchase_date->format('d.m.Y') }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('purchases.show', $item->purchase) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $item->purchase->number }}</a>
                            @if ($item->purchase->isCancelled())
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 ms-1">İptal</span>
                            @endif
                            <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $item->purchase->account?->name ?? 'Genel Tedarikçi' }}</p>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $item->purchase->account?->name ?? 'Genel Tedarikçi' }}</td>
                        <td class="px-4 py-3 text-right">{{ $item->quantity }}</td>
                        <td class="hidden sm:table-cell px-4 py-3 text-right">{{ \App\Support\Currency::format($item->unit_price, $item->purchase->currency) }}</td>
                        <td class="px-4 py-3 text-right font-medium">{{ \App\Support\Currency::format($item->line_total, $item->purchase->currency) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Bu ürün için alış hareketi bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mb-6">{{ $purchaseItems->links() }}</div>

    {{-- ============================= STOK HAREKETLERİ ============================= --}}
    <div class="bg-white rounded-lg shadow overflow-x-auto mb-2">
        <div class="px-4 pt-4">
            <h3 class="font-semibold text-gray-800">Stok Hareketleri</h3>
        </div>
        <table class="min-w-full text-sm mt-2">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Tarih</th>
                    <th class="px-4 py-3">Tip</th>
                    <th class="px-4 py-3 text-right">Miktar</th>
                    <th class="hidden sm:table-cell px-4 py-3 text-right">Birim Fiyat</th>
                    <th class="hidden sm:table-cell px-4 py-3">Para Birimi</th>
                    <th class="hidden sm:table-cell px-4 py-3">Cari</th>
                    <th class="px-4 py-3">Kaynak</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($stockMovements as $movement)
                    <tr @class(['opacity-50' => $movement->isCancelled()])>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $movement->movement_date->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-3">
                            @if ($movement->type === 'in')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">Giriş</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-800">Çıkış</span>
                            @endif
                            @if ($movement->isCancelled())
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 ms-1">İptal</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ $movement->quantity }}</td>
                        <td class="hidden sm:table-cell px-4 py-3 text-right">
                            @if ($movement->amount() === null)
                                -
                            @elseif ($movement->currency)
                                {{ \App\Support\Currency::format($movement->unit_price, $movement->currency) }}
                            @else
                                {{ number_format($movement->unit_price, 2, ',', '.') }}
                            @endif
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $movement->currency ?? 'Bilinmiyor' }}</td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $movement->account?->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if ($movement->source instanceof \App\Models\Sale)
                                <a href="{{ route('sales.show', $movement->source) }}" class="text-indigo-600 hover:underline text-xs">{{ $movement->source->number }}</a>
                            @elseif ($movement->source instanceof \App\Models\Purchase)
                                <a href="{{ route('purchases.show', $movement->source) }}" class="text-indigo-600 hover:underline text-xs">{{ $movement->source->number }}</a>
                            @else
                                <span class="text-gray-500 text-xs">Manuel</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Bu ürün için stok hareketi bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $stockMovements->links() }}</div>
</x-app-layout>
