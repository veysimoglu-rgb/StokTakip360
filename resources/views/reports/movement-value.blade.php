<x-app-layout>
    <x-slot name="title">Stok Hareketi Değer Raporu</x-slot>

    <p class="text-sm text-gray-500 mb-4">
        Her hareketin tutarı, o hareketin kayıtlı birim fiyatı ile hesaplanır (ürünün güncel fiyatı kullanılmaz).
        Para birimi kayıtlı olmayan eski hareketler listede miktarıyla görünür ancak toplamlara dahil edilmez.
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
        <x-select-input name="type" class="w-36">
            <option value="">Tüm Hareketler</option>
            <option value="in" @selected(request('type') === 'in')>Giriş</option>
            <option value="out" @selected(request('type') === 'out')>Çıkış</option>
        </x-select-input>
        <x-select-input name="currency" class="w-32">
            <option value="">Tüm Para Birimleri</option>
            @foreach (\App\Support\Currency::LIST as $code)
                <option value="{{ $code }}" @selected(request('currency') === $code)>{{ $code }}</option>
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
        <x-select-input name="customer_id" class="w-40">
            <option value="">Tüm Müşteriler</option>
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected(request('customer_id') == $customer->id)>{{ $customer->name }}</option>
            @endforeach
        </x-select-input>
        <x-select-input name="supplier_id" class="w-40">
            <option value="">Tüm Tedarikçiler</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
            @endforeach
        </x-select-input>
        <x-secondary-button type="submit">Filtrele</x-secondary-button>

        <div class="ms-auto flex gap-2">
            <a href="{{ route('reports.movement-value.export', request()->query()) }}" title="CSV dosyası indirilir, Excel'de doğrudan açılabilir">
                <x-secondary-button type="button">Excel / CSV'ye Aktar</x-secondary-button>
            </a>
            <a href="{{ route('reports.movement-value.print', request()->query()) }}" target="_blank" title="Yazdırma önizlemesi açılır, tarayıcı üzerinden PDF olarak kaydedilebilir">
                <x-secondary-button type="button">Yazdır / PDF Kaydet</x-secondary-button>
            </a>
        </div>
    </form>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-sm text-gray-500">Toplam Giriş Miktarı</p>
            <p class="text-2xl font-semibold text-gray-800 mt-1">{{ $qtyTotals->get('in', 0) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-sm text-gray-500">Toplam Çıkış Miktarı</p>
            <p class="text-2xl font-semibold text-gray-800 mt-1">{{ $qtyTotals->get('out', 0) }}</p>
        </div>
        @foreach ($totalsByCurrency as $currency => $rows)
            @foreach ($rows as $row)
                <div class="bg-white rounded-lg shadow p-5">
                    <p class="text-sm text-gray-500">{{ $row->type === 'in' ? 'Giriş/Alış Tutarı' : 'Çıkış/Satış Tutarı' }} ({{ $currency }})</p>
                    <p class="text-2xl font-semibold text-gray-800 mt-1">{{ \App\Support\Currency::formatWithSymbol($row->total, $currency) }}</p>
                </div>
            @endforeach
        @endforeach
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto mb-6">
        <div class="px-4 py-3 border-b">
            <h3 class="font-semibold text-gray-800">Ürün Bazlı Özet (Çıkışlar)</h3>
        </div>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Ürün</th>
                    <th class="px-4 py-3">Kod</th>
                    <th class="px-4 py-3">Kategori</th>
                    <th class="px-4 py-3 text-right">Çıkış Miktarı</th>
                    <th class="px-4 py-3">Para Birimi</th>
                    <th class="px-4 py-3 text-right">Satış Tutarı</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($productSummary as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row->name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $row->code }}</td>
                        <td class="px-4 py-3">{{ $categoryNames->get($row->category_id, '-') }}</td>
                        <td class="px-4 py-3 text-right">{{ $row->qty_out }}</td>
                        <td class="px-4 py-3">{{ $row->currency ?? 'Bilinmiyor' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($row->amount > 0 && $row->currency)
                                {{ \App\Support\Currency::format($row->amount, $row->currency) }}
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Seçilen dönemde çıkış bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Tarih</th>
                    <th class="px-4 py-3">Ürün</th>
                    <th class="px-4 py-3">Tip</th>
                    <th class="px-4 py-3 text-right">Miktar</th>
                    <th class="px-4 py-3 text-right">Birim Fiyat</th>
                    <th class="px-4 py-3 text-right">Tutar</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($movements as $movement)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $movement->movement_date->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-3">{{ $movement->product->name }}</td>
                        <td class="px-4 py-3">
                            @if ($movement->type === 'in')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">Giriş</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-800">Çıkış</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ $movement->quantity }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($movement->amount() === null)
                                -
                            @elseif ($movement->currency)
                                {{ \App\Support\Currency::format($movement->unit_price, $movement->currency) }}
                            @else
                                {{ number_format($movement->unit_price, 2, ',', '.') }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($movement->amount() === null)
                                -
                            @elseif ($movement->currency)
                                {{ \App\Support\Currency::format($movement->amount(), $movement->currency) }}
                            @else
                                {{ number_format($movement->amount(), 2, ',', '.') }}
                                <span class="text-xs text-gray-400">(para birimi bilinmiyor)</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Kayıt bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $movements->links() }}</div>
</x-app-layout>
