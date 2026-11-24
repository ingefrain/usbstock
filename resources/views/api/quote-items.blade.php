@foreach ($rows as $row)
<tr>
    <td>@if ($row['sku'] !== null){{ $row['sku'] }} · @endif{{ $row['name'] }}@if ($row['description'] !== null)<br>{{ $row['description'] }}@endif</td>
    <td>{{ $row['color'] }}</td>
    <td>{{ $row['quantity'] }}</td>
    <td>{{ $row['printing'] }}</td>
    <td>{{ $row['unit_price'] }}<br>Impresión/u.: {{ $row['printing_price'] }}</td>
    <td>{{ $row['amount'] }}@if ($row['discount'] !== null)<br>Descuento: {{ $row['discount'] }}@endif</td>
</tr>
@endforeach
