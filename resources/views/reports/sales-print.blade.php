<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Satış Raporu' }}</title>
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

    <h1>{{ $title ?? 'Satış Raporu' }}</h1>
    <p class="meta">Dönem: {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d.m.Y') }} — {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d.m.Y') }}</p>

    @forelse ($totalsByCurrency as $currency => $row)
        <div class="totals">
            <div>
                <div class="label">TOPLAM ({{ $currency }})</div>
                <div class="value">{{ \App\Support\Currency::format($row->total, $currency) }}</div>
            </div>
            <div>
                <div class="label">ÖDENEN ({{ $currency }})</div>
                <div class="value">{{ \App\Support\Currency::format($row->paid, $currency) }}</div>
            </div>
            <div>
                <div class="label">KALAN ({{ $currency }})</div>
                <div class="value">{{ \App\Support\Currency::format($row->remaining, $currency) }}</div>
            </div>
        </div>
    @empty
        <div class="totals">
            <div>
                <div class="label">TOPLAM (TL)</div>
                <div class="value">{{ \App\Support\Currency::format(0, 'TL') }}</div>
            </div>
        </div>
    @endforelse

    <table>
        <thead>
            <tr>
                <th>{{ $numberColumn ?? 'Satış No' }}</th>
                <th>Tarih</th>
                <th>Cari</th>
                <th>Ödeme Tipi</th>
                <th>Durum</th>
                <th>Para Birimi</th>
                <th class="text-right">Toplam</th>
                <th class="text-right">Ödenen</th>
                <th class="text-right">Kalan</th>
                <th>Vade</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sales as $sale)
                <tr>
                    <td>{{ $sale->number }}</td>
                    <td>{{ ($sale->sale_date ?? $sale->purchase_date)->format('d.m.Y') }}</td>
                    <td>{{ $sale->account?->name ?? 'Genel' }}</td>
                    <td>{{ $sale->paymentTypeLabel() }}</td>
                    <td>{{ $sale->isCancelled() ? 'İptal Edildi' : $sale->statusLabel() }}</td>
                    <td>{{ $sale->currency }}</td>
                    <td class="text-right">{{ \App\Support\Currency::format($sale->total, $sale->currency) }}</td>
                    <td class="text-right">{{ \App\Support\Currency::format($sale->paid_amount, $sale->currency) }}</td>
                    <td class="text-right">{{ \App\Support\Currency::format($sale->remaining(), $sale->currency) }}</td>
                    <td>{{ $sale->due_date?->format('d.m.Y') ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="10">Kayıt bulunamadı.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
