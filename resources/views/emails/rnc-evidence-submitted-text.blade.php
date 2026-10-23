SigWorks
RNC {{ $rnc->formatted_number }} - evidências registradas

Ola, {{ $notifiable->name }}.

{{ $actor->name }} enviou as evidencias da correcao e deixou pronta para assinatura a RNC {{ $rnc->formatted_number }}.

Contrato: {{ $rnc->contract?->code }} - {{ $rnc->contract?->name }}
Obra: {{ $rnc->obra?->codigo }} - {{ $rnc->obra?->nome }}
Contratada: {{ $rnc->contratada?->sigla ?: $rnc->contratada?->nome }}
Fotos de evidencia: {{ $evidencia->photos->count() }}
@if ($evidencia->attachment_original_name)
Arquivo anexado: {{ $evidencia->attachment_original_name }}
@endif
Evidências registradas em: {{ $evidencia->submitted_at?->format('d/m/Y H:i') }}
@if ($evidencia->resolution_result)
Resultado: {{ $evidencia->resolution_label }}
@endif
@if ($evidencia->resolution_reason)
Motivo: {{ $evidencia->resolution_reason }}
@endif

Abrir RNC:
{{ $url }}

Acessar sistema:
{{ $systemUrl }}
