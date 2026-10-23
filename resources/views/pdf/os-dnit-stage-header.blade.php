<div class="stage-heading">
    <div class="eyebrow">Ordem de Serviço · {{ $sectionTitle }}</div>
    <table class="plain"><tr><td style="width: 70%"><h1>Ordem de Serviço</h1></td><td style="width: 30%; text-align: right"><span class="number">{{ $order->request_number }}</span></td></tr></table>
    @if($compact ?? false)
    <p class="muted">Contrato {{ $order->contract->code }} · {{ $order->supervision_lot }} · {{ $order->highway_name }} · km {{ $number($order->km_start, true) }} a {{ $number($order->km_end, true) }}<br>Referência SEI / processo: {{ $order->sei_reference ?: 'Não informado' }}</p>
    @else
    <table class="meta">
        <tr><td><span class="label">Contrato de supervisão</span>{{ $order->contract->code }} · {{ $order->contract->name }}</td><td><span class="label">Lote de supervisão</span>{{ $order->supervision_lot }}</td></tr>
        <tr><td><span class="label">Rodovia / trecho</span>{{ $order->highway_name }} · km {{ $number($order->km_start, true) }} a {{ $number($order->km_end, true) }}</td><td><span class="label">Referência SEI / processo</span>{{ $order->sei_reference ?: 'Não informado' }}</td></tr>
        <tr><td><span class="label">Abertura da solicitação</span>{{ $time($order->created_at) }}</td><td><span class="label">Situação atual da OS</span>{{ $statuses[$order->status] ?? $order->status }}</td></tr>
    </table>
    @endif
    <p class="stage-subject"><span class="label">Assunto da solicitação</span>{{ $order->subject }}</p>
</div>
