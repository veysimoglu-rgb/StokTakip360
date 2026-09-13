<x-app-layout>
    <x-slot name="title">Kasa Raporu</x-slot>

    @php
        $presetParams = request()->except(['date_from', 'date_to', 'period', 'page']);
    @endphp
    <div class="flex flex-wrap gap-2 mb-4">
        @foreach (['today' => 'Bugün', 'this_week' => 'Bu Hafta', 'this_month' => 'Bu Ay', 'last_month' => 'Geçen Ay'] as $key => $label)
            <a href="{{ request()->url() }}?{{ http_build_query(array_merge($presetParams, ['period' => $key])) }}"
               @class([
                   'px-3 py-1.5 rounded-md text-sm border',
                   'bg-indigo-600 text-white border-indigo-600' => $period === $key,
                   'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' => $period !== $key,
               ])>{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap gap-2 mb-6 items-end">
        <div>
            <x-input-label value="Başlangıç" class="text-xs" />
            <x-text-input type="date" name="date_from" value="{{ $dateFrom }}" />
        </div>
        <div>
            <x-input-label value="Bitiş" class="text-xs" />
            <x-text-input type="date" name="date_to" value="{{ $dateTo }}" />
        </div>
        <x-secondary-button type="submit">Filtrele</x-secondary-button>

        <div class="ms-auto flex gap-2">
            <a href="{{ route('reports.cash.export', request()->query()) }}">
                <x-secondary-button type="button">Excel / CSV'ye Aktar</x-secondary-button>
            </a>
            <a href="{{ route('reports.cash.print', request()->query()) }}" target="_blank">
                <x-secondary-button type="button">Yazdır / PDF Kaydet</x-secondary-button>
            </a>
        </div>
    </form>

    @forelse ($totalsByCurrency as $currency => $row)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-3">
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Toplam Giriş ({{ $currency }})</p>
                <p class="text-2xl font-semibold text-green-600 mt-1">{{ \App\Support\Currency::format($row['in'], $currency) }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Toplam Çıkış ({{ $currency }})</p>
                <p class="text-2xl font-semibold text-red-600 mt-1">{{ \App\Support\Currency::format($row['out'], $currency) }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Net Değişim ({{ $currency }})</p>
                <p @class([
                    'text-2xl font-semibold mt-1',
                    'text-red-600' => $row['net'] < 0,
                    'text-blue-600' => $row['net'] >= 0,
                ])>{{ \App\Support\Currency::format($row['net'], $currency) }}</p>
            </div>
        </div>
    @empty
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-3">
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Toplam Giriş (TL)</p>
                <p class="text-2xl font-semibold text-green-600 mt-1">{{ \App\Support\Currency::format(0, 'TL') }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Toplam Çıkış (TL)</p>
                <p class="text-2xl font-semibold text-red-600 mt-1">{{ \App\Support\Currency::format(0, 'TL') }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Net Değişim (TL)</p>
                <p class="text-2xl font-semibold text-blue-600 mt-1">{{ \App\Support\Currency::format(0, 'TL') }}</p>
            </div>
        </div>
    @endforelse

    <div class="bg-white rounded-lg shadow overflow-x-auto mt-3">
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
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Seçilen dönemde kasa hareketi bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $transactions->links() }}</div>
</x-app-layout>
