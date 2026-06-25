<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assinatura do RDO {{ $rdo->code }}</title>
</head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:#0b5fff;color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Deming</div>
                            <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">Assinatura de RDO solicitada</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Olá, {{ $notifiable->name }}.</p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                                Você foi indicado como responsável pela assinatura deste Relatório Diário de Obra.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">RDO</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $rdo->code }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Data de referência</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $rdo->reference_date?->format('d/m/Y') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Contrato</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $rdo->contract?->code }} - {{ $rdo->contract?->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;color:#667085;font-size:13px;">Situação</td>
                                    <td style="padding:10px 0;text-align:right;font-size:13px;font-weight:700;">Aguardando assinatura</td>
                                </tr>
                            </table>

                            @if($signingUrl)
                                <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#667085;">
                                    O botão abaixo abre diretamente a plataforma de assinatura.
                                </p>
                            @else
                                <div style="margin:0 0 20px;padding:14px 16px;border:1px solid #fde68a;border-radius:10px;background:#fffbeb;color:#92400e;font-size:13px;line-height:1.55;">
                                    O OpenSign enviará o convite de assinatura em uma mensagem separada. Por segurança, o link interno do sistema não substitui o link de assinatura.
                                </div>
                            @endif

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0;">
                                <tr>
                                    @if($signingUrl)
                                        <td style="padding:0 10px 0 0;">
                                            <a href="{{ $signingUrl }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700;">
                                                Assinar documento
                                            </a>
                                        </td>
                                    @endif
                                    <td>
                                        <a href="{{ $rdoUrl }}" style="display:inline-block;background:#ffffff;color:#0b5fff;text-decoration:none;border:1px solid #0b5fff;border-radius:9px;padding:11px 18px;font-size:14px;font-weight:700;">
                                            Visualizar RDO no Deming
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
