Deming
Projeto reprovado

Olá, {{ $notifiable->name }}.

{{ $actor->name }} reprovou o projeto que você submeteu durante a etapa de {{ mb_strtolower($stageLabel) }}.

Projeto: {{ $document->title }}
Código: {{ $document->eap($document->latestVersion?->revision) ?: 'Sem código' }}
Contrato: {{ $document->contract?->code }} - {{ $document->contract?->name }}
Revisão: {{ $document->latestVersion?->revision ?: 'Sem revisão' }}

Motivo da reprovação:
{{ $reason }}

Ver projeto:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
