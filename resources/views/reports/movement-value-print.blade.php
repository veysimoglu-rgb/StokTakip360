<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Stok Hareketi Değer Raporu</title>
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

    <h1>Stok Hareketi Değer Raporu</h1>
    <p class="meta">Dönem: {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d.m.Y') }} — {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d.m.Y') }}</p>

    <div class="totals">
        <div>
            <div class="label">TOPLAM GİRİŞ MİKTARI</div>
            <div class="value">{{ $qtyTotals->get('in', 0) }}</div>
        </div>
        <div>
            <div class="label">TOPLAM ÇIKIŞ MİKTARI</div>
            <div class="value">{{ $qtyTotals->get('out', 0) }}</div>
        </div>
        @foreach ($totalsByCurrency as $currency => $rows)
            @foreach ($rows as $row)
                <div>
                    <div class="label">{{ $row->type === 'in' ? 'GİRİŞ/ALIŞ TUTARI' : 'ÇIKIŞ/SATIŞ TUTARI' }} ({{ $currency }})</div>
                    <div class="value">{{ \App\Support\Currency::formatWithSymbol($row->total, $currency) }}</div>
                </div>
            @endforeach
        @endforeach
    </div>

    <table>
        <thead>
            <tr>
                <th>Tarih</th>
                <th>Ürün</th>
                <th>Kod</th>
                <th>Tip</th>
                <th class="text-right">Miktar</th>
                <th class="text-right">Birim Fiyat</th>
                <th class="text-right">Tutar</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($movements as $movement)
                <tr>
                    <td>{{ $movement->movement_date->format('d.m.Y H:i') }}</td>
                    <td>{{ $movement->product->name }}</td>
                    <td>{{ $movement->product->code }}</td>
                    <td>{{ $movement->type === 'in' ? 'Giriş' : 'Çıkış' }}</td>
                    <td class="text-right">{{ $movement->quantity }}</td>
                    <td class="text-right">
                        @if ($movement->amount() === null)
                            -
                        @elseif ($movement->currency)
                            {{ \App\Support\Currency::format($movement->unit_price, $movement->currency) }}
                        @else
                            {{ number_format($movement->unit_price, 2, ',', '.') }} (?)
                        @endif
                    </td>
                    <td class="text-right">
                        @if ($movement->amount() === null)
                            -
                        @elseif ($movement->currency)
                            {{ \App\Support\Currency::format($movement->amount(), $movement->currency) }}
                        @else
                            {{ number_format($movement->amount(), 2, ',', '.') }} (para birimi bilinmiyor)
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">Kayıt bulunamadı.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
