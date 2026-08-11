Deming - Ordem de Serviço
{{ $headline }}

Olá, {{ $notifiable->name }}.

{{ $bodyText }}

{{ $highlightTitle }}:
{{ $highlightBody }}

OS: {{ $ordem->codigo }}
Título: {{ $ordem->titulo }}
Contrato: {{ trim(($ordem->contract?->code ? $ordem->contract->code.' - ' : '').($ordem->contract?->name ?? '')) ?: 'Não informado' }}
Obra: {{ trim(($ordem->obra?->codigo ? $ordem->obra->codigo.' - ' : '').($ordem->obra?->nome ?? '')) ?: 'Não informada' }}
Início previsto: {{ $ordem->prazo_inicio?->format('d/m/Y') ?: 'Não informado' }}
Finalização prevista: {{ $ordem->prazo_finalizacao?->format('d/m/Y') ?: 'Não informada' }}
Custo previsto: R$ {{ number_format((float) $ordem->custo_previsto, 2, ',', '.') }}
Situação atual: {{ $statusLabel }}
Ação realizada por: {{ $actor->name }}
@if ($observation)

Observação:
{{ $observation }}
@endif

{{ $actionLabel }}:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
