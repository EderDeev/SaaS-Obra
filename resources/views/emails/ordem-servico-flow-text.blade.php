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
Prazo de execução: {{ $ordem->prazo_execucao?->format('d/m/Y') ?: 'Sem prazo' }}
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
