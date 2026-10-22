<table><thead><tr><th style="width: 10%">Código</th><th style="width: 56%">Produto</th><th style="width: 19%">Quantidade</th><th style="width: 15%">Unidade</th></tr></thead><tbody>
    @forelse($items as $item)<tr><td><strong>{{ $item['product_code'] }}</strong></td><td>{{ $item['product_name'] }}</td><td>{{ $number($item['quantity']) }}</td><td>{{ $item['unit'] }}</td></tr>@empty<tr><td colspan="4">Produtos não registrados nesta etapa.</td></tr>@endforelse
</tbody></table>
