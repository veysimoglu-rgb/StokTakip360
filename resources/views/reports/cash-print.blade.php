<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Kasa Raporu</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 11px; }
        th { background: #f3f4f6; }
        .text-right { text-align: right; }
        .totals { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; }
        .totals div { border: 1px solid #ddd; padding: 8px 12px; border-radius: 4px; }
        .totals .label { color: #666; font-size: 10px; }
        .totals .value { font-size: 15px; font-weight: bold; }
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

    <h1>Kasa Raporu</h1>
    <p class="meta">Dönem: {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d.m.Y') }} — {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d.m.Y') }}</p>

    @forelse ($totalsByCurrency as $currency => $row)
        <div class="totals">
            <div>
                <div class="label">TOPLAM GİRİŞ ({{ $currency }})</div>
                <div class="value">{{ \App\Support\Currency::format($row['in'], $currency) }}</div>
            </div>
            <div>
                <div class="label">TOPLAM ÇIKIŞ ({{ $currency }})</div>
                <div class="value">{{ \App\Support\Currency::format($row['out'], $currency) }}</div>
            </div>
            <div>
                <div class="label">NET DEĞİŞİM ({{ $currency }})</div>
                <div class="value">{{ \App\Support\Currency::format($row['net'], $currency) }}</div>
            </div>
        </div>
    @empty
        <div class="totals">
            <div>
                <div class="label">TOPLAM GİRİŞ (TL)</div>
                <div class="value">{{ \App\Support\Currency::format(0, 'TL') }}</div>
            </div>
        </div>
    @endforelse

    <table>
        <thead>
            <tr>
                <th>Tarih</th>
                <th>Tip</th>
                <th>Açıklama</th>
                <th>Cari</th>
                <th>Para Birimi</th>
                <th class="text-right">Tutar</th>
                <th>Kullanıcı</th>
                <th>Durum</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->transaction_date->format('d.m.Y H:i') }}</td>
                    <td>{{ $transaction->typeLabel() }}</td>
                    <td>{{ $transaction->description ?? '-' }}</td>
                    <td>{{ $transaction->account?->name ?? '-' }}</td>
                    <td>{{ $transaction->currency }}</td>
                    <td class="text-right">{{ $transaction->direction === 'in' ? '+' : '-' }}{{ \App\Support\Currency::format($transaction->amount, $transaction->currency) }}</td>
                    <td>{{ $transaction->user?->name ?? '-' }}</td>
                    <td>{{ $transaction->isCancelled() ? 'İptal Edildi' : ($transaction->reversal_of_id ? 'Ters Kayıt' : '-') }}</td>
                </tr>
            @empty
                <tr><td colspan="8">Kayıt bulunamadı.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
