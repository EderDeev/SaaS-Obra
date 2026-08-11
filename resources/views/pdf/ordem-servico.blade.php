<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $ordem->codigo }}</title>
    <style>
        @page { margin: 34px 38px 42px; }
        * { box-sizing: border-box; }
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.45; margin: 0; }
        h1, h2, p { margin: 0; }
        .muted { color: #64748b; }
        .eyebrow { color: #64748b; font-size: 7px; font-weight: bold; letter-spacing: .7px; text-transform: uppercase; }
        .header { border-bottom: 2px solid #1769ff; padding-bottom: 16px; }
        .header-table, .meta-table, .items, .signatures { border-collapse: collapse; width: 100%; }
        .brand { font-size: 12px; font-weight: bold; }
        .title { font-size: 20px; margin-top: 12px; }
        .code { color: #1769ff; font-family: DejaVu Sans Mono, monospace; font-size: 11px; font-weight: bold; }
        .status { background: #dcfce7; border-radius: 10px; color: #166534; display: inline-block; font-size: 8px; font-weight: bold; padding: 4px 9px; }
        .section { margin-top: 20px; }
        .section-title { border-bottom: 1px solid #cbd5e1; font-size: 11px; margin-bottom: 10px; padding-bottom: 6px; }
        .meta-table td { padding: 5px 14px 7px 0; vertical-align: top; width: 25%; }
        .meta-value { font-size: 9px; font-weight: bold; margin-top: 2px; }
        .summary { background: #f8fafc; border-left: 3px solid #1769ff; padding: 12px 14px; }
        .items th { background: #f1f5f9; color: #475569; font-size: 7px; letter-spacing: .4px; padding: 7px; text-align: left; text-transform: uppercase; }
        .items td { border-bottom: 1px solid #e2e8f0; padding: 7px; vertical-align: top; }
        .items .number { text-align: right; white-space: nowrap; }
        .history { border-left: 2px solid #bfdbfe; margin-left: 4px; padding-left: 12px; }
        .history-row { margin-bottom: 9px; }
        .file { border-bottom: 1px solid #e2e8f0; padding: 6px 0; }
        .footer { bottom: -24px; color: #94a3b8; font-size: 7px; left: 0; position: fixed; right: 0; text-align: center; }
    </style>
</head>
<body>
    <div class="footer">Documento gerado pelo Deming em {{ $generatedAt->format('d/m/Y H:i') }}</div>

    <header class="header">
        <table class="header-table">
            <tr>
                <td class="brand">{{ $ordem->gerenciadoraEmpresa?->nome ?: ($ordem->tenant?->name ?: 'Deming') }}</td>
                <td style="text-align:right">
                    <span class="status">CONCLUÍDA</span>
                </td>
            </tr>
        </table>
        <p class="eyebrow" style="margin-top:14px">Ordem de serviço · registro final</p>
        <h1 class="title">{{ $ordem->titulo }}</h1>
        <p class="code" style="margin-top:5px">{{ $ordem->codigo }}</p>
    </header>

    <section class="section">
        <table class="meta-table">
            <tr>
                <td><span class="eyebrow">Contrato</span><p class="meta-value">{{ $ordem->contract?->code }} - {{ $ordem->contract?->name }}</p></td>
                <td><span class="eyebrow">Obra</span><p class="meta-value">{{ $ordem->obra?->codigo }} - {{ $ordem->obra?->nome }}</p></td>
                <td><span class="eyebrow">Construtora</span><p class="meta-value">{{ $ordem->construtoraEmpresa?->nome ?: 'Não informada' }}</p></td>
                <td><span class="eyebrow">Prazo para início</span><p class="meta-value">{{ $ordem->prazo_inicio?->format('d/m/Y') ?: 'Não informado' }}</p></td>
            </tr>
            <tr>
                <td><span class="eyebrow">Solicitante</span><p class="meta-value">{{ $ordem->creator?->name }}</p></td>
                <td><span class="eyebrow">Prazo para finalização</span><p class="meta-value">{{ $ordem->prazo_finalizacao?->format('d/m/Y') ?: 'Não informada' }}</p></td>
                <td><span class="eyebrow">Execução iniciada</span><p class="meta-value">{{ $ordem->execution_started_at?->format('d/m/Y H:i') ?: '-' }}</p></td>
                <td><span class="eyebrow">Conclusão</span><p class="meta-value">{{ $ordem->completed_at?->format('d/m/Y H:i') ?: '-' }}</p></td>
            </tr>
        </table>
    </section>

    @if ($ordem->descricao)
        <section class="section">
            <h2 class="section-title">Escopo autorizado</h2>
            <p>{{ $ordem->descricao }}</p>
        </section>
    @endif

    <section class="section">
        <h2 class="section-title">Registro de conclusão</h2>
        <div class="summary">{{ $ordem->completion_summary }}</div>
    </section>

    <section class="section">
        <h2 class="section-title">Itens vinculados</h2>
        <table class="items">
            <thead><tr><th style="width:16%">Item / código</th><th>Descrição</th><th style="width:10%">Unidade</th><th style="width:15%">Qtd. prevista</th><th style="width:16%">Valor previsto</th></tr></thead>
            <tbody>
                @foreach ($ordem->itens as $item)
                    <tr>
                        <td>{{ $item->medicaoItem?->item }}<br><span class="muted">{{ $item->medicaoItem?->codigo }}</span></td>
                        <td>{{ $item->medicaoItem?->descricao }}</td>
                        <td>{{ $item->medicaoItem?->unidade }}</td>
                        <td class="number">{{ number_format((float) $item->quantidade_solicitada, 4, ',', '.') }}</td>
                        <td class="number">R$ {{ number_format((float) $item->valor_previsto, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">Evidências finais</h2>
        @forelse ($ordem->documentos->where('categoria', 'conclusao') as $documento)
            <div class="file"><strong>{{ $documento->nome_original }}</strong> <span class="muted">· {{ number_format($documento->size / 1024, 1, ',', '.') }} KB</span></div>
        @empty
            <p class="muted">Nenhuma evidência registrada.</p>
        @endforelse
    </section>

    <section class="section">
        <h2 class="section-title">Histórico do fluxo</h2>
        <div class="history">
            @foreach ($ordem->analises->sortBy('created_at') as $registro)
                <div class="history-row">
                    <strong>{{ ucfirst($registro->decisao) }}</strong> · {{ $registro->created_at?->format('d/m/Y H:i') }} · {{ $registro->user?->name ?: 'Sistema' }}
                    @if ($registro->observacao)<br><span class="muted">{{ $registro->observacao }}</span>@endif
                </div>
            @endforeach
        </div>
    </section>
</body>
</html>
