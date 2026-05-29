Deming
Projeto aprovado

Ola, {{ $notifiable->name }}.

{{ $actor->name }} aprovou um projeto do contrato e liberou o documento para a arvore principal.

Projeto: {{ $document->title }}
Codigo: {{ $document->code ?: 'Sem codigo' }}
Contrato: {{ $document->contract?->code }} - {{ $document->contract?->name }}
Obra: {{ $document->obra?->codigo }} - {{ $document->obra?->nome }}
Disciplina: {{ $document->disciplina?->sigla }} - {{ $document->disciplina?->nome }}
Revisao: {{ $document->latestVersion?->revision ?: 'Sem revisao' }}

Ver projetos:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
