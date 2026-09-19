@php
    $isEdit = isset($sale) && $sale !== null;
    $emptyRow = ['product_id' => '', 'quantity' => '', 'quantity_text' => '', 'quantity_error' => '', 'unit_price' => '', 'package_count' => '', 'package_text' => '', 'package_error' => ''];

    // Quantity fields are typed in Turkish format (0,345 / 20.000): the
    // visible *_text is that display form, the plain number is what the
    // hidden inputs submit.
    $num = fn ($v) => ($v === '' || $v === null) ? '' : (float) $v;
    $text = fn ($v) => ($v === '' || $v === null) ? '' : \App\Support\Quantity::format($v);

    if (is_array(old('items'))) {
        $initialItems = collect(old('items'))->map(fn ($i) => [
            'product_id' => (string) ($i['product_id'] ?? ''),
            'quantity' => $num($i['quantity'] ?? ''),
            'quantity_text' => $text($i['quantity'] ?? ''),
            'quantity_error' => '',
            'unit_price' => $i['unit_price'] ?? '',
            'package_count' => $num($i['package_qty_input'] ?? ''),
            'package_text' => $text($i['package_qty_input'] ?? ''),
            'package_error' => '',
        ])->values()->all();
    } elseif ($isEdit) {
        $initialItems = $sale->items->map(function ($item) {
            $p = $item->product;
            $count = $item->package_qty_input;

            if ($count === null && $p->hasPackaging()) {
                $count = round((float) $item->quantity / $p->baseUnitsPerPackage(), 3);
            }

            return [
                'product_id' => (string) $item->product_id,
                'quantity' => (float) $item->quantity,
                'quantity_text' => \App\Support\Quantity::format($item->quantity),
                'quantity_error' => '',
                'unit_price' => (float) $item->unit_price,
                'package_count' => $count === null ? '' : (float) $count,
                'package_text' => $count === null ? '' : \App\Support\Quantity::format($count),
                'package_error' => '',
            ];
        })->values()->all();
    } else {
        $initialItems = [$emptyRow];
    }

    // While editing, what this order already took from stock is given back
    // when it is saved, so it counts as available for the new content.
    $restoredStock = $isEdit
        ? $sale->items->groupBy('product_id')->map(fn ($rows) => (float) $rows->sum(fn ($r) => (float) $r->quantity - (float) $r->stock_shortfall_quantity))
        : collect();

    $initialPaid = old('paid_amount', $isEdit ? ($initialPaidAmount ?? 0) : 0);
    $initialDiscount = old('discount_total', $isEdit ? (float) $sale->discount_total : 0);
    $initialPaymentType = old('payment_type', $isEdit ? $sale->payment_type : 'pesin');
    $initialDueDate = old('due_date', $isEdit && $sale->due_date ? $sale->due_date->toDateString() : '');
    $initialAccount = old('account_id', $isEdit ? $sale->account_id : '');
    $initialNote = old('note', $isEdit ? $sale->note : '');
    $stockShortages = session('stock_shortages', []);
@endphp

<style>
    /* Product rows: stacked below 900px (phone/tablet), one compact line from 900px up.
       Plain CSS on purpose: it does not depend on the Tailwind build containing these classes. */
    .order-head { display: none; }
    .order-qty { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .5rem; }
    .order-qty-input { width: 100%; }
    .order-qty-error { flex-basis: 100%; margin: 0; }
    @media (min-width: 900px) {
        .order-head,
        div.order-row {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(0, 2.4fr) minmax(0, 1fr) minmax(0, 1.15fr) 2.5rem;
            column-gap: .5rem;
            align-items: center;
        }
        .order-head { padding-bottom: .375rem; margin-bottom: .5rem; border-bottom: 1px solid #e5e7eb; }
        div.order-row { padding-bottom: .5rem; }
        .order-row .order-label { display: none; }
        .order-qty-input { width: 5rem; flex: none; }
        .order-qty-info { white-space: nowrap; }
        .order-total, .order-del { text-align: right; }
    }
</style>

<div
    x-data="{
        items: {{ Js::from($initialItems) }},
        products: {{ Js::from($products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'unit' => $p->unit,
            'price' => (float) $p->sale_price,
            'currency' => $p->currency,
            'stock' => (float) $p->current_stock,
            'package_label' => $p->package_label,
            'package_qty' => $p->hasPackaging() ? $p->package_qty : null,
            'subunit_label' => $p->subunit_label,
            'subunit_to_base_qty' => $p->hasPackaging() ? $p->subunit_to_base_qty : null,
            'package_weight_kg' => $p->package_weight_kg !== null ? (float) $p->package_weight_kg : null,
        ])) }},
        restored: {{ Js::from($restoredStock) }},
        currencySymbols: {{ Js::from(\App\Support\Currency::SYMBOLS) }},
        discount: {{ (float) $initialDiscount }},
        paymentType: '{{ $initialPaymentType }}',
        paidAmount: {{ (float) $initialPaid }},
        dueDate: '{{ $initialDueDate }}',
        submitting: false,
        confirmShortage: false,
        addItem() { this.items.push({ product_id: '', quantity: '', quantity_text: '', quantity_error: '', unit_price: '', package_count: '', package_text: '', package_error: '' }) },
        removeItem(i) { if (this.items.length > 1) this.items.splice(i, 1) },
        productOf(item) { return this.products.find(p => p.id === Number(item.product_id)) },
        isPackaged(item) { const p = this.productOf(item); return !!(p && p.package_qty && p.subunit_to_base_qty) },
        multiplier(item) { const p = this.productOf(item); return this.isPackaged(item) ? p.package_qty * p.subunit_to_base_qty : 0 },
        rowEmpty(item) { return !item.product_id && !item.quantity && !item.package_count && !item.quantity_text && !item.package_text },
        // Turkish number entry: ',' is the decimal separator, '.' only groups
        // thousands in exact groups of three (20.000, 1.234.567,5). Anything
        // ambiguous (0.345, 1.5, 12.3456) is rejected instead of guessed.
        parseTr(text, maxDecimals) {
            const t = String(text === null || text === undefined ? '' : text).replace(/\s+/g, '');
            if (t === '') return { value: '', error: '' };
            let whole = t;
            let frac = '';
            if (t.indexOf(',') !== -1) {
                const parts = t.split(',');
                if (parts.length !== 2 || !/^\d*$/.test(parts[1])) return { value: '', error: 'Geçersiz sayı. Ondalık için tek virgül kullanın (örn. 0,345).' };
                whole = parts[0];
                frac = parts[1];
                if (frac.length > maxDecimals) return { value: '', error: 'En fazla ' + maxDecimals + ' ondalık basamak girilebilir.' };
            }
            if (whole === '') whole = '0';
            if (!/^\d+$/.test(whole)) {
                if (!/^[1-9]\d{0,2}(\.\d{3})+$/.test(whole)) return { value: '', error: 'Ondalık ayraç virgüldür (0,345). Nokta yalnızca binlik ayraç olarak, üçlü gruplarda kullanılabilir (20.000).' };
                whole = whole.replace(/\./g, '');
            }
            if (whole.length > 11) return { value: '', error: 'Sayı çok büyük.' };
            const value = Number(whole + (frac ? '.' + frac : ''));
            if (!(value > 0)) return { value: '', error: 'Sıfırdan büyük bir değer girin.' };
            return { value: value, error: '' };
        },
        onQuantityInput(item) {
            const r = this.parseTr(item.quantity_text, 3);
            item.quantity = r.value;
            item.quantity_error = r.error;
        },
        onPackageInput(item) {
            const r = this.parseTr(item.package_text, 3);
            item.package_count = r.value;
            item.package_error = r.error;
            this.onPackageCountChange(item);
        },
        onQuantityBlur(item) { if (item.quantity !== '' && !item.quantity_error) item.quantity_text = this.fmt(item.quantity) },
        onPackageBlur(item) { if (item.package_count !== '' && !item.package_error) item.package_text = this.fmt(item.package_count) },
        optionLabel(p, index) {
            return p.name + ' (' + p.currency + ', stok: ' + this.fmt(this.availableStock(p)) + ')' + (this.isDisabledProduct(p, index) ? ' — farklı para birimi' : '');
        },
        // The order is only ever saved through this method (the Kaydet
        // buttons) — the form has no submit button, so no keyboard can
        // trigger an implicit submit. Blank extra rows are dropped first.
        save(confirm = false) {
            if (this.submitting) return;
            this.confirmShortage = confirm;
            this.items = this.items.filter(i => !this.rowEmpty(i));
            if (this.items.length === 0) this.addItem();
            this.$nextTick(() => {
                const bad = this.items.findIndex(i => i.quantity_error || i.package_error);
                if (bad > -1) {
                    this.$root.querySelector('[data-row=\'' + bad + '\'][data-field=\'quantity\']')?.focus();
                    return;
                }
                const form = this.$root.querySelector('form');
                if (!form.reportValidity()) return;
                this.submitting = true;
                form.submit();
            });
        },
        onProductChange(item) {
            const p = this.productOf(item);
            item.unit_price = p ? p.price : '';
            item.package_count = '';
            item.package_text = '';
            item.package_error = '';
            item.quantity = '';
            item.quantity_text = '';
            item.quantity_error = '';
        },
        onPackageCountChange(item) {
            if (!this.isPackaged(item)) return;
            item.quantity = item.package_count === '' ? '' : Math.round(Number(item.package_count) * this.multiplier(item) * 1000) / 1000;
        },
        // Every line of the document must share one currency: it is whatever
        // the first product-carrying line uses.
        documentCurrency() {
            for (const item of this.items) {
                const p = this.productOf(item);
                if (p) return p.currency;
            }
            return null;
        },
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
        availableStock(p) { return p.stock + (Number(this.restored[p.id]) || 0) },
        // Informational only: how much of this line cannot come out of stock,
        // counting what earlier lines of the same product already use.
        shortfall(item, index) {
            const p = this.productOf(item);
            if (!p) return 0;
            let left = this.availableStock(p);
            for (let i = 0; i < index; i++) {
                if (Number(this.items[i].product_id) === p.id) left -= Number(this.items[i].quantity) || 0;
            }
            const short = (Number(item.quantity) || 0) - Math.max(0, left);
            return short > 0.0005 ? Math.round(short * 1000) / 1000 : 0;
        },
        symbol() { return this.currencySymbols[this.documentCurrency() || 'TL'] || ''; },
        fmt(n) { return (Number(n) || 0).toLocaleString('tr-TR', { maximumFractionDigits: 3 }) },
        money(n) { return (Number(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
        lineTotal(item) { return Math.round((Number(item.quantity) || 0) * (Number(item.unit_price) || 0) * 100) / 100 },
        lineWeight(item) { const p = this.productOf(item); return this.isPackaged(item) && p.package_weight_kg ? (Number(item.package_count) || 0) * p.package_weight_kg : 0 },
        totalWeight() { return this.items.reduce((sum, i) => sum + this.lineWeight(i), 0) },
        subtotal() { return this.items.reduce((sum, i) => sum + this.lineTotal(i), 0) },
        total() { return Math.max(0, this.subtotal() - (Number(this.discount) || 0)) },
        remaining() { return Math.max(0, this.total() - (Number(this.paidAmount) || 0)) },
        // Enter never submits the order: it moves to the next field, and at
        // the last price field of the last row it starts a new row. Saving
        // is only ever the explicit Kaydet button.
        onEnter(e) {
            const t = e.target;
            if (t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON' || t.type === 'submit' || t.type === 'button') return;
            e.preventDefault();
            if (t.dataset.field === 'unit_price' && Number(t.dataset.row) === this.items.length - 1 && !this.rowEmpty(this.items[this.items.length - 1])) {
                this.addItem();
                this.$nextTick(() => this.$root.querySelector('[data-row=\'' + (this.items.length - 1) + '\'][data-field=\'product\']')?.focus());
                return;
            }
            const fields = Array.from(this.$root.querySelectorAll('input:not([type=hidden]):not([readonly]):not([disabled]), select:not([disabled])')).filter(el => el.offsetParent !== null);
            const idx = fields.indexOf(t);
            if (idx > -1 && idx < fields.length - 1) fields[idx + 1].focus();
        },
    }"
    x-init="$watch('paymentType', v => { if (v === 'pesin') paidAmount = total(); if (v === 'vadeli') paidAmount = 0; });
            $watch('discount', () => { if (paymentType === 'pesin') paidAmount = total(); });
            $watch('items', () => { if (paymentType === 'pesin') paidAmount = total(); }, { deep: true });"
    @pageshow.window="submitting = false"
    class="max-w-4xl"
>
    <form method="POST" action="{{ $action }}" class="space-y-6"
          @keydown.enter="onEnter($event)">
        @csrf
        <input type="hidden" name="confirm_insufficient_stock" :value="confirmShortage ? 1 : 0">
        @if ($isEdit)
            @method('PUT')
        @endif

        @if (session('stock_warning'))
            <div class="rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-900">
                <p class="font-semibold">Yetersiz stok</p>
                <ul class="list-disc ms-5 mt-1">
                    @foreach ($stockShortages as $s)
                        <li>{{ $s['product'] }}: istenen {{ \App\Support\Quantity::format($s['requested']) }}, stokta {{ \App\Support\Quantity::format($s['available']) }} — {{ \App\Support\Quantity::format($s['shortfall']) }} eksik</li>
                    @endforeach
                </ul>
                <p class="mt-2">Siparişi yine de kaydederseniz sipariş ve cari tutarı istenen miktar üzerinden oluşur; stoktan yalnızca mevcut miktar düşülür (stok sıfırın altına inmez) ve eksik kısım sipariş satırında işaretlenir.</p>
                <button type="button" @click="save(true)" :disabled="submitting"
                        class="mt-3 inline-flex items-center px-4 py-2 bg-yellow-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-700 disabled:opacity-50">
                    Yine de Kaydet
                </button>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow p-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="account_id" value="Müşteri (opsiyonel — peşinde boş bırakılabilir)" />
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
                        <p class="text-sm text-gray-700 mt-2">{{ $sale->sale_date->format('d.m.Y') }} <span class="text-gray-400">(düzenlemede değişmez)</span></p>
                    @else
                        <x-input-label for="sale_date" value="Tarih" />
                        <x-text-input id="sale_date" type="date" name="sale_date" value="{{ old('sale_date', now()->toDateString()) }}" class="w-full" />
                    @endif
                </div>
            </div>
            <div class="mt-4">
                <x-input-label for="note" value="Not" />
                <x-text-input id="note" name="note" class="w-full" value="{{ $initialNote }}" />
            </div>
        </div>

        {{-- space-y-6 gives 24px; +20px extra between the customer card and this one only (inline so it wins over the space-y rule). --}}
        <div class="bg-white rounded-lg shadow p-5" style="margin-top: 2.75rem">
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <h3 class="font-semibold text-gray-800">Ürünler</h3>
                <span x-show="documentCurrency()" class="text-sm font-medium text-indigo-700 bg-indigo-50 rounded px-3 py-1">
                    Para Birimi: <span x-text="documentCurrency()"></span>
                </span>
                <button type="button" @click="addItem()" class="text-sm text-indigo-600 hover:underline">+ Satır Ekle</button>
            </div>

            {{-- Column titles: shown once, only in the compact desktop layout (>= 900px). --}}
            <div class="order-head text-xs text-gray-500">
                <span>Ürün</span>
                <span>Miktar</span>
                <span x-text="'Birim Fiyat' + (documentCurrency() ? ' (' + documentCurrency() + ')' : '')"></span>
                <span class="order-total">Satır Toplamı</span>
                <span></span>
            </div>

            <div class="space-y-3 order-rows">
                <template x-for="(item, index) in items" :key="index">
                    <div class="order-row grid grid-cols-1 gap-2 border-b pb-3 last:border-0">
                        <div>
                            <label class="order-label block text-xs text-gray-500 mb-1">Ürün</label>
                            <select :name="'items['+index+'][product_id]'" x-model="item.product_id" @change="onProductChange(item)"
                                    required :data-row="index" data-field="product" :title="productOf(item) ? productOf(item).name : ''"
                                    class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full text-sm">
                                <option value="">Ürün seçin</option>
                                @foreach ($products as $pi => $product)
                                    <option value="{{ $product->id }}" :disabled="isDisabledProduct(products[{{ $pi }}], index)"
                                            x-text="optionLabel(products[{{ $pi }}], index)">{{ $product->name }}</option>
                                @endforeach
                            </select>
                            <p x-show="shortfall(item, index) > 0" x-cloak class="text-xs text-red-600 mt-1"
                               x-text="'Stok yetersiz: ' + fmt(shortfall(item, index)) + ' adet stoktan düşülemez'"></p>
                        </div>
                        <div>
                            <template x-if="isPackaged(item)">
                                <div>
                                    <label class="order-label block text-xs text-gray-500 mb-1"
                                           x-text="'Kaç ' + (productOf(item).package_label || 'Paket') + '?'"></label>
                                    <div class="order-qty">
                                        <input type="text" inputmode="decimal" autocomplete="off" required
                                               x-model="item.package_text" :placeholder="productOf(item).package_label || 'Paket'"
                                               @input="onPackageInput(item)" @blur="onPackageBlur(item)" :data-row="index" data-field="quantity"
                                               :class="item.package_error ? 'border-red-500' : 'border-gray-300'"
                                               class="order-qty-input focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                        <input type="hidden" :name="'items['+index+'][package_qty_input]'" :value="item.package_count">
                                        <input type="hidden" :name="'items['+index+'][quantity]'" :value="item.quantity">
                                        <p class="order-qty-error text-xs text-red-600" x-show="item.package_error" x-text="item.package_error"></p>
                                        <span class="text-xs text-gray-500 order-qty-info" x-show="item.quantity > 0"
                                              x-text="(productOf(item).package_label || 'Paket') + ' = ' + fmt(item.quantity) + ' ' + (productOf(item).unit || 'Adet') + (lineWeight(item) > 0 ? ' · ' + fmt(lineWeight(item)) + ' kg' : '')"></span>
                                    </div>
                                </div>
                            </template>
                            <template x-if="!isPackaged(item)">
                                <div>
                                    <label class="order-label block text-xs text-gray-500 mb-1">Miktar</label>
                                    <div class="order-qty">
                                        <input type="text" inputmode="decimal" autocomplete="off" required placeholder="0,345"
                                               x-model="item.quantity_text" @input="onQuantityInput(item)" @blur="onQuantityBlur(item)"
                                               :data-row="index" data-field="quantity"
                                               :class="item.quantity_error ? 'border-red-500' : 'border-gray-300'"
                                               class="order-qty-input focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                        <input type="hidden" :name="'items['+index+'][quantity]'" :value="item.quantity">
                                        <span class="text-xs text-gray-500 order-qty-info" x-show="productOf(item)" x-text="(productOf(item) && productOf(item).unit) || 'Adet'"></span>
                                        <p class="order-qty-error text-xs text-red-600" x-show="item.quantity_error" x-text="item.quantity_error"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div>
                            <label class="order-label block text-xs text-gray-500 mb-1" x-text="'Birim Fiyat' + (documentCurrency() ? ' (' + documentCurrency() + ')' : '')"></label>
                            <input type="number" min="0" step="0.01" inputmode="decimal" required
                                   :name="'items['+index+'][unit_price]'" x-model.number="item.unit_price" :data-row="index" data-field="unit_price"
                                   class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full text-sm">
                        </div>
                        <div class="order-total text-sm text-gray-600">
                            <span class="order-label block text-xs text-gray-500 mb-1">Satır Toplamı</span>
                            <span class="whitespace-nowrap" x-text="money(lineTotal(item)) + ' ' + symbol()"></span>
                        </div>
                        <div class="order-del">
                            <button type="button" @click="removeItem(index)" class="text-red-600 hover:underline text-xs">Sil</button>
                        </div>
                    </div>
                </template>
            </div>
            <x-input-error :messages="$errors->get('items')" class="mt-2" />
            @foreach ($errors->get('items.*') as $messages)
                <x-input-error :messages="$messages" class="mt-1" />
            @endforeach
        </div>

        <div class="bg-white rounded-lg shadow p-5 grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div class="space-y-3">
                <div>
                    <x-input-label value="Ödeme Tipi" />
                    <div class="flex flex-wrap gap-4 mt-1 text-sm">
                        @foreach (\App\Models\Sale::PAYMENT_TYPES as $value => $label)
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
                    <span x-text="money(subtotal()) + ' ' + symbol()"></span>
                </div>
                <div class="flex justify-between items-center">
                    <label for="discount_total" class="text-gray-500" x-text="'İskonto (' + (documentCurrency() || 'TL') + ')'"></label>
                    <input type="number" min="0" step="0.01" id="discount_total" name="discount_total" x-model.number="discount"
                           class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-32 text-right">
                </div>
                <div class="flex justify-between text-base font-semibold text-gray-800 pt-1 border-t">
                    <span>Sipariş Toplamı</span>
                    <span x-text="money(total()) + ' ' + symbol()"></span>
                </div>
                <div class="flex justify-between text-red-600" x-show="remaining() > 0">
                    <span>Kalan Tutar</span>
                    <span x-text="money(remaining()) + ' ' + symbol()"></span>
                </div>
                <div class="flex justify-between text-gray-700" x-show="totalWeight() > 0">
                    <span>Toplam Ağırlık</span>
                    <span x-text="fmt(totalWeight()) + ' kg'"></span>
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            <x-primary-button type="button" @click="save(false)" x-bind:disabled="submitting">{{ $isEdit ? 'Siparişi Güncelle' : 'Siparişi Kaydet' }}</x-primary-button>
            <a href="{{ $isEdit ? route('sales.show', $sale) : route('sales.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
        </div>
    </form>
</div>
