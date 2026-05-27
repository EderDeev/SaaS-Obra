SIGWORKS
Projeto aguardando analise

Ola, {{ $notifiable->name }}.

{{ $actor->name }} submeteu um projeto da sua disciplina para analise.

Projeto: {{ $document->title }}
Codigo: {{ $document->code ?: 'Sem codigo' }}
Contrato: {{ $document->contract?->code }} - {{ $document->contract?->name }}
Obra: {{ $document->obra?->codigo }} - {{ $document->obra?->nome }}
Disciplina: {{ $document->disciplina?->sigla }} - {{ $document->disciplina?->nome }}
Revisao: {{ $document->latestVersion?->revision ?: 'Sem revisao' }}

Analisar projeto:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
