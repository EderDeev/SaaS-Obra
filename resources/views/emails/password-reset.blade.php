<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefinição de senha</title>
</head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:#0b5fff;color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Deming</div>
                            <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">Redefinição de senha</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                                Olá, {{ $user->name }}.
                            </p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                                Recebemos uma solicitação para redefinir a senha da sua conta no Deming.
                            </p>

                            <div style="margin:0 0 24px;padding:16px;border:1px solid #e5e7eb;border-radius:10px;background:#fbfbfd;">
                                <p style="margin:0;font-size:14px;line-height:1.6;color:#111827;">
                                    Por segurança, este link expira em breve. Se você não solicitou a redefinição, ignore este e-mail.
                                </p>
                            </div>

                            <a href="{{ $resetUrl }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700;">
                                Redefinir senha
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
