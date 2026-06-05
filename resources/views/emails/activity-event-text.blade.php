Deming
{{ $title }}

Ola, {{ $notifiable->name }}.

{{ $intro }}

Atividade: {{ $activity->title }}
Contrato: {{ $activity->contract?->code }} - {{ $activity->contract?->name }}
{{ $eventLabel }}: {{ $eventBody }}
Prazo: {{ $activity->due_date?->format('d/m/Y') ?: 'Sem prazo' }}

Descricao:
{{ $description ?: 'Sem descricao informada.' }}

Abrir atividades:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
