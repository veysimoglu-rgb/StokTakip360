<x-app-layout>
    <x-slot name="title">Kasa</x-slot>

    <div class="bg-white rounded-lg shadow p-4 sm:p-5 mb-6">
        <h2 class="font-semibold text-gray-800 mb-4">Kasa Özeti</h2>

        {{-- One compact card per currency at every breakpoint: 3-up from
             640px, a safe single column below it (no table, ever). --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach ($cashSummaries as $summary)
                <x-currency-summary-card :currency="$summary['currency']">
                    <x-metric-stat label="Bakiye" :value="\App\Support\Currency::format($summary['balance'], $summary['currency'])" :color="$summary['balance'] < 0 ? 'text-red-600' : 'text-blue-600'" />
                    <x-metric-stat label="Bugünkü Giriş" :value="\App\Support\Currency::format($summary['today_in'], $summary['currency'])" color="text-green-600" />
                    <x-metric-stat label="Bugünkü Çıkış" :value="\App\Support\Currency::format($summary['today_out'], $summary['currency'])" color="text-red-600" />
                </x-currency-summary-card>
            @endforeach
        </div>
    </div>

    @role('Admin')
    <div x-data="{ open: false }" class="mb-6">
        <button type="button" @click="open = !open" class="text-sm font-medium text-indigo-600 hover:underline mb-2">
            <span x-show="!open">+ Yeni Kasa Hareketi</span>
            <span x-show="open" x-cloak>− Formu Kapat</span>
        </button>
        <form x-show="open" x-cloak method="POST" action="{{ route('cash.store') }}" class="bg-white rounded-lg shadow p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
            @csrf
            <div>
                <x-input-label for="type" value="Hareket Tipi" />
                <x-select-input id="type" name="type" class="w-full">
                    <option value="manual_in">Manuel Giriş</option>
                    <option value="manual_out">Manuel Çıkış</option>
                    <option value="other_income">Diğer Gelir</option>
                    <option value="expense">Gider</option>
                </x-select-input>
            </div>
            <div>
                <x-input-label for="amount" value="Tutar" />
                <x-text-input id="amount" type="number" step="0.01" min="0.01" name="amount" class="w-full" required />
                <x-input-error :messages="$errors->get('amount')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="currency" value="Para Birimi" />
                <x-select-input id="currency" name="currency" class="w-full">
                    @foreach (\App\Support\Currency::LIST as $currency)
                        <option value="{{ $currency }}" @selected($currency === 'TL')>{{ $currency }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('currency')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="description" value="Açıklama" />
                <x-text-input id="description" name="description" class="w-full" />
            </div>
            <div>
                <x-input-label for="account_id" value="Cari (opsiyonel)" />
                <x-select-input id="account_id" name="account_id" class="w-full">
                    <option value="">-</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </x-select-input>
            </div>
            <div>
                <x-input-label for="transaction_date" value="Tarih" />
                <x-text-input id="transaction_date" type="date" name="transaction_date" value="{{ now()->toDateString() }}" class="w-full" />
            </div>
            <div class="lg:col-span-6">
                <x-primary-button type="submit">Kaydet</x-primary-button>
            </div>
        </form>
    </div>
    @endrole

    <form method="GET" class="flex flex-wrap gap-2 mb-4">
        <x-select-input name="type" class="w-48">
            <option value="">Tüm Hareketler</option>
            @foreach (\App\Models\CashTransaction::TYPES as $value => $label)
                <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
            @endforeach
        </x-select-input>
        <x-text-input type="date" name="date_from" value="{{ request('date_from') }}" />
        <x-text-input type="date" name="date_to" value="{{ request('date_to') }}" />
        <x-secondary-button type="submit">Filtrele</x-secondary-button>
    </form>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Tarih</th>
                    <th class="px-4 py-3">Tip</th>
                    <th class="hidden sm:table-cell px-4 py-3">Açıklama</th>
                    <th class="hidden sm:table-cell px-4 py-3">Cari</th>
                    <th class="hidden sm:table-cell px-4 py-3">Para Birimi</th>
                    <th class="px-4 py-3 text-right">Tutar</th>
                    <th class="hidden sm:table-cell px-4 py-3">Kullanıcı</th>
                    @role('Admin')<th class="px-4 py-3 text-right">İşlem</th>@endrole
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($transactions as $transaction)
                    <tr @class(['opacity-50' => $transaction->isCancelled()])>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $transaction->transaction_date->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs',
                                'bg-green-100 text-green-800' => $transaction->direction === 'in',
                                'bg-red-100 text-red-800' => $transaction->direction === 'out',
                            ])>{{ $transaction->typeLabel() }}</span>
                            @if ($transaction->isCancelled())
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 ms-1">İptal</span>
                            @endif
                            @if ($transaction->reversal_of_id)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800 ms-1">Ters Kayıt</span>
                            @endif
                            <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $transaction->description ?? '-' }}{{ $transaction->account ? ' · '.$transaction->account->name : '' }}</p>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3 text-gray-500">{{ $transaction->description ?? '-' }}</td>
                        <td class="hidden sm:table-cell px-4 py-3">
                            @if ($transaction->account)
                                <a href="{{ route('accounts.show', $transaction->account) }}" class="text-indigo-600 hover:underline">{{ $transaction->account->name }}</a>
                            @else
                                -
                            @endif
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $transaction->currency }}</td>
                        <td @class([
                            'px-4 py-3 text-right font-medium',
                            'text-green-600' => $transaction->direction === 'in',
                            'text-red-600' => $transaction->direction === 'out',
                        ])>{{ $transaction->direction === 'in' ? '+' : '-' }}{{ \App\Support\Currency::format($transaction->amount, $transaction->currency) }}</td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $transaction->user?->name ?? '-' }}</td>
                        @role('Admin')
                        <td class="px-4 py-3 text-right">
                            @if (! $transaction->isCancelled() && ! $transaction->reversal_of_id)
                                <form action="{{ route('cash-transactions.cancel', $transaction) }}" method="POST" class="inline" onsubmit="return confirm('Bu kasa hareketi iptal edilsin mi?')">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:underline text-xs">İptal Et</button>
                                </form>
                            @endif
                        </td>
                        @endrole
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">Henüz kasa hareketi yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $transactions->links() }}</div>
</x-app-layout>
