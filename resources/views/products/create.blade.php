<x-app-layout>
    <x-slot name="title">Yeni Ürün</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <form method="POST" action="{{ route('products.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf

            <div>
                <x-input-label for="code" value="Ürün Kodu" />
                <x-text-input id="code" name="code" value="{{ old('code') }}" class="w-full" required autofocus />
                <x-input-error :messages="$errors->get('code')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="barcode" value="Barkod" />
                <x-text-input id="barcode" name="barcode" value="{{ old('barcode') }}" class="w-full" />
                <x-input-error :messages="$errors->get('barcode')" class="mt-1" />
            </div>

            <div class="md:col-span-2">
                <x-input-label for="name" value="Ürün Adı" />
                <x-text-input id="name" name="name" value="{{ old('name') }}" class="w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="category_id" value="Kategori" />
                <x-select-input id="category_id" name="category_id">
                    <option value="">Seçiniz</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="brand_id" value="Marka" />
                <x-select-input id="brand_id" name="brand_id">
                    <option value="">Seçiniz</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(old('brand_id') == $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="unit" value="Birim" />
                <x-text-input id="unit" name="unit" list="unit-suggestions" value="{{ old('unit', 'Adet') }}" class="w-full" required />
                <datalist id="unit-suggestions">
                    <option value="Adet"><option value="Kutu"><option value="Kg"><option value="Litre"><option value="Metre"><option value="Paket">
                </datalist>
                <x-input-error :messages="$errors->get('unit')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="min_stock" value="Minimum Stok" />
                <x-text-input id="min_stock" type="number" min="0" name="min_stock" value="{{ old('min_stock', 0) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('min_stock')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="shelf_location" value="Raf Konumu (opsiyonel)" />
                <x-text-input id="shelf_location" name="shelf_location" placeholder="Örn: A-02" value="{{ old('shelf_location') }}" class="w-full" />
            </div>

            <div>
                <x-input-label for="purchase_price" value="Alış Fiyatı" />
                <x-text-input id="purchase_price" type="number" step="0.01" min="0" name="purchase_price" value="{{ old('purchase_price', 0) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('purchase_price')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="sale_price" value="Satış Fiyatı" />
                <x-text-input id="sale_price" type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price', 0) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('sale_price')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="vat_rate" value="KDV (%)" />
                <x-text-input id="vat_rate" type="number" step="0.01" min="0" max="100" name="vat_rate" value="{{ old('vat_rate', 20) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('vat_rate')" class="mt-1" />
            </div>

            <div class="md:col-span-2">
                <x-input-label for="description" value="Açıklama" />
                <x-textarea-input id="description" name="description" rows="3">{{ old('description') }}</x-textarea-input>
            </div>

            <div class="md:col-span-2">
                <label class="inline-flex items-center">
                    <input type="checkbox" name="active" value="1" checked class="rounded border-gray-300 text-indigo-600">
                    <span class="ms-2 text-sm text-gray-700">Aktif</span>
                </label>
            </div>

            <div class="md:col-span-2 flex gap-3 pt-2">
                <x-primary-button>Kaydet</x-primary-button>
                <a href="{{ route('products.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>
    </div>
</x-app-layout>
