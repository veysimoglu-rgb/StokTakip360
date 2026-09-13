<x-app-layout>
    <x-slot name="title">Cari Ekstre</x-slot>

    <form method="GET" class="flex flex-wrap gap-2 mb-6 items-end">
        <div>
            <x-input-label value="Cari" class="text-xs" />
            <x-select-input name="account_id" class="w-56">
                <option value="">Cari seçin</option>
                @foreach ($accounts as $acc)
                    <option value="{{ $acc->id }}" @selected($account && $account->id === $acc->id)>{{ $acc->name }}</option>
                @endforeach
            </x-select-input>
        </div>
        <div>
            <x-input-label value="Başlangıç" class="text-xs" />
            <x-text-input type="date" name="date_from" value="{{ $dateFrom }}" />
        </div>
        <div>
            <x-input-label value="Bitiş" class="text-xs" />
            <x-text-input type="date" name="date_to" value="{{ $dateTo }}" />
        </div>
        @if ($account)
            <div>
                <x-input-label value="Para Birimi" class="text-xs" />
                <x-select-input name="currency" class="w-28">
                    @foreach ($currencyOptions as $option)
                        <option value="{{ $option }}" @selected($currency === $option)>{{ $option }}</option>
                    @endforeach
                </x-select-input>
            </div>
        @endif
        <x-secondary-button type="submit">Görüntüle</x-secondary-button>

        @if ($account)
            <div class="ms-auto flex gap-2">
                <a href="{{ route('reports.account-statement.export', request()->query()) }}">
                    <x-secondary-button type="button">Excel / CSV'ye Aktar</x-secondary-button>
                </a>
                <a href="{{ route('reports.account-statement.print', request()->query()) }}" target="_blank">
                    <x-secondary-button type="button">Yazdır / PDF Kaydet</x-secondary-button>
                </a>
            </div>
        @endif
    </form>

    @if (! $account)
        <div class="bg-white rounded-lg shadow p-10 text-center text-gray-500">
            Ekstreyi görüntülemek için önce yukarıdan bir cari seçin.
        </div>
    @else
        <div class="flex items-center gap-2 mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ $account->name }}</h2>
            <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-800">{{ $account->typeLabel() }}</span>
            <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-700">{{ $currency }}</span>
            <a href="{{ route('accounts.show', $account) }}" class="text-sm text-indigo-600 hover:underline">Cari Detayına Git</a>
        </div>

        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 text-left">
                    <tr>
                        <th class="px-4 py-3">Tarih</th>
                        <th class="px-4 py-3">İşlem Tipi</th>
                        <th class="hidden sm:table-cell px-4 py-3">Belge No</th>
                        <th class="px-4 py-3 text-right">Borç</th>
                        <th class="px-4 py-3 text-right">Alacak</th>
                        <th class="px-4 py-3 text-right">Bakiye</th>
                        <th class="hidden sm:table-cell px-4 py-3">Kaynak</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr class="bg-gray-50">
                        <td class="px-4 py-3" colspan="5">Açılış Bakiyesi ({{ $currency }})</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ \App\Support\Currency::format($openingBalance, $currency) }}</td>
                        <td class="hidden sm:table-cell px-4 py-3"></td>
                    </tr>
                    @forelse ($rows as $row)
                        @php $transaction = $row['transaction']; @endphp
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
                            </td>
                            <td class="hidden sm:table-cell px-4 py-3">
                                @if ($row['documentNumber'] && $transaction->source instanceof \App\Models\Sale)
                                    <a href="{{ route('sales.show', $transaction->source) }}" class="text-indigo-600 hover:underline">{{ $row['documentNumber'] }}</a>
                                @elseif ($row['documentNumber'] && $transaction->source instanceof \App\Models\Purchase)
                                    <a href="{{ route('purchases.show', $transaction->source) }}" class="text-indigo-600 hover:underline">{{ $row['documentNumber'] }}</a>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-red-600">{{ $row['debit'] !== null ? \App\Support\Currency::format($row['debit'], $currency) : '-' }}</td>
                            <td class="px-4 py-3 text-right text-green-600">{{ $row['credit'] !== null ? \App\Support\Currency::format($row['credit'], $currency) : '-' }}</td>
                            <td class="px-4 py-3 text-right font-medium">{{ \App\Support\Currency::format($row['balance'], $currency) }}</td>
                            <td class="hidden sm:table-cell px-4 py-3 text-gray-500">{{ $row['sourceLabel'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Seçilen dönemde hareket bulunamadı.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</x-app-layout>
