<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Projeto reprovado</title>
</head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background:#b42318;color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Deming</div>
                            <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">Projeto reprovado</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Olá, {{ $notifiable->name }}.</p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                                {{ $actor->name }} reprovou o projeto que você submeteu durante a etapa de {{ mb_strtolower($stageLabel) }}.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Projeto</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $document->title }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Código</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $document->eap($document->latestVersion?->revision) ?: 'Sem código' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Contrato</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $document->contract?->code }} - {{ $document->contract?->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;color:#667085;font-size:13px;">Revisão</td>
                                    <td style="padding:10px 0;text-align:right;font-size:13px;font-weight:700;">{{ $document->latestVersion?->revision ?: 'Sem revisão' }}</td>
                                </tr>
                            </table>

                            <div style="margin:0 0 22px;border-left:4px solid #b42318;background:#fff5f5;padding:14px 16px;">
                                <div style="margin:0 0 6px;color:#7a271a;font-size:12px;font-weight:700;text-transform:uppercase;">Motivo da reprovação</div>
                                <div style="white-space:pre-line;color:#344054;font-size:14px;line-height:1.6;">{{ $reason }}</div>
                            </div>

                            <table role="presentation" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="padding:0 10px 0 0;">
                                        <a href="{{ $url }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:8px;padding:12px 18px;font-size:14px;font-weight:700;">Ver projeto</a>
                                    </td>
                                    <td>
                                        <a href="{{ $systemUrl }}" style="display:inline-block;background:#ffffff;color:#0b5fff;text-decoration:none;border:1px solid #0b5fff;border-radius:8px;padding:11px 18px;font-size:14px;font-weight:700;">Acessar sistema</a>
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
