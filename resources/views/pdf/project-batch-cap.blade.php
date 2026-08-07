<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $batch->cap_number }}</title>
    <style>
        @page { margin: 34px 42px 36px; }
        * { box-sizing: border-box; }
        body { color: #252525; font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; line-height: 1.5; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        .brands { height: 64px; margin-bottom: 12px; table-layout: fixed; }
        .brand { vertical-align: middle; width: 33.333%; }
        .brand-center { text-align: center; }
        .brand-right { text-align: right; }
        .brand img { max-height: 48px; max-width: 145px; vertical-align: middle; }
        .brand-name { color: #4b5563; display: inline-block; font-size: 9px; font-weight: 700; max-width: 135px; vertical-align: middle; }
        .brand-left .brand-name { margin-left: 7px; }
        .brand-right .brand-name { margin-right: 7px; }
        .brand-initials { color: #1f4e79; font-size: 15px; font-weight: 700; }
        .heading { margin-bottom: 12px; table-layout: fixed; }
        .heading-title { width: 63%; }
        .heading-meta { text-align: right; width: 37%; }
        h1 { color: #163f67; font-size: 14px; line-height: 1.3; margin: 0 0 3px; }
        .subtitle { color: #6b7280; margin: 0; }
        .label { color: #163f67; font-weight: 700; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .project-line { border-bottom: 1px solid #737373; margin: 0 0 15px; padding: 8px 0 10px; }
        .details { margin-bottom: 15px; table-layout: fixed; }
        .details td { padding: 0 18px 7px 0; vertical-align: top; width: 33.333%; }
        .detail-label { color: #6b7280; display: block; font-size: 7px; font-weight: 700; letter-spacing: .06em; margin-bottom: 1px; text-transform: uppercase; }
        .detail-value { font-weight: 600; overflow-wrap: break-word; }
        .section { margin-top: 16px; }
        .section-title { color: #163f67; font-size: 9.5px; margin: 0 0 7px; }
        .narrative { line-height: 1.6; margin: 0; text-align: justify; white-space: pre-line; }
        .projects { border-top: 1px solid #9ca3af; table-layout: fixed; }
        .projects th { color: #6b7280; font-size: 7px; letter-spacing: .04em; padding: 6px 5px; text-align: left; text-transform: uppercase; }
        .projects td { border-top: 1px solid #e5e7eb; padding: 7px 5px; vertical-align: top; }
        .projects .eap { width: 34%; }
        .projects .title { width: 26%; }
        .projects .discipline { width: 14%; }
        .projects .revision { width: 8%; }
        .projects .file { width: 18%; overflow-wrap: anywhere; }
        .review { border-top: 1px solid #555; table-layout: fixed; }
        .review td { padding: 9px 24px 8px 0; vertical-align: top; width: 50%; }
        .review td:nth-child(2) { padding-left: 24px; padding-right: 0; }
        .review-role { color: #163f67; font-weight: 700; margin-bottom: 3px; }
        .review-person { font-weight: 700; }
        .muted { color: #6b7280; font-size: 8px; }
        .footer { bottom: -24px; color: #8a8a8a; font-size: 7px; left: 0; position: fixed; right: 0; }
        .footer-right { text-align: right; }
        .page-number:after { content: "Folha " counter(page) "/" counter(pages); }
    </style>
</head>
<body>
    <div class="footer">
        <table><tr><td>{{ $tenant->name }} &middot; CAP de pacote gerada eletronicamente</td><td class="footer-right page-number"></td></tr></table>
    </div>

    <table class="brands"><tr>
        @foreach (['gerenciadora', 'cliente', 'construtora'] as $index => $role)
            @php($company = $branding[$role])
            <td class="brand {{ $index === 0 ? 'brand-left' : ($index === 1 ? 'brand-center' : 'brand-right') }}">
                @if ($index === 2 && $company['name'])<span class="brand-name">{{ $company['name'] }}</span>@endif
                @if ($company['logo_data_uri'])
                    <img src="{{ $company['logo_data_uri'] }}" alt="{{ $company['name'] }}">
                @elseif ($company['sigla'])
                    <span class="brand-initials">{{ $company['sigla'] }}</span>
                @endif
                @if ($index !== 2 && $company['name'])<span class="brand-name">{{ $company['name'] }}</span>@endif
            </td>
        @endforeach
    </tr></table>

    <table class="heading"><tr>
        <td class="heading-title">
            <h1>Controle e Alteração de Projetos - CAP</h1>
            <p class="subtitle">{{ $batch->title }} &middot; {{ $batch->versions->count() }} projetos no pacote</p>
        </td>
        <td class="heading-meta">
            <div><span class="label">Nº:</span> <span class="mono">{{ $batch->cap_number }}</span></div>
            <div><span class="label">Pacote:</span> <span class="mono">{{ $batch->package_number }}</span></div>
            <div><span class="label">Data:</span> {{ optional($batch->cap_requested_at ?: $batch->created_at)->timezone(config('app.timezone'))->format('d/m/Y') }}</div>
        </td>
    </tr></table>

    <p class="project-line"><span class="label">Obra:</span> {{ $batch->obra?->codigo }} - {{ $batch->obra?->nome ?: 'Não informada' }} &nbsp; <span class="label">Trecho:</span> {{ $batch->trecho?->codigo }} - {{ $batch->trecho?->nome }}</p>

    <table class="details"><tr>
        <td><span class="detail-label">Contrato</span><span class="detail-value">{{ $batch->contract?->code }} - {{ $batch->contract?->name }}</span></td>
        <td><span class="detail-label">Fase</span><span class="detail-value">{{ $batch->phase?->code }} - {{ $batch->phase?->name }}</span></td>
        <td><span class="detail-label">Solicitado por</span><span class="detail-value">{{ $batch->submitter?->name ?: 'Não informado' }}</span></td>
    </tr></table>

    <section class="section">
        <h2 class="section-title">Projetos incluídos</h2>
        <table class="projects">
            <thead><tr><th class="eap">EAP</th><th class="title">Título</th><th class="discipline">Disciplina</th><th class="revision">Revisão</th><th class="file">Arquivo</th></tr></thead>
            <tbody>
                @foreach ($batch->versions as $version)
                    <tr>
                        <td class="eap mono">{{ $version->document?->eap($version->revision) }}</td>
                        <td class="title">{{ $version->document?->title }}</td>
                        <td class="discipline">{{ $version->document?->disciplina?->sigla }}</td>
                        <td class="revision mono">{{ $version->revision }}</td>
                        <td class="file">{{ $version->stored_name ?: $version->original_name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="section"><h2 class="section-title">Motivo</h2><p class="narrative">{{ $batch->cap_reason ?: 'Submissão conjunta de projetos para análise e aprovação.' }}</p></section>
    <section class="section"><h2 class="section-title">Descrição</h2><p class="narrative">{{ $batch->cap_description ?: 'Os projetos relacionados nesta CAP compõem um único pacote de tramitação.' }}</p></section>
    <section class="section"><h2 class="section-title">Impactos</h2><p class="narrative">{{ collect($batch->cap_impacts ?: [])->map(fn ($impact) => $impactLabels[$impact] ?? $impact)->implode(', ') ?: 'Nenhum impacto informado.' }}</p></section>

    <section class="section">
        <h2 class="section-title">Análise e aprovação do pacote</h2>
        <table class="review"><tr>
            <td><div class="review-role">Análise</div><div class="review-person">{{ $batch->reviewer?->name ?: 'Não informado' }}</div>@if($batch->reviewed_at)<div class="muted">{{ $batch->reviewed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>@endif<div>{{ $batch->review_notes ?: 'Sem parecer registrado.' }}</div></td>
            <td><div class="review-role">Aprovação</div><div class="review-person">{{ $batch->approver?->name ?: 'Não informado' }}</div>@if($batch->approved_at)<div class="muted">{{ $batch->approved_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>@endif<div>{{ $batch->approval_notes ?: 'Sem parecer registrado.' }}</div></td>
        </tr></table>
    </section>
</body>
</html>
