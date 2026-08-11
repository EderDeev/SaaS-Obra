<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Fluxo das Folhas de Rosto</title>
    <style>
        @page { margin: 18px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 7px; }
        h1 { margin: 0 0 4px; font-size: 15px; }
        .meta { margin-bottom: 12px; color: #4b5563; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border-bottom: 1px solid #dbe2ea; padding: 5px 6px; vertical-align: top; overflow-wrap: break-word; }
        th { background: #f3f6fa; color: #475569; text-align: left; text-transform: uppercase; font-size: 6px; }
        .group-row td { background: #eaf2ff; color: #0f172a; font-weight: bold; font-size: 8px; }
        th:nth-child(1), td:nth-child(1) { width: 8%; }
        th:nth-child(2), td:nth-child(2) { width: 9%; }
        th:nth-child(3), td:nth-child(3) { width: 13%; }
        th:nth-child(4), td:nth-child(4) { width: 8%; }
        th:nth-child(5), td:nth-child(5) { width: 10%; }
        th:nth-child(6), td:nth-child(6) { width: 17%; }
        th:nth-child(7), td:nth-child(7) { width: 20%; }
        th:nth-child(8), td:nth-child(8) { width: 15%; }
    </style>
</head>
<body>
    <h1>Fluxo das Folhas de Rosto</h1>
    <div class="meta">
        {{ $boletim['codigo'] ?? '-' }}
        · Referência {{ $boletim['periodo_formatado'] ?? '-' }}
        · {{ $boletim['tipo_label'] ?? '-' }}
        @if(! empty($boletim['contract']))
            · {{ $boletim['contract']['code'] ?? '' }} - {{ $boletim['contract']['name'] ?? '' }}
        @endif
    </div>

    <table>
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th>{{ $header['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                @if(! empty($row['_is_group']))
                    <tr class="group-row">
                        <td colspan="{{ count($headers) }}">{{ $row['group_title'] ?? '-' }}</td>
                    </tr>
                @else
                    <tr>
                        @foreach($headers as $header)
                            <td>{{ $row[$header['key']] ?? '-' }}</td>
                        @endforeach
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}">Nenhum registro de fluxo encontrado neste BM.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
