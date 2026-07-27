<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $version->cap_number }}</title>
    <style>
        @page { margin: 34px 42px 36px; }
        * { box-sizing: border-box; }
        body {
            color: #333333;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9.5px;
            line-height: 1.55;
            margin: 0;
        }
        table { border-collapse: collapse; width: 100%; }
        .brands { height: 64px; margin-bottom: 12px; table-layout: fixed; }
        .brand { vertical-align: middle; width: 33.333%; }
        .brand-center { text-align: center; }
        .brand-right { text-align: right; }
        .brand img { max-height: 48px; max-width: 145px; vertical-align: middle; }
        .brand-name {
            color: #4b5563;
            display: inline-block;
            font-size: 9.5px;
            font-weight: 700;
            max-width: 135px;
            vertical-align: middle;
        }
        .brand-left .brand-name { margin-left: 7px; }
        .brand-right .brand-name { margin-right: 7px; }
        .brand-initials { color: #1f4e79; font-size: 15px; font-weight: 700; }
        .heading { margin-bottom: 13px; table-layout: fixed; }
        .heading-title { padding-right: 24px; vertical-align: bottom; width: 64%; }
        .heading-meta { line-height: 1.65; text-align: right; vertical-align: bottom; width: 36%; }
        h1 {
            color: #163f67;
            font-size: 14px;
            line-height: 1.3;
            margin: 0;
        }
        .label { color: #163f67; font-weight: 700; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .project-line {
            border-bottom: 1px solid #737373;
            margin: 0 0 18px;
            padding: 8px 0 10px;
        }
        .rule { border-top: 1px solid #555555; height: 1px; margin: 0 0 20px; }
        .details { margin: 0 0 20px; table-layout: fixed; }
        .details td { padding: 0 18px 7px 0; vertical-align: top; width: 50%; }
        .details td:nth-child(2) { padding-right: 0; }
        .detail-label {
            color: #6b7280;
            display: block;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: .06em;
            margin-bottom: 1px;
            text-transform: uppercase;
        }
        .detail-value { color: #252525; font-weight: 600; overflow-wrap: break-word; }
        .section { margin-top: 18px; }
        .section-title {
            color: #163f67;
            font-size: 9.5px;
            font-weight: 700;
            margin: 0 0 8px;
        }
        .narrative {
            line-height: 1.65;
            margin: 0;
            text-align: justify;
            white-space: pre-line;
        }
        .impact-list { margin: 0; }
        .review { margin-top: 4px; table-layout: fixed; }
        .review td { padding: 9px 24px 8px 0; vertical-align: top; width: 50%; }
        .review td:nth-child(2) { padding-left: 24px; padding-right: 0; }
        .review-role { color: #163f67; font-weight: 700; margin-bottom: 3px; }
        .review-person { color: #252525; font-weight: 700; }
        .muted { color: #6b7280; font-size: 8px; }
        .review-note { line-height: 1.55; margin-top: 6px; white-space: pre-line; }
        .footer {
            bottom: -24px;
            color: #8a8a8a;
            font-size: 7px;
            left: 0;
            position: fixed;
            right: 0;
        }
        .footer-right { text-align: right; }
        .page-number:after { content: "Folha " counter(page) "/" counter(pages); }
    </style>
</head>
<body>
    <div class="footer">
        <table><tr>
            <td>{{ $tenant->name }} &middot; Documento gerado eletronicamente</td>
            <td class="footer-right page-number"></td>
        </tr></table>
    </div>

    <table class="brands">
        <tr>
            @foreach (['gerenciadora', 'cliente', 'construtora'] as $index => $role)
                @php($company = $branding[$role])
                <td class="brand {{ $index === 0 ? 'brand-left' : ($index === 1 ? 'brand-center' : 'brand-right') }}">
                    @if ($index === 2 && $company['name'])
                        <span class="brand-name">{{ $company['name'] }}</span>
                    @endif
                    @if ($company['logo_data_uri'])
                        <img src="{{ $company['logo_data_uri'] }}" alt="{{ $company['name'] }}">
                    @elseif ($company['sigla'])
                        <span class="brand-initials">{{ $company['sigla'] }}</span>
                    @endif
                    @if ($index !== 2 && $company['name'])
                        <span class="brand-name">{{ $company['name'] }}</span>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    <table class="heading">
        <tr>
            <td class="heading-title">
                <h1>Controle e Altera&ccedil;&atilde;o de Projetos - CAP</h1>
            </td>
            <td class="heading-meta">
                <div><span class="label">N&ordm;:</span> <span class="mono">{{ $version->cap_number }}</span></div>
                <div><span class="label">Data:</span> {{ optional($version->cap_requested_at ?: $version->created_at)->timezone(config('app.timezone'))->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <p class="project-line">
        <span class="label">Obra:</span>
        {{ $document->obra?->codigo }} - {{ $document->obra?->nome ?: 'Nao informada' }}
    </p>

    <table class="details">
        <tr>
            <td>
                <span class="detail-label">EAP do projeto</span>
                <span class="detail-value mono">{{ $document->eap($version->revision) ?: '-' }}</span>
            </td>
            <td>
                <span class="detail-label">EAP da CAP</span>
                <span class="detail-value mono">{{ $version->cap_number }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="detail-label">Contrato</span>
                <span class="detail-value">{{ $document->contract?->code }} - {{ $document->contract?->name }}</span>
            </td>
            <td>
                <span class="detail-label">Disciplina e fase</span>
                <span class="detail-value">{{ $document->disciplina?->sigla }} - {{ $document->disciplina?->nome }} &middot; {{ $document->phase?->code }} - {{ $document->phase?->name }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="detail-label">Tipo de documento</span>
                <span class="detail-value">CAP</span>
            </td>
            <td>
                <span class="detail-label">Solicitado por</span>
                <span class="detail-value">{{ $version->capRequester?->name ?: $version->uploader?->name ?: 'Nao informado' }}</span>
            </td>
        </tr>
    </table>

    <section class="section">
        <h2 class="section-title">Motivo da altera&ccedil;&atilde;o</h2>
        <p class="narrative">{{ $version->cap_reason ?: 'Nao informado.' }}</p>
    </section>

    <section class="section">
        <h2 class="section-title">Descri&ccedil;&atilde;o da altera&ccedil;&atilde;o</h2>
        <p class="narrative">{{ $version->cap_description ?: $version->revision_change_summary ?: 'Nao informado.' }}</p>
    </section>

    <section class="section">
        <h2 class="section-title">Impactos</h2>
        <p class="impact-list">
            @if (count($version->cap_impacts ?: []) > 0)
                {{ collect($version->cap_impacts)->map(fn ($impact) => $impactLabels[$impact] ?? $impact)->implode(', ') }}.
            @else
                Nenhum impacto informado.
            @endif
        </p>
    </section>

    <section class="section">
        <h2 class="section-title">An&aacute;lise e aprova&ccedil;&atilde;o</h2>
        <div class="rule" style="margin-bottom: 0;"></div>
        <table class="review">
            <tr>
                <td>
                    <div class="review-role">An&aacute;lise</div>
                    <div class="review-person">{{ $version->reviewer?->name ?: 'Nao informado' }}</div>
                    @if ($version->reviewed_at)
                        <div class="muted">{{ $version->reviewed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>
                    @endif
                    <div class="review-note">{{ $version->review_notes ?: 'Sem parecer registrado.' }}</div>
                </td>
                <td>
                    <div class="review-role">Aprova&ccedil;&atilde;o</div>
                    <div class="review-person">{{ $version->approver?->name ?: 'Nao informado' }}</div>
                    @if ($version->approved_at)
                        <div class="muted">{{ $version->approved_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>
                    @endif
                    <div class="review-note">{{ $version->approval_notes ?: 'Sem parecer registrado.' }}</div>
                </td>
            </tr>
        </table>
    </section>
</body>
</html>
