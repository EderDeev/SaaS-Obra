<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14px 16px 22px; }
        * { box-sizing: border-box; }
        body {
            color: #07111f;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 8px;
            line-height: 1.18;
        }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th, td { border: .75px solid #111; padding: 2.5px 3px; vertical-align: top; }
        th { background: #d9d9d9; font-weight: 700; text-align: center; text-transform: uppercase; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .footer {
            bottom: -15px;
            color: #5b6479;
            font-size: 7px;
            left: 0;
            position: fixed;
            right: 0;
            text-align: right;
        }
        .footer:after { content: "P\00E1gina " counter(page) " de " counter(pages); }
        .header td { vertical-align: middle; }
        .logo-cell { height: 52px; text-align: center; width: 24%; }
        .logo-cell img { max-height: 42px; max-width: 145px; }
        .logo-fallback {
            border: .8px dashed #98a2b3;
            color: #344054;
            display: block;
            font-size: 7px;
            font-weight: 700;
            height: 38px;
            line-height: 1.15;
            padding: 7px 4px 0;
            text-align: center;
            text-transform: uppercase;
        }
        .document-title {
            color: #0b5fff;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .03em;
            text-align: center;
            text-transform: uppercase;
        }
        .document-subtitle {
            color: #344054;
            font-size: 7.5px;
            margin-top: 3px;
            text-align: center;
        }
        .system-mark {
            border-top: 2px solid #0b5fff;
            color: #0b5fff;
            display: inline-block;
            font-size: 7px;
            font-weight: 800;
            margin-top: 4px;
            padding-top: 2px;
            text-transform: uppercase;
        }
        .section { margin-top: 5px; }
        .bar {
            background: #c7d7f8;
            color: #07111f;
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
        }
        .bar-dark {
            background: #475467;
            color: #fff;
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
        }
        .label {
            display: block;
            font-size: 6.4px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .value {
            display: block;
            font-size: 8px;
            font-weight: 600;
            margin-top: 1px;
        }
        .muted { color: #667085; }
        .center { text-align: center; }
        .right { text-align: right; }
        .strong { font-weight: 800; }
        .blue { color: #0b5fff; font-weight: 800; }
        .green { color: #008000; font-weight: 800; }
        .small { font-size: 6.8px; }
        .tiny { font-size: 6.2px; }
        .day-active {
            background: #0b5fff;
            color: #fff;
            font-weight: 800;
        }
        .vertical {
            background: #d0d5dd;
            font-size: 7px;
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
            vertical-align: middle;
            width: 22px;
        }
        .vertical span {
            display: block;
            line-height: 1.05;
        }
        .blank-row td { height: 16px; }
        .activity td { height: 20px; }
        .comment-box { height: 42px; }
        .signature td {
            border-top: 0;
            height: 54px;
            padding-top: 32px;
            text-align: center;
        }
        .signature .line {
            border-top: .75px solid #111;
            display: inline-block;
            min-width: 150px;
            padding-top: 4px;
        }
        .photo-title {
            color: #0b5fff;
            font-size: 13px;
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
        }
        .photo-cell {
            height: 204px;
            padding: 4px;
            text-align: center;
            vertical-align: middle;
        }
        .photo-cell img {
            max-height: 172px;
            max-width: 100%;
        }
        .photo-caption {
            height: 28px;
            font-size: 7px;
            text-align: left;
        }
    </style>
</head>
<body>
<div class="footer"></div>
@php
    $fmtDate = fn ($date) => $date ? $date->format('d/m/Y') : '-';
    $fmtDateTime = fn ($date) => $date ? $date->format('d/m/Y H:i') : '-';
    $fmtQty = fn ($value, $decimals = 2) => is_numeric($value) ? number_format((float) $value, $decimals, ',', '.') : ($value ?: '');
    $fmtMoney = fn ($value) => is_numeric($value) ? 'R$ '.number_format((float) $value, 2, ',', '.') : '-';
    $cnpj = function ($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return strlen($digits) === 14
            ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits)
            : ($value ?: '-');
    };
    $sectionOf = fn ($obraId, $key) => data_get($sections, "{$obraId}.{$key}", []);
    $empresaNome = fn ($empresa, $fallback = '-') => $empresa?->nome ?: $empresa?->sigla ?: $fallback;
    $empresaCurta = fn ($empresa, $fallback = '-') => $empresa?->sigla ?: $empresa?->nome ?: $fallback;
    $logoBox = function ($key, $empresa, $fallback) use ($logos, $empresaCurta) {
        $logo = data_get($logos, $key);
        if ($logo) {
            return '<img src="'.$logo.'" alt="Logo">';
        }

        return '<span class="logo-fallback">'.e($empresaCurta($empresa, $fallback)).'</span>';
    };
    $statusLabel = fn ($value) => match ($value) {
        'em_aprovacao' => 'Em aprovação',
        'devolvido_construtora' => 'Devolvido',
        'pendente_comprovacao' => 'Pendente de comprovação',
        'arquivado' => 'Arquivado',
        default => 'Rascunho',
    };
    $weather = fn ($value) => match ($value) {
        'ensolarado' => 'Sol',
        'nublado' => 'Nublado',
        'chuvoso' => 'Chuva',
        'nao_aplicavel' => 'N/A',
        default => $value ?: '-',
    };
    $weatherMark = fn ($value, $expected) => $value === $expected ? 'X' : '';
    $decisionText = fn ($value) => match ($value) {
        'approve' => 'Aprovado',
        'approve_with_reservations' => 'Aprovado com ressalvas',
        'return' => 'Devolvido',
        default => 'Enviado para análise',
    };
    $flowCommentsFor = function (int $obraId, string $stage) use ($analyses, $fmtDateTime, $decisionText) {
        return $analyses
            ->filter(fn ($analysis) => (int) $analysis->obra_id === $obraId && $analysis->etapa === $stage && filled($analysis->comentario))
            ->map(fn ($analysis) => trim(sprintf(
                '[%s - %s - %s] %s',
                $fmtDateTime($analysis->created_at),
                $analysis->user?->name ?: 'Responsável',
                $decisionText($analysis->decisao),
                $analysis->comentario,
            )))
            ->values()
            ->all();
    };
    $joinComments = function (...$parts) {
        $comments = collect($parts)
            ->flatten()
            ->filter(fn ($value) => filled($value))
            ->values();

        return $comments->isNotEmpty() ? $comments->implode("\n") : '-';
    };
    $situation = fn ($value) => match ($value) {
        'operando' => 'Operando',
        'parado' => 'Parado',
        'manutencao' => 'Manutenção',
        default => $value ?: '-',
    };
    $weekdays = [
        1 => 'Seg.',
        2 => 'Ter.',
        3 => 'Qua.',
        4 => 'Qui.',
        5 => 'Sex.',
        6 => 'Sáb.',
        0 => 'Dom.',
    ];
    $referenceWeekday = (int) ($rdo->reference_date?->dayOfWeek ?? 0);
    $submissionDueDate = $rdo->reference_date?->copy()?->addDays((int) ($rdo->configuracao?->submission_deadline_days ?? 7));
    $contractStart = $contract->starts_at;
    $contractEnd = $contract->ends_at;
    $referenceDate = $rdo->reference_date;
    $totalDays = ($contractStart && $contractEnd) ? max($contractStart->diffInDays($contractEnd) + 1, 1) : null;
    $elapsedDays = ($contractStart && $referenceDate) ? max(min($contractStart->diffInDays($referenceDate) + 1, $totalDays ?: PHP_INT_MAX), 0) : null;
    $remainingDays = ($totalDays !== null && $elapsedDays !== null) ? max($totalDays - $elapsedDays, 0) : null;
    $elapsedPercent = ($totalDays && $elapsedDays !== null) ? min(($elapsedDays / $totalDays) * 100, 100) : null;
    $projectName = $contract->description ?: $contract->name;
    $local = trim(($contract->city ?: '').($contract->state ? ' / '.$contract->state : '')) ?: '-';
    $rowsFixed = 10;
@endphp

@foreach ($obras as $obra)
    @php
        $clima = $sectionOf($obra->id, 'clima');
        $maoObra = $sectionOf($obra->id, 'mao_obra');
        $equipamentos = $sectionOf($obra->id, 'equipamentos');
        $atividades = $sectionOf($obra->id, 'atividades');
        $fotos = $sectionOf($obra->id, 'fotos');
        $comentarios = $sectionOf($obra->id, 'comentarios');
        $direct = collect(data_get($maoObra, 'efetivos', []))->filter(fn ($qty, $id) => data_get($catalogs['mao_obra'], "{$id}.tipo") === 'direta');
        $indirect = collect(data_get($maoObra, 'efetivos', []))->filter(fn ($qty, $id) => data_get($catalogs['mao_obra'], "{$id}.tipo") === 'indireta');
        $subs = collect(data_get($maoObra, 'subcontratadas', []));
        $equips = collect(data_get($equipamentos, 'registros', []));
        $maxRows = max($rowsFixed, $direct->count(), $indirect->count(), $subs->count(), $equips->count());
        $directValues = $direct->values()->all();
        $directKeys = $direct->keys()->values()->all();
        $indirectValues = $indirect->values()->all();
        $indirectKeys = $indirect->keys()->values()->all();
        $subValues = $subs->values()->all();
        $subKeys = $subs->keys()->values()->all();
        $equipValues = $equips->values()->all();
        $equipKeys = $equips->keys()->values()->all();
        $activityItems = collect(data_get($atividades, 'atividades', []))
            ->map(fn ($activity) => [
                'titulo' => data_get($activity, 'titulo'),
                'ocorrencia' => data_get($activity, 'ocorrencia', data_get($activity, 'descricao')),
            ])
            ->filter(fn ($activity) => filled(data_get($activity, 'titulo')) || filled(data_get($activity, 'ocorrencia')))
            ->values();
        if ($activityItems->isEmpty() && filled(data_get($atividades, 'atividades_executadas'))) {
            $activityItems = collect([[
                'titulo' => 'Atividade executada',
                'ocorrencia' => data_get($atividades, 'atividades_executadas'),
            ]]);
        }
        $legacyOccurrences = collect([
            data_get($atividades, 'ocorrencias'),
            data_get($atividades, 'interferencias'),
            data_get($atividades, 'acidentes'),
        ])->filter(fn ($value) => filled($value))->implode("\n\n");
        if ($activityItems->isEmpty() && filled($legacyOccurrences)) {
            $activityItems = collect([[
                'titulo' => 'Ocorrências importantes',
                'ocorrencia' => $legacyOccurrences,
            ]]);
        }
        $photos = collect(data_get($fotos, 'arquivos', []))->values();
        $impraticavel = data_get($clima, 'dia_impraticavel') ? 1 : 0;
        $gerenciadoraFlowComments = $flowCommentsFor((int) $obra->id, 'gerenciadora');
        $clienteFlowComments = $flowCommentsFor((int) $obra->id, 'cliente');
    @endphp

    <section class="page">
        <table class="header">
            <tr>
                <td class="logo-cell">{!! $logoBox('cliente', $contract->clienteEmpresa, 'Logo do cliente') !!}</td>
                <td class="logo-cell">{!! $logoBox('gerenciadora', $contract->gerenciadoraEmpresa, $tenant->name ?: 'Gerenciadora') !!}</td>
                <td colspan="2">
                    <div class="document-title">Relatório Diário de Obra</div>
                    <div class="document-subtitle">{{ $tenant->name }} · {{ $rdo->code }} · {{ $statusLabel($rdo->status) }}</div>
                    <div class="center"><span class="system-mark">Deming</span></div>
                </td>
                <td class="logo-cell">{!! $logoBox('construtora', $contract->construtoraEmpresa, 'Logo da empresa') !!}</td>
            </tr>
            <tr>
                <td colspan="4">
                    <span class="label">Nome do projeto</span>
                    <span class="value">{{ $projectName }}</span>
                </td>
                <td>
                    <span class="label">Local</span>
                    <span class="value">{{ $local }}</span>
                </td>
            </tr>
            <tr>
                <td colspan="2"><span class="label">Empresa</span><span class="value">{{ $empresaNome($contract->construtoraEmpresa, $contract->contractor_company_name ?: '-') }}</span></td>
                <td colspan="3"><span class="label">Serviço / Frente</span><span class="value">{{ $obra->codigo }} - {{ $obra->nome }}</span></td>
            </tr>
            <tr>
                <td><span class="label">Contrato nº</span><span class="value">{{ $contract->code ?: '-' }}</span></td>
                <td><span class="label">Data OS</span><span class="value">{{ $fmtDate($contractStart) }}</span></td>
                <td><span class="label">Responsável técnico</span><span class="value">{{ $rdo->responsible?->name ?: '-' }}</span></td>
                <td><span class="label">RDO nº</span><span class="value">{{ $rdo->code }}</span></td>
                <td><span class="label">Prazo de envio</span><span class="value">{{ $fmtDate($submissionDueDate) }} · {{ $submissionDueDate ? data_get($weekdays, $submissionDueDate->dayOfWeek, '-') : '-' }}</span></td>
            </tr>
        </table>

        <div class="section">
            <table>
                <tr>
                    <th colspan="3">Data</th>
                    <th colspan="5">Condições do tempo</th>
                    <th colspan="4">Prazos</th>
                </tr>
                <tr>
                    <td colspan="3" rowspan="2" class="center">
                        <div class="strong" style="font-size: 12px;">{{ $fmtDate($referenceDate) }}</div>
                        <div class="small muted">Dia da semana</div>
                        <table style="margin-top: 3px;">
                            <tr>
                                @foreach ($weekdays as $idx => $label)
                                    <td class="center {{ $referenceWeekday === $idx ? 'day-active' : '' }}">{{ $label }}</td>
                                @endforeach
                            </tr>
                        </table>
                    </td>
                    <th>Condição</th>
                    <th>Manhã</th>
                    <th>Tarde</th>
                    <th>Noite</th>
                    <th>Observações</th>
                    <th>Total</th>
                    <th>Decorridos</th>
                    <th>% Decorrido</th>
                    <th>Restantes</th>
                </tr>
                <tr>
                    <td>
                        <div>Sol</div>
                        <div>Nublado</div>
                        <div>Chuva</div>
                        <div>Pluviosidade (mm)</div>
                    </td>
                    <td class="center">
                        <div>{{ $weatherMark(data_get($clima, 'manha'), 'ensolarado') }}</div>
                        <div>{{ $weatherMark(data_get($clima, 'manha'), 'nublado') }}</div>
                        <div>{{ $weatherMark(data_get($clima, 'manha'), 'chuvoso') }}</div>
                        <div>{{ $fmtQty(data_get($clima, 'precipitacao_manha_mm')) }}</div>
                    </td>
                    <td class="center">
                        <div>{{ $weatherMark(data_get($clima, 'tarde'), 'ensolarado') }}</div>
                        <div>{{ $weatherMark(data_get($clima, 'tarde'), 'nublado') }}</div>
                        <div>{{ $weatherMark(data_get($clima, 'tarde'), 'chuvoso') }}</div>
                        <div>{{ $fmtQty(data_get($clima, 'precipitacao_tarde_mm')) }}</div>
                    </td>
                    <td class="center">
                        <div>{{ $weatherMark(data_get($clima, 'noite'), 'ensolarado') }}</div>
                        <div>{{ $weatherMark(data_get($clima, 'noite'), 'nublado') }}</div>
                        <div>{{ $weatherMark(data_get($clima, 'noite'), 'chuvoso') }}</div>
                        <div>{{ $fmtQty(data_get($clima, 'precipitacao_noite_mm')) }}</div>
                    </td>
                    <td>{{ data_get($clima, 'observacoes') ?: '-' }}</td>
                    <td class="center">{{ $totalDays ?? '-' }}</td>
                    <td class="center">{{ $elapsedDays ?? '-' }}</td>
                    <td class="center">{{ $elapsedPercent !== null ? $fmtQty($elapsedPercent).'%' : '-' }}</td>
                    <td class="center">{{ $remainingDays ?? '-' }}<br><span class="tiny">Impraticável: {{ $impraticavel }}</span></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <table>
                <tr>
                    <td class="vertical" rowspan="{{ $maxRows + 3 }}"><span>Efetivo<br>Mão de obra<br>e equipamento</span></td>
                    <th>MO Direta</th><th>Quant.</th>
                    <th>MO Indireta</th><th>Quant.</th>
                    <th>Subcontratada</th><th>Quant.</th>
                    <th>Equipamentos</th><th>Quant.</th>
                </tr>
                @for ($i = 0; $i < $maxRows; $i++)
                    @php
                        $directId = $directKeys[$i] ?? null;
                        $indirectId = $indirectKeys[$i] ?? null;
                        $subId = $subKeys[$i] ?? null;
                        $equipId = $equipKeys[$i] ?? null;
                        $equipRecord = $equipValues[$i] ?? [];
                    @endphp
                    <tr class="blank-row">
                        <td>{{ $directId ? data_get($catalogs['mao_obra'], "{$directId}.descricao", "Item {$directId}") : '' }}</td>
                        <td class="center">{{ $directId ? $fmtQty($directValues[$i] ?? null) : '' }}</td>
                        <td>{{ $indirectId ? data_get($catalogs['mao_obra'], "{$indirectId}.descricao", "Item {$indirectId}") : '' }}</td>
                        <td class="center">{{ $indirectId ? $fmtQty($indirectValues[$i] ?? null) : '' }}</td>
                        <td>{{ $subId ? (data_get($catalogs['subcontratadas'], "{$subId}.nome_fantasia") ?: data_get($catalogs['subcontratadas'], "{$subId}.razao_social", "Empresa {$subId}")) : '' }}</td>
                        <td class="center">{{ $subId ? $fmtQty($subValues[$i] ?? null) : '' }}</td>
                        <td>{{ $equipId ? trim((data_get($catalogs['equipamentos'], "{$equipId}.codigo") ? data_get($catalogs['equipamentos'], "{$equipId}.codigo").' - ' : '').data_get($catalogs['equipamentos'], "{$equipId}.descricao", "Equipamento {$equipId}")) : '' }}</td>
                        <td class="center">{{ $equipId ? $fmtQty(data_get($equipRecord, 'quantidade')) : '' }}</td>
                    </tr>
                @endfor
                <tr>
                    <td class="right strong">Total MO Direta</td><td class="center strong">{{ $fmtQty($direct->sum(fn ($value) => (float) $value)) }}</td>
                    <td class="right strong">Total MO Indireta</td><td class="center strong">{{ $fmtQty($indirect->sum(fn ($value) => (float) $value)) }}</td>
                    <td class="right strong">Total Subcontratada</td><td class="center strong">{{ $fmtQty($subs->sum(fn ($value) => (float) $value)) }}</td>
                    <td class="right strong">Total Equipamentos</td><td class="center strong">{{ $fmtQty($equips->sum(fn ($value) => (float) data_get($value, 'quantidade'))) }}</td>
                </tr>
                <tr>
                    <td colspan="4"><span class="label">Observações mão de obra</span>{{ data_get($maoObra, 'observacoes') ?: '-' }}</td>
                    <td colspan="4"><span class="label">Observações equipamentos</span>{{ data_get($equipamentos, 'observacoes') ?: '-' }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <table>
                <tr><td class="bar" colspan="3">Atividades e ocorrências</td></tr>
                <tr><th style="width: 8%;">Item</th><th style="width: 28%;">Título</th><th>Ocorrência</th></tr>
                @for ($i = 0; $i < 11; $i++)
                    @php $activity = $activityItems[$i] ?? null; @endphp
                    <tr class="activity">
                        <td class="center">{{ $i + 1 }}</td>
                        <td>{{ data_get($activity, 'titulo', '') }}</td>
                        <td>{{ data_get($activity, 'ocorrencia', '') }}</td>
                    </tr>
                @endfor
            </table>
        </div>

        <div class="section">
            <table>
                <tr><td class="bar" colspan="3">Comentários gerais</td></tr>
                <tr><th>Construtora</th><th>Gerenciadora / Fiscalização</th><th>Cliente</th></tr>
                <tr>
                    <td class="comment-box">{{ data_get($comentarios, 'construtora') ?: '-' }}</td>
                    <td class="comment-box">{!! nl2br(e($joinComments(data_get($comentarios, 'gerenciadora'), $gerenciadoraFlowComments))) !!}</td>
                    <td class="comment-box">{!! nl2br(e($joinComments(data_get($comentarios, 'cliente'), $clienteFlowComments))) !!}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <table class="signature">
                <tr>
                    <td><span class="line">{{ $empresaCurta($contract->construtoraEmpresa, 'Construtora') }}<br>(CONTRATADA)</span></td>
                    <td><span class="line">{{ $empresaCurta($contract->gerenciadoraEmpresa, 'Gerenciadora') }}<br>(GERENCIADORA / FISCALIZAÇÃO)</span></td>
                    <td><span class="line">{{ $empresaCurta($contract->clienteEmpresa, 'Cliente') }}<br>(CONTRATANTE)</span></td>
                </tr>
            </table>
        </div>
    </section>

    @foreach ($photos->chunk(6) as $photoPage)
        <section class="page">
            <table class="header">
                <tr>
                    <td class="logo-cell">{!! $logoBox('cliente', $contract->clienteEmpresa, 'Logo do cliente') !!}</td>
                    <td colspan="3">
                        <div class="photo-title">Registro Fotográfico</div>
                        <div class="document-subtitle">{{ $rdo->code }} · {{ $obra->codigo }} - {{ $obra->nome }} · {{ $fmtDate($referenceDate) }}</div>
                    </td>
                    <td class="logo-cell">{!! $logoBox('construtora', $contract->construtoraEmpresa, 'Logo da empresa') !!}</td>
                </tr>
                <tr>
                    <td colspan="4"><span class="label">Nome do projeto</span><span class="value">{{ $projectName }}</span></td>
                    <td><span class="label">Local</span><span class="value">{{ $local }}</span></td>
                </tr>
            </table>

            <div class="section">
                <table>
                    @foreach ($photoPage->chunk(2) as $row)
                        <tr>
                            @foreach ($row as $photo)
                                @php $path = public_path('storage/'.data_get($photo, 'path')); @endphp
                                <td class="photo-cell">
                                    @if (data_get($photo, 'path') && file_exists($path))
                                        <img src="{{ $path }}" alt="Foto RDO">
                                    @else
                                        <span class="muted">Imagem não encontrada</span>
                                    @endif
                                </td>
                            @endforeach
                            @for ($i = $row->count(); $i < 2; $i++)
                                <td class="photo-cell"></td>
                            @endfor
                        </tr>
                        <tr>
                            @foreach ($row as $photo)
                                <td class="photo-caption">{{ data_get($photo, 'comment') ?: data_get($photo, 'legenda') ?: '-' }}</td>
                            @endforeach
                            @for ($i = $row->count(); $i < 2; $i++)
                                <td class="photo-caption"></td>
                            @endfor
                        </tr>
                    @endforeach
                </table>
            </div>
        </section>
    @endforeach
@endforeach

@if ($analyses->isNotEmpty())
    <section class="page">
        <table class="header">
            <tr>
                <td class="logo-cell">{!! $logoBox('cliente', $contract->clienteEmpresa, 'Logo do cliente') !!}</td>
                <td colspan="3">
                    <div class="document-title">Histórico de análise e aprovação</div>
                    <div class="document-subtitle">{{ $rdo->code }} · {{ $fmtDate($referenceDate) }}</div>
                </td>
                <td class="logo-cell">{!! $logoBox('construtora', $contract->construtoraEmpresa, 'Logo da empresa') !!}</td>
            </tr>
        </table>
        <div class="section">
            <table>
                <tr>
                    <th>Data</th>
                    <th>Etapa</th>
                    <th>Decisão</th>
                    <th>Responsável</th>
                    <th>Frente</th>
                    <th>Comentário</th>
                </tr>
                @foreach ($analyses as $analysis)
                    <tr>
                        <td>{{ $fmtDateTime($analysis->created_at) }}</td>
                        <td>{{ match ($analysis->etapa) { 'gerenciadora' => 'Gerenciadora', 'cliente' => 'Cliente', default => 'Construtora' } }}</td>
                        <td>{{ match ($analysis->decisao) { 'approve' => 'Aprovado', 'approve_with_reservations' => 'Aprovado com ressalvas', 'return' => 'Devolvido', default => 'Enviado para análise' } }}</td>
                        <td>{{ $analysis->user?->name ?: '-' }}</td>
                        <td>{{ $analysis->obra ? "{$analysis->obra->codigo} - {$analysis->obra->nome}" : '-' }}</td>
                        <td>{{ $analysis->comentario ?: '-' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </section>
@endif
</body>
</html>
