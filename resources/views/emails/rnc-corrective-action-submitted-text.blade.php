SIGWORKS
Proposta de acao corretiva enviada

Ola, {{ $notifiable->name }}.

{{ $actor->name }} enviou uma proposta de acao corretiva para a RNC {{ $rnc->formatted_number }}.

Contrato: {{ $rnc->contract?->code }} - {{ $rnc->contract?->name }}
Obra: {{ $rnc->obra?->codigo }} - {{ $rnc->obra?->nome }}
Arquivo: {{ $acaoCorretiva->attachment_original_name }}
Prazo proposto: {{ $acaoCorretiva->prazo_execucao_proposto?->format('d/m/Y') }}
Enviado em: {{ $acaoCorretiva->submitted_at?->format('d/m/Y H:i') }}

Proposta de acao:
{{ $descricao ?: 'Sem descricao informada.' }}

Abrir RNC:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
