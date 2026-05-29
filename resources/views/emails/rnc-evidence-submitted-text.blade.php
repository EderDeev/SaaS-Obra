Deming
RNC {{ $rnc->formatted_number }} finalizada

Ola, {{ $notifiable->name }}.

{{ $actor->name }} enviou as evidencias da correcao e finalizou a RNC {{ $rnc->formatted_number }}.

Contrato: {{ $rnc->contract?->code }} - {{ $rnc->contract?->name }}
Obra: {{ $rnc->obra?->codigo }} - {{ $rnc->obra?->nome }}
Contratada: {{ $rnc->contratada?->sigla ?: $rnc->contratada?->nome }}
Fotos de evidencia: {{ $evidencia->photos->count() }}
Arquivo anexado: {{ $evidencia->attachment_original_name }}
Finalizada em: {{ $evidencia->submitted_at?->format('d/m/Y H:i') }}

Abrir RNC:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
