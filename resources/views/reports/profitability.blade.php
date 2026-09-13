<x-app-layout>
    <x-slot name="title">Kârlılık Raporu</x-slot>

    <p class="text-sm text-gray-500 mb-4">
        Brüt kâr, satış anında kaydedilen maliyet snapshot'ından (<code>sale_items.cost_price</code>) hesaplanır.
        Maliyet bilgisi olmayan (eski demo) satışların geliri kaybolmaz ama kâr hesabına dahil edilmez.
        İptal edilmiş satışlar bu rapora hiç dahil edilmez.
    </p>

    @php
        $presetParams = request()->except(['date_from', 'date_to', 'period', 'page']);
    @endphp
    <div class="flex flex-wrap gap-2 mb-4">
        @foreach (['today' => 'Bugün', 'this_week' => 'Bu Hafta', 'this_month' => 'Bu Ay', 'last_month' => 'Geçen Ay'] as $key => $label)
            <a href="{{ request()->url() }}?{{ http_build_query(array_merge($presetParams, ['period' => $key])) }}"
               @class([
                   'px-3 py-1.5 rounded-md text-sm border',
                   'bg-indigo-600 text-white border-indigo-600' => $period === $key,
                   'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' => $period !== $key,
               ])>{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap gap-2 mb-6 items-end">
        <div>
            <x-input-label value="Başlangıç" class="text-xs" />
            <x-text-input type="date" name="date_from" value="{{ $dateFrom }}" />
        </div>
        <div>
            <x-input-label value="Bitiş" class="text-xs" />
            <x-text-input type="date" name="date_to" value="{{ $dateTo }}" />
        </div>
        <x-select-input name="currency" class="w-32">
            <option value="">Tüm Para Birimleri</option>
            @foreach (\App\Support\Currency::LIST as $currencyOption)
                <option value="{{ $currencyOption }}" @selected(request('currency') === $currencyOption)>{{ $currencyOption }}</option>
            @endforeach
        </x-select-input>
        <x-select-input name="product_id" class="w-48">
            <option value="">Tüm Ürünler</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>
            @endforeach
        </x-select-input>
        <x-select-input name="category_id" class="w-40">
            <option value="">Tüm Kategoriler</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
            @endforeach
        </x-select-input>
        <x-secondary-button type="submit">Filtrele</x-secondary-button>

        <div class="ms-auto flex gap-2">
            <a href="{{ route('reports.profitability.export', request()->query()) }}">
                <x-secondary-button type="button">Excel / CSV'ye Aktar</x-secondary-button>
            </a>
            <a href="{{ route('reports.profitability.print', request()->query()) }}" target="_blank">
                <x-secondary-button type="button">Yazdır / PDF Kaydet</x-secondary-button>
            </a>
        </div>
    </form>

    {{-- ============================= CURRENCY BAZLI ÖZET ============================= --}}
    @if ($summaryByCurrency->isEmpty())
        <div class="bg-white rounded-lg shadow p-10 text-center text-gray-500 mb-6">
            Seçilen filtrelerde satış bulunamadı.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
            @foreach ($summaryByCurrency as $currency => $row)
                <x-currency-summary-card :currency="$currency">
                    <x-metric-stat label="Satılan Adet" :value="(int) $row->qty" />
                    <x-metric-stat label="Toplam Satış Tutarı" :value="\App\Support\Currency::format($row->revenue, $currency)" />
                    <x-metric-stat label="Toplam Maliyet" :value="\App\Support\Currency::format($row->cost, $currency)" color="text-blue-600" />
                    <x-metric-stat
                        label="Brüt Kâr"
                        :value="$row->profit === null ? 'Maliyet bilgisi yok' : \App\Support\Currency::format($row->profit, $currency)"
                        :color="$row->profit === null ? 'text-gray-400' : ($row->profit >= 0 ? 'text-green-600' : 'text-red-600')"
                    />
                    <x-metric-stat
                        label="Kâr Marjı"
                        :value="$row->margin === null ? '—' : number_format($row->margin, 1, ',', '.').' %'"
                        :color="$row->margin === null ? 'text-gray-400' : 'text-green-600'"
                    />
                    @if ((float) $row->revenue_without_cost > 0)
                        <x-metric-stat label="Maliyetsiz Satış Tutarı" :value="\App\Support\Currency::format($row->revenue_without_cost, $currency)" color="text-amber-600" />
                    @endif
                </x-currency-summary-card>
            @endforeach
        </div>

        @php $hasMissingCost = $summaryByCurrency->contains(fn ($row) => (float) $row->revenue_without_cost > 0); @endphp
        @if ($hasMissingCost)
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800 mb-6">
                Bazı satışların maliyet bilgisi (WP-9a öncesi kayıtlar) bulunmuyor. Bu satışların geliri toplam satış
                tutarına dahil, ancak maliyet/kâr hesabına dahil edilmedi.
                @foreach ($summaryByCurrency as $currency => $row)
                    @if ((float) $row->revenue_without_cost > 0)
                        <span class="block mt-1">{{ $currency }}: {{ (int) $row->qty_without_cost }} adet, {{ \App\Support\Currency::format($row->revenue_without_cost, $currency) }} maliyetsiz satış.</span>
                    @endif
                @endforeach
            </div>
        @endif
    @endif

    {{-- ============================= ÜRÜN PERFORMANSI (CURRENCY BAZINDA AYRI TABLOLAR) ============================= --}}
    @foreach ($productPerformanceByCurrency as $currency => $rows)
        <div class="bg-white rounded-lg shadow overflow-x-auto mb-6">
            <div class="px-4 py-3 border-b">
                <h3 class="font-semibold text-gray-800">Ürün Performansı ({{ $currency }})</h3>
            </div>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 text-left">
                    <tr>
                        <th class="px-4 py-3">Ürün</th>
                        <th class="hidden sm:table-cell px-4 py-3">Kod</th>
                        <th class="hidden sm:table-cell px-4 py-3">Para Birimi</th>
                        <th class="px-4 py-3 text-right">Satılan Adet</th>
                        <th class="px-4 py-3 text-right">Satış Tutarı</th>
                        <th class="hidden sm:table-cell px-4 py-3 text-right">Maliyet</th>
                        <th class="px-4 py-3 text-right">Brüt Kâr</th>
                        <th class="hidden sm:table-cell px-4 py-3 text-right">Kâr Marjı</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row->product_name }}</td>
                            <td class="hidden sm:table-cell px-4 py-3 font-mono text-xs">{{ $row->product_code }}</td>
                            <td class="hidden sm:table-cell px-4 py-3">{{ $currency }}</td>
                            <td class="px-4 py-3 text-right">{{ (int) $row->qty }}</td>
                            <td class="px-4 py-3 text-right font-medium">{{ \App\Support\Currency::format($row->revenue, $currency) }}</td>
                            <td class="hidden sm:table-cell px-4 py-3 text-right">
                                {{ $row->has_cost ? \App\Support\Currency::format($row->cost, $currency) : 'Maliyet bilgisi yok' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($row->profit === null)
                                    <span class="text-gray-400 text-xs">—</span>
                                @else
                                    <span @class(['font-medium', 'text-green-600' => $row->profit >= 0, 'text-red-600' => $row->profit < 0])>{{ \App\Support\Currency::format($row->profit, $currency) }}</span>
                                @endif
                            </td>
                            <td class="hidden sm:table-cell px-4 py-3 text-right">{{ $row->margin === null ? '—' : number_format($row->margin, 1, ',', '.').' %' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</x-app-layout>
