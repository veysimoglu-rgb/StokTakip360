<x-app-layout>
    <x-slot name="title">Alış Raporu</x-slot>

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
        <x-select-input name="account_id" class="w-48">
            <option value="">Tüm Cariler</option>
            @foreach ($accounts as $account)
                <option value="{{ $account->id }}" @selected(request('account_id') == $account->id)>{{ $account->name }}</option>
            @endforeach
        </x-select-input>
        <x-select-input name="status" class="w-40">
            <option value="">Tüm Durumlar</option>
            @foreach (\App\Models\Purchase::STATUSES as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </x-select-input>
        <x-select-input name="currency" class="w-32">
            <option value="">Tüm Para Birimleri</option>
            @foreach (\App\Support\Currency::LIST as $currencyOption)
                <option value="{{ $currencyOption }}" @selected(request('currency') === $currencyOption)>{{ $currencyOption }}</option>
            @endforeach
        </x-select-input>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="overdue" value="1" @checked(request('overdue')) class="rounded border-gray-300 text-indigo-600">
            Sadece vadesi geçenler
        </label>
        <x-secondary-button type="submit">Filtrele</x-secondary-button>

        <div class="ms-auto flex gap-2">
            <a href="{{ route('reports.purchases.export', request()->query()) }}">
                <x-secondary-button type="button">Excel / CSV'ye Aktar</x-secondary-button>
            </a>
            <a href="{{ route('reports.purchases.print', request()->query()) }}" target="_blank">
                <x-secondary-button type="button">Yazdır / PDF Kaydet</x-secondary-button>
            </a>
        </div>
    </form>

    @forelse ($totalsByCurrency as $currency => $row)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-3">
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Toplam Alış ({{ $currency }})</p>
                <p class="text-2xl font-semibold text-gray-800 mt-1">{{ \App\Support\Currency::format($row->total, $currency) }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Ödenen ({{ $currency }})</p>
                <p class="text-2xl font-semibold text-green-600 mt-1">{{ \App\Support\Currency::format($row->paid, $currency) }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Kalan ({{ $currency }})</p>
                <p class="text-2xl font-semibold text-red-600 mt-1">{{ \App\Support\Currency::format($row->remaining, $currency) }}</p>
            </div>
        </div>
    @empty
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-3">
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Toplam Alış (TL)</p>
                <p class="text-2xl font-semibold text-gray-800 mt-1">{{ \App\Support\Currency::format(0, 'TL') }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Ödenen (TL)</p>
                <p class="text-2xl font-semibold text-green-600 mt-1">{{ \App\Support\Currency::format(0, 'TL') }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-sm text-gray-500">Kalan (TL)</p>
                <p class="text-2xl font-semibold text-red-600 mt-1">{{ \App\Support\Currency::format(0, 'TL') }}</p>
            </div>
        </div>
    @endforelse
    <p class="text-xs text-gray-400 mb-6">İptal edilmiş alışlar listede görünür ancak yukarıdaki toplamlara dahil edilmez.</p>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Alış No</th>
                    <th class="px-4 py-3">Tarih</th>
                    <th class="hidden sm:table-cell px-4 py-3">Cari</th>
                    <th class="hidden sm:table-cell px-4 py-3">Ödeme Tipi</th>
                    <th class="px-4 py-3">Durum</th>
                    <th class="hidden sm:table-cell px-4 py-3">Para Birimi</th>
                    <th class="px-4 py-3 text-right">Toplam</th>
                    <th class="hidden sm:table-cell px-4 py-3 text-right">Ödenen</th>
                    <th class="hidden sm:table-cell px-4 py-3 text-right">Kalan</th>
                    <th class="hidden sm:table-cell px-4 py-3">Vade</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($purchases as $purchase)
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('purchases.show', $purchase) }}'">
                        <td class="px-4 py-3 font-mono text-xs">{{ $purchase->number }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ $purchase->purchase_date->format('d.m.Y') }}
                            <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $purchase->account?->name ?? 'Genel Tedarikçi' }}</p>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $purchase->account?->name ?? 'Genel Tedarikçi' }}</td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $purchase->paymentTypeLabel() }}</td>
                        <td class="px-4 py-3">
                            @if ($purchase->isCancelled())
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">İptal Edildi</span>
                            @else
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs',
                                    'bg-green-100 text-green-800' => $purchase->status === 'paid',
                                    'bg-yellow-100 text-yellow-800' => $purchase->status === 'partial',
                                    'bg-red-100 text-red-800' => $purchase->status === 'unpaid',
                                ])>{{ $purchase->statusLabel() }}</span>
                                @if ($purchase->isOverdue())
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-800 ms-1">Vadesi Geçti</span>
                                @endif
                            @endif
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $purchase->currency }}</td>
                        <td class="px-4 py-3 text-right font-medium">{{ \App\Support\Currency::format($purchase->total, $purchase->currency) }}</td>
                        <td class="hidden sm:table-cell px-4 py-3 text-right text-green-600">{{ \App\Support\Currency::format($purchase->paid_amount, $purchase->currency) }}</td>
                        <td class="hidden sm:table-cell px-4 py-3 text-right text-red-600">{{ \App\Support\Currency::format($purchase->remaining(), $purchase->currency) }}</td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $purchase->due_date?->format('d.m.Y') ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-6 text-center text-gray-500">Seçilen dönemde alış bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $purchases->links() }}</div>
</x-app-layout>
