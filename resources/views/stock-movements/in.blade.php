<x-app-layout>
    <x-slot name="title">Stok Girişi</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-xl">
        <form method="POST" action="{{ route('stock-movements.in.store') }}" class="space-y-4">
            @csrf

            <div>
                <x-input-label for="product_id" value="Ürün" />
                <x-select-input id="product_id" name="product_id" class="w-full" required autofocus onchange="const p=this.options[this.selectedIndex]; if(p.dataset.price){document.getElementById('unit_price').value=p.dataset.price;}">
                    <option value="">Seçiniz</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-price="{{ $product->purchase_price }}" @selected(old('product_id') == $product->id)>
                            {{ $product->code }} - {{ $product->name }} (Stok: {{ \App\Support\Quantity::format($product->current_stock) }} {{ $product->unit }})
                        </option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('product_id')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="quantity" value="Miktar" />
                <x-text-input id="quantity" type="number" min="1" name="quantity" value="{{ old('quantity') }}" class="w-full" required />
                <x-input-error :messages="$errors->get('quantity')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="unit_price" value="Birim Fiyat (opsiyonel)" />
                <x-text-input id="unit_price" type="number" step="0.01" min="0" name="unit_price" value="{{ old('unit_price') }}" class="w-full" />
            </div>

            <div>
                <x-input-label for="account_id" value="Tedarikçi (opsiyonel)" />
                <x-select-input id="account_id" name="account_id" class="w-full">
                    <option value="">Seçiniz</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>{{ $account->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('account_id')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="movement_date" value="Tarih" />
                <x-text-input id="movement_date" type="datetime-local" name="movement_date" value="{{ old('movement_date', now()->format('Y-m-d\TH:i')) }}" class="w-full" />
            </div>

            <div>
                <x-input-label for="note" value="Not (opsiyonel)" />
                <x-text-input id="note" name="note" value="{{ old('note') }}" class="w-full" />
            </div>

            <div class="flex gap-3 pt-2">
                <x-primary-button>Girişi Kaydet</x-primary-button>
                <a href="{{ route('stock-movements.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>
    </div>
</x-app-layout>
