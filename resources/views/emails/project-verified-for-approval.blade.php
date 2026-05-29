<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Projeto aguardando aprovacao</title>
</head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:#0b5fff;color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Deming</div>
                            <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">Projeto aguardando aprovacao</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                                Ola, {{ $notifiable->name }}.
                            </p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                                {{ $actor->name }} verificou um projeto da sua disciplina e enviou para aprovacao final.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Projeto</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $document->title }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Codigo</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $document->code ?: 'Sem codigo' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Contrato</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $document->contract?->code }} - {{ $document->contract?->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Obra</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $document->obra?->codigo }} - {{ $document->obra?->nome }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Disciplina</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $document->disciplina?->sigla }} - {{ $document->disciplina?->nome }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;color:#667085;font-size:13px;">Revisao</td>
                                    <td style="padding:10px 0;text-align:right;font-size:13px;font-weight:700;">{{ $document->latestVersion?->revision ?: 'Sem revisao' }}</td>
                                </tr>
                            </table>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0;">
                                <tr>
                                    <td style="padding:0 10px 0 0;">
                                        <a href="{{ $url }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700;">
                                            Aprovar projeto
                                        </a>
                                    </td>
                                    <td>
                                        <a href="{{ $systemUrl }}" style="display:inline-block;background:#ffffff;color:#0b5fff;text-decoration:none;border:1px solid #0b5fff;border-radius:9px;padding:11px 18px;font-size:14px;font-weight:700;">
                                            Acessar sistema
                                        </a>
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
