<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Lista Mestra de Projetos</title>
    <style>
        @page { margin: 20px 20px 28px; }

        * { box-sizing: border-box; }

        body {
            color: #1e293b;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 8.5px;
            line-height: 1.3;
            margin: 0;
        }

        .document-header {
            border-bottom: 1px solid #cbd5e1;
            margin-bottom: 10px;
            padding-bottom: 9px;
            width: 100%;
        }

        .document-header td { vertical-align: bottom; }

        .eyebrow {
            color: #2563eb;
            font-size: 7.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        h1 {
            color: #0f172a;
            font-size: 17px;
            line-height: 1.15;
            margin: 3px 0 0;
        }

        .document-meta {
            color: #64748b;
            font-size: 8px;
            line-height: 1.45;
            text-align: right;
        }

        .brand-grid {
            border-collapse: collapse;
            margin-bottom: 12px;
            table-layout: fixed;
            width: 100%;
        }

        .brand-cell {
            border: 1px solid #e2e8f0;
            padding: 0;
            vertical-align: middle;
            width: 33.333%;
        }

        .brand-label {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: .07em;
            padding: 4px 7px;
            text-transform: uppercase;
        }

        .brand-content {
            border-collapse: collapse;
            height: 38px;
            table-layout: fixed;
            width: 100%;
        }

        .brand-logo {
            padding: 5px 7px;
            text-align: center;
            vertical-align: middle;
            width: 84px;
        }

        .brand-logo img {
            max-height: 27px;
            max-width: 70px;
        }

        .brand-placeholder {
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
        }

        .brand-name {
            color: #1e293b;
            font-size: 8.5px;
            font-weight: 700;
            padding: 5px 7px 5px 0;
            vertical-align: middle;
        }

        .brand-note {
            color: #94a3b8;
            font-size: 7px;
            font-weight: 400;
            margin-top: 2px;
        }

        .data-table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        .data-table th {
            background: #eef2f7;
            border-bottom: 1px solid #cbd5e1;
            border-top: 2px solid #2563eb;
            color: #475569;
            font-size: 7px;
            font-weight: 700;
            padding: 5px 4px;
            text-align: left;
            text-transform: uppercase;
        }

        .data-table td {
            border-bottom: 1px solid #e2e8f0;
            overflow-wrap: break-word;
            padding: 4px;
            vertical-align: top;
        }

        .data-table tr:nth-child(even) td { background: #f8fafc; }

        .code {
            color: #1d4ed8;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 7.5px;
            font-weight: 700;
        }

        .primary { color: #0f172a; font-weight: 700; }
        .muted { color: #64748b; font-size: 7px; margin-top: 1px; }

        .status {
            border-radius: 8px;
            display: inline-block;
            font-size: 7px;
            font-weight: 700;
            padding: 2px 5px;
        }

        .status-blue { background: #eaf2ff; color: #1d4ed8; }
        .status-amber { background: #fff4d6; color: #a16207; }
        .status-green { background: #e8f7ee; color: #166534; }
        .status-red { background: #fdecec; color: #b91c1c; }

        .footer {
            bottom: -20px;
            color: #94a3b8;
            font-size: 7px;
            left: 0;
            position: fixed;
            right: 0;
        }

        .footer-table { border-collapse: collapse; width: 100%; }
        .footer-table td { border-top: 1px solid #e2e8f0; padding-top: 4px; }
        .page-number { text-align: right; }
        .page-number:after { content: "Página " counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        <table class="footer-table">
            <tr>
                <td>{{ $tenant->name }} · Lista Mestra de Projetos</td>
                <td class="page-number"></td>
            </tr>
        </table>
    </div>

    <table class="document-header">
        <tr>
            <td>
                <div class="eyebrow">Controle de projetos</div>
                <h1>Lista Mestra de Projetos</h1>
            </td>
            <td class="document-meta">
                <strong>{{ $tenant->name }}</strong><br>
                Gerado em {{ $generatedAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }}<br>
                {{ $documents->count() }} projeto(s)
            </td>
        </tr>
    </table>

    <table class="brand-grid">
        <tr>
            @foreach (['gerenciadora', 'cliente', 'construtora'] as $role)
                @php
                    $company = $branding[$role];
                @endphp
                <td class="brand-cell">
                    <div class="brand-label">{{ $company['label'] }}</div>
                    <table class="brand-content">
                        <tr>
                            <td class="brand-logo">
                                @if ($company['logo_data_uri'])
                                    <img src="{{ $company['logo_data_uri'] }}" alt="{{ $company['name'] }}">
                                @else
                                    <span class="brand-placeholder">{{ $company['sigla'] ?: '—' }}</span>
                                @endif
                            </td>
                            <td class="brand-name">
                                {{ $company['name'] }}
                                @if ($company['additional_count'] > 0)
                                    <div class="brand-note">+ {{ $company['additional_count'] }} empresa(s) no escopo</div>
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
            @endforeach
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 13%;">Código</th>
                <th style="width: 18%;">Documento</th>
                <th style="width: 11%;">Contrato</th>
                <th style="width: 12%;">Obra</th>
                <th style="width: 10%;">Disciplina</th>
                <th style="width: 9%;">Fase</th>
                <th style="width: 7%;">Tipo</th>
                <th style="width: 6%;">Rev.</th>
                <th style="width: 7%;">Status</th>
                <th style="width: 7%;">Datas</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($documents as $document)
                @php
                    $statusClass = match ($document['status']) {
                        'em_aprovacao' => 'status-amber',
                        'ativo' => 'status-green',
                        'inativo', 'reprovado' => 'status-red',
                        default => 'status-blue',
                    };
                @endphp
                <tr>
                    <td class="code">{{ $document['code'] ?: '-' }}</td>
                    <td>
                        <div class="primary">{{ $document['title'] ?: 'Sem título' }}</div>
                        <div class="muted">{{ $document['file_name'] ?: 'Sem arquivo' }}</div>
                    </td>
                    <td>
                        <div class="primary">{{ $document['contract']['code'] ?: '-' }}</div>
                        <div class="muted">{{ $document['contract']['name'] ?: '-' }}</div>
                    </td>
                    <td>
                        <div class="primary">{{ $document['obra']['codigo'] ?: '-' }}</div>
                        <div class="muted">{{ $document['obra']['nome'] ?: '-' }}</div>
                    </td>
                    <td>
                        <div class="primary">{{ $document['disciplina']['sigla'] ?: '-' }}</div>
                        <div class="muted">{{ $document['disciplina']['nome'] ?: '-' }}</div>
                    </td>
                    <td>
                        <div class="primary">{{ $document['phase']['code'] ?: '-' }}</div>
                        <div class="muted">{{ $document['phase']['name'] ?: '-' }}</div>
                    </td>
                    <td>{{ $document['document_type_label'] ?: '-' }}</td>
                    <td>{{ $document['revision'] ?: '-' }}</td>
                    <td>
                        <span class="status {{ $statusClass }}">{{ $document['status_label'] ?: '-' }}</span>
                        @if (($document['open_rncs_count'] ?? 0) > 0)
                            <div class="muted">{{ $document['open_rncs_count'] }} RNC aberta(s)</div>
                        @endif
                    </td>
                    <td>
                        <div>{{ $document['created_at'] ?: '-' }}</div>
                        <div class="muted">Aprov. {{ $document['approved_at'] ?: '-' }}</div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">Nenhum projeto encontrado para os filtros selecionados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
