<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $headline }}</title></head>
<body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;"><tr><td align="center">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
        <tr><td style="background:#0b5fff;color:#ffffff;padding:22px 26px;"><div style="font-size:12px;font-weight:700;">SigWorks · OS DNIT</div><h1 style="margin:10px 0 0;font-size:22px;line-height:1.3;">{{ $headline }}</h1></td></tr>
        <tr><td style="padding:26px;">
            <p style="font-size:15px;line-height:1.6;">Olá, {{ $notifiable->name }}.</p>
            <p style="font-size:15px;line-height:1.6;">{{ $nextStep }}</p>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:20px 0;">
                @foreach (['OS' => $snapshot['request_number'], 'Assunto' => $snapshot['subject'], 'Contrato' => $contract->code.' — '.$contract->name, 'Etapa desta atualização' => $statusLabel, 'Ação realizada por' => $event->actor->name, 'Data' => $occurredAt] as $label => $value)
                    <tr><td style="padding:10px 0;border-bottom:1px solid #eef2f7;color:#667085;font-size:13px;width:36%;vertical-align:top;">{{ $label }}</td><td style="padding:10px 0 10px 12px;border-bottom:1px solid #eef2f7;font-size:14px;word-break:break-word;">{{ $value }}</td></tr>
                @endforeach
                @if ($snapshot['estimated_deadline'])
                    <tr><td style="padding:10px 0;color:#667085;font-size:13px;">Prazo estimado Concremat</td><td style="padding:10px 0 10px 12px;font-size:14px;">{{ \Carbon\Carbon::parse($snapshot['estimated_deadline'])->format('d/m/Y') }}</td></tr>
                @endif
            </table>
            @if ($event->comment)
                <div style="margin:20px 0;padding:16px;border:1px solid #dbe7ff;border-radius:10px;background:#f5f8ff;"><strong style="font-size:14px;">{{ $event->action === 'returned' ? 'Justificativa da reprovação / devolução' : 'Comentário' }}</strong><p style="margin:10px 0 0;font-size:14px;line-height:1.6;white-space:pre-wrap;word-break:break-word;">{{ $event->comment }}</p></div>
            @endif
            <a href="{{ $url }}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700;">Acessar OS</a>
            <p style="margin:20px 0 0;font-size:12px;line-height:1.6;color:#667085;">Aviso referente à etapa registrada em {{ $occurredAt }}. Consulte a situação mais recente e o histórico completo no sistema.</p>
        </td></tr>
    </table>
</td></tr></table>
</body></html>
