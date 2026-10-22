<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>OS {{ $order->request_number }}</title>
    <style>
        @page { margin: 88pt 42pt 58pt; }
        body { word-wrap: break-word; font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #152839; line-height: 1.4; }
        * { box-sizing: border-box; }
        .header { position: fixed; top: -65pt; left: 0; right: 0; height: 46pt; border-bottom: 1pt solid #244d6a; }
        .header img { width: 100pt; }
        .brand { font-weight: bold; color: #244d6a; font-size: 13pt; }
        .header-label { position: absolute; top: 3pt; right: 0; width: 75%; text-align: right; font-size: 7pt; color: #244d6a; word-wrap: break-word; }
        h1, h2 { font-family: 'DejaVu Serif', serif; color: #163348; font-weight: bold; }
        h1 { font-size: 23pt; margin: 8pt 0; line-height: 1.15; }
        h2 { font-size: 12pt; border-bottom: .6pt solid #d8e1e8; margin: 17pt 0 10pt; padding-bottom: 7pt; page-break-after: avoid; }
        h3 { font-size: 9pt; margin: 12pt 0 6pt; page-break-after: avoid; }
        p { margin: 0 0 10pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background: #eef4f8; font-size: 7pt; color: #163348; text-align: left; padding: 9pt 7pt; }
        td { padding: 8pt 7pt; border-bottom: .5pt solid #d8e1e8; vertical-align: top; font-size: 8pt; word-wrap: break-word; }
        .plain td { border: none; padding: 4pt 0; }
        .meta td { width: 50%; padding: 6pt 10pt 8pt 0; border: none; font-size: 8.5pt; }
        .label { display: block; font-size: 6.8pt; color: #56687a; text-transform: uppercase; margin-bottom: 5pt; }
        .eyebrow { color: #244d6a; font-size: 7pt; text-transform: uppercase; margin-bottom: 10pt; }
        .number { font-family: 'DejaVu Sans', sans-serif; color: #244d6a; font-size: 21pt; font-weight: normal; }
        .status { background: #eef4f8; padding: 6pt 10pt; font-size: 7pt; color: #244d6a; }
        .approved { background: #eaf5ef; color: #216442; }
        .subject { font-size: 11pt; margin: 0 0 14pt; }
        .intro { font-size: 9pt; margin-bottom: 16pt; }
        .decision { border: .7pt solid #244d6a; border-left: 3pt solid #244d6a; padding: 12pt; margin-top: 18pt; }
        .decision-title { color: #244d6a; font-size: 7pt; font-weight: bold; margin-bottom: 8pt; page-break-after: avoid; }
        .muted { color: #56687a; font-size: 8pt; }
        .prose { white-space: pre-wrap; word-wrap: break-word; }
        .deadline { font-family: 'DejaVu Serif', serif; font-size: 15pt; color: #244d6a; margin-top: 5pt; }
        .page-break { page-break-before: always; }
        .changed td { background: #fff9ed; }
        .comparison td, .comparison th { padding-left: 6pt; padding-right: 6pt; }
        .comparison td { font-size: 7.4pt; }
        .comparison th { font-size: 6.5pt; }
        .deadline-box td { width: 50%; background: #eef4f8; border: none; padding: 12pt; }
        .event-caption { color: #244d6a; font-size: 8pt; }
        .history td { font-size: 7.3pt; padding-top: 5pt; padding-bottom: 5pt; }
        .record { border-top: .5pt solid #d8e1e8; margin-top: 12pt; padding-top: 2pt; }
        .record .caption { page-break-after: avoid; }
        .stage-heading { page-break-inside: avoid; }
        .stage-heading h1 { font-size: 21pt; margin: 4pt 0 7pt; }
        .stage-heading .meta td { padding: 4pt 8pt 6pt 0; font-size: 8pt; }
        .stage-heading .label { font-size: 6.5pt; margin-bottom: 3pt; }
        .stage-subject { font-size: 9pt; border-bottom: .5pt solid #d8e1e8; padding: 8pt 0 11pt; margin-bottom: 11pt; }
        .flow-entry { border-left: 1pt solid #d8e1e8; padding-left: 11pt; margin: 9pt 0; }
        .flow-entry p { margin-bottom: 5pt; font-size: 8pt; }
        .flow-entry .flow-caption { font-size: 7.8pt; font-weight: bold; color: #244d6a; page-break-after: avoid; }
        .flow-entry .flow-state { font-size: 7pt; color: #56687a; page-break-after: avoid; }
        .flow-entry .muted { font-size: 7.3pt; }
        .stage-person { font-size: 8pt; color: #56687a; margin-bottom: 12pt; }
    </style>
</head>
<body>
    <div class="header">
        @if($logo)<img src="{{ $logo }}" alt="Concremat">@else<span class="brand">Concremat</span>@endif
        <span class="header-label">OS DNIT · {{ $tenant->name }} · OS {{ $order->request_number }}</span>
    </div>

    @include('pdf.os-dnit-stage-header', ['sectionTitle' => 'Solicitação do analista DNIT'])
    <p class="stage-person">Analista DNIT: <strong>{{ $order->creator?->name ?? 'Usuário não disponível' }}</strong> · Prazo pretendido: <strong>{{ $date($order->desired_deadline) }}</strong></p>
    <h2>Descrição da necessidade técnica</h2><p class="prose">{{ $order->technical_need }}</p>
    <h2>Justificativa / resultado esperado</h2><p class="prose">{{ $order->justification }}</p>
    <h2>Produtos solicitados</h2>
    @include('pdf.os-dnit-products', ['items' => $requested])
    <h3>Anexos de referência</h3>
    @forelse($order->attachments as $attachment)<p class="muted">{{ $attachment->original_name }}</p>@empty<p class="muted">Nenhum anexo.</p>@endforelse
    @if($order->attachments->isNotEmpty())<p class="muted">Os arquivos estão relacionados acima; seus conteúdos não integram este PDF.</p>@endif

    @if($includeConcremat)
    <div class="page-break"></div>
    @include('pdf.os-dnit-stage-header', ['sectionTitle' => 'Análise Concremat'])
    <h2>Análise Concremat</h2>
    @if($hasReview)
        <p class="stage-person">{{ $order->analystReview?->name ?? 'Responsável não registrado' }} · Fiscal Concremat · {{ $time($order->reviewed_at) }}</p>
        <table class="deadline-box"><tr><td><span class="label">Prazo solicitado pelo analista</span>{{ $date($order->desired_deadline) }}</td><td><span class="label">Prazo estimado pela Concremat</span>{{ $date($reviewedDeadline) }}</td></tr></table>
        <h3>Comentário da análise</h3><p class="prose">{{ filled($reviewComment) ? $reviewComment : 'Comentário da análise não registrado.' }}</p>
        <h2>Produtos: solicitação do analista × Análise Concremat</h2>
        @include('pdf.os-dnit-comparison', ['rows' => $comparison])
    @else<p>Análise Concremat ainda não registrada.</p>@endif
    @endif

    @if($includeInspector)
    <div class="page-break"></div>
    @include('pdf.os-dnit-stage-header', ['sectionTitle' => 'Análise do fiscal do contrato'])
    <h2>Decisão do fiscal do contrato</h2>
    @if($decision)
        <p class="stage-person">{{ $decision->actor?->name ?? 'Responsável não registrado' }} · Fiscal do contrato · {{ $time($decision->created_at) }}</p>
        <p><span class="status {{ $decision->action === 'approved' ? 'approved' : '' }}">{{ $decision->action === 'approved' ? 'Serviço aprovado' : ($statuses[$decision->to_status] ?? 'Reprovada e devolvida') }}</span></p>
        @if($decision->action === 'approved')
            <p class="intro">Fica autorizada a execução dos serviços abaixo, conforme a Análise Concremat aprovada.</p>
        @else
            <p>Esta decisão devolveu a solicitação para correção e não autoriza a execução do serviço.</p>
            @if($order->status !== $decision->to_status)<p class="muted">A OS teve novas movimentações após esta decisão. Situação atual: {{ $statuses[$order->status] ?? $order->status }}.</p>@endif
        @endif
        <h3>{{ $decision->action === 'approved' ? 'Comentário da decisão' : 'Motivo da reprovação' }}</h3>
        <p class="prose">{{ filled($decision->comment) ? $decision->comment : 'Comentário da decisão não registrado.' }}</p>
        <h2>{{ $decision->action === 'approved' ? 'Serviços autorizados' : 'Produtos avaliados nesta decisão' }}</h2>
        @include('pdf.os-dnit-products', ['items' => $decisionItems])
        <div style="margin-top: 16pt"><span class="label">{{ $decision->action === 'approved' ? 'Prazo autorizado' : 'Prazo avaliado nesta decisão' }}</span><p class="deadline">{{ $date($decisionDeadline) }}</p></div>
    @else
        <p>Decisão do fiscal do contrato ainda não registrada. A execução ainda não foi autorizada pelo fiscal do contrato.</p>
    @endif
    @endif

    @if($includeFlow)
    <div class="page-break"></div>
    @include('pdf.os-dnit-stage-header', ['sectionTitle' => 'Histórico do fluxo', 'compact' => true])
    <h2>Histórico das movimentações</h2>
    @forelse($events as $event)
        @php($record = $flowRecords->get($event->id))
        <div class="flow-entry">
            <p class="flow-caption">{{ $time($event->created_at) }} · {{ $actions[$event->action] ?? $event->action }} · {{ $event->actor?->name ?? 'Usuário não disponível' }}</p>
            <p class="flow-state">{{ $statuses[$event->to_status] ?? $event->to_status }}</p>
            @if($record)
                @if(filled($event->comment))<p class="prose">{{ $event->comment }}</p>@endif
                @if($event->action === 'draft_updated')
                    <p><strong>Assunto:</strong> {{ $event->snapshot['subject'] ?? 'Não registrado' }} · <strong>Prazo solicitado:</strong> {{ $date($event->snapshot['desired_deadline'] ?? null) }}</p>
                    <p class="prose">{{ $event->snapshot['technical_need'] ?? '' }}</p><p class="prose">{{ $event->snapshot['justification'] ?? '' }}</p>
                @endif
                @if($record['analysisChanged'])
                    <p class="muted">Prazo registrado nesta análise: <strong>{{ $date($record['deadline']) }}</strong></p>
                    @foreach($record['comparison'] as $row)<p class="muted"><strong>{{ $row['product']['product_code'] }}</strong> · {{ $row['product']['product_name'] }}: {{ $row['reviewed'] ? $number($row['reviewed']['quantity']).' '.$row['reviewed']['unit'] : 'Não incluído na análise' }} · {{ $row['label'] }}.</p>@endforeach
                @endif
            @endif
        </div>
    @empty<p class="muted">OS cadastrada antes deste fluxo. Histórico anterior não disponível.</p>@endforelse
    @endif
</body>
</html>
