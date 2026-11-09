@php
    $accent = $documentConfiguration['color'];
    $titleColor = $documentConfiguration['title_color'] ?? $accent;
    $customColors = isset($documentConfiguration['title_color']);
    $rgb = array_map('hexdec', str_split(ltrim($accent, '#'), 2));
    $tint = fn ($amount) => sprintf('#%02x%02x%02x', ...array_map(fn ($channel) => (int) round($channel + (255 - $channel) * $amount), $rgb));
    $onAccent = ($rgb[0] * .2126 + $rgb[1] * .7152 + $rgb[2] * .0722) > 155 ? '#0f172a' : '#ffffff';
@endphp
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
        table { border-collapse: collapse; table-layout: fixed; width: 100%; font-family: DejaVu Sans, sans-serif; font-size: 7px; line-height: 1.25; }
        th, td { border: .55px solid {{ $customColors ? $tint(.65) : '#cbd5e1' }}; padding: 3px 4px; vertical-align: top; font-family: DejaVu Sans, sans-serif; }
        th { background: {{ $customColors ? $tint(.93) : '#f3f5f7' }}; color: #334155; font-weight: 700; text-align: center; text-transform: uppercase; }
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
        .footer:after { content: "P\00E1gina " counter(page); }
        .header td { vertical-align: middle; }
        .report-masthead { padding: 12px 10px; }
        .masthead-layout, .masthead-layout td { border: 0; }
        .report-logos { width: 46%; vertical-align: middle; }
        .report-logos img { max-width: 125px; max-height: 54px; margin-right: 4px; vertical-align: middle; }
        .report-heading { text-align: right; vertical-align: middle; }
        .report-title { font-family: DejaVu Sans, sans-serif; font-size: 14px; font-weight: bold; line-height: 1.3; color: {{ $titleColor }}; }
        .report-meta { margin-top: 6px; font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #64748b; }
        .report-party { display: inline-block; margin-top: 5px; padding: 4px 9px; font-size: 10px; font-weight: bold; color: #fff; }
        .report-party-construtora { background: #c2410c; }
        .report-party-gerenciadora { background: #1d4ed8; }

        .logo-cell { height: 52px; text-align: center; width: 24%; }
        .logo-cell img { max-height: 50px; max-width: 160px; }
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
            color: {{ $titleColor }};
            font-size: 12px;
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
            border-top: 2px solid {{ $accent }};
            color: {{ $accent }};
            display: inline-block;
            font-size: 7px;
            font-weight: 800;
            margin-top: 4px;
            padding-top: 2px;
            text-transform: uppercase;
        }
        .section { margin-top: 6px; }
        .daily-summary { border: 0; }
        .daily-summary > tbody > tr > td { border: 0; padding: 0; }
        .daily-summary td.summary-left { width: 55%; padding-right: 6px; }
        .daily-summary td.summary-right { width: 45%; }
        .deadline-table th, .climate-table th { font-size: 6.1px; line-height: 1.3; vertical-align: middle; }
        .deadline-table td, .climate-table td { vertical-align: middle; padding: 4px 3px; }
        .deadline-number { color: #0f172a; font-size: 8px; font-weight: 700; text-align: center; }
        .summary-highlight { background: {{ $tint(.91) }}; color: {{ $accent }}; font-weight: 700; }
        .climate-table .climate-heading { background: {{ $accent }}; color: {{ $onAccent }}; }
        .climate-table .weather-mark { color: {{ $accent }}; font-size: 8px; font-weight: 700; text-align: center; }
        .contract-milestones td { font-size: 8.5px; font-weight: 700; color: #0f172a; text-align: center; background: #f8fafc; }
        .contract-milestones th { font-size: 6.8px; }
        .contract-progress th { font-size: 7px; color: {{ $accent }}; background: {{ $tint(.91) }}; }
        .contract-progress td { font-size: 10px; font-weight: 700; color: {{ $accent }}; background: {{ $tint(.91) }}; text-align: center; }
        .flow-signoff { page-break-inside: avoid; margin-top: 18px; }
        .signoff-title { font-size: 11px; color: {{ $titleColor }}; margin: 0 0 4px; }
        .final-signatures { width: 100%; margin-top: 8px; }
        .final-signatures td { border: 0; padding: 0 8px; text-align: center; vertical-align: top; }
        .signature-slot { height: 60px; }
        .signer-label { border-top: .6px solid #64748b; padding-top: 7px; font-size: 8px; line-height: 1.4; }
        .summary-note { color: #64748b; font-size: 6.3px; line-height: 1.35; }

        .bar {
            background: {{ $tint(.88) }};
            color: {{ $customColors ? $titleColor : '#07111f' }};
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
        }
        .bar-dark {
            background: {{ $customColors ? $accent : '#475467' }};
            color: {{ $customColors ? $onAccent : '#fff' }};
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
        }
        .label {
            color: #64748b;
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
        .blue { color: {{ $accent }}; font-weight: 800; }
        .green { color: #008000; font-weight: 800; }
        .small { font-size: 6.8px; }
        .tiny { font-size: 6.2px; }
        .day-active {
            background: {{ $accent }};
            color: #fff;
            font-weight: 800;
        }
        .vertical {
            background: {{ $tint(.92) }};
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
        .resource-row td { height: 13px; }
        .activity td { height: 17px; }
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
            font-size: 12px;
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

        return '';
    };
    $statusLabel = fn ($value) => match ($value) {
        'em_analise_construtora' => 'Em análise pela construtora',
        'em_analise_gerenciadora' => 'Em análise pela gerenciadora',
        'em_aprovacao_cliente' => 'Aguardando manifestação do cliente',
        'devolvido_construtora' => 'Devolvido',
        'pendente_comprovacao' => 'Pendente de comprovação',
        'arquivado' => \App\Support\RdoWorkflow::savesWithoutApproval($rdo) ? 'Finalizado' : 'Arquivado',
        default => 'Rascunho',
    };
    $weather = fn ($value) => match ($value) {
        'ensolarado' => 'Sol',
        'nublado' => 'Nublado',
        'chuvoso' => 'Chuva',
        'nao_aplicavel' => 'N/A',
        default => $value ?: '-',
    };
    $weatherMark = fn ($value, $expected) => in_array($expected, is_array($value) ? $value : ($value ? [$value] : []), true) ? 'X' : '';
    $decisionText = fn ($value) => match ($value) {
        'save' => 'Salvo sem aprovação',
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
@endphp

@foreach ($fillingParties as $fillingParty)
    @php
        $sectionOf = fn ($obraId, $key) => data_get($sectionsByParty, "{$fillingParty}.{$obraId}.{$key}", []);
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
        $maxRows = max($direct->count(), $indirect->count(), $subs->count(), $equips->count());
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
            @include('pdf.rdo-heading-row', ['heading' => $documentConfiguration['title'], 'subtitle' => $rdo->code.' · '.$fmtDate($referenceDate).' · '.$statusLabel($rdo->status), 'headerParty' => $fillingParty])
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
                <td colspan="2"><span class="label">Empresa</span><span class="value">{{ $documentConfiguration['company_name'] ?: $empresaNome($fillingParty === 'gerenciadora' ? $contract->gerenciadoraEmpresa : $contract->construtoraEmpresa, $fillingParty === 'construtora' ? ($contract->contractor_company_name ?: '-') : 'Gerenciadora') }}</span></td>
                <td colspan="3"><span class="label">Serviço / Frente</span><span class="value">{{ $obra->codigo }} - {{ $obra->nome }}</span></td>
            </tr>
            <tr>
                <td><span class="label">Contrato nº</span><span class="value">{{ $contract->code ?: '-' }}</span></td>
                <td><span class="label">Data OS</span><span class="value">{{ $fmtDate($contractStart) }}</span></td>
                <td><span class="label">Responsável técnico</span><span class="value">{{ $rdo->responsible?->name ?: '-' }}</span></td>
                <td colspan="2"><span class="label">RDO nº</span><span class="value">{{ $rdo->code }}</span></td>
            </tr>
        </table>

        @php
            $fullWeekdays = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
            $periods = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
            $dailyRain = collect(array_keys($periods))->sum(fn ($period) => is_numeric(data_get($clima, 'precipitacao_'.$period.'_mm')) ? (float) data_get($clima, 'precipitacao_'.$period.'_mm') : 0);
            $hasRain = collect(array_keys($periods))->contains(fn ($period) => is_numeric(data_get($clima, 'precipitacao_'.$period.'_mm')));
        @endphp
        <div class="section">
            <table class="daily-summary"><tr>
                <td class="summary-left">
                    <table class="deadline-table">
                        <tr><th>Prazo contratual</th><td class="deadline-number">{{ $totalDays !== null ? (int) $totalDays.' dias' : '—' }}</td><th>Tempo decorrido</th><td class="deadline-number">{{ $elapsedDays !== null ? (int) $elapsedDays.' dias' : '—' }}</td><th>Saldo de prazo</th><td class="deadline-number">{{ $remainingDays !== null ? (int) $remainingDays.' dias' : '—' }}</td></tr>
                        <tr><th>Data do RDO</th><td colspan="2" class="center strong">{{ $fmtDate($referenceDate) }}</td><td colspan="3" class="center summary-highlight">{{ $fullWeekdays[$referenceWeekday] }}</td></tr>
                        <tr><th>Responsável</th><td colspan="5" class="strong">{{ $rdo->responsible?->name ?: '—' }}</td></tr>
                        <tr class="contract-milestones"><th>Início</th><td colspan="2">{{ $fmtDate($contractStart) }}</td><th>Término previsto</th><td colspan="2">{{ $fmtDate($contractEnd) }}</td></tr>
                        <tr class="contract-progress"><th colspan="2">Prazo decorrido</th><td colspan="4">{{ $elapsedPercent !== null ? $fmtQty($elapsedPercent, 1).'%' : '—' }}</td></tr>
                    </table>
                </td>
                <td class="summary-right">
                    <table class="climate-table">
                        <tr><th class="climate-heading" style="width:24%">Condições climáticas</th><th style="width:15%">Bom</th><th style="width:15%">Estável</th><th style="width:17%">Chuvoso</th><th style="width:29%">Índice pluviométrico</th></tr>
                        @foreach ($periods as $period => $label)<tr><th>{{ $label }}</th><td class="weather-mark">{{ $weatherMark(data_get($clima, $period), 'ensolarado') }}</td><td class="weather-mark">{{ $weatherMark(data_get($clima, $period), 'nublado') }}</td><td class="weather-mark">{{ $weatherMark(data_get($clima, $period), 'chuvoso') }}</td><td class="center strong">{{ is_numeric(data_get($clima, 'precipitacao_'.$period.'_mm')) ? $fmtQty(data_get($clima, 'precipitacao_'.$period.'_mm')).' mm' : '—' }}</td></tr>@endforeach
                        <tr><td colspan="3" class="summary-note">{{ $impraticavel ? 'Dia impraticável' : 'Registro por período' }}</td><td colspan="2" class="center summary-highlight">Total: {{ $hasRain ? $fmtQty($dailyRain).' mm' : '—' }}</td></tr>
                    </table>
                </td>
            </tr></table>
            @if (filled(data_get($clima, 'observacoes')))<table style="margin-top:4px"><tr><td class="summary-note"><strong>Observações do clima:</strong> {{ data_get($clima, 'observacoes') }}</td></tr></table>@endif
        </div>

        @if (!\App\Support\RdoWorkflow::isManagerFieldRecord($rdo, $fillingParty) || $direct->isNotEmpty() || $indirect->isNotEmpty() || $subs->isNotEmpty() || $equips->isNotEmpty() || filled(data_get($maoObra, 'observacoes')) || filled(data_get($equipamentos, 'observacoes')))
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
                    <tr class="resource-row">
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

        @endif
        <div class="section">
            <table>
                <tr><td class="bar">Atividades e ocorrências</td></tr>
            </table>
            <table>
                <tr><th style="width:6%">Item</th><th style="width:34%">Título</th><th style="width:60%">Ocorrência</th></tr>
                @forelse ($activityItems as $activity)
                    <tr class="activity">
                        <td class="center">{{ $loop->iteration }}</td>
                        <td>{{ data_get($activity, 'titulo', '') }}</td>
                        <td>{{ data_get($activity, 'ocorrencia', '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Nenhuma atividade registrada nesta frente.</td></tr>
                @endforelse
            </table>
        </div>

        @if ($rdo->is_holiday !== null)<table style="margin-top:4px"><tr><td><span class="label">Feriado</span>{{ $rdo->is_holiday ? 'Sim' : 'Não' }}</td></tr></table>@endif
        <div class="section">
            <table>
                <tr><td class="bar" colspan="{{ count($signatureParties) }}">Comentários gerais</td></tr>
                <tr>@foreach ($signatureParties as $party)<th>{{ $party === 'gerenciadora' ? 'Gerenciadora / Fiscalização' : \App\Support\RdoWorkflow::PARTIES[$party] }}</th>@endforeach</tr>
                <tr>
                    @foreach ($signatureParties as $party)
                    <td class="comment-box">{!! nl2br(e($joinComments(data_get($comentarios, $party), $party === 'gerenciadora' ? $gerenciadoraFlowComments : ($party === 'cliente' ? $clienteFlowComments : '')))) !!}</td>
                    @endforeach
                </tr>
            </table>
        </div>

    </section>

    @php $photoPages = $rdo->configuracao?->require_photos ? ($photos->isEmpty() ? collect([collect()]) : $photos->chunk(6)) : collect(); @endphp
    @foreach ($photoPages as $photoPage)
        <section class="page">
            <table class="header">
                @include('pdf.rdo-heading-row', ['heading' => 'Registro Fotográfico', 'subtitle' => $rdo->code.' · '.$fmtDate($referenceDate), 'headerParty' => $fillingParty])
                <tr>
                    <td colspan="2"><span class="label">Nome do projeto</span><span class="value">{{ $projectName }}</span></td>
                    <td colspan="2"><span class="label">Frente de serviço</span><span class="value">{{ $obra->nome }}</span></td>
                    <td><span class="label">Local</span><span class="value">{{ $local }}</span></td>
                </tr>
            </table>

            <div class="section">
                <table>
                    @if ($photoPage->isEmpty())<tr><td class="muted" style="padding:18px">Nenhuma fotografia registrada nesta frente.</td></tr>@endif
                    @foreach ($photoPage->chunk(2) as $row)
                        <tr>
                            @foreach ($row as $photo)
                                @php $imageData = $photoDataUri(data_get($photo, 'path')); @endphp
                                <td class="photo-cell">
                                    @if ($imageData)
                                        <img src="{{ $imageData }}" alt="Foto RDO">
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

@endforeach

@if ($inspections->isNotEmpty())
    <section class="page">
        <table class="header">
            @include('pdf.rdo-heading-row', ['heading' => 'Observações e evidências da gerenciadora', 'subtitle' => $rdo->code.' · '.$fmtDate($referenceDate), 'headerParty' => 'gerenciadora', 'partyLabel' => 'Gerenciadora'])
</table>
        @foreach ($inspections as $inspection)
            <div class="section">
                <table>
                    <tr><td class="bar" colspan="4">{{ $inspection->obra ? "{$inspection->obra->codigo} - {$inspection->obra->nome}" : 'Frente de serviço' }}</td></tr>
                    <tr>
                        <td colspan="2"><span class="label">Registrado por</span>{{ $inspection->user?->name ?: '-' }}</td>
                        <td colspan="2"><span class="label">Registrado em</span>{{ $fmtDateTime($inspection->submitted_at) }}</td>
                    </tr>
                </table>
                @if (!empty($inspection->clima))
                    @php $inspectionWeather = $inspection->clima; @endphp
                    <table style="margin-top:6px">
                        <tr><td class="bar" colspan="5">Condições do tempo — Gerenciadora</td></tr>
                        <tr><th>Período</th><th>Sol</th><th>Nublado</th><th>Chuva</th><th>Índice pluviométrico</th></tr>
                        @foreach (['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'] as $period => $label)
                            <tr><th>{{ $label }}{{ data_get($inspectionWeather, $period) === 'nao_aplicavel' ? ' (N/A)' : '' }}</th><td class="weather-mark">{{ $weatherMark(data_get($inspectionWeather, $period), 'ensolarado') }}</td><td class="weather-mark">{{ $weatherMark(data_get($inspectionWeather, $period), 'nublado') }}</td><td class="weather-mark">{{ $weatherMark(data_get($inspectionWeather, $period), 'chuvoso') }}</td><td class="center strong">{{ is_numeric(data_get($inspectionWeather, 'precipitacao_'.$period.'_mm')) ? $fmtQty(data_get($inspectionWeather, 'precipitacao_'.$period.'_mm')).' mm' : '—' }}</td></tr>
                        @endforeach
                        @if (data_get($inspectionWeather, 'observacoes'))<tr><td colspan="5"><span class="label">Observações do clima</span>{!! nl2br(e(data_get($inspectionWeather, 'observacoes'))) !!}</td></tr>@endif
                        <tr><td colspan="5"><span class="label">Dia impraticável</span>{{ data_get($inspectionWeather, 'dia_impraticavel') ? 'Sim' : 'Não' }}</td></tr>
                    </table>
                @endif
                @php
                    $inspectionActivities = collect($inspection->atividades ?? []);
                    if ($inspection->atividades === null && filled($inspection->resumo_fiscalizacao)) {
                        $inspectionActivities = collect([['titulo' => 'Registro da gerenciadora', 'ocorrencia' => $inspection->resumo_fiscalizacao]]);
                    }
                @endphp
                @if (!\App\Support\RdoWorkflow::hasParallelManagerFilling($rdo) || $inspectionActivities->isNotEmpty() || filled($inspection->comentario))
                <table style="margin-top:6px">
                    <tr><td class="bar" colspan="2">Atividades da gerenciadora</td></tr>
                    @forelse ($inspectionActivities as $activity)
                        <tr><td colspan="2"><span class="label">{{ $activity['titulo'] ?? 'Atividade' }}</span>{!! nl2br(e($activity['ocorrencia'] ?? '')) !!}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">Nenhuma atividade registrada.</td></tr>
                    @endforelse
                    <tr><td class="bar" colspan="2">Comentário da gerenciadora</td></tr>
                    <tr><td colspan="2">{!! nl2br(e($inspection->comentario ?: 'Nenhum comentário registrado.')) !!}</td></tr>
                </table>
                @endif
                @if (!empty($inspection->evidencias))
                    <table style="margin-top:6px"><tr><td class="bar" colspan="2">Evidências da frente</td></tr>
                    @foreach (collect($inspection->evidencias)->chunk(2) as $files)<tr>@foreach ($files as $file)<td style="width:50%; padding:8px; text-align:center">@php $image = $inspectionImage($file); @endphp
                        @if ($image)<img src="{{ $image }}" style="max-width:245px; max-height:155px"><br>@endif
                        @if (!$image)<span class="label">Documento anexado</span>@endif
                        <span class="tiny">{{ $file['name'] ?? 'Evidência' }}</span>
                        @if (!empty($file['comment']))<div class="photo-caption">{!! nl2br(e($file['comment'])) !!}</div>@endif
                    </td>@endforeach @if ($files->count() === 1)<td></td>@endif</tr>@endforeach
                    </table>
                @else
                    <table style="margin-top:6px"><tr><td class="bar">Evidências da frente</td></tr><tr><td class="muted" style="padding:12px">Nenhuma evidência anexada.</td></tr></table>
                @endif
            </div>
        @endforeach
    </section>
@endif

@if ($divergences->isNotEmpty())
    <section class="page">
        <table class="header">
            @include('pdf.rdo-heading-row', ['heading' => 'Matriz de divergências', 'subtitle' => $rdo->code.' · '.$fmtDate($referenceDate)])
</table>
        @foreach ($divergences as $item)
            <div class="section">
                <table>
                    <tr><td class="bar" colspan="4">{{ $item->titulo }} · {{ $item->topico }}</td></tr>
                    <tr><td colspan="2"><span class="label">Relato da construtora</span>{!! nl2br(e($item->versao_construtora)) !!}</td><td colspan="2"><span class="label">Constatação da gerenciadora</span>{!! nl2br(e($item->versao_gerenciadora)) !!}</td></tr>
                    <tr><td colspan="4"><span class="label">Resposta da construtora</span>{!! nl2br(e($item->resposta_construtora ?: '-')) !!}</td></tr>
                    <tr><td colspan="4"><span class="label">Conclusão da gerenciadora</span>{!! nl2br(e($item->conclusao_gerenciadora ?: '-')) !!}</td></tr>
                    @if (in_array('cliente', $signatureParties, true))<tr>
                        <td colspan="2"><span class="label">Decisão do cliente</span>{{ $item->decisao_cliente ? ucfirst(str_replace('_', ' ', $item->decisao_cliente)) : '-' }}</td>
                        <td colspan="2"><span class="label">Manifestação final</span>{!! nl2br(e($item->comentario_cliente ?: '-')) !!}</td>
                    </tr>@endif
                </table>
            </div>
        @endforeach
    </section>
@endif

@if ($includeWorkflowAppendix ?? true)
<section class="page">
        <table class="header">
            @include('pdf.rdo-heading-row', ['heading' => 'Histórico de análise e aprovação', 'subtitle' => $rdo->code.' · '.$fmtDate($referenceDate)])
</table>
        @if ($versions->isNotEmpty())
            <div class="section">
                <table>
                    <tr><td class="bar" colspan="3">Histórico de envios</td></tr>
                    <tr><th>Tipo</th><th>Responsável</th><th>Data do envio</th></tr>
                    @foreach ($versions as $version)
                        <tr>
                            <td>{{ $version->trigger === 'initial_submission' ? 'Envio inicial' : 'Reenvio após ajustes' }}</td>
                            <td>{{ $version->createdBy?->name ?: '-' }}</td>
                            <td>{{ $fmtDateTime($version->submitted_at) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
        @if ($analyses->count() > 1)
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
                @foreach ($analyses->slice(0, -1) as $analysis)
                    @include('pdf.rdo-analysis-row', ['analysis' => $analysis])
                @endforeach
            </table>
        </div>
        @endif
    <div class="flow-signoff">
    @if ($analyses->isNotEmpty())<table><tr><th>Data</th><th>Etapa</th><th>Decisão</th><th>Responsável</th><th>Frente</th><th>Comentário</th></tr>@include('pdf.rdo-analysis-row', ['analysis' => $analyses->last()])</table>@endif
    <h2 class="signoff-title">Assinaturas das partes</h2>
    <p class="summary-note">As assinaturas abrangem os registros das frentes incluídas neste documento e suas evidências.</p>
    <table id="rdo-final-signature-table" class="final-signatures"><tr>
        @foreach ($signatureParties as $role)
        <td style="width:{{ 100 / count($signatureParties) }}%"><div class="signature-slot"></div><div class="signer-label">{{ $empresaCurta($contract->{$role.'Empresa'}, \App\Support\RdoWorkflow::PARTIES[$role]) }}<br><span class="small">{{ \App\Support\RdoWorkflow::PARTIES[$role] }}</span></div></td>
        @endforeach
    </tr></table>
    </div>
</section>
@endif
</body>
</html>
