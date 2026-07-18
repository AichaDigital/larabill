<html>
<body>
{{-- A radically restyled but CONFORMANT consumer template (ADR-011 fixture):
     free layout, every mandatory fiscal datum printed as handed. --}}
<p>{{ $invoice->fiscal_number }} · {{ $invoice->invoice_date->format('Y-m-d') }}</p>
@if ($operation_date)
    <p>Operación: {{ $operation_date }}</p>
@endif
<p>{{ $company['name'] ?? '' }} · {{ $company['tax_id'] ?? '' }}</p>
<p>{{ $client['name'] ?? '' }} · {{ $client['tax_id'] ?? '' }}</p>
@foreach ($items as $item)
    <p>{{ $item['description'] }} — {{ $item['unit_price'] }}</p>
@endforeach
@foreach ($totals['tax_breakdown'] as $row)
    <p>{{ $row['name'] }} {{ $row['rate'] }}% / {{ $row['base'] }} / {{ $row['amount'] }}</p>
@endforeach
<p>{{ $totals['subtotal'] }} → {{ $totals['total'] }}</p>
</body>
</html>
