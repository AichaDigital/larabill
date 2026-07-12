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
            line-height: 1.6;
            color: #2c3e50;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .invoice-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .company-info h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }

        .invoice-info {
            text-align: right;
        }

        .invoice-number {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .content {
            padding: 40px 30px;
        }

        .client-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #667eea;
        }

        .section-title {
            color: #667eea;
            font-weight: bold;
            margin-bottom: 15px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .items-table th {
            background: #667eea;
            color: white;
            padding: 15px 10px;
            text-align: left;
            font-weight: 500;
        }

        .items-table td {
            padding: 15px 10px;
            border-bottom: 1px solid #eee;
        }

        .items-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .items-table .text-right {
            text-align: right;
        }

        .totals-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
        }

        .totals-table .total-row {
            font-weight: bold;
            font-size: 16px;
            color: #667eea;
            border-top: 2px solid #667eea;
            border-bottom: 2px solid #667eea;
        }

        .qr-section {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .qr-code {
            display: inline-block;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .payment-terms {
            margin-top: 20px;
            padding: 15px;
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            border-radius: 4px;
            font-size: 11px;
        }

        .notes {
            margin-top: 20px;
            padding: 15px;
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            border-radius: 4px;
            font-size: 11px;
        }

        .footer {
            background: #2c3e50;
            color: white;
            padding: 20px 30px;
            text-align: center;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <h1>{{ $company['name'] }}</h1>
                <div>{{ $company['address'] }}</div>
                <div>{{ $company['postal_code'] }} {{ $company['city'] }}</div>
                <div>{{ $company['country'] }}</div>
                <div>{{ $company['tax_id'] }}</div>
            </div>

            <div class="invoice-info">
                <div class="invoice-number">FACTURA</div>
                <div><strong>Número:</strong> {{ $invoice->number }}</div>
                <div><strong>Fecha de expedición:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}</div>
                @if ($operation_date)
                    <div><strong>Fecha de operación:</strong> {{ $operation_date }}</div>
                @endif
                <div><strong>Estado:</strong> {{ $invoice->status?->label() }}</div>
            </div>
        </div>

        <div class="content">
            <!-- Client Information -->
            <div class="client-section">
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

            <!-- QR Code Section -->
            @if($include_qr && isset($qr_data))
                <div class="qr-section">
                    <div><strong>Código QR de Verificación Fiscal</strong></div>
                    <div class="qr-code">
                        <div style="border: 1px solid #ccc; padding: 10px; display: inline-block;">
                            QR: {{ $qr_data['qr_code'] ?? 'QR_CODE' }}
                        </div>
                    </div>
                    <div style="font-size: 10px; color: #666; margin-top: 10px;">
                        URL: {{ $qr_data['qr_url'] ?? 'QR_URL' }}
                    </div>
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
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>{{ $company['name'] }} - {{ $company['address'] }} - {{ $company['tax_id'] }}</p>
            <p>Tel: {{ $company['phone'] }} - Email: {{ $company['email'] }} - Web: {{ $company['website'] }}</p>
            <p>Generado el {{ $generated_at->format('d/m/Y H:i:s') }}</p>
        </div>
    </div>
</body>
</html>
