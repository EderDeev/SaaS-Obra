SIGWORKS
Projeto aguardando aprovacao

Ola, {{ $notifiable->name }}.

{{ $actor->name }} verificou um projeto da sua disciplina e enviou para aprovacao final.

Projeto: {{ $document->title }}
Codigo: {{ $document->code ?: 'Sem codigo' }}
Contrato: {{ $document->contract?->code }} - {{ $document->contract?->name }}
Obra: {{ $document->obra?->codigo }} - {{ $document->obra?->nome }}
Disciplina: {{ $document->disciplina?->sigla }} - {{ $document->disciplina?->nome }}
Revisao: {{ $document->latestVersion?->revision ?: 'Sem revisao' }}

Aprovar projeto:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
