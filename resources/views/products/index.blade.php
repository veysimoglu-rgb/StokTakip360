<x-app-layout>
    <x-slot name="title">Ürünler</x-slot>

    @php
        // Sort links keep every other current filter (query string) and only
        // ever touch sort/direction. Clicking the active column's arrow
        // toggles asc/desc; clicking any other column always starts at asc.
        $sortUrl = fn (string $column) => request()->fullUrlWithQuery([
            'sort' => $column,
            'direction' => ($sort === $column && $direction === 'asc') ? 'desc' : 'asc',
            'page' => null,
        ]);
        $sortArrow = fn (string $column) => $sort !== $column ? '↕' : ($direction === 'asc' ? '↑' : '↓');
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <form method="GET" class="flex flex-wrap gap-2">
            <x-text-input type="text" name="q" value="{{ request('q') }}" placeholder="Ürün adı, kod veya barkod..." class="w-64" />
            <x-select-input name="category_id" class="w-48">
                <option value="">Tüm Kategoriler</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </x-select-input>
            <x-select-input name="currency" class="w-32">
                <option value="">Tüm Para Birimleri</option>
                @foreach (\App\Support\Currency::LIST as $currencyOption)
                    <option value="{{ $currencyOption }}" @selected(request('currency') === $currencyOption)>{{ $currencyOption }}</option>
                @endforeach
            </x-select-input>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="low_stock" value="1" @checked(request('low_stock')) class="rounded border-gray-300 text-indigo-600" onchange="this.form.submit()">
                Sadece kritik stok
            </label>
            <x-secondary-button type="submit">Filtrele</x-secondary-button>
        </form>
        @role('Admin')
        <a href="{{ route('products.create') }}" class="hidden sm:inline-block">
            <x-primary-button>+ Yeni Ürün</x-primary-button>
        </a>
        <a href="{{ route('products.create') }}"
           class="sm:hidden fixed top-20 right-4 z-30 w-12 h-12 rounded-full bg-indigo-600 text-white shadow-lg flex items-center justify-center text-2xl leading-none"
           aria-label="Yeni Ürün Ekle">
            +
        </a>
        @endrole
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Kod</th>
                    <th class="px-4 py-3">Ürün Adı</th>
                    <th class="hidden sm:table-cell px-4 py-3">
                        <a href="{{ $sortUrl('category') }}" class="inline-flex items-center gap-1 hover:text-gray-800">
                            Kategori <span class="text-gray-400">{{ $sortArrow('category') }}</span>
                        </a>
                    </th>
                    <th class="hidden sm:table-cell px-4 py-3">Birim</th>
                    <th class="px-4 py-3 text-right">
                        <a href="{{ $sortUrl('stock') }}" class="inline-flex items-center gap-1 hover:text-gray-800">
                            Stok <span class="text-gray-400">{{ $sortArrow('stock') }}</span>
                        </a>
                    </th>
                    <th class="hidden sm:table-cell px-4 py-3 text-right">
                        @if (request('currency'))
                            <a href="{{ $sortUrl('sale_price') }}" class="inline-flex items-center gap-1 hover:text-gray-800">
                                Satış Fiyatı <span class="text-gray-400">{{ $sortArrow('sale_price') }}</span>
                            </a>
                        @else
                            <span title="Sıralamak için önce bir para birimi seçin">Satış Fiyatı</span>
                        @endif
                    </th>
                    <th class="hidden sm:table-cell px-4 py-3">Durum</th>
                    @role('Admin')<th class="px-4 py-3 text-right">İşlemler</th>@endrole
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($products as $product)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $product->code }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('products.show', $product) }}" class="text-indigo-600 hover:underline">{{ $product->name }}</a>
                            <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $product->category?->name ?? '-' }} · {{ $product->unit }}</p>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $product->category?->name ?? '-' }}</td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $product->unit }}</td>
                        <td class="px-4 py-3 text-right">
                            <span @class([
                                'font-medium',
                                'text-red-600' => $product->current_stock <= $product->min_stock,
                            ])>{{ $product->current_stock }}</span>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3 text-right">{{ \App\Support\Currency::format($product->sale_price, $product->currency) }}</td>
                        <td class="hidden sm:table-cell px-4 py-3">
                            @if ($product->active)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">Aktif</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Pasif</span>
                            @endif
                        </td>
                        @role('Admin')
                        <td class="px-4 py-3 text-right space-x-2">
                            <a href="{{ route('products.edit', $product) }}" class="text-indigo-600 hover:underline">Düzenle</a>
                            <form action="{{ route('products.destroy', $product) }}" method="POST" class="inline" onsubmit="return confirm('Silinsin mi?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">Sil</button>
                            </form>
                        </td>
                        @endrole
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">Kayıt bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
