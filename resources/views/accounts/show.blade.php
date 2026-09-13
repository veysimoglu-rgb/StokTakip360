<x-app-layout>
    <x-slot name="title">{{ $account->name }}</x-slot>

    @php
        // TL/USD/EUR are never blended into one number — each currency this
        // account has any activity in gets its own debt/credit/balance row.
        // Currencies with no activity yet are left out entirely, except TL
        // (always shown, even at zero, so the card group is never empty).
        $activeCurrencies = $account->balancesByCurrency()->keys();
        $currencies = collect(\App\Support\Currency::LIST)
            ->filter(fn ($c) => $c === 'TL' || $activeCurrencies->contains($c))
            ->values();

        $currencyRows = $currencies->map(function ($currency) use ($account) {
            $totalDebt = $account->totalDebt($currency);
            $totalCredit = $account->totalCredit($currency);
            $balance = $totalDebt - $totalCredit;

            $balanceLabel = match (true) {
                $balance == 0 => 'Bakiye Yok',
                $account->type === 'supplier' => $balance > 0 ? 'Borcumuz' : 'Alacağımız',
                default => $balance > 0 ? 'Borçlu' : 'Alacaklı',
            };

            return [
                'currency' => $currency,
                'total_debt' => $totalDebt,
                'total_credit' => $totalCredit,
                'balance' => $balance,
                'balance_label' => $balanceLabel,
            ];
        });

        // Currency dropdowns on the Tahsilat/Ödeme forms show currencies
        // this account already has activity in first, then the rest.
        $collectPayCurrencyOrder = $activeCurrencies
            ->concat(\App\Support\Currency::LIST)
            ->unique()
            ->values();
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-lg font-semibold text-gray-800">{{ $account->name }}</h2>
                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-800">{{ $account->typeLabel() }}</span>
                @if ($account->active)
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">Aktif</span>
                @else
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Pasif</span>
                @endif
            </div>
            <p class="text-sm text-gray-500 font-mono mt-1">{{ $account->code }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('accounts.index') }}" class="text-sm text-gray-600 self-center hover:underline">← Cari Listesi</a>
            @role('Admin')
            <a href="{{ route('accounts.edit', $account) }}"><x-secondary-button>Düzenle</x-secondary-button></a>
            @endrole
        </div>
    </div>

    <div class="space-y-3 mb-6">
        @foreach ($currencyRows as $row)
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-xs sm:text-sm text-gray-500">Toplam Borç ({{ $row['currency'] }})</p>
                    <p class="text-2xl font-semibold text-red-600 mt-1">{{ \App\Support\Currency::format($row['total_debt'], $row['currency']) }}</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-xs sm:text-sm text-gray-500">Toplam Alacak ({{ $row['currency'] }})</p>
                    <p class="text-2xl font-semibold text-green-600 mt-1">{{ \App\Support\Currency::format($row['total_credit'], $row['currency']) }}</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-xs sm:text-sm text-gray-500">Bakiye ({{ $row['currency'] }} — {{ $row['balance_label'] }})</p>
                    <p @class([
                        'text-2xl font-semibold mt-1',
                        'text-red-600' => $row['balance'] > 0,
                        'text-green-600' => $row['balance'] < 0,
                        'text-gray-800' => $row['balance'] == 0,
                    ])>{{ \App\Support\Currency::format(abs($row['balance']), $row['currency']) }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white rounded-lg shadow p-5 lg:col-span-1 space-y-2 text-sm h-fit">
            <h3 class="font-semibold text-gray-800 mb-2">Cari Bilgileri</h3>
            <p><span class="text-gray-500">Yetkili:</span> {{ $account->contact_person ?: '-' }}</p>
            <p><span class="text-gray-500">Telefon:</span> {{ $account->phone ?: '-' }}</p>
            <p><span class="text-gray-500">E-posta:</span> {{ $account->email ?: '-' }}</p>
            <p><span class="text-gray-500">Vergi Dairesi:</span> {{ $account->tax_office ?: '-' }}</p>
            <p><span class="text-gray-500">Vergi No:</span> {{ $account->tax_no ?: '-' }}</p>
            <p><span class="text-gray-500">Adres:</span> {{ $account->address ?: '-' }}</p>
            @if ($account->note)
                <p class="pt-2 border-t text-gray-600">{{ $account->note }}</p>
            @endif

            @role('Admin')
            @if ($account->isCustomer())
                <div x-data="{ open: false }" class="pt-4 mt-4 border-t">
                    <button type="button" @click="open = !open" class="text-sm font-medium text-indigo-600 hover:underline">
                        <span x-show="!open">+ Tahsilat Al</span>
                        <span x-show="open" x-cloak>− Formu Kapat</span>
                    </button>
                    <form x-show="open" x-cloak method="POST" action="{{ route('accounts.collect', $account) }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <x-input-label for="collect_amount" value="Tahsilat Tutarı" />
                            <x-text-input id="collect_amount" type="number" step="0.01" min="0.01" name="amount" class="w-full" required />
                        </div>
                        <div>
                            <x-input-label for="collect_currency" value="Para Birimi" />
                            <x-select-input id="collect_currency" name="currency" class="w-full">
                                @foreach ($collectPayCurrencyOrder as $currency)
                                    <option value="{{ $currency }}" @selected($currency === 'TL')>{{ $currency }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div>
                            <x-input-label for="collect_description" value="Açıklama" />
                            <x-text-input id="collect_description" name="description" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="collect_date" value="Tarih" />
                            <x-text-input id="collect_date" type="date" name="transaction_date" value="{{ now()->toDateString() }}" class="w-full" />
                        </div>
                        <x-primary-button type="submit" class="w-full justify-center">Tahsilatı Kaydet</x-primary-button>
                    </form>
                </div>
            @endif

            @if ($account->isSupplier())
                <div x-data="{ open: false }" class="pt-4 mt-4 border-t">
                    <button type="button" @click="open = !open" class="text-sm font-medium text-indigo-600 hover:underline">
                        <span x-show="!open">+ Ödeme Yap</span>
                        <span x-show="open" x-cloak>− Formu Kapat</span>
                    </button>
                    <form x-show="open" x-cloak method="POST" action="{{ route('accounts.pay', $account) }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <x-input-label for="pay_amount" value="Ödeme Tutarı" />
                            <x-text-input id="pay_amount" type="number" step="0.01" min="0.01" name="amount" class="w-full" required />
                        </div>
                        <div>
                            <x-input-label for="pay_currency" value="Para Birimi" />
                            <x-select-input id="pay_currency" name="currency" class="w-full">
                                @foreach ($collectPayCurrencyOrder as $currency)
                                    <option value="{{ $currency }}" @selected($currency === 'TL')>{{ $currency }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div>
                            <x-input-label for="pay_description" value="Açıklama" />
                            <x-text-input id="pay_description" name="description" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="pay_date" value="Tarih" />
                            <x-text-input id="pay_date" type="date" name="transaction_date" value="{{ now()->toDateString() }}" class="w-full" />
                        </div>
                        <x-primary-button type="submit" class="w-full justify-center">Ödemeyi Kaydet</x-primary-button>
                    </form>
                </div>
            @endif

            <form method="POST" action="{{ route('accounts.transactions.store', $account) }}" class="pt-4 mt-4 border-t space-y-3">
                @csrf
                <h3 class="font-semibold text-gray-800">Manuel Hareket Ekle</h3>
                <div>
                    <x-input-label for="type" value="Hareket Tipi" />
                    <x-select-input id="type" name="type" class="w-full">
                        <option value="manual_debt">Manuel Borç</option>
                        <option value="manual_credit">Manuel Alacak</option>
                    </x-select-input>
                </div>
                <div>
                    <x-input-label for="amount" value="Tutar (TL)" />
                    <x-text-input id="amount" type="number" step="0.01" min="0.01" name="amount" class="w-full" required />
                    <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="description" value="Açıklama" />
                    <x-text-input id="description" name="description" class="w-full" />
                </div>
                <div>
                    <x-input-label for="transaction_date" value="Tarih" />
                    <x-text-input id="transaction_date" type="date" name="transaction_date" value="{{ now()->toDateString() }}" class="w-full" />
                </div>
                <x-primary-button type="submit" class="w-full justify-center">Ekle</x-primary-button>
            </form>
            @endrole
        </div>

        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-left">
                        <tr>
                            <th class="px-4 py-3">Tarih</th>
                            <th class="px-4 py-3">Tip</th>
                            <th class="hidden sm:table-cell px-4 py-3">Açıklama</th>
                            <th class="px-4 py-3 text-right">Borç</th>
                            <th class="px-4 py-3 text-right">Alacak</th>
                            <th class="hidden sm:table-cell px-4 py-3">Para Birimi</th>
                            @role('Admin')<th class="px-4 py-3 text-right">İşlem</th>@endrole
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($transactions as $transaction)
                            <tr @class(['opacity-50' => $transaction->isCancelled()])>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $transaction->transaction_date->format('d.m.Y') }}</td>
                                <td class="px-4 py-3">
                                    {{ $transaction->typeLabel() }}
                                    @if ($transaction->isCancelled())
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 ms-1">İptal</span>
                                    @endif
                                    @if ($transaction->reversal_of_id)
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800 ms-1">Ters Kayıt</span>
                                    @endif
                                    <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $transaction->description ?? '-' }}</p>
                                </td>
                                <td class="hidden sm:table-cell px-4 py-3 text-gray-500">{{ $transaction->description ?? '-' }}</td>
                                <td class="px-4 py-3 text-right text-red-600">{{ $transaction->direction === 'debit' ? \App\Support\Currency::format($transaction->amount, $transaction->currency) : '-' }}</td>
                                <td class="px-4 py-3 text-right text-green-600">{{ $transaction->direction === 'credit' ? \App\Support\Currency::format($transaction->amount, $transaction->currency) : '-' }}</td>
                                <td class="hidden sm:table-cell px-4 py-3">{{ $transaction->currency }}</td>
                                @role('Admin')
                                <td class="px-4 py-3 text-right">
                                    @if (! $transaction->isCancelled() && ! $transaction->reversal_of_id)
                                        <form action="{{ route('account-transactions.cancel', $transaction) }}" method="POST" class="inline" onsubmit="return confirm('Bu hareket iptal edilsin mi?')">
                                            @csrf
                                            <button type="submit" class="text-red-600 hover:underline text-xs">İptal Et</button>
                                        </form>
                                    @endif
                                </td>
                                @endrole
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Henüz hareket yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $transactions->links() }}</div>
        </div>
    </div>
</x-app-layout>
