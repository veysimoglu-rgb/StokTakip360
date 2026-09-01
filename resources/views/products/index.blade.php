<x-app-layout>
    <x-slot name="title">Ürünler</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <form method="GET" class="flex flex-wrap gap-2">
            <x-text-input type="text" name="q" value="{{ request('q') }}" placeholder="Ürün adı, kod veya barkod..." class="w-64" />
            <x-select-input name="category_id" class="w-48">
                <option value="">Tüm Kategoriler</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </x-select-input>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="low_stock" value="1" @checked(request('low_stock')) class="rounded border-gray-300 text-indigo-600" onchange="this.form.submit()">
                Sadece kritik stok
            </label>
            <x-secondary-button type="submit">Filtrele</x-secondary-button>
        </form>
        @role('Admin')
        <a href="{{ route('products.create') }}">
            <x-primary-button>+ Yeni Ürün</x-primary-button>
        </a>
        @endrole
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Kod</th>
                    <th class="px-4 py-3">Ürün Adı</th>
                    <th class="px-4 py-3">Kategori</th>
                    <th class="px-4 py-3">Birim</th>
                    <th class="px-4 py-3 text-right">Stok</th>
                    <th class="px-4 py-3 text-right">Satış Fiyatı</th>
                    <th class="px-4 py-3">Durum</th>
                    @role('Admin')<th class="px-4 py-3 text-right">İşlemler</th>@endrole
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($products as $product)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $product->code }}</td>
                        <td class="px-4 py-3">{{ $product->name }}</td>
                        <td class="px-4 py-3">{{ $product->category?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $product->unit }}</td>
                        <td class="px-4 py-3 text-right">
                            <span @class([
                                'font-medium',
                                'text-red-600' => $product->current_stock <= $product->min_stock,
                            ])>{{ $product->current_stock }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">{{ number_format($product->sale_price, 2) }}</td>
                        <td class="px-4 py-3">
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
