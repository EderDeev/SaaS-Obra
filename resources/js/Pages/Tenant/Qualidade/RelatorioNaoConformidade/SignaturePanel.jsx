import OsDnitConfirmDialog from '@/Components/OsDnitConfirmDialog';
import { Link, useForm } from '@inertiajs/react';
import { FileCheck2, RefreshCw, Send } from 'lucide-react';
import { useState } from 'react';
import { Errors } from '../InstrucaoCampo/Ui';

const roles = { cliente: 'Cliente', gerenciadora: 'Gerenciadora', construtora: 'Construtora' };
const labels = { draft: 'Preparando documento', sent: 'Enviada para assinatura', pending: 'Aguardando assinaturas', completed: 'Assinaturas concluídas', failed: 'Falha no envio', expired: 'Solicitação expirada', cancelled: 'Solicitação cancelada', declined: 'Assinatura recusada' };

export default function SignaturePanel({ tenant, rnc, data }) {
    const form = useForm({});
    const [confirm, setConfirm] = useState(false);
    const request = data.request;
    const active = request && ['draft', 'sent', 'pending', 'completed'].includes(request.status);
    const signers = active ? request.signers : data.configured;
    const canManage = rnc.user_permissions?.includes('evidence_rnc');
    const hasEvidence = rnc.evidencias?.length > 0;
    const canSend = canManage && hasEvidence && !active && signers.length > 0 && !data.configuration_error && data.integration_ready;
    const allSigned = signers.length > 0 && signers.every((signer) => signer.status === 'completed');
    return <section id="assinaturas" className="sig-card min-w-0 overflow-hidden">
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5"><h2 className="flex items-center gap-2 text-base font-semibold"><FileCheck2 size={18} />Assinaturas das partes</h2><span className={`sig-pill ${data.has_signed_pdf ? 'sig-pill-green' : 'sig-pill-amber'}`}>{data.has_signed_pdf ? 'Documento assinado' : allSigned ? 'Aguardando PDF assinado' : request ? labels[request.status] || request.status : 'Aguardando envio'}</span></header>
        <div className="grid gap-5 p-5"><Errors errors={form.errors} />
            <p className="text-sm text-[var(--ink-500)]">Após o envio, o OpenSign encaminha por e-mail o documento final para as partes selecionadas. A RNC será finalizada quando todas assinarem e o PDF assinado estiver disponível.</p>
            {!hasEvidence && <p className="text-sm text-slate-500">Registre as evidências para liberar o envio para assinatura.</p>}
            {signers.length ? <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{signers.map((signer) => <li key={signer.role} className="min-w-0 rounded-lg border border-slate-200 p-4"><p className="text-xs font-semibold text-blue-700">{roles[signer.role]}</p><p className="mt-2 break-words text-sm font-semibold">{signer.name}</p><p className="mt-1 break-all text-xs text-slate-500">{signer.email}</p>{signer.status && <p className={`mt-3 text-xs font-semibold ${signer.status === 'completed' ? 'text-green-700' : 'text-amber-700'}`}>{signer.status === 'completed' ? 'Assinou' : signer.status === 'declined' ? 'Recusou' : 'Aguardando assinatura'}</p>}</li>)}</ul> : <p className="text-sm text-slate-500">Nenhuma parte selecionada. Configure os responsáveis pelas assinaturas.</p>}
            {data.configuration_error && <p role="alert" className="text-sm text-amber-800">{data.configuration_error}</p>}
            {!data.integration_ready && !active && <p className="text-sm text-amber-800">A integração OpenSign precisa estar configurada para realizar o envio.</p>}
            {request?.error_message && <p role="alert" className="text-sm text-amber-800">{request.error_message}</p>}
            <div className="flex flex-wrap justify-end gap-3">
                {rnc.user_permissions?.includes('responsibles_rnc') && <Link href={route('tenant.qualidade.rnc.responsaveis.index', tenant.slug)} className="sig-btn sig-btn-secondary">Configurar responsáveis</Link>}
                {request?.has_original && <a href={route('tenant.qualidade.rnc.signatures.unsigned', [tenant.slug, rnc.id, request.id])} className="sig-btn sig-btn-secondary">Documento enviado</a>}
                {canManage && active && !data.has_signed_pdf && <button type="button" disabled={form.processing} className="sig-btn sig-btn-secondary" onClick={() => form.post(route('tenant.qualidade.rnc.signatures.refresh', [tenant.slug, rnc.id, request.id]), { preserveScroll: true })}><RefreshCw size={15} />{form.processing ? 'Atualizando...' : 'Atualizar assinaturas'}</button>}
                {canSend && <button type="button" disabled={form.processing} className="sig-btn sig-btn-primary" onClick={() => setConfirm(true)}><Send size={15} />Enviar para assinatura</button>}
            </div>
        </div>
        <OsDnitConfirmDialog open={confirm} title="Enviar RNC para assinatura" message="Enviar o documento completo por e-mail para as partes selecionadas? A RNC aguardará as assinaturas e seus dados ficarão bloqueados para edição após o envio." confirmLabel="Enviar para assinatura" disabled={form.processing} onCancel={() => setConfirm(false)} onConfirm={() => { setConfirm(false); form.post(route('tenant.qualidade.rnc.signatures.store', [tenant.slug, rnc.id]), { preserveScroll: true }); }} />
    </section>;
}
