Deming
RNC {{ $rnc->formatted_number }} aguardando resposta

Ola, {{ $notifiable->name }}.

{{ $actor->name }} notificou voce sobre esta RNC.

Contrato: {{ $rnc->contract?->code }} - {{ $rnc->contract?->name }}
Obra: {{ $rnc->obra?->codigo }} - {{ $rnc->obra?->nome }}
Natureza: {{ $rnc->natureza }}
Gravidade: {{ $rnc->gravidade }}
Prazo para resposta: {{ $rnc->prazo_resposta_acao_corretiva?->format('d/m/Y') }}

Descricao do problema:
{{ $descricao ?: 'Sem descricao informada.' }}

Abrir RNC:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
