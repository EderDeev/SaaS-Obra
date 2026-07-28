@php
    $headerColor = match ($tone) {
        'success' => '#067647',
        'danger' => '#b42318',
        default => '#0b5fff',
    };
    $softBackground = match ($tone) {
        'success' => '#ecfdf3',
        'danger' => '#fff5f5',
        default => '#f5f8ff',
    };
    $softBorder = match ($tone) {
        'success' => '#abefc6',
        'danger' => '#fecdca',
        default => '#dbe7ff',
    };
    $contractLabel = trim(($ordem->contract?->code ? $ordem->contract->code.' - ' : '').($ordem->contract?->name ?? ''));
    $obraLabel = trim(($ordem->obra?->codigo ? $ordem->obra->codigo.' - ' : '').($ordem->obra?->nome ?? ''));
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:{{ $headerColor }};color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Deming · Ordem de Serviço</div>
                            <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">{{ $headline }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Olá, {{ $notifiable->name }}.</p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">{{ $bodyText }}</p>

                            <div style="margin:0 0 20px;padding:16px;border:1px solid {{ $softBorder }};border-radius:10px;background:{{ $softBackground }};">
                                <div style="margin:0 0 7px;color:{{ $headerColor }};font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;">{{ $highlightTitle }}</div>
                                <p style="margin:0;font-size:14px;line-height:1.6;color:#111827;">{{ $highlightBody }}</p>
                            </div>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">OS</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $ordem->codigo }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Título</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $ordem->titulo }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Contrato</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $contractLabel ?: 'Não informado' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Obra</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $obraLabel ?: 'Não informada' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Prazo de execução</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $ordem->prazo_execucao?->format('d/m/Y') ?: 'Sem prazo' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Custo previsto</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">R$ {{ number_format((float) $ordem->custo_previsto, 2, ',', '.') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Situação atual</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;color:{{ $headerColor }};">{{ $statusLabel }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;color:#667085;font-size:13px;">Ação realizada por</td>
                                    <td style="padding:10px 0;text-align:right;font-size:13px;font-weight:700;">{{ $actor->name }}</td>
                                </tr>
                            </table>

                            @if ($observation)
                                <div style="margin:0 0 22px;border-left:4px solid {{ $headerColor }};background:#fbfbfd;padding:14px 16px;">
                                    <div style="margin:0 0 6px;color:#667085;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;">Observação</div>
                                    <div style="white-space:pre-line;color:#344054;font-size:14px;line-height:1.6;">{{ $observation }}</div>
                                </div>
                            @endif

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0;">
                                <tr>
                                    <td style="padding:0 10px 0 0;">
                                        <a href="{{ $url }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700;">{{ $actionLabel }}</a>
                                    </td>
                                    <td>
                                        <a href="{{ $systemUrl }}" style="display:inline-block;background:#ffffff;color:#0b5fff;text-decoration:none;border:1px solid #0b5fff;border-radius:9px;padding:11px 18px;font-size:14px;font-weight:700;">Acessar sistema</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
