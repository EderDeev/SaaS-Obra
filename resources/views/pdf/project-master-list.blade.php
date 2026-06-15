<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Lista Mestra de Projetos</title>
    <style>
        @page {
            margin: 18px;
        }

        body {
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9px;
            line-height: 1.35;
        }

        .header {
            border-bottom: 2px solid #0f5bff;
            margin-bottom: 14px;
            padding-bottom: 10px;
        }

        .eyebrow {
            color: #64748b;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        h1 {
            font-size: 18px;
            margin: 3px 0 0;
        }

        .meta {
            color: #475569;
            margin-top: 4px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th {
            background: #0f172a;
            color: #fff;
            font-size: 8px;
            padding: 6px 5px;
            text-align: left;
            text-transform: uppercase;
        }

        td {
            border-bottom: 1px solid #dbe3ef;
            padding: 5px;
            vertical-align: top;
        }

        tr:nth-child(even) td {
            background: #f8fafc;
        }

        .code {
            color: #0f5bff;
            font-family: DejaVu Sans Mono, monospace;
            font-weight: 700;
            white-space: nowrap;
        }

        .muted {
            color: #64748b;
            font-size: 8px;
        }

        .status {
            border-radius: 99px;
            display: inline-block;
            font-size: 8px;
            font-weight: 700;
            padding: 2px 6px;
        }

        .status-blue { background: #dbeafe; color: #1d4ed8; }
        .status-amber { background: #fef3c7; color: #b45309; }
        .status-green { background: #dcfce7; color: #15803d; }
        .status-red { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <div class="header">
        <div class="eyebrow">{{ $tenant->name }} · Lista Mestra</div>
        <h1>Lista Mestra de Projetos</h1>
        <div class="meta">Gerado em {{ $generatedAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $documents->count() }} documento(s)</div>
    </div>

    <table>
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
                        <strong>{{ $document['title'] ?: 'Sem título' }}</strong>
                        <div class="muted">Arquivo: {{ $document['file_name'] ?: '-' }}</div>
                    </td>
                    <td>
                        <strong>{{ $document['contract']['code'] ?: '-' }}</strong>
                        <div class="muted">{{ $document['contract']['name'] ?: '-' }}</div>
                    </td>
                    <td>
                        <strong>{{ $document['obra']['codigo'] ?: '-' }}</strong>
                        <div class="muted">{{ $document['obra']['nome'] ?: '-' }}</div>
                    </td>
                    <td>
                        <strong>{{ $document['disciplina']['sigla'] ?: '-' }}</strong>
                        <div class="muted">{{ $document['disciplina']['nome'] ?: '-' }}</div>
                    </td>
                    <td>
                        <strong>{{ $document['phase']['code'] ?: '-' }}</strong>
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
                        <div>Criado: {{ $document['created_at'] ?: '-' }}</div>
                        <div class="muted">Aprov.: {{ $document['approved_at'] ?: '-' }}</div>
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
