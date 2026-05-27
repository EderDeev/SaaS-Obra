SIGWORKS
Nova marcacao de projeto

Ola, {{ $notifiable->name }}.

{{ $actor->name }} criou uma marcacao de projeto para voce analisar.

Marcacao: {{ $markup->title }}
Projeto: {{ $markup->document?->title }}
EAP: {{ $markup->document?->code ?: 'Sem codigo' }}
Revisao: {{ $markup->version?->revision ?: 'Sem revisao' }}
Contrato: {{ $markup->contract?->code }} - {{ $markup->contract?->name }}
Prioridade: {{ $priorityLabel }}
Prazo: {{ $markup->due_date?->format('d/m/Y') ?: 'Sem prazo' }}

Descricao:
{{ $description ?: 'Sem descricao informada.' }}

Abrir projeto:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
