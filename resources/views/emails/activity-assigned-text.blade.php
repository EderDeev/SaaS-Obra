SIGWORKS
Nova atividade atribuida

Ola, {{ $notifiable->name }}.

{{ $creator->name }} atribuiu uma atividade para voce.

Atividade: {{ $activity->title }}
Contrato: {{ $activity->contract?->code }} - {{ $activity->contract?->name }}
Prioridade: {{ $priorityLabel }}
Prazo: {{ $activity->due_date?->format('d/m/Y') ?: 'Sem prazo' }}

Descricao:
{{ $description ?: 'Sem descricao informada.' }}

Abrir atividades:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
