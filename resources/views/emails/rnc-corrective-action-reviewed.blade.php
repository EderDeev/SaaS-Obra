<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $approved ? 'Proposta aprovada' : 'Proposta reprovada' }} - RNC {{ $rnc->formatted_number }}</title>
</head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:{{ $approved ? '#11805a' : '#b42318' }};color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Deming</div>
                            <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">
                                {{ $approved ? 'Proposta de acao corretiva aprovada' : 'Proposta de acao corretiva reprovada' }}
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                                Ola, {{ $notifiable->name }}.
                            </p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                                {{ $actor->name }} analisou a proposta da RNC {{ $rnc->formatted_number }}.
                                @if ($approved)
                                    A proposta foi aceita e o processo corretivo pode ser iniciado.
                                @else
                                    A proposta foi reprovada e uma nova proposta de acao corretiva deve ser enviada.
                                @endif
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Contrato</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $rnc->contract?->code }} - {{ $rnc->contract?->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Obra</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $rnc->obra?->codigo }} - {{ $rnc->obra?->nome }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Resultado</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $approved ? 'Aprovada' : 'Reprovada' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;">Prazo proposto</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #eef2f7;text-align:right;font-size:13px;font-weight:700;">{{ $acaoCorretiva->prazo_execucao_proposto?->format('d/m/Y') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;color:#667085;font-size:13px;">Analisada em</td>
                                    <td style="padding:10px 0;text-align:right;font-size:13px;font-weight:700;">{{ $acaoCorretiva->reviewed_at?->format('d/m/Y H:i') }}</td>
                                </tr>
                            </table>

                            <div style="margin:0 0 24px;padding:16px;border:1px solid #e5e7eb;border-radius:10px;background:#fbfbfd;">
                                <div style="margin:0 0 8px;color:#667085;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;">
                                    {{ $approved ? 'Observacoes da aprovacao' : 'Motivo da reprovacao' }}
                                </div>
                                <p style="margin:0;font-size:14px;line-height:1.6;color:#111827;">{{ $observacao ?: 'Sem observacoes informadas.' }}</p>
                            </div>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0;">
                                <tr>
                                    <td style="padding:0 10px 0 0;">
                                        <a href="{{ $url }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700;">
                                            {{ $approved ? 'Abrir RNC' : 'Enviar nova proposta' }}
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
