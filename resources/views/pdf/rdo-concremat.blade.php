@php
    $accent = $documentConfiguration['color'] ?? '#2f75b5';
    $titleColor = $documentConfiguration['title_color'] ?? '#ffffff';
    $date = $rdo->reference_date;
    $fmtDate = fn ($value) => $value?->format('d/m/Y') ?: '—';
    $qty = fn ($value) => is_numeric($value) ? number_format((float) $value, (float) $value == (int) $value ? 0 : 2, ',', '.') : '—';
    $start = $contract?->starts_at;
    $end = $contract?->ends_at;
    $duration = $start && $end ? max(0, (int) $start->diffInDays($end, false)) : null;
    $elapsed = $start && $date ? max(0, (int) $start->diffInDays($date, false)) : null;
    $balance = $end && $date ? (int) $date->diffInDays($end, false) : null;
    $days = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $periods = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
    $companyName = $documentConfiguration['company_name'] ?: ($contract?->gerenciadoraEmpresa?->nome ?: 'Gerenciadora');
    $weatherMark = fn ($value, $expected) => in_array($expected, is_array($value) ? $value : [$value], true) ? 'X' : '';
    $flowComments = fn ($role, $obraId) => $analyses->filter(fn ($item) => $item->etapa === $role && (int) $item->obra_id === (int) $obraId && filled($item->comentario))->map(fn ($item) => $item->comentario)->unique()->implode("\n\n");
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $rdo->code }} — Diário de obra</title>
    <style>
        @page { margin: 20px 25px 26px; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 8px; line-height: 1.25; color: #111; }
        * { box-sizing: border-box; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: .65px solid #252525; padding: 3px 4px; vertical-align: middle; word-wrap: break-word; }
        th { background: #c8c8c8; font-weight: bold; }
        .masthead td { padding: 5px; }
        .masthead tr:first-child td { height: 48px; }
        .logos { width: 30%; text-align: center; background: white; }
        .logos img { max-width: {{ (int) (155 / max(count(array_filter($logos)), 1)) }}px; max-height: 43px; vertical-align: middle; margin: 0 3px; }
        .banner { background: {{ $accent }}; color: {{ $titleColor }}; text-align: center; font-size: 25px; font-weight: normal; line-height: 1.15; }
        .description { font-size: 8px; font-weight: bold; text-transform: uppercase; }
        .label { background: #c8c8c8; text-align: center; font-weight: bold; font-size: 7px; }
        .value { font-weight: bold; text-align: center; }
        .blue { background: {{ $accent }}; color: {{ $titleColor }}; font-weight: bold; text-align: center; }
        .identification { border-collapse: collapse; }
        .identification th, .identification td { font-size: 7px; padding: 3px 4px; height: 17px; }
        .identification .label { font-size: 6.7px; }
        .identification .climate-title { background: {{ $accent }}; color: {{ $titleColor }}; }
        .identification .weather-mark { text-align: center; font-weight: bold; }
        .work { page-break-before: always; }
        .work.first { page-break-before: auto; }
        .group-title { border: .65px solid #252525; background: {{ $accent }}; color: {{ $titleColor }}; text-align: center; font-size: 11px; font-weight: bold; padding: 5px; text-transform: uppercase; }
        .section-title { border: .65px solid #252525; padding: 3px 4px; background: #eeeeee; font-weight: bold; text-transform: uppercase; page-break-after: avoid; }
        .section-title.center { background: #c8c8c8; text-align: center; }
        .activity { border: .65px solid #252525; padding: 4px; min-height: 18px; word-wrap: break-word; }
        .activity p { margin: 2px 0 3px; }
        .activity h3 { margin: 0 0 3px; font-size: 8px; page-break-after: avoid; }
        .muted { color: #59616b; }
        .small { font-size: 7px; }
        .photo-cell { width: 50%; text-align: center; vertical-align: top; padding: 5px; }
        .photo-cell img { max-width: 245px; max-height: 100px; }
        .caption { margin: 4px 0 0; font-size: 7px; text-align: left; word-wrap: break-word; }
        .team th, .team td { font-size: 7px; padding: 3px; }
        .signoff { page-break-inside: avoid; }
        .signoff td { padding: 5px; text-align: center; vertical-align: bottom; }
        .signature-slot { height: 42px; }
        .signature-line { border-top: .65px solid #555; margin: 0 10px 4px; }
        .flow-signoff { margin-top: 8px; page-break-inside: avoid; }
        .flow { margin-top: 0; }
        .flow th, .flow td { font-size: 7px; }
        .footer { position: fixed; bottom: -17px; left: 0; right: 0; text-align: right; font-size: 6px; color: #555; }
        .footer:after { content: ' · Página ' counter(page); }
    </style>
</head>
<body>
<div class="footer">{{ $companyName }} · {{ $rdo->code }} · {{ $fmtDate($date) }}</div>
@foreach ($obras as $obra)
    @php
        $data = data_get($sectionsByParty, 'gerenciadora.'.$obra->id, []);
        $clima = data_get($data, 'clima', []);
        $workforce = data_get($data, 'mao_obra', []);
        $equipment = data_get($data, 'equipamentos', []);
        $activities = collect(data_get($data, 'atividades.atividades', []));
        if ($activities->isEmpty() && filled(data_get($data, 'atividades.descricao'))) $activities = collect([['titulo' => 'Engenharia', 'ocorrencia' => data_get($data, 'atividades.descricao')]]);
        $groups = ['Engenharia' => [], 'Segurança do trabalho' => [], 'Meio ambiente' => []];
        foreach ($activities as $activity) {
            $normalized = strtolower(\Illuminate\Support\Str::ascii($activity['titulo'] ?? ''));
            $group = preg_match('/seguranca|sst/', $normalized) ? 'Segurança do trabalho' : (preg_match('/ambiente|ambiental|residuo/', $normalized) ? 'Meio ambiente' : 'Engenharia');
            $group = \App\Support\RdoPdfTemplates::ACTIVITY_SUBJECTS[$activity['assunto'] ?? ''] ?? $group;
            $groups[$group][] = $activity;
        }
        $team = [];
        foreach (data_get($workforce, 'efetivos', []) as $id => $amount) {
            $entry = $catalogs['mao_obra'][$id] ?? [];
            $team[] = ['code' => $entry['codigo'] ?? '', 'name' => $entry['descricao'] ?? 'Cadastro '.$id, 'amount' => $amount];
        }
        foreach (data_get($workforce, 'subcontratadas', []) as $id => $amount) {
            $entry = $catalogs['subcontratadas'][$id] ?? [];
            $team[] = ['code' => $entry['codigo'] ?? '', 'name' => ($entry['razao_social'] ?? 'Empresa '.$id).' (subcontratada)', 'amount' => $amount];
        }
        $photos = collect(data_get($data, 'fotos.arquivos', []))->sortBy('position')->values();
        $rain = collect(array_keys($periods))->filter(fn ($period) => is_numeric(data_get($clima, 'precipitacao_'.$period.'_mm')));
        $rainTotal = $rain->isNotEmpty() ? $rain->sum(fn ($period) => (float) data_get($clima, 'precipitacao_'.$period.'_mm')) : data_get($clima, 'precipitacao_total_anterior_mm');
    @endphp
    <section class="work {{ $loop->first ? 'first' : '' }}" data-rdo-layout="concremat_diary">
        <table class="masthead"><tr>
            <td class="logos">@foreach (array_filter($logos) as $logo)<img src="{{ $logo }}" alt="Logo do contrato">@endforeach</td>
            <td class="banner">{{ mb_strtoupper($documentConfiguration['title']) }}</td>
        </tr><tr><td colspan="2" class="description">{{ $contract?->description ?: $contract?->name }}</td></tr></table>
        @php
            $headerPeriods = $periods;
            if (in_array(data_get($clima, 'noite'), [null, '', 'nao_aplicavel'], true) && !(float) data_get($clima, 'precipitacao_noite_mm')) unset($headerPeriods['noite']);
        @endphp
        <table class="identification">
            <colgroup>@foreach ([12, 13, 8, 9, 8, 9, 9, 6, 6, 6, 14] as $width)<col style="width:{{ $width }}%">@endforeach</colgroup>
            <tr><td class="label" style="width:12%">Obra</td><td colspan="3" style="width:29%">{{ $referencePreview['work_label'] ?? ($obra->codigo.' · '.$obra->nome) }}</td><td colspan="2" class="label" style="width:18%">Início contrato</td><td class="value" style="width:9%">{{ $fmtDate($start) }}</td><td colspan="2" class="label" style="width:12%">Término contrato</td><td colspan="2" class="value" style="width:20%">{{ $fmtDate($end) }}</td></tr>
            <tr><th class="label">Prazo contratual</th><td class="value">{{ $duration !== null ? $duration.' dias' : '—' }}</td><th class="label">Tempo decorrido</th><td class="value">{{ $elapsed !== null ? $elapsed.' dias' : '—' }}</td><th class="label">Saldo de prazo</th><td class="value">{{ $balance !== null ? $balance.' dias' : '—' }}</td><th class="climate-title">Condições climáticas</th><th>Bom</th><th>Estável</th><th>Chuvoso</th><th>Índice pluviométrico</th></tr>
            @foreach ($headerPeriods as $period => $label)
                <tr>
                    @if ($loop->first)<td class="label">Data hoje</td><td colspan="2" class="value">{{ $fmtDate($date) }}</td><td colspan="3" class="blue">{{ $date ? $days[$date->dayOfWeek] : '—' }}@if ($rdo->is_holiday !== null)<br><span class="small">Feriado: {{ $rdo->is_holiday ? 'Sim' : 'Não' }}</span>@endif</td>
                    @elseif ($loop->index === 1)<td class="label" rowspan="{{ count($headerPeriods) - 1 }}">Responsável</td><td colspan="5" rowspan="{{ count($headerPeriods) - 1 }}">{{ $rdo->responsible?->name ?: 'Não informado' }}</td>@endif
                    <td class="label">{{ $label }}</td><td class="weather-mark">{{ $weatherMark(data_get($clima, $period), 'ensolarado') }}</td><td class="weather-mark">{{ $weatherMark(data_get($clima, $period), 'nublado') }}</td><td class="weather-mark">{{ $weatherMark(data_get($clima, $period), 'chuvoso') }}</td>
                    @if ($loop->first)<td rowspan="{{ count($headerPeriods) }}" class="value">{{ is_numeric($rainTotal) ? number_format((float) $rainTotal, 2, ',', '.').' mm' : '—' }}</td>@endif
                </tr>
            @endforeach
            <tr><td class="label">Equipe</td><td colspan="10">{{ $referencePreview['team_summary'] ?? (collect($team)->map(fn ($item) => $qty($item['amount']).' '.$item['name'])->implode(' · ') ?: 'Nenhum efetivo registrado.') }}</td></tr>
        </table>
        @if (filled(data_get($clima, 'observacoes')) || data_get($clima, 'dia_impraticavel'))<div class="activity small"><strong>Condições do dia:</strong> {{ data_get($clima, 'dia_impraticavel') ? 'Dia impraticável. ' : '' }}{!! nl2br(e(data_get($clima, 'observacoes'))) !!}</div>@endif
        <div class="group-title">{{ $referencePreview['activity_heading'] ?? ('Acompanhamento · '.$obra->nome) }}</div>
        @foreach ($groups as $label => $items)
            <div class="section-title">{{ $label }}{{ in_array($label, ['Engenharia', 'Meio ambiente'], true) ? ':' : '' }}</div>
            @forelse ($items as $activity)<div class="activity">@if (mb_strtolower(trim($activity['titulo'] ?? '')) !== mb_strtolower($label))<h3>{{ $activity['titulo'] ?? 'Atividade' }}</h3>@endif<p>{!! nl2br(e($activity['ocorrencia'] ?? $activity['descricao'] ?? '')) !!}</p></div>@empty<div class="activity muted">Nenhum registro informado neste assunto.</div>@endforelse
        @endforeach
        <div class="section-title">Equipamentos utilizados</div>
        <div class="activity">
            @forelse (data_get($equipment, 'registros', []) as $id => $record)<p><strong>{{ $qty($record['quantidade'] ?? 0) }} · {{ $catalogs['equipamentos'][$id]['descricao'] ?? 'Equipamento '.$id }}</strong> · {{ ['operando' => 'Operando', 'parado' => 'Parado', 'manutencao' => 'Manutenção'][$record['situacao'] ?? ''] ?? 'Situação não informada' }}</p>@empty<p class="muted">Nenhum equipamento registrado.</p>@endforelse
            @if (filled(data_get($equipment, 'observacoes')))<p>{!! nl2br(e(data_get($equipment, 'observacoes'))) !!}</p>@endif
        </div>
        @if ($rdo->configuracao?->require_photos)
            <div class="section-title center">Relatório fotográfico</div>
            <table>@forelse ($photos->chunk(2) as $row)<tr>@foreach ($row as $photo)<td class="photo-cell">@php $image = $photoDataUri($photo['path'] ?? null); @endphp @if ($image)<img src="{{ $image }}" alt="Registro da frente">@else<span class="muted">Imagem indisponível</span>@endif<div class="caption">{!! nl2br(e($photo['comment'] ?? $photo['legenda'] ?? '')) !!}</div></td>@endforeach @if ($row->count() === 1)<td class="photo-cell"></td>@endif</tr>@empty<tr><td class="activity muted">Nenhuma fotografia registrada nesta frente.</td></tr>@endforelse</table>
        @endif
        <div class="section-title center">Equipe envolvida</div>
        <table class="team"><thead><tr>@for ($i = 0; $i < 3; $i++)<th style="width:7%">Código</th><th style="width:20%">Descrição</th><th style="width:6.33%">Quant.</th>@endfor</tr></thead><tbody>
            @forelse (collect($team)->chunk(3) as $row)<tr>@foreach ($row as $item)<td>{{ $item['code'] }}</td><td>{{ $item['name'] }}</td><td class="value">{{ $qty($item['amount']) }}</td>@endforeach @for ($i = $row->count(); $i < 3; $i++)<td></td><td></td><td></td>@endfor</tr>@empty<tr><td colspan="9" class="muted">Nenhum efetivo registrado.</td></tr>@endforelse
        </tbody></table>
        @if (filled(data_get($workforce, 'observacoes')))<div class="activity small">{!! nl2br(e(data_get($workforce, 'observacoes'))) !!}</div>@endif
        @if (in_array('cliente', $signatureParties, true))
            <div class="section-title center">Comentários {{ $contract?->clienteEmpresa?->sigla ?: 'do cliente' }}</div>
            <div class="activity">{!! nl2br(e($flowComments('cliente', $obra->id) ?: 'Nenhuma manifestação registrada.')) !!}</div>
        @endif
    </section>
@endforeach
@if ($includeWorkflowAppendix ?? true)
<div class="flow-signoff">
    <div class="signoff">
        <div class="section-title center">Assinaturas das partes</div>
        <table id="rdo-final-signature-table"><tr>@foreach ($signatureParties as $role)<td style="width:{{ 100 / count($signatureParties) }}%"><div class="signature-slot"></div><div class="signature-line"></div><div>Visto {{ \App\Support\RdoWorkflow::PARTIES[$role] }}</div><strong>{{ $contract->{$role.'Empresa'}?->nome ?: \App\Support\RdoWorkflow::PARTIES[$role] }}</strong></td>@endforeach</tr></table>
    </div>
</div>
@endif
</body>
</html>
