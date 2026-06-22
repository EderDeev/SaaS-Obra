<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Pleito preliminar' }}</title>
    <style>
        @page { margin: 12px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 7px; }
        h1 { margin: 0 0 3px; font-size: 14px; }
        .meta { margin-bottom: 8px; color: #4b5563; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d1d5db; padding: 3px 4px; vertical-align: top; overflow-wrap: break-word; }
        th { background: #f3f4f6; text-transform: uppercase; font-size: 6px; text-align: left; }
        td.numeric, th.numeric { text-align: right; white-space: nowrap; }
        .group-row td { background: #d1d5db; text-align: center; font-weight: bold; text-transform: uppercase; font-size: 7px; }
        .subtotal-row td { background: #f3f4f6; font-weight: bold; }
        th:nth-child(1), td:nth-child(1) { width: 4.5%; }
        th:nth-child(2), td:nth-child(2) { width: 6%; }
        th:nth-child(3), td:nth-child(3) { width: 16%; }
        th:nth-child(4), td:nth-child(4) { width: 4.5%; }
        tfoot td { background: #f3f4f6; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $title ?? 'Pleito preliminar' }}</h1>
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
                    <th class="{{ ! empty($header['numeric']) ? 'numeric' : '' }}">{{ $header['label'] }}</th>
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
                    <tr class="{{ ! empty($row['_is_fr_total']) ? 'subtotal-row' : '' }}">
                        @foreach($headers as $header)
                            @php($key = $header['key'])
                            @php($value = $row[$key] ?? null)
                            <td class="{{ ! empty($header['numeric']) ? 'numeric' : '' }}">
                                @if($value === null || $value === '')

                                @elseif(! empty($header['money']))
                                    R$ {{ number_format((float) $value, 2, ',', '.') }}
                                @elseif(! empty($header['percent']))
                                    {{ number_format((float) $value, 2, ',', '.') }}%
                                @elseif(! empty($header['numeric']))
                                    {{ number_format((float) $value, 4, ',', '.') }}
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}">Nenhum pleito encontrado.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">Total</td>
                @foreach(array_slice($headers, 4) as $header)
                    @php($key = $header['key'])
                    <td class="{{ ! empty($header['numeric']) ? 'numeric' : '' }}">
                        @if(array_key_exists($key, $totals ?? []))
                            @if(! empty($header['money']))
                                R$ {{ number_format((float) ($totals[$key] ?? 0), 2, ',', '.') }}
                            @elseif(! empty($header['percent']))
                                {{ number_format((float) ($totals[$key] ?? 0), 2, ',', '.') }}%
                            @elseif(! empty($header['numeric']))
                                {{ number_format((float) ($totals[$key] ?? 0), 4, ',', '.') }}
                            @else
                                {{ number_format((float) ($totals[$key] ?? 0), 2, ',', '.') }}
                            @endif
                        @endif
                    </td>
                @endforeach
            </tr>
        </tfoot>
    </table>
</body>
</html>
