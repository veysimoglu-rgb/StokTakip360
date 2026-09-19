<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Kârlılık Raporu</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 20px 0 8px; }
        .meta { color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 11px; }
        th { background: #f3f4f6; }
        .text-right { text-align: right; }
        .totals { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 12px; }
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

    <h1>Kârlılık Raporu</h1>
    <p class="meta">Dönem: {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d.m.Y') }} — {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d.m.Y') }}</p>

    @forelse ($summaryByCurrency as $currency => $row)
        <h2>{{ $currency }}</h2>
        <div class="totals">
            <div>
                <div class="label">SATILAN ADET</div>
                <div class="value">{{ \App\Support\Quantity::format($row->qty) }}</div>
            </div>
            <div>
                <div class="label">TOPLAM SATIŞ</div>
                <div class="value">{{ \App\Support\Currency::format($row->revenue, $currency) }}</div>
            </div>
            <div>
                <div class="label">TOPLAM MALİYET</div>
                <div class="value">{{ \App\Support\Currency::format($row->cost, $currency) }}</div>
            </div>
            <div>
                <div class="label">BRÜT KÂR</div>
                <div class="value">{{ $row->profit === null ? 'Maliyet bilgisi yok' : \App\Support\Currency::format($row->profit, $currency) }}</div>
            </div>
            <div>
                <div class="label">KÂR MARJI</div>
                <div class="value">{{ $row->margin === null ? '—' : number_format($row->margin, 1, ',', '.').' %' }}</div>
            </div>
        </div>
        @if ((float) $row->revenue_without_cost > 0)
            <p class="meta">Maliyet bilgisi olmayan satış: {{ \App\Support\Quantity::format($row->qty_without_cost) }} adet, {{ \App\Support\Currency::format($row->revenue_without_cost, $currency) }} — kâr hesabına dahil edilmedi.</p>
        @endif

        <table>
            <thead>
                <tr>
                    <th>Ürün</th>
                    <th>Kod</th>
                    <th class="text-right">Satılan Adet</th>
                    <th class="text-right">Satış Tutarı</th>
                    <th class="text-right">Maliyet</th>
                    <th class="text-right">Brüt Kâr</th>
                    <th class="text-right">Kâr Marjı</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($productPerformanceByCurrency->get($currency, collect()) as $productRow)
                    <tr>
                        <td>{{ $productRow->product_name }}</td>
                        <td>{{ $productRow->product_code }}</td>
                        <td class="text-right">{{ \App\Support\Quantity::format($productRow->qty) }}</td>
                        <td class="text-right">{{ \App\Support\Currency::format($productRow->revenue, $currency) }}</td>
                        <td class="text-right">{{ $productRow->has_cost ? \App\Support\Currency::format($productRow->cost, $currency) : 'Maliyet bilgisi yok' }}</td>
                        <td class="text-right">{{ $productRow->profit === null ? '—' : \App\Support\Currency::format($productRow->profit, $currency) }}</td>
                        <td class="text-right">{{ $productRow->margin === null ? '—' : number_format($productRow->margin, 1, ',', '.').' %' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>Seçilen filtrelerde satış bulunamadı.</p>
    @endforelse
</body>
</html>
