<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura #{{ $invoice->number }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }

        .company-info {
            flex: 1;
        }

        .invoice-info {
            text-align: right;
            flex: 1;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .invoice-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .client-section {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
        }

        .client-info {
            flex: 1;
        }

        .section-title {
            font-weight: bold;
            margin-bottom: 10px;
            text-transform: uppercase;
            font-size: 14px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .items-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }

        .items-table .text-right {
            text-align: right;
        }

        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }

        .totals-table {
            width: 300px;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 5px 10px;
            border-bottom: 1px solid #ddd;
        }

        .totals-table .total-row {
            font-weight: bold;
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
        }

        .qr-section {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }

        .qr-code {
            margin: 10px 0;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 10px;
            color: #666;
        }

        .legal-notes {
            margin-top: 20px;
            font-size: 10px;
            color: #666;
        }

        .payment-terms {
            margin-top: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
            font-size: 11px;
        }

        .notes {
            margin-top: 20px;
            padding: 10px;
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="company-info">
            <div class="company-name">{{ $company['name'] }}</div>
            <div>{{ $company['address'] }}</div>
            <div>{{ $company['postal_code'] }} {{ $company['city'] }}</div>
            <div>{{ $company['country'] }}</div>
            <div>{{ $company['tax_id'] }}</div>
            <div>{{ $company['phone'] }}</div>
            <div>{{ $company['email'] }}</div>
        </div>

        <div class="invoice-info">
            <div class="invoice-title">FACTURA</div>
            <div><strong>Número:</strong> {{ $invoice->number }}</div>
            <div><strong>Fecha:</strong> {{ $invoice->created_at ? $invoice->created_at->format('d/m/Y') : date('d/m/Y') }}</div>
            <div><strong>Estado:</strong> {{ $invoice->status?->label() }}</div>
        </div>
    </div>

    <!-- Client Information -->
    <div class="client-section">
        <div class="client-info">
            <div class="section-title">Facturar a:</div>
            @if(!empty($client))
                <div><strong>{{ $client['name'] }}</strong></div>
                <div>{{ $client['address'] }}</div>
                <div>{{ $client['postal_code'] }} {{ $client['city'] }}</div>
                <div>{{ $client['country'] }}</div>
                @if(isset($client['tax_id']))
                    <div>{{ $client['tax_id'] }}</div>
                @endif
            @else
                <div>Cliente no especificado</div>
            @endif
        </div>
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="text-right">Cantidad</th>
                <th class="text-right">Precio Unit.</th>
                <th class="text-right">% IVA</th>
                <th class="text-right">Importe IVA</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="text-right">{{ number_format($item['quantity'], 2) }}</td>
                    <td class="text-right">{{ number_format($item['unit_price'] / 100, 2) }} €</td>
                    <td class="text-right">{{ $item['tax_rate'] }}%</td>
                    <td class="text-right">{{ number_format($item['tax_amount'] / 100, 2) }} €</td>
                    <td class="text-right">{{ number_format($item['total'] / 100, 2) }} €</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals-section">
        <table class="totals-table">
            <tr>
                <td>Subtotal:</td>
                <td class="text-right">{{ number_format($totals['subtotal'] / 100, 2) }} €</td>
            </tr>
            <tr>
                <td>IVA ({{ $items[0]['tax_rate'] ?? 21 }}%):</td>
                <td class="text-right">{{ number_format($totals['tax_amount'] / 100, 2) }} €</td>
            </tr>
            <tr class="total-row">
                <td>TOTAL:</td>
                <td class="text-right">{{ number_format($totals['total'] / 100, 2) }} €</td>
            </tr>
        </table>
    </div>

    <!-- QR Code Section (only for fiscal invoices) -->
    @if($include_qr && isset($qr_data))
        <div class="qr-section">
            <div><strong>QR tributario:</strong></div>
            <div class="qr-code">
                @if(!empty($qr_data['qr_svg']))
                    {!! $qr_data['qr_svg'] !!}
                @elseif(!empty($qr_data['qr_png']))
                    <img src="{{ $qr_data['qr_png'] }}" alt="QR tributario" style="width: 35mm; height: 35mm;">
                @else
                    <div style="border: 1px solid #ccc; padding: 10px; display: inline-block;">
                        {{ $qr_data['qr_code'] ?? 'QR_CODE' }}
                    </div>
                @endif
            </div>
            @if(($qr_data['source'] ?? null) === 'fiscal_verification')
                <div class="verifactu-legend" style="margin-top: 8px; font-size: 10px; color: #555;">
                    Factura verificable en la sede electrónica de la AEAT — VERI*FACTU
                </div>
            @endif
            @if(!empty($qr_data['qr_url']))
                <div style="font-size: 10px; color: #666;">
                    URL: {{ $qr_data['qr_url'] }}
                </div>
            @endif
        </div>
    @endif

    <!-- Payment Terms -->
    @if($payment_terms)
        <div class="payment-terms">
            <p><strong>Términos de pago:</strong></p>
            <p>{{ $payment_terms }}</p>
        </div>
    @endif

    <!-- Notes -->
    @if($notes)
        <div class="notes">
            <p><strong>Notas:</strong></p>
            <p>{{ $notes }}</p>
        </div>
    @endif

    <!-- Legal Notes -->
    <div class="legal-notes">
        <p><strong>Notas legales:</strong></p>
        <p>Esta factura es válida fiscalmente y cumple con la normativa española.</p>
        <p>El IVA aplicado corresponde a la legislación vigente.</p>
        <p>Generado el {{ $generated_at->format('d/m/Y H:i:s') }}</p>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>{{ $company['name'] }} - {{ $company['address'] }} - {{ $company['tax_id'] }}</p>
        <p>Tel: {{ $company['phone'] }} - Email: {{ $company['email'] }} - Web: {{ $company['website'] }}</p>
    </div>
</body>
</html>
