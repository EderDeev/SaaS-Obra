Deming
{{ $isRevision ? 'Revisao de projeto aprovada' : 'Projeto aprovado' }}

Ola, {{ $notifiable->name }}.

@if ($isRevision)
A revisao {{ $document->latestVersion?->revision }} do projeto foi aprovada por {{ $actor->name }} e esta disponivel para uso.
@else
{{ $actor->name }} aprovou um projeto do contrato e liberou o documento para a arvore principal.
@endif

Projeto: {{ $document->title }}
Codigo: {{ $document->eap($document->latestVersion?->revision) ?: 'Sem codigo' }}
Contrato: {{ $document->contract?->code }} - {{ $document->contract?->name }}
Obra: {{ $document->obra?->codigo }} - {{ $document->obra?->nome }}
Disciplina: {{ $document->disciplina?->sigla }} - {{ $document->disciplina?->nome }}
Revisao: {{ $document->latestVersion?->revision ?: 'Sem revisao' }}
@if ($isRevision)
CAP: {{ $document->latestVersion?->cap_number }}
@endif

{{ $isRevision ? 'Ver revisoes:' : 'Ver projetos:' }}
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
