SigWorks · OS DNIT
{{ $headline }}

Olá, {{ $notifiable->name }}.

{{ $nextStep }}

OS: {{ $snapshot['request_number'] }}
Assunto: {{ $snapshot['subject'] }}
Contrato: {{ $contract->code }} — {{ $contract->name }}
Etapa desta atualização: {{ $statusLabel }}
Ação realizada por: {{ $event->actor->name }}
Data: {{ $occurredAt }}
@if ($snapshot['estimated_deadline'])
Prazo estimado Concremat: {{ \Carbon\Carbon::parse($snapshot['estimated_deadline'])->format('d/m/Y') }}
@endif
@if ($event->comment)

{{ $event->action === 'returned' ? 'Justificativa da reprovação / devolução' : 'Comentário' }}:
{{ $event->comment }}
@endif

Acessar OS: {{ $url }}

Consulte a situação mais recente e o histórico completo no sistema.
