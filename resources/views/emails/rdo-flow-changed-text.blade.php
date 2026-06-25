Deming
Atualização no fluxo do RDO

Olá, {{ $notifiable->name }}.

{{ $message }}

RDO: {{ $rdo->code }}
Data de referência: {{ $rdo->reference_date?->format('d/m/Y') }}
Contrato: {{ $rdo->contract?->code }} - {{ $rdo->contract?->name }}
Frente(s) de serviço: {{ $rdo->configuracao?->obras?->map(fn ($obra) => trim(($obra->codigo ? $obra->codigo.' - ' : '').$obra->nome))->join(', ') ?: 'Não informada' }}
Situação atual: {{ $statusLabel }}
Ação realizada por: {{ $actor->name }}

Acessar RDO:
{{ $rdoUrl }}
