<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seu acesso ao Deming</title>
</head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:#0b5fff;color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Deming</div>
                            <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">Seu acesso foi criado</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                                Olá, {{ $user->name }}.
                            </p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                                Criamos seu acesso ao tenant <strong>{{ $tenant->name }}</strong>. Use os dados abaixo para o primeiro login.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Login</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $user->email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;color:#667085;font-size:13px;">Senha provisória</td>
                                    <td style="padding:10px 0;text-align:right;font-family:Consolas,Menlo,monospace;font-size:15px;font-weight:700;">{{ $temporaryPassword }}</td>
                                </tr>
                            </table>

                            <div style="margin:0 0 24px;padding:16px;border:1px solid #e5e7eb;border-radius:10px;background:#fbfbfd;">
                                <p style="margin:0;font-size:14px;line-height:1.6;color:#111827;">
                                    Por segurança, você precisará criar uma senha definitiva no primeiro acesso.
                                </p>
                            </div>

                            <a href="{{ $loginUrl }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700;">
                                Acessar sistema
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
