@php
    $isEdit = isset($purchase) && $purchase !== null;

    if (is_array(old('items'))) {
        $initialItems = collect(old('items'))->map(fn ($i) => [
            'product_id' => (string) ($i['product_id'] ?? ''),
            'quantity' => (int) ($i['quantity'] ?? 1),
            'unit_price' => (float) ($i['unit_price'] ?? 0),
        ])->values()->all();
    } elseif ($isEdit) {
        $initialItems = $purchase->items->map(fn ($i) => [
            'product_id' => (string) $i->product_id,
            'quantity' => (int) $i->quantity,
            'unit_price' => (float) $i->unit_price,
        ])->values()->all();
    } else {
        $initialItems = [['product_id' => '', 'quantity' => 1, 'unit_price' => 0]];
    }

    $initialDiscount = old('discount_total', $isEdit ? (float) $purchase->discount_total : 0);
    $initialPaymentType = old('payment_type', $isEdit ? $purchase->payment_type : 'pesin');
    $initialPaid = old('paid_amount', $isEdit ? ($initialPaidAmount ?? 0) : 0);
    $initialDueDate = old('due_date', $isEdit && $purchase->due_date ? $purchase->due_date->toDateString() : '');
    $initialAccount = old('account_id', $isEdit ? $purchase->account_id : '');
    $initialNote = old('note', $isEdit ? $purchase->note : '');
@endphp

<div
    x-data="{
        items: {{ Js::from($initialItems) }},
        products: {{ Js::from($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->purchase_price, 'currency' => $p->currency, 'stock' => (float) $p->current_stock])) }},
        mins: {{ Js::from($minimumQuantities ?? []) }},
        currencySymbols: {{ Js::from(\App\Support\Currency::SYMBOLS) }},
        discount: {{ (float) $initialDiscount }},
        paymentType: '{{ $initialPaymentType }}',
        paidAmount: {{ (float) $initialPaid }},
        dueDate: '{{ $initialDueDate }}',
        addItem() { this.items.push({ product_id: '', quantity: 1, unit_price: 0 }) },
        removeItem(i) { if (this.items.length > 1) this.items.splice(i, 1) },
        productOf(item) { return this.products.find(p => p.id === Number(item.product_id)) },
        // Smallest quantity this purchase may keep for the product (part of its stock was already used).
        minQty(item) { return Number(this.mins[item.product_id]) || 0 },
        onProductChange(item) {
            const p = this.productOf(item);
            item.unit_price = p ? p.price : 0;
        },
        // The document currency is whichever currency the first
        // product-carrying line uses — every other line must match it.
        documentCurrency() {
            for (const item of this.items) {
                const p = this.productOf(item);
                if (p) return p.currency;
            }
            return null;
        },
        // Currency of every OTHER line except `index` — used to decide
        // which products index's own dropdown may still offer, so a
        // single remaining line can always be changed to any currency.
        documentCurrencyExcluding(index) {
            for (let i = 0; i < this.items.length; i++) {
                if (i === index) continue;
                const p = this.productOf(this.items[i]);
                if (p) return p.currency;
            }
            return null;
        },
        isDisabledProduct(p, index) {
            const dc = this.documentCurrencyExcluding(index);
            return dc !== null && p.currency !== dc;
        },
        symbol() { return this.currencySymbols[this.documentCurrency() || 'TL'] || ''; },
        lineTotal(item) { return (Number(item.quantity) || 0) * (Number(item.unit_price) || 0) },
        subtotal() { return this.items.reduce((sum, i) => sum + this.lineTotal(i), 0) },
        total() { return Math.max(0, this.subtotal() - (Number(this.discount) || 0)) },
        remaining() { return Math.max(0, this.total() - (Number(this.paidAmount) || 0)) },
    }"
    x-init="$watch('paymentType', v => { if (v === 'pesin') paidAmount = total(); if (v === 'vadeli') paidAmount = 0; });
            $watch('discount', () => { if (paymentType === 'pesin') paidAmount = total(); });
            $watch('items', () => { if (paymentType === 'pesin') paidAmount = total(); }, { deep: true });"
    class="max-w-4xl"
>
    <form method="POST" action="{{ $action }}" class="space-y-6">
            @csrf
            @if ($isEdit)
                @method('PUT')
                <input type="hidden" name="_version" value="{{ $version }}">
            @endif
<div class="bg-white rounded-lg shadow p-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="account_id" value="Tedarikçi (opsiyonel — peşinde boş bırakılabilir)" />
                    <x-select-input id="account_id" name="account_id" class="w-full">
                        <option value="">-</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected((string) $initialAccount === (string) $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('account_id')" class="mt-1" />
                </div>
                <div>
                    @if ($isEdit)
                        <x-input-label value="Tarih" />
                        <p class="text-sm text-gray-700 mt-2">{{ $purchase->purchase_date->format('d.m.Y') }} <span class="text-gray-400">(düzenlemede değişmez)</span></p>
                    @else
                        <x-input-label for="purchase_date" value="Tarih" />
                        <x-text-input id="purchase_date" type="date" name="purchase_date" value="{{ now()->toDateString() }}" class="w-full" />
                    @endif
                </div>
            </div>
            <div class="mt-4">
                <x-input-label for="note" value="Not" />
                <x-text-input id="note" name="note" class="w-full" value="{{ $initialNote }}"  />
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-5">
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <h3 class="font-semibold text-gray-800">Ürünler</h3>
                <span x-show="documentCurrency()" class="text-sm font-medium text-indigo-700 bg-indigo-50 rounded px-3 py-1">
                    Para Birimi: <span x-text="documentCurrency()"></span>
                </span>
                <button type="button" @click="addItem()" class="text-sm text-indigo-600 hover:underline">+ Satır Ekle</button>
            </div>

            <div class="space-y-3">
                <template x-for="(item, index) in items" :key="index">
                    <div class="grid grid-cols-1 sm:grid-cols-[2fr_1fr_1fr_1fr_auto] gap-2 items-end border-b pb-3 last:border-0">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1 sm:hidden">Ürün</label>
                            <select :name="'items['+index+'][product_id]'" x-model="item.product_id" @change="onProductChange(item)"
                                    class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full text-sm">
                                <option value="">Ürün seçin</option>
                                <template x-for="p in products" :key="p.id">
                                    <option :value="p.id" :disabled="isDisabledProduct(p, index)"
                                            x-text="p.name + ' (' + p.currency + ', stok: ' + p.stock + ')' + (isDisabledProduct(p, index) ? ' — farklı para birimi' : '')"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1 sm:hidden">Miktar</label>
                            <input type="number" min="1" :name="'items['+index+'][quantity]'" x-model.number="item.quantity"
                               class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full text-sm">
                        <p x-show="minQty(item) > 0" x-cloak class="text-xs text-amber-700 mt-1"
                           x-text="'Stoğu kısmen kullanılmış: en az ' + minQty(item) + ' adet olmalı'"></p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1 sm:hidden" x-text="'Birim Fiyat' + (documentCurrency() ? ' (' + documentCurrency() + ')' : '')"></label>
                            <input type="number" min="0" step="0.01" :name="'items['+index+'][unit_price]'" x-model.number="item.unit_price"
                                   class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full text-sm">
                        </div>
                        <div class="text-sm text-gray-600">
                            <span class="block text-xs text-gray-500 sm:hidden">Satır Toplamı</span>
                            <span x-text="lineTotal(item).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ' + symbol()"></span>
                        </div>
                        <div>
                            <button type="button" @click="removeItem(index)" class="text-red-600 hover:underline text-xs">Sil</button>
                        </div>
                    </div>
                </template>
            </div>
            <x-input-error :messages="$errors->get('items')" class="mt-2" />
        </div>

        <div class="bg-white rounded-lg shadow p-5 grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div class="space-y-3">
                <div>
                    <x-input-label value="Ödeme Tipi" />
                    <div class="flex flex-wrap gap-4 mt-1 text-sm">
                        @foreach (\App\Models\Purchase::PAYMENT_TYPES as $value => $label)
                            <label class="inline-flex items-center gap-1.5">
                                <input type="radio" name="payment_type" value="{{ $value }}" x-model="paymentType" @checked($initialPaymentType === $value)>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('payment_type')" class="mt-1" />
                </div>
                <template x-if="paymentType !== 'pesin'">
                    <div>
                        <x-input-label for="due_date" value="Vade Tarihi" />
                        <input type="date" id="due_date" name="due_date" x-model="dueDate" required
                               class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full">
                        <x-input-error :messages="$errors->get('due_date')" class="mt-1" />
                    </div>
                </template>
                <template x-if="paymentType === 'kismi'">
                    <div>
                        <x-input-label for="paid_amount">
                            <span x-text="'Ödenen Tutar (' + (documentCurrency() || 'TL') + ')'"></span>
                        </x-input-label>
                        <input type="number" min="0.01" step="0.01" id="paid_amount" name="paid_amount" x-model.number="paidAmount"
                               class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full">
                        <x-input-error :messages="$errors->get('paid_amount')" class="mt-1" />
                    </div>
                </template>
                <template x-if="paymentType !== 'kismi'">
                    <input type="hidden" name="paid_amount" :value="paidAmount">
                </template>
            </div>

            <div class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Ara Toplam</span>
                    <span x-text="subtotal().toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ' + symbol()"></span>
                </div>
                <div class="flex justify-between items-center">
                    <label for="discount_total" class="text-gray-500" x-text="'İskonto (' + (documentCurrency() || 'TL') + ')'"></label>
                    <input type="number" min="0" step="0.01" id="discount_total" name="discount_total" x-model.number="discount"
                           class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-32 text-right">
                </div>
                <div class="flex justify-between text-base font-semibold text-gray-800 pt-1 border-t">
                    <span>Genel Toplam</span>
                    <span x-text="total().toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ' + symbol()"></span>
                </div>
                <div class="flex justify-between text-red-600" x-show="remaining() > 0">
                    <span>Kalan Tutar</span>
                    <span x-text="remaining().toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ' + symbol()"></span>
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            <x-primary-button type="submit">{{ $isEdit ? 'Alışı Güncelle' : 'Alışı Kaydet' }}</x-primary-button>
        <a href="{{ $isEdit ? route('purchases.show', $purchase) : route('purchases.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
        </div>
    </form>
</div>
