<x-app-layout>
    <x-slot name="title">Ürün Düzenle</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <form method="POST" action="{{ route('products.update', $product) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf @method('PUT')

            <div>
                <x-input-label for="code" value="Ürün Kodu" />
                <x-text-input id="code" name="code" value="{{ old('code', $product->code) }}" class="w-full" required autofocus />
                <x-input-error :messages="$errors->get('code')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="barcode" value="Barkod" />
                <x-text-input id="barcode" name="barcode" value="{{ old('barcode', $product->barcode) }}" class="w-full" />
                <x-input-error :messages="$errors->get('barcode')" class="mt-1" />
            </div>

            <div class="md:col-span-2">
                <x-input-label for="name" value="Ürün Adı" />
                <x-text-input id="name" name="name" value="{{ old('name', $product->name) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="category_id" value="Kategori" />
                <x-select-input id="category_id" name="category_id">
                    <option value="">Seçiniz</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="brand_id" value="Marka" />
                <x-select-input id="brand_id" name="brand_id">
                    <option value="">Seçiniz</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(old('brand_id', $product->brand_id) == $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="unit" value="Birim" />
                <x-text-input id="unit" name="unit" list="unit-suggestions" value="{{ old('unit', $product->unit) }}" class="w-full" required />
                <datalist id="unit-suggestions">
                    <option value="Adet"><option value="Kutu"><option value="Kg"><option value="Litre"><option value="Metre"><option value="Paket">
                </datalist>
                <x-input-error :messages="$errors->get('unit')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="min_stock" value="Minimum Stok" />
                <x-text-input id="min_stock" type="number" min="0" step="0.001" name="min_stock" value="{{ old('min_stock', $product->min_stock) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('min_stock')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="shelf_location" value="Raf Konumu (opsiyonel)" />
                <x-text-input id="shelf_location" name="shelf_location" placeholder="Örn: A-02" value="{{ old('shelf_location', $product->shelf_location) }}" class="w-full" />
            </div>

            <div>
                <x-input-label for="purchase_price" value="Alış Fiyatı" />
                <x-text-input id="purchase_price" type="number" step="0.01" min="0" name="purchase_price" value="{{ old('purchase_price', $product->purchase_price) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('purchase_price')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="sale_price" value="Satış Fiyatı" />
                <x-text-input id="sale_price" type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price', $product->sale_price) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('sale_price')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="currency" value="Para Birimi" />
                <x-select-input id="currency" name="currency" class="w-full" required>
                    @foreach (\App\Support\Currency::LIST as $code)
                        <option value="{{ $code }}" @selected(old('currency', $product->currency) === $code)>{{ $code }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('currency')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="vat_rate" value="KDV (%)" />
                <x-text-input id="vat_rate" type="number" step="0.01" min="0" max="100" name="vat_rate" value="{{ old('vat_rate', $product->vat_rate) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('vat_rate')" class="mt-1" />
            </div>

            <div>
                <x-input-label value="Güncel Stok" />
                <p class="mt-2 text-lg font-semibold">{{ \App\Support\Quantity::format($product->current_stock) }} {{ $product->unit }}</p>
                <p class="text-xs text-gray-500">Stok miktarı, Stok Girişi/Çıkışı ekranlarından değiştirilir.</p>
            </div>

                        <div class="md:col-span-2 border-t pt-4">
                <p class="text-sm font-semibold text-gray-700">Paketleme (opsiyonel)</p>
                <p class="text-xs text-gray-500 mt-1">Sipariş balya/koli üzerinden girilecekse doldurun. Stok her zaman ürünün temel birimi (Adet) üzerinden tutulur. Örn: 1 Balya = 15 Paket, 1 Paket = 100 Adet.</p>
            </div>

            <div>
                <x-input-label for="package_label" value="Büyük Paket Adı (örn. Balya)" />
                <x-text-input id="package_label" name="package_label" value="{{ old('package_label', $product->package_label ?? null) }}" class="w-full" />
                <x-input-error :messages="$errors->get('package_label')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="package_qty" value="1 Büyük Paket = kaç küçük paket?" />
                <x-text-input id="package_qty" type="number" min="1" step="1" name="package_qty" value="{{ old('package_qty', $product->package_qty ?? null) }}" class="w-full" />
                <x-input-error :messages="$errors->get('package_qty')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="subunit_label" value="Küçük Paket Adı (örn. Paket)" />
                <x-text-input id="subunit_label" name="subunit_label" value="{{ old('subunit_label', $product->subunit_label ?? null) }}" class="w-full" />
                <x-input-error :messages="$errors->get('subunit_label')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="subunit_to_base_qty" value="1 Küçük Paket = kaç Adet?" />
                <x-text-input id="subunit_to_base_qty" type="number" min="1" step="1" name="subunit_to_base_qty" value="{{ old('subunit_to_base_qty', $product->subunit_to_base_qty ?? null) }}" class="w-full" />
                <x-input-error :messages="$errors->get('subunit_to_base_qty')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="package_weight_kg" value="1 Büyük Paket ağırlığı (kg)" />
                <x-text-input id="package_weight_kg" type="number" min="0" step="0.001" name="package_weight_kg" value="{{ old('package_weight_kg', $product->package_weight_kg ?? null) }}" class="w-full" />
                <x-input-error :messages="$errors->get('package_weight_kg')" class="mt-1" />
            </div>

            <div class="md:col-span-2">
                <x-input-label for="description" value="Açıklama" />
                <x-textarea-input id="description" name="description" rows="3">{{ old('description', $product->description) }}</x-textarea-input>
            </div>

            <div class="md:col-span-2">
                <label class="inline-flex items-center">
                    <input type="checkbox" name="active" value="1" @checked(old('active', $product->active)) class="rounded border-gray-300 text-indigo-600">
                    <span class="ms-2 text-sm text-gray-700">Aktif</span>
                </label>
            </div>

            <div class="md:col-span-2 flex gap-3 pt-2">
                <x-primary-button>Güncelle</x-primary-button>
                <a href="{{ route('products.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>
    </div>
</x-app-layout>
