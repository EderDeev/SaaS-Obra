Deming
{{ $approved ? 'Proposta de acao corretiva aprovada' : 'Proposta de acao corretiva reprovada' }}

Ola, {{ $notifiable->name }}.

{{ $actor->name }} analisou a proposta da RNC {{ $rnc->formatted_number }}.
@if ($approved)
A proposta foi aceita e o processo corretivo pode ser iniciado.
@else
A proposta foi reprovada e uma nova proposta de acao corretiva deve ser enviada.
@endif

Contrato: {{ $rnc->contract?->code }} - {{ $rnc->contract?->name }}
Obra: {{ $rnc->obra?->codigo }} - {{ $rnc->obra?->nome }}
Resultado: {{ $approved ? 'Aprovada' : 'Reprovada' }}
Prazo proposto: {{ $acaoCorretiva->prazo_execucao_proposto?->format('d/m/Y') }}
Analisada em: {{ $acaoCorretiva->reviewed_at?->format('d/m/Y H:i') }}

{{ $approved ? 'Observacoes da aprovacao' : 'Motivo da reprovacao' }}:
{{ $observacao ?: 'Sem observacoes informadas.' }}

{{ $approved ? 'Abrir RNC' : 'Enviar nova proposta' }}:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
