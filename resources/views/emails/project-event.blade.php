@php
    $headerColor = match ($tone ?? 'info') {
        'success' => '#067647',
        'danger' => '#b42318',
        'warning' => '#b54708',
        default => '#0b5fff',
    };
    $softBackground = match ($tone ?? 'info') {
        'success' => '#ecfdf3',
        'danger' => '#fff5f5',
        'warning' => '#fffaeb',
        default => '#f5f8ff',
    };
    $softBorder = match ($tone ?? 'info') {
        'success' => '#abefc6',
        'danger' => '#fecdca',
        'warning' => '#fedf89',
        default => '#dbe7ff',
    };
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
                            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Deming &middot; Projetos</div>
                            <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">{{ $headline }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Olá, {{ $notifiable->name }}.</p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">{{ $intro }}</p>

                            @if (!empty($highlightBody))
                                <div style="margin:0 0 20px;padding:16px;border:1px solid {{ $softBorder }};border-radius:10px;background:{{ $softBackground }};">
                                    @if (!empty($highlightTitle))
                                        <div style="margin:0 0 7px;color:{{ $headerColor }};font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;">{{ $highlightTitle }}</div>
                                    @endif
                                    <p style="margin:0;white-space:pre-line;font-size:14px;line-height:1.6;color:#111827;">{{ $highlightBody }}</p>
                                </div>
                            @endif

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 22px;border-collapse:collapse;table-layout:fixed;">
                                @foreach ($details as $detail)
                                    <tr>
                                        <td width="34%" style="padding:10px 8px 10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;vertical-align:top;">{{ $detail['label'] }}</td>
                                        <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;line-height:1.45;overflow-wrap:anywhere;vertical-align:top;">{{ $detail['value'] }}</td>
                                    </tr>
                                @endforeach
                            </table>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0;">
                                <tr>
                                    <td style="padding:0 10px 10px 0;">
                                        <a href="{{ $url }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700;">{{ $actionLabel }}</a>
                                    </td>
                                    <td style="padding:0 0 10px;">
                                        <a href="{{ $systemUrl }}" style="display:inline-block;background:#ffffff;color:#0b5fff;text-decoration:none;border:1px solid #0b5fff;border-radius:9px;padding:11px 18px;font-size:14px;font-weight:700;">Acessar sistema</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:16px 0 0;padding-top:16px;border-top:1px solid #eef2f7;color:#98a2b3;font-size:11px;line-height:1.5;">
                                Esta é uma mensagem automática do fluxo de Projetos do Deming.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
