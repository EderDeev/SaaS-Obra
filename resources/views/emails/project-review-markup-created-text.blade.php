Deming
Novo comentário visual de projeto

Olá, {{ $notifiable->name }}.

{{ $actor->name }} criou um comentário visual de projeto para você analisar.

Comentário: {{ $markup->title }}
Projeto: {{ $markup->document?->title }}
EAP: {{ $markup->document?->code ?: 'Sem código' }}
Revisão: {{ $markup->version?->revision ?: 'Sem revisão' }}
Contrato: {{ $markup->contract?->code }} - {{ $markup->contract?->name }}
Prioridade: {{ $priorityLabel }}
Prazo: {{ $markup->due_date?->format('d/m/Y') ?: 'Sem prazo' }}

Descrição:
{{ $description ?: 'Sem descrição informada.' }}

Abrir projeto:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
