<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 26px 30px; }
        body {
            color: #182033;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
        }
        h1, h2, h3, p { margin: 0; }
        .header {
            border-bottom: 2px solid #0b5fff;
            padding-bottom: 14px;
        }
        .title {
            color: #0b1020;
            font-size: 22px;
            font-weight: 700;
        }
        .subtitle {
            color: #5b6479;
            font-size: 11px;
            margin-top: 4px;
        }
        .document-header {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }
        .document-header td {
            vertical-align: middle;
        }
        .document-logo-cell {
            width: 28%;
        }
        .document-title-cell {
            text-align: center;
            width: 44%;
        }
        .logo-panel {
            border: 1px solid #e3e7ef;
            padding: 7px;
        }
        .logo-panel-right {
            text-align: right;
        }
        .logo-box {
            height: 54px;
            margin-top: 5px;
            text-align: center;
        }
        .logo-box img {
            display: block;
            height: 54px;
            margin: 0 auto;
            width: 144px;
        }
        .logo-placeholder {
            border: 1px dashed #cfd6e3;
            color: #5b6479;
            font-size: 10px;
            font-weight: 700;
            height: 44px;
            line-height: 1.25;
            padding: 10px 6px 0;
            text-align: center;
        }
        .section {
            margin-top: 18px;
        }
        .section-title {
            border-bottom: 1px solid #d8dde7;
            color: #0b1020;
            font-size: 13px;
            font-weight: 700;
            padding-bottom: 6px;
            text-transform: uppercase;
        }
        .grid {
            display: table;
            margin-top: 10px;
            table-layout: fixed;
            width: 100%;
        }
        .row {
            display: table-row;
        }
        .cell {
            border: 1px solid #e3e7ef;
            display: table-cell;
            padding: 8px;
            vertical-align: top;
            width: 25%;
        }
        .label {
            color: #5b6479;
            display: block;
            font-size: 8.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .value {
            color: #182033;
            display: block;
            font-size: 11px;
            font-weight: 700;
            margin-top: 3px;
        }
        .badge {
            border-radius: 10px;
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            margin-right: 5px;
            padding: 3px 8px;
        }
        .badge-blue { background: #e7efff; color: #1d68d8; }
        .badge-red { background: #fde7eb; color: #c8364a; }
        .badge-amber { background: #fdf3d6; color: #b58105; }
        .text-box {
            border: 1px solid #e3e7ef;
            margin-top: 10px;
            padding: 10px;
        }
        .text-box + .text-box {
            margin-top: 8px;
        }
        .text-box h3 {
            color: #5b6479;
            font-size: 9px;
            letter-spacing: 0.04em;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        .photo-page {
            page-break-before: always;
        }
        .photo-grid {
            border-collapse: separate;
            border-spacing: 8px;
            margin-top: 10px;
            table-layout: fixed;
            width: 100%;
        }
        .photo-cell {
            border: 1px solid #e3e7ef;
            height: 292px;
            padding: 6px;
            vertical-align: top;
            width: 50%;
        }
        .photo-frame {
            height: 240px;
            overflow: hidden;
            text-align: center;
        }
        .photo img {
            display: block;
            height: 240px;
            margin: 0 auto;
            width: 100%;
        }
        .photo-caption {
            color: #5b6479;
            font-size: 9px;
            line-height: 1.35;
            margin-top: 5px;
        }
        .muted {
            color: #5b6479;
        }
        .flow-table {
            border-collapse: collapse;
            margin-top: 10px;
            width: 100%;
        }
        .flow-table th {
            background: #f6f8fb;
            border: 1px solid #d8dde7;
            color: #5b6479;
            font-size: 8.5px;
            letter-spacing: 0.04em;
            padding: 7px;
            text-align: left;
            text-transform: uppercase;
        }
        .flow-table td {
            border: 1px solid #e3e7ef;
            padding: 7px;
            vertical-align: top;
        }
        .flow-status {
            color: #0b1020;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <section class="header">
        @php
            $graveBadgeClass = $rnc->gravidade === 'Leve'
                ? 'badge-blue'
                : (in_array($rnc->gravidade, ['Media', 'Média', 'MÃ©dia'], true) ? 'badge-amber' : 'badge-red');
        @endphp
        <table class="document-header">
            <tr>
                <td class="document-logo-cell">
                    <div class="logo-panel">
                        <div class="logo-box">
                            @if ($contratanteLogo)
                                <img src="{{ $contratanteLogo }}" alt="Logo Contratante">
                            @else
                                <div class="logo-placeholder">{{ $rnc->contratante?->nome ?: 'Empresa sem logo' }}</div>
                            @endif
                        </div>
                    </div>
                </td>
                <td class="document-title-cell">
                    <h1 class="title">Relat&oacute;rio de N&atilde;o Conformidade</h1>
                    <p class="subtitle">
                        {{ $tenant->name }} &middot; RNC {{ $rnc->formatted_number }}
                    </p>
                    @if ($rnc->projectDocument)
                        <p class="subtitle">
                            Projeto vinculado: {{ $rnc->projectDocument->code ?: 'Sem codigo' }} - {{ $rnc->projectDocument->title }}
                        </p>
                    @endif
                </td>
                <td class="document-logo-cell">
                    <div class="logo-panel logo-panel-right">
                        <div class="logo-box">
                            @if ($contratadaLogo)
                                <img src="{{ $contratadaLogo }}" alt="Logo Contratada">
                            @else
                                <div class="logo-placeholder">{{ $rnc->contratada?->nome ?: 'Empresa sem logo' }}</div>
                            @endif
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="grid">
            <div class="row">
                <div class="cell">
                    <span class="label">Contrato</span>
                    <span class="value">{{ $rnc->contract?->code }} - {{ $rnc->contract?->name }}</span>
                </div>
                <div class="cell">
                    <span class="label">Obra</span>
                    <span class="value">{{ $rnc->obra?->codigo }} - {{ $rnc->obra?->nome }}</span>
                </div>
                <div class="cell">
                    <span class="label">Data de abertura</span>
                    <span class="value">{{ $rnc->opened_at?->format('d/m/Y') }}</span>
                </div>
                <div class="cell">
                    <span class="label">Prazo para resposta de a&ccedil;&atilde;o corretiva</span>
                    <span class="value">{{ $rnc->prazo_resposta_acao_corretiva?->format('d/m/Y') }}</span>
                </div>
            </div>
            <div class="row">
                <div class="cell">
                    <span class="label">Contratante</span>
                    <span class="value">{{ $rnc->contratante?->sigla ?: $rnc->contratante?->nome }}</span>
                </div>
                <div class="cell">
                    <span class="label">Contratada</span>
                    <span class="value">{{ $rnc->contratada?->sigla ?: $rnc->contratada?->nome }}</span>
                </div>
                <div class="cell">
                    <span class="label">Local</span>
                    <span class="value">{{ $rnc->contract?->city ?: '-' }}{{ $rnc->contract?->state ? ' / '.$rnc->contract->state : '' }}</span>
                </div>
                <div class="cell">
                    <span class="label">Coordenadas</span>
                    <span class="value">{{ $rnc->latitude && $rnc->longitude ? $rnc->latitude.', '.$rnc->longitude : '-' }}</span>
                </div>
            </div>
            <div class="row">
                <div class="cell">
                    <span class="label">Disciplina</span>
                    <span class="badge badge-blue">
                        {{ $rnc->disciplina?->sigla ? $rnc->disciplina->sigla.' - '.$rnc->disciplina->nome : ($rnc->disciplina?->nome ?: $rnc->natureza) }}
                    </span>
                </div>
                <div class="cell">
                    <span class="label">Gravidade</span>
                    <span class="badge {{ $graveBadgeClass }}">{{ $rnc->gravidade }}</span>
                </div>
                <div class="cell">
                    <span class="label">Status</span>
                    <span class="value">{{ $rnc->status }}</span>
                </div>
                <div class="cell">
                    <span class="label">Criado por</span>
                    <span class="value">{{ $rnc->creator?->name ?: '-' }}</span>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <h2 class="section-title">Observa&ccedil;&otilde;es e coment&aacute;rios</h2>
        <div class="text-box">
            <h3>Descri&ccedil;&atilde;o do problema</h3>
            <p>{{ $rnc->descricao_problema }}</p>
        </div>
        <div class="text-box">
            <h3>Observa&ccedil;&atilde;o</h3>
            <p>{{ $rnc->observacao ?: 'Sem observa&ccedil;&otilde;es adicionais.' }}</p>
        </div>
        <div class="text-box">
            <h3>A&ccedil;&otilde;es corretivas recomendadas</h3>
            <p>{{ $rnc->acoes_corretivas_recomendadas }}</p>
        </div>
    </section>

    @forelse ($photos->chunk(6) as $photoPage)
        <section class="section photo-page">
            <h2 class="section-title">Imagens</h2>
            <table class="photo-grid">
                @foreach ($photoPage->chunk(2) as $photoRow)
                    <tr>
                        @foreach ($photoRow as $photo)
                            <td class="photo-cell">
                                <div class="photo-frame photo">
                                    @if ($photo['data_uri'])
                                        <img src="{{ $photo['data_uri'] }}" alt="Registro fotografico {{ $photo['position'] }}">
                                    @else
                                        <p class="muted">
                                            @if ($photo['needs_raster_extension'] && ! $canRasterizePdfImages)
                                                Imagem cadastrada, mas imagens PNG/WebP precisam da extens&atilde;o GD ou Imagick habilitada no PHP para aparecer no PDF. Envie imagens JPG/JPEG ou habilite a extens&atilde;o.
                                            @elseif ($canRasterizePdfImages)
                                                Imagem indispon&iacute;vel no armazenamento.
                                            @else
                                                Imagem cadastrada, mas n&atilde;o foi poss&iacute;vel embutir no PDF.
                                            @endif
                                        </p>
                                    @endif
                                </div>
                                <p class="photo-caption">
                                    Imagem {{ $photo['position'] }}
                                    @if ($photo['comment'])
                                        &middot; {{ $photo['comment'] }}
                                    @endif
                                </p>
                            </td>
                        @endforeach

                        @if ($photoRow->count() === 1)
                            <td class="photo-cell">&nbsp;</td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </section>
    @empty
        <section class="section photo-page">
            <h2 class="section-title">Imagens</h2>
            <p class="muted">Nenhuma imagem cadastrada nesta RNC.</p>
        </section>
    @endforelse

    <section class="section">
        <h2 class="section-title">Proposta de a&ccedil;&atilde;o corretiva aprovada</h2>
        @if ($approvedAction)
            <div class="text-box">
                <h3>Proposta aprovada</h3>
                <p>{{ $approvedAction->descricao_proposta }}</p>
            </div>
            <div class="grid">
                <div class="row">
                    <div class="cell">
                        <span class="label">Respons&aacute;vel pela proposta</span>
                        <span class="value">{{ $approvedAction->user?->name ?: '-' }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">Enviada em</span>
                        <span class="value">{{ $approvedAction->submitted_at?->format('d/m/Y H:i') ?: '-' }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">Prazo de execu&ccedil;&atilde;o proposto</span>
                        <span class="value">{{ $approvedAction->prazo_execucao_proposto?->format('d/m/Y') ?: '-' }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">Aprovada por</span>
                        <span class="value">{{ $approvedAction->reviewer?->name ?: '-' }}</span>
                    </div>
                </div>
                <div class="row">
                    <div class="cell">
                        <span class="label">Aprovada em</span>
                        <span class="value">{{ $approvedAction->reviewed_at?->format('d/m/Y H:i') ?: '-' }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">Arquivo anexado</span>
                        <span class="value">{{ $approvedAction->attachment_original_name ?: '-' }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">Status da proposta</span>
                        <span class="value">{{ $approvedAction->status }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">RNC</span>
                        <span class="value">{{ $rnc->formatted_number }}</span>
                    </div>
                </div>
            </div>
            @if ($approvedAction->review_observation)
                <div class="text-box">
                    <h3>Observa&ccedil;&atilde;o da an&aacute;lise</h3>
                    <p>{{ $approvedAction->review_observation }}</p>
                </div>
            @endif
        @else
            <p class="muted">Nenhuma proposta de a&ccedil;&atilde;o corretiva aprovada at&eacute; o momento.</p>
        @endif
    </section>

    <section class="section">
        <h2 class="section-title">Evid&ecirc;ncias da corre&ccedil;&atilde;o</h2>
        @if ($latestEvidence)
            <div class="grid">
                <div class="row">
                    <div class="cell">
                        <span class="label">Enviado por</span>
                        <span class="value">{{ $latestEvidence->user?->name ?: '-' }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">Enviado em</span>
                        <span class="value">{{ $latestEvidence->submitted_at?->format('d/m/Y H:i') ?: '-' }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">Arquivo anexado</span>
                        <span class="value">{{ $latestEvidence->attachment_original_name ?: '-' }}</span>
                    </div>
                    <div class="cell">
                        <span class="label">Status</span>
                        <span class="value">{{ $rnc->finalized_at ? 'RNC finalizada' : $rnc->status }}</span>
                    </div>
                </div>
            </div>

            @forelse ($evidencePhotos->chunk(6) as $photoPage)
                <table class="photo-grid">
                    @foreach ($photoPage->chunk(2) as $photoRow)
                        <tr>
                            @foreach ($photoRow as $photo)
                                <td class="photo-cell">
                                    <div class="photo-frame photo">
                                        @if ($photo['data_uri'])
                                            <img src="{{ $photo['data_uri'] }}" alt="Evidencia {{ $photo['position'] }}">
                                        @else
                                            <p class="muted">Imagem de evid&ecirc;ncia indispon&iacute;vel no PDF.</p>
                                        @endif
                                    </div>
                                    <p class="photo-caption">
                                        Evid&ecirc;ncia {{ $photo['position'] }}
                                        @if ($photo['comment'])
                                            &middot; {{ $photo['comment'] }}
                                        @elseif ($photo['original_name'])
                                            &middot; {{ $photo['original_name'] }}
                                        @endif
                                    </p>
                                </td>
                            @endforeach

                            @if ($photoRow->count() === 1)
                                <td class="photo-cell">&nbsp;</td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            @empty
                <p class="muted">Nenhuma imagem de evid&ecirc;ncia cadastrada.</p>
            @endforelse
        @else
            <p class="muted">Nenhuma evid&ecirc;ncia de corre&ccedil;&atilde;o enviada ainda.</p>
        @endif
    </section>

    <section class="section">
        <h2 class="section-title">Fluxo da RNC</h2>
        <table class="flow-table">
            <thead>
                <tr>
                    <th>Etapa</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th>Respons&aacute;vel</th>
                    <th>Detalhe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($flowRows as $row)
                    <tr>
                        <td>{{ $row['etapa'] }}</td>
                        <td class="flow-status">{{ $row['status'] }}</td>
                        <td>{{ $row['data'] }}</td>
                        <td>{{ $row['responsavel'] }}</td>
                        <td>{{ $row['detalhe'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</body>
</html>
