<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura Reverse Charge #{{ $invoice->number }}</title>
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
            color: #d63384;
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

        .reverse-charge-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            border-left: 4px solid #ffc107;
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
            <div class="invoice-title">FACTURA REVERSE CHARGE</div>
            <div><strong>Número:</strong> {{ $invoice->number }}</div>
            <div><strong>Fecha de expedición:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}</div>
            @if ($operation_date)
                <div><strong>Fecha de operación:</strong> {{ $operation_date }}</div>
            @endif
            <div><strong>Estado:</strong> {{ $invoice->status->label() }}</div>
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
                    <td class="text-right">0%</td>
                    <td class="text-right">0,00 €</td>
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
                <td>IVA (Reverse Charge):</td>
                <td class="text-right">0,00 €</td>
            </tr>
            <tr class="total-row">
                <td>TOTAL:</td>
                <td class="text-right">{{ number_format($totals['total'] / 100, 2) }} €</td>
            </tr>
        </table>
    </div>

    <!-- Reverse Charge Notice -->
    <div class="reverse-charge-notice">
        <strong>AVISO REVERSE CHARGE:</strong><br>
        El IVA debe ser declarado por el cliente en su país de origen según la normativa de Reverse Charge aplicable.
        Esta factura está sujeta al mecanismo de inversión del sujeto pasivo.
    </div>

    <!-- QR Code Section (only for fiscal invoices) -->
    @if($include_qr && isset($qr_data))
        <div class="qr-section">
            <div><strong>Código QR de Verificación Fiscal</strong></div>
            <div class="qr-code">
                <!-- QR Code would be rendered here -->
                <div style="border: 1px solid #ccc; padding: 10px; display: inline-block;">
                    QR: {{ $qr_data['qr_code'] ?? 'QR_CODE' }}
                </div>
            </div>
            <div style="font-size: 10px; color: #666;">
                URL: {{ $qr_data['qr_url'] ?? 'QR_URL' }}
            </div>
        </div>
    @endif

    <!-- Legal Notes -->
    <div class="legal-notes">
        <p><strong>Notas legales:</strong></p>
        <p>Esta factura está sujeta al mecanismo de inversión del sujeto pasivo (Reverse Charge).</p>
        <p>El IVA debe ser declarado por el cliente en su país de origen.</p>
        <p>Generado el {{ $generated_at->format('d/m/Y H:i:s') }}</p>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>{{ $company['name'] }} - {{ $company['address'] }} - {{ $company['tax_id'] }}</p>
        <p>Tel: {{ $company['phone'] }} - Email: {{ $company['email'] }} - Web: {{ $company['website'] }}</p>
    </div>
</body>
</html>
