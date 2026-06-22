<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Resumo' }}</title>
    <style>
        @page { margin: 10px; }
        body { font-family: DejaVu Sans, sans-serif; color: #000; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #000; padding: 3px 4px; vertical-align: middle; }
        .title { font-size: 12px; font-weight: bold; text-align: center; }
        .label { font-weight: bold; }
        .center { text-align: center; }
        .right { text-align: right; white-space: nowrap; }
        .section { background: #aaa; font-weight: bold; text-align: center; font-size: 10px; }
        .summary td { background: #f1f1f1; font-weight: bold; }
        .financial-summary { margin-top: 8px; }
        .financial-summary th { font-size: 8px; text-align: center; }
        .financial-summary td { font-size: 8px; }
        .financial-summary .description { text-align: center; }
        .financial-summary .words { font-weight: bold; text-align: center; text-transform: uppercase; }
        .signatures { margin-top: 18px; }
        .signatures td { border: 0; height: 70px; text-align: center; vertical-align: bottom; }
        .signature-line { display: inline-block; width: 82%; border-top: 1px solid #000; padding-top: 4px; }
        thead th { font-size: 7px; text-transform: uppercase; text-align: center; }
        tbody td:first-child { text-align: left; }
    </style>
</head>
<body>
    @php
        $summaryRow = collect($rows)->firstWhere('_is_summary', true) ?: [];
        $valorPeriodo = (float) ($summaryRow['no_periodo_p0'] ?? $totals['no_periodo_p0'] ?? 0);
        $valorReajuste = (float) ($summaryRow['valor_reajuste_periodo'] ?? $totals['valor_reajuste_periodo'] ?? 0);
        $valorTotal = $valorPeriodo + $valorReajuste;
        $moneyInWords = function (float $value): string {
            if (! class_exists(\NumberFormatter::class)) {
                return 'VALOR POR EXTENSO INDISPONIVEL';
            }

            $formatter = new \NumberFormatter('pt_BR', \NumberFormatter::SPELLOUT);
            $negative = $value < 0;
            $absolute = abs($value);
            $reais = (int) floor($absolute);
            $centavos = (int) round(($absolute - $reais) * 100);

            if ($centavos === 100) {
                $reais++;
                $centavos = 0;
            }

            $upper = fn (string $text): string => function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
            $parts = [
                $upper($formatter->format($reais)).' '.($reais === 1 ? 'REAL' : 'REAIS'),
            ];

            if ($centavos > 0) {
                $parts[] = $upper($formatter->format($centavos)).' '.($centavos === 1 ? 'CENTAVO' : 'CENTAVOS');
            }

            return ($negative ? 'MENOS ' : '').implode(' E ', $parts);
        };
        $financialRows = [
            ['label' => '[3] Medido no Período (H)', 'value' => $valorPeriodo],
            ['label' => '[4] Valor do Reajuste', 'value' => $valorReajuste],
            ['label' => '[5] Total [3]+[4]', 'value' => $valorTotal],
        ];
    @endphp

    <table>
        <tr>
            <td colspan="{{ count($headers) }}" class="title">BOLETIM DE MEDI&Ccedil;&Atilde;O</td>
        </tr>
        <tr>
            <td colspan="3">
                <span class="label">Contrato</span><br>
                {{ $boletim['contract']['name'] ?? '-' }}
            </td>
            <td colspan="2" class="center">
                <span class="label">N&uacute;mero do Contrato</span><br>
                {{ $boletim['contract']['code'] ?? '-' }}
            </td>
            <td colspan="2" class="center">
                <span class="label">M&ecirc;s</span><br>
                {{ $boletim['periodo_formatado'] ?? '-' }}
            </td>
            <td colspan="{{ max(1, count($headers) - 7) }}" class="center">
                <span class="label">Medi&ccedil;&atilde;o</span><br>
                {{ $boletim['codigo'] ?? '-' }}
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <span class="label">Objeto/Empreendimento</span><br>
                {{ $boletim['contract']['name'] ?? '-' }}
            </td>
            <td colspan="2" class="center">
                <span class="label">Tipo</span><br>
                {{ $boletim['tipo_label'] ?? '-' }}
            </td>
            <td colspan="{{ max(1, count($headers) - 5) }}" class="center">
                <span class="label">Per&iacute;odo de Refer&ecirc;ncia</span><br>
                {{ $boletim['periodo_formatado'] ?? '-' }}
            </td>
        </tr>
        <tr>
            <td colspan="{{ count($headers) }}" class="section">RESUMO</td>
        </tr>
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th class="{{ ! empty($header['numeric']) ? 'right' : '' }}">{{ $header['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr class="{{ ! empty($row['_is_summary']) ? 'summary' : '' }}">
                    @foreach($headers as $header)
                        @php($key = $header['key'])
                        <td class="{{ ! empty($header['numeric']) ? 'right' : '' }}">
                            @if(! empty($header['money']))
                                R$ {{ number_format((float) ($row[$key] ?? 0), 2, ',', '.') }}
                            @elseif(! empty($header['percent']))
                                {{ number_format((float) ($row[$key] ?? 0), 2, ',', '.') }}%
                            @elseif(! empty($header['numeric']))
                                {{ number_format((float) ($row[$key] ?? 0), 4, ',', '.') }}
                            @else
                                {{ $row[$key] ?? '-' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}">Nenhuma planilha encontrada.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="financial-summary">
        <thead>
            <tr>
                <th>Descri&ccedil;&atilde;o</th>
                <th>Valores (R$)</th>
                <th>Por extenso</th>
            </tr>
        </thead>
        <tbody>
            @foreach($financialRows as $row)
                <tr>
                    <td class="description">{!! $row['label'] !!}</td>
                    <td class="right">R$ {{ number_format((float) $row['value'], 2, ',', '.') }}</td>
                    <td class="words">{{ $moneyInWords((float) $row['value']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $assinaturas = [
            [
                'label' => 'Gerenciadora',
                'empresa' => $boletim['contract']['gerenciadora_empresa']['nome']
                    ?? $boletim['contract']['gerenciadora_empresa']['sigla']
                    ?? 'Gerenciadora',
            ],
            [
                'label' => 'Cliente',
                'empresa' => $boletim['contract']['cliente_empresa']['nome']
                    ?? $boletim['contract']['cliente_empresa']['sigla']
                    ?? 'Cliente',
            ],
            [
                'label' => 'Construtora',
                'empresa' => $boletim['contract']['construtora_empresa']['nome']
                    ?? $boletim['contract']['construtora_empresa']['sigla']
                    ?? 'Construtora',
            ],
        ];
    @endphp

    <table class="signatures">
        <tr>
            @foreach($assinaturas as $assinatura)
                <td>
                    <span class="signature-line">
                        {{ $assinatura['empresa'] }}<br>
                        <strong>{{ mb_strtoupper($assinatura['label']) }}</strong>
                    </span>
                </td>
            @endforeach
        </tr>
    </table>
</body>
</html>
