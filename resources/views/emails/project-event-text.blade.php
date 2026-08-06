{{ $headline }}

Olá, {{ $notifiable->name }}.

{{ $intro }}

@if (!empty($highlightBody))
{{ $highlightTitle ?: 'Informação' }}: {{ $highlightBody }}

@endif
@foreach ($details as $detail)
{{ $detail['label'] }}: {{ $detail['value'] }}
@endforeach

{{ $actionLabel }}: {{ $url }}
Acessar sistema: {{ $systemUrl }}

Esta é uma mensagem automática do fluxo de Projetos do Deming.
