<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Cari Ekstre — {{ $account->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 11px; }
        th { background: #f3f4f6; }
        .text-right { text-align: right; }
        .opening { background: #f9fafb; font-weight: bold; }
        .no-print { margin-bottom: 16px; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Yazdır / PDF Kaydet</button>
    </div>

    <h1>Cari Ekstre — {{ $account->name }} ({{ $account->code }})</h1>
    <p class="meta">
        Dönem: {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d.m.Y') }} — {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d.m.Y') }}
        · Para Birimi: {{ $currency }}
    </p>

    <table>
        <thead>
            <tr>
                <th>Tarih</th>
                <th>İşlem Tipi</th>
                <th>Belge No</th>
                <th>Para Birimi</th>
                <th class="text-right">Borç</th>
                <th class="text-right">Alacak</th>
                <th class="text-right">Bakiye</th>
                <th>Kaynak</th>
            </tr>
        </thead>
        <tbody>
            <tr class="opening">
                <td colspan="6">Açılış Bakiyesi</td>
                <td class="text-right">{{ \App\Support\Currency::format($openingBalance, $currency) }}</td>
                <td></td>
            </tr>
            @forelse ($rows as $row)
                @php $transaction = $row['transaction']; @endphp
                <tr>
                    <td>{{ $transaction->transaction_date->format('d.m.Y') }}</td>
                    <td>{{ $transaction->typeLabel() }}{{ $transaction->isCancelled() ? ' (İptal)' : '' }}</td>
                    <td>{{ $row['documentNumber'] ?? '-' }}</td>
                    <td>{{ $transaction->currency }}</td>
                    <td class="text-right">{{ $row['debit'] !== null ? \App\Support\Currency::format($row['debit'], $currency) : '-' }}</td>
                    <td class="text-right">{{ $row['credit'] !== null ? \App\Support\Currency::format($row['credit'], $currency) : '-' }}</td>
                    <td class="text-right">{{ \App\Support\Currency::format($row['balance'], $currency) }}</td>
                    <td>{{ $row['sourceLabel'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8">Kayıt bulunamadı.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
