<x-app-layout>
    <x-slot name="title">Yeni Alış</x-slot>

    <div
        x-data="{
            items: [{ product_id: '', quantity: 1, unit_price: 0 }],
            products: {{ Js::from($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->purchase_price, 'currency' => $p->currency, 'stock' => $p->current_stock])) }},
            currencySymbols: {{ Js::from(\App\Support\Currency::SYMBOLS) }},
            discount: {{ (float) old('discount_total', 0) }},
            paymentType: '{{ old('payment_type', 'pesin') }}',
            paidAmount: {{ (float) old('paid_amount', 0) }},
            dueDate: '{{ old('due_date') }}',
            addItem() { this.items.push({ product_id: '', quantity: 1, unit_price: 0 }) },
            removeItem(i) { if (this.items.length > 1) this.items.splice(i, 1) },
            productOf(item) { return this.products.find(p => p.id === Number(item.product_id)) },
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
        <form method="POST" action="{{ route('purchases.store') }}" class="space-y-6">
            @csrf

            <div class="bg-white rounded-lg shadow p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="account_id" value="Tedarikçi (opsiyonel — peşinde boş bırakılabilir)" />
                        <x-select-input id="account_id" name="account_id" class="w-full">
                            <option value="">-</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>{{ $account->name }}</option>
                            @endforeach
                        </x-select-input>
                        <x-input-error :messages="$errors->get('account_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="purchase_date" value="Tarih" />
                        <x-text-input id="purchase_date" type="date" name="purchase_date" value="{{ now()->toDateString() }}" class="w-full" />
                    </div>
                </div>
                <div class="mt-4">
                    <x-input-label for="note" value="Not" />
                    <x-text-input id="note" name="note" class="w-full" value="{{ old('note') }}" />
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
                                    <input type="radio" name="payment_type" value="{{ $value }}" x-model="paymentType" @checked(old('payment_type', 'pesin') === $value)>
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
                <x-primary-button type="submit">Alışı Kaydet</x-primary-button>
                <a href="{{ route('purchases.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>
    </div>
</x-app-layout>
