<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    @php
        $formNumber = sprintf('%03d/%d', $rnc->sequence_number ?: $rnc->id, $rnc->sequence_year ?: ($rnc->opened_at?->year ?? now()->year));
        $normalize = fn ($value) => mb_strtolower(\Illuminate\Support\Str::ascii(trim((string) $value)));
        $nature = $rnc->disciplina?->nome ?: $rnc->natureza;
        $natures = ['Terraplanagem', 'Qualidade', 'Pavimentação', 'Ambiental', 'Sinalização', 'Drenagem', 'Fundação'];
        $isClosed = $rnc->finalized_at || $rnc->status === 'finalizada';
        $extraLines = max(0, (int) ceil(mb_strlen($pdfOptions['title']) / 90) - 1)
            + max(0, (int) ceil(mb_strlen($rnc->contratante?->nome ?? '') / 115) - 1)
            + max(0, (int) ceil(mb_strlen($rnc->contratada?->nome ?? '') / 115) - 1)
            + max(0, (int) ceil(mb_strlen($rnc->obra?->nome ?? '') / 115) - 1);
        $headerHeight = 291 + $extraLines * 9 + $additionalHeaderHeight;
        // Large sets of fields flow on the opening pages instead of occupying every page.
        $flowingHeader = $headerHeight > 400;
        $pageTop = $flowingHeader ? 9 : 9 + $headerHeight;
    @endphp
    <style>
        @page { margin: {{ $pageTop }}pt 9pt 24pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7.5pt; line-height: 1.45; color: #111827; }
        * { box-sizing: border-box; }
        p, h1, h2, h3 { margin: 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td, th { vertical-align: middle; overflow-wrap: break-word; word-wrap: break-word; }
        .header { position: {{ $flowingHeader ? 'static' : 'fixed' }}; top: {{ 9 - $pageTop }}pt; left: 0; right: 0; }
        .footer { position: fixed; bottom: -15pt; right: 0; color: #637080; font-size: 6pt; }
        .page-number:before { content: counter(page); }
        .logos { border: 1.3pt solid #1b1b1b; }
        .logos td { width: 50%; padding: 0 8pt; height: 39pt; font-size: 8pt; font-weight: bold; }
        .logo-holder { position: relative; height: 38pt; overflow: hidden; }
        .logo-holder img { position: absolute; top: -5.75pt; left: 0; width: 132pt; height: 49.5pt; }
        .logo-holder.right img { top: -2pt; left: auto; right: 0; width: 112pt; height: 42pt; }
        .logo-name { padding-top: 12pt; }
        .right { text-align: right; }
        .bar { background: {{ $pdfOptions['color'] }}; color: #fff; border: 1.3pt solid #1b1b1b; text-align: center; font-size: 9pt; line-height: 1.2; font-weight: bold; padding: 6pt 4pt; page-break-after: avoid; }
        .document-title { font-size: 9.8pt; padding: 4.5pt 4pt; border-top: 0; }
        .identity { position: relative; border: 1.3pt solid #1b1b1b; border-top: 0; background: {{ $pdfOptions['color'] }}; }
        .identity-main { margin-left: 25.5pt; padding: 3pt 4pt; background: #fff; border-left: 1pt solid #1b1b1b; }
        .identity-table { font-size: 6.8pt; font-weight: bold; }
        .identity-table td { padding: 1.65pt 3pt; }
        .field-box { display: block; min-height: 12.7pt; padding: 2pt 3pt; border: .65pt solid #111; background: #fff; color: #111827; font-size: 6.8pt; font-weight: normal; line-height: 1.25; }
        .field-center { text-align: center; }
        .label-right { text-align: right; white-space: nowrap; }
        .model-header-fields tr { page-break-inside: avoid; }
        .model-field-label { white-space: normal; }
        .model-field-value { overflow-wrap: anywhere; word-wrap: break-word; }
        .choice.model-field-choice { white-space: normal; max-width: 100%; }
        .check { display: inline-block; width: 10pt; height: 10pt; border: .65pt solid #111; color: #e60026; font-family: DejaVu Sans, sans-serif; font-size: 7pt; font-weight: bold; text-align: center; line-height: 10pt; vertical-align: middle; margin-right: 3pt; }
        .choice { display: inline-block; margin: 1pt 6pt 1pt 0; white-space: nowrap; font-size: 6.6pt; }
        .rail-block { position: relative; background: {{ $pdfOptions['color'] }}; border: 1.3pt solid #1b1b1b; border-top: 0; }
        .rail-content { margin-left: 25.5pt; background: #fff; border-left: 1pt solid #1b1b1b; }
        .rail-label { position: absolute; left: -43.5pt; top: 80pt; width: 112pt; margin-top: -5pt; transform: rotate(-90deg); color: #fff; text-align: center; font-family: DejaVu Serif, serif; font-size: 8pt; line-height: 10pt; white-space: nowrap; }
        .identity .rail-label { top: 110pt; }
        .recommended-action .rail-label { top: 68pt; }
        .workflow-block .rail-label { top: 70pt; }
        .box { padding: 7pt; overflow-wrap: break-word; word-wrap: break-word; }
        .box-title { font-weight: bold; margin-bottom: 4pt; }
        .occurrence { min-height: 64pt; }
        .observation-text { min-height: 80pt; }
        .recommended-text { min-height: 102pt; }
        .inner-bar { border-left: 0; border-right: 0; font-size: 8.5pt; padding: 5pt 4pt; }
        .muted { color: #637080; }
        .text { white-space: normal; }
        .spaced { margin-top: 7pt; }
        .page { page-break-before: always; }
        .photos-content { padding: 9pt 10pt; }
        .photos { border-collapse: separate; border-spacing: 5pt 5pt; }
        .photos td { width: 50%; border: 1.3pt solid #1b1b1b; padding: 5pt; font-size: 6.8pt; vertical-align: top; }
        .photos tr { page-break-inside: avoid; }
        .photo-frame { height: 156pt; text-align: center; }
        .photo-frame img { width: 226pt; height: 153.68pt; }
        .photo-placeholder { padding: 58pt 6pt; color: #637080; }
        .caption { margin-top: 4pt; font-size: 6.8pt; line-height: 1.3; }
        .empty-photo { border-color: transparent !important; }
        .info td { padding: 5pt; border: .65pt solid #1b1b1b; font-size: 6.8pt; }
        .flow { font-size: 6.8pt; }
        .flow th { background: #edf2f7; text-align: left; padding: 5pt; border: 1pt solid #1b1b1b; }
        .flow td { padding: 5pt; border: 1pt solid #1b1b1b; vertical-align: top; }
        .signature-page { position: relative; height: {{ 790 - $pageTop }}pt; }
        .signature-label { position: absolute; top: {{ 680 - $pageTop }}pt; width: 112pt; font-size: 7pt; text-align: center; }
        .signature-widget { position: absolute; top: {{ 710 - $pageTop }}pt; width: 112pt; height: 34pt; border: .5pt dashed #9aa5b3; }
    </style>
</head>
<body>
    <footer class="footer">RNC {{ $formNumber }} · Página <span class="page-number"></span></footer>
    <header class="header">
        <table class="logos"><tr>
            <td><div class="logo-holder">@if ($contratanteLogo)<img src="{{ $contratanteLogo }}" alt="Logo esquerdo">@else<p class="logo-name">{{ $leftLogoName }}</p>@endif</div></td>
            <td><div class="logo-holder right">@if ($contratadaLogo)<img src="{{ $contratadaLogo }}" alt="Logo direito">@else<p class="logo-name">{{ $rightLogoName }}</p>@endif</div></td>
        </tr></table>
        <div class="bar document-title">{{ $pdfOptions['title'] }}</div>
        <div class="identity">
            <div class="rail-label">IDENTIFICAÇÃO</div>
            <div class="identity-main">
                <table class="identity-table"><tr>
                    <td style="width:9%" class="label-right">RNC Nº:</td><td style="width:14%"><span class="field-box field-center">{{ $formNumber }}</span></td>
                    <td style="width:19%" class="label-right">Contrato:</td><td style="width:20%"><span class="field-box field-center document-contract">{{ $inspectionContract !== '' ? $inspectionContract : '-' }}</span></td>
                    <td style="width:8%" class="label-right">Data:</td>
                    <td style="width:8%"><span class="field-box field-center">{{ $rnc->opened_at?->format('d') ?: '-' }}</span></td>
                    <td style="width:8%"><span class="field-box field-center">{{ $rnc->opened_at?->format('m') ?: '-' }}</span></td>
                    <td style="width:14%"><span class="field-box field-center">{{ $rnc->opened_at?->format('Y') ?: '-' }}</span></td>
                </tr></table>
                <table class="identity-table"><tr>
                    <td style="width:9%" class="label-right">Status:</td><td style="width:80%">
                        <span class="choice"><span class="check">{{ !$isClosed ? 'X' : '' }}</span>Aberta</span>
                        <span class="choice"><span class="check">{{ $isClosed ? 'X' : '' }}</span>Finalizada</span>
                        @if ($rnc->status === 'em_assinatura')<span class="muted">Aguardando assinaturas</span>@endif
                    </td><td style="width:11%"></td>
                </tr>
                @foreach (['Obra:' => $rnc->obra?->nome, 'Contratante:' => $rnc->contratante?->nome, 'Contratada:' => $rnc->contratada?->nome] as $label => $value)
                    <tr><td class="label-right">{{ $label }}</td><td><span class="field-box">{{ $value ?: '-' }}</span></td><td></td></tr>
                @endforeach
                </table>
                <table class="identity-table"><tr>
                    <td style="width:24%" class="label-right">Localização da RNC:</td><td style="width:22%"><span class="field-box document-location">{{ $inspectionLocation !== '' ? $inspectionLocation : '-' }}</span></td>
                    <td style="width:6%" class="label-right">KM:</td><td style="width:17%"><span class="field-box document-km">{{ $inspectionKm !== '' ? $inspectionKm : '-' }}</span></td>
                    <td style="width:14%" class="label-right">Coord. N:</td><td style="width:17%"><span class="field-box field-center">{{ $rnc->latitude ?? '-' }}</span></td>
                </tr><tr><td colspan="4"></td><td class="label-right">Coord. E:</td><td><span class="field-box field-center">{{ $rnc->longitude ?? '-' }}</span></td></tr></table>
                <table class="identity-table"><tr>
                    <td style="width:14%" class="label-right">{{ collect($modelFields)->firstWhere('key', 'work_type')['label'] ?? 'Tipo de Obra' }}:</td><td style="width:41%">
                        @foreach (['Pista Nova', 'Pista Existente'] as $type)<span class="choice"><span class="check">{{ $normalize($pdfOptions['work_type']) === $normalize($type) ? 'X' : '' }}</span>{{ $type }}</span>@endforeach
                    </td><td style="width:21%" class="label-right">{{ collect($modelFields)->firstWhere('key', 'pavement_type')['label'] ?? 'Tipo de Pavimento' }}:</td><td style="width:24%">
                        @foreach (['Rígido', 'Flexível'] as $type)<span class="choice"><span class="check">{{ $normalize($pdfOptions['pavement_type']) === $normalize($type) ? 'X' : '' }}</span>{{ $type }}</span>@endforeach
                    </td>
                </tr></table>
                <table class="identity-table"><tr>
                    <td style="width:14%" class="label-right">Natureza:</td>
                    @foreach (array_slice($natures, 0, 5) as $item)<td style="width:17.2%"><span class="choice"><span class="check">{{ $normalize($nature) === $normalize($item) ? 'X' : '' }}</span>{{ $item }}</span></td>@endforeach
                </tr><tr><td></td>
                    @foreach (array_slice($natures, 5) as $item)<td><span class="choice"><span class="check">{{ $normalize($nature) === $normalize($item) ? 'X' : '' }}</span>{{ $item }}</span></td>@endforeach
                    <td colspan="3">@if (!collect($natures)->contains(fn ($item) => $normalize($nature) === $normalize($item)))<span class="choice">Outra: {{ $nature ?: '-' }}</span>@endif</td>
                </tr><tr><td class="label-right">Grau:</td>
                    @foreach (['Leve', 'Média', 'Grave', 'Gravíssima'] as $item)<td><span class="choice"><span class="check">{{ $normalize($rnc->gravidade) === $normalize($item) ? 'X' : '' }}</span>{{ $item }}</span></td>@endforeach<td></td>
                </tr></table>
                @include('pdf.partials.rnc-model-fields', ['fields' => $inspectionHeaderFields, 'layout' => 'inspection_form'])
            </div>
        </div>
    </header>

    <div class="bar">Descrição da Ocorrência</div>
    <div class="rail-block opening-observations">
        <div class="rail-label">OBSERVAÇÕES</div>
        <div class="rail-content">
            <div class="box occurrence"><p class="box-title">{{ $rnc->assunto }}</p>
                @if ($rnc->instrucaoCampo)<p class="muted">IC de referência: {{ $rnc->instrucaoCampo->formatted_number }} - {{ $rnc->instrucaoCampo->assunto }}</p>@endif
                <div class="text">{!! nl2br(e($rnc->descricao_problema)) !!}</div>
            </div>
            <div class="bar inner-bar">Observações</div>
            <div class="box observation-text">
                @if ($rnc->observacao)<div class="text">{!! nl2br(e($rnc->observacao)) !!}</div>@endif
                @if ($pdfOptions['contract_notes'])<div class="text {{ $rnc->observacao ? 'spaced' : '' }}">{!! nl2br(e($pdfOptions['contract_notes'])) !!}</div>@endif
                @if (!$rnc->observacao && !$pdfOptions['contract_notes'])<span class="muted">Sem observações adicionais.</span>@endif
            </div>
        </div>
    </div>
    <div class="rail-block recommended-action">
        <div class="rail-label">OBSERVAÇÕES</div>
        <div class="rail-content"><div class="bar inner-bar">Ação Corretiva Recomendada</div>
            <div class="box text recommended-text">{!! nl2br(e($rnc->acoes_corretivas_recomendadas)) !!}
                <p class="spaced"><strong>Prazo de resposta:</strong> {{ $rnc->prazo_resposta_acao_corretiva?->format('d/m/Y') ?: 'Não informado' }}</p>
            </div>
        </div>
    </div>
    @php $projects = $rnc->projectDocuments->isNotEmpty() ? $rnc->projectDocuments : collect($rnc->projectDocument ? [$rnc->projectDocument] : []); @endphp
    @if ($projects->isNotEmpty())
        <div class="box"><strong>Projetos vinculados:</strong>@foreach ($projects as $project)<p>{{ $project->eap($project->latestVersion?->revision) }} - {{ $project->title }}</p>@endforeach</div>
    @endif

    @foreach ($photos->chunk(4) as $photoPage)
        <section class="page"><div class="bar">Registro Fotográfico</div>
            @include('pdf.rnc-form-photos', ['photoPage' => $photoPage, 'photoLabel' => 'Não conformidade'])
        </section>
    @endforeach

    @if ($includeAnalysis)
        <section class="page"><div class="rail-block workflow-block"><div class="rail-label">AÇÃO CORRETIVA</div><div class="rail-content"><div class="bar inner-bar">Proposta de ação corretiva</div>
            @if ($latestAction)
                <table class="info"><tr><td><strong>Enviada por:</strong> {{ $latestAction->user?->name }}</td><td><strong>Data:</strong> {{ $latestAction->submitted_at?->format('d/m/Y H:i') }}</td><td><strong>Prazo proposto:</strong> {{ $latestAction->prazo_execucao_proposto?->format('d/m/Y') }}</td></tr></table>
                <div class="box text">{!! nl2br(e($latestAction->descricao_proposta)) !!}
                    @if ($latestAction->attachment_original_name)<p class="spaced"><strong>Documento anexado:</strong> {{ $latestAction->attachment_original_name }}</p>@endif
                </div>
            @else<div class="box muted">Nenhuma proposta registrada.</div>@endif
            <div class="bar spaced">Análise da Supervisão</div>
            @if ($latestAction)
                <table class="info"><tr><td><strong>Situação:</strong> {{ ['pending' => 'Em análise', 'approved' => 'Aprovada', 'rejected' => 'Reprovada'][$latestAction->status] ?? $latestAction->status }}</td><td><strong>Analisada por:</strong> {{ $latestAction->reviewer?->name ?: '-' }}</td><td><strong>Data:</strong> {{ $latestAction->reviewed_at?->format('d/m/Y H:i') ?: '-' }}</td></tr></table>
                <div class="box text">{!! nl2br(e($latestAction->review_observation ?: 'Sem observações adicionais.')) !!}</div>
            @else<div class="box muted">Aguardando proposta para análise.</div>@endif
        </div></div></section>
    @endif

    @if ($includeEvidence)
        <section class="page"><div class="rail-block workflow-block"><div class="rail-label">EVIDÊNCIAS</div><div class="rail-content"><div class="bar inner-bar">Evidências da correção</div>
            @if ($latestEvidence)
                <table class="info"><tr><td><strong>Registradas por:</strong> {{ $latestEvidence->user?->name }}</td><td><strong>Data:</strong> {{ $latestEvidence->submitted_at?->format('d/m/Y H:i') }}</td></tr></table>
                <div class="box"><p><strong>O problema foi resolvido?</strong> {{ $latestEvidence->resolution_label }}</p>
                    @if ($latestEvidence->resolution_reason)<p class="box-title spaced">Motivos / pendências:</p><div class="text">{!! nl2br(e($latestEvidence->resolution_reason)) !!}</div>@endif
                    @if ($latestEvidence->attachment_original_name)<p class="spaced"><strong>Documento anexado:</strong> {{ $latestEvidence->attachment_original_name }}</p>@endif
                </div>
            @else<div class="box muted">Nenhuma evidência registrada.</div>@endif
            <div class="bar spaced">Acompanhamento das etapas</div>
            <table class="flow"><thead><tr><th>Etapa</th><th>Situação</th><th>Data</th><th>Responsável</th></tr></thead><tbody>
                @foreach ($flowRows as $row)<tr><td>{{ $row['etapa'] }}</td><td>{{ $row['status'] }}</td><td>{{ $row['data'] }}</td><td>{{ $row['responsavel'] }}</td></tr>@endforeach
            </tbody></table>
        </div></div></section>
        @foreach ($evidencePhotos->chunk(4) as $photoPage)
            <section class="page"><div class="bar">Registro Fotográfico - Evidências da correção</div>
                @include('pdf.rnc-form-photos', ['photoPage' => $photoPage, 'photoLabel' => 'Evidência da correção'])
            </section>
        @endforeach
    @endif

    @if ($signatureSigners)
        <section class="page signature-page"><div class="bar">Assinaturas das partes</div>
            <div class="box"><strong>RNC {{ $formNumber }}</strong> - {{ $rnc->assunto }}
                <p class="spaced">Documento que reúne a abertura, a proposta de ação corretiva, a análise e as evidências da correção.</p>
                @foreach ($signatureSigners as $signer)<p class="spaced"><strong>{{ \App\Models\RncSignatureSetting::ROLES[$signer['role']] }}</strong><br>{{ $signer['name'] }}<br>{{ $signer['email'] }}</p>@endforeach
            </div>
            @foreach ($signatureSigners as $signer)
                @php $left = ['construtora' => 42, 'gerenciadora' => 232, 'cliente' => 422][$signer['role']]; @endphp
                <div class="signature-label" style="left:{{ $left }}pt"><strong>{{ \App\Models\RncSignatureSetting::ROLES[$signer['role']] }}</strong><br>{{ $signer['name'] }}</div>
                <div class="signature-widget" style="left:{{ $left }}pt"></div>
            @endforeach
        </section>
    @endif
</body>
</html>
