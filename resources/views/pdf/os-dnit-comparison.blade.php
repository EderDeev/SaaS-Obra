<table class="comparison">
    <thead><tr><th style="width: 10%">Código</th><th style="width: 35%">Produto</th><th style="width: 18%">Solicitação do analista</th><th style="width: 18%">Análise Concremat</th><th style="width: 19%">Alteração</th></tr></thead>
    <tbody>@foreach($rows as $row)<tr class="{{ $row['changed'] ? 'changed' : '' }}"><td><strong>{{ $row['product']['product_code'] }}</strong></td><td>{{ $row['product']['product_name'] }}</td><td>{{ $row['requested'] ? $number($row['requested']['quantity']).' '.$row['requested']['unit'] : 'Não solicitado' }}</td><td>{{ $row['reviewed'] ? $number($row['reviewed']['quantity']).' '.$row['reviewed']['unit'] : 'Não incluído' }}</td><td>{{ $row['label'] }}</td></tr>@endforeach</tbody>
</table>
