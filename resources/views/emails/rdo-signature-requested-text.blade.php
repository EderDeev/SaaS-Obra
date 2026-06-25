Deming
Assinatura de RDO solicitada

Olá, {{ $notifiable->name }}.

Você foi indicado como responsável pela assinatura deste Relatório Diário de Obra.

RDO: {{ $rdo->code }}
Data de referência: {{ $rdo->reference_date?->format('d/m/Y') }}
Contrato: {{ $rdo->contract?->code }} - {{ $rdo->contract?->name }}
Situação: Aguardando assinatura

@if($signingUrl)
Assinar documento:
{{ $signingUrl }}
@else
O OpenSign enviará o convite de assinatura em uma mensagem separada.
@endif

Visualizar RDO no Deming:
{{ $rdoUrl }}
