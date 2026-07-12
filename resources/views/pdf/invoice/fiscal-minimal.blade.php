<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura #{{ $invoice->number }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 15px;
        }

        .invoice {
            max-width: 700px;
            margin: 0 auto;
        }

        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .invoice-info {
            float: right;
            text-align: right;
        }

        .invoice-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .client-info {
            margin-bottom: 20px;
            padding: 10px;
            background: #f9f9f9;
            border-left: 3px solid #333;
        }

        .client-title {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
        }

        .items-table th {
            background: #f0f0f0;
            font-weight: bold;
        }

        .items-table .number {
            text-align: right;
        }

        .totals {
            width: 300px;
            margin-left: auto;
            margin-bottom: 20px;
        }

        .totals table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals td {
            padding: 5px 10px;
            border-bottom: 1px solid #ccc;
        }

        .totals .total-row {
            font-weight: bold;
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
        }

        .qr-section {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ccc;
        }

        .qr-code {
            font-family: monospace;
            font-size: 10px;
        }

        .payment-terms {
            margin-top: 15px;
            padding: 10px;
            background: #f5f5f5;
            border: 1px solid #ddd;
            font-size: 10px;
        }

        .notes {
            margin-top: 15px;
            padding: 10px;
            background: #fffacd;
            border: 1px solid #ddd;
            font-size: 10px;
        }

        .footer {
            border-top: 1px solid #ccc;
            padding-top: 10px;
            font-size: 9px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="invoice">
        <!-- Header -->
        <div class="header">
            <div class="company-name">{{ $company['name'] }}</div>
            <div>{{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}</div>
            <div>{{ $company['tax_id'] }}</div>

            <div class="invoice-info">
                <div class="invoice-title">FACTURA</div>
                <div><strong>Nº:</strong> {{ $invoice->number }}</div>
                <div><strong>Fecha de expedición:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}</div>
                @if ($operation_date)
                    <div><strong>Fecha de operación:</strong> {{ $operation_date }}</div>
                @endif
                <div><strong>Estado:</strong> {{ $invoice->status?->label() }}</div>
            </div>
            <div style="clear: both;"></div>
        </div>

        <!-- Client Information -->
        <div class="client-info">
            <div class="client-title">Facturar a:</div>
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

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th class="number">Cant.</th>
                    <th class="number">Precio</th>
                    <th class="number">% IVA</th>
                    <th class="number">IVA</th>
                    <th class="number">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td class="number">{{ number_format($item['quantity'], 2) }}</td>
                        <td class="number">{{ number_format($item['unit_price'] / 100, 2) }} €</td>
                        <td class="number">{{ $item['tax_rate'] }}%</td>
                        <td class="number">{{ number_format($item['tax_amount'] / 100, 2) }} €</td>
                        <td class="number">{{ number_format($item['total'] / 100, 2) }} €</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal:</td>
                    <td class="number">{{ number_format($totals['subtotal'] / 100, 2) }} €</td>
                </tr>
                <tr>
                    <td>IVA:</td>
                    <td class="number">{{ number_format($totals['tax_amount'] / 100, 2) }} €</td>
                </tr>
                <tr class="total-row">
                    <td>TOTAL:</td>
                    <td class="number">{{ number_format($totals['total'] / 100, 2) }} €</td>
                </tr>
            </table>
        </div>

        <!-- QR Code Section -->
        @if($include_qr && isset($qr_data))
            <div class="qr-section">
                <div><strong>Verificación Fiscal</strong></div>
                <div class="qr-code">{{ $qr_data['qr_code'] ?? 'QR_CODE' }}</div>
                <div style="font-size: 9px; margin-top: 5px;">{{ $qr_data['qr_url'] ?? 'QR_URL' }}</div>
            </div>
        @endif

        <!-- Payment Terms -->
        @if($payment_terms)
            <div class="payment-terms">
                <strong>Términos de pago:</strong> {{ $payment_terms }}
            </div>
        @endif

        <!-- Notes -->
        @if($notes)
            <div class="notes">
                <strong>Notas:</strong> {{ $notes }}
            </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>{{ $company['name'] }} | {{ $company['address'] }} | {{ $company['tax_id'] }}</p>
            <p>Tel: {{ $company['phone'] }} | Email: {{ $company['email'] }}</p>
            <p>Generado: {{ $generated_at->format('d/m/Y H:i:s') }}</p>
        </div>
    </div>
</body>
</html>
