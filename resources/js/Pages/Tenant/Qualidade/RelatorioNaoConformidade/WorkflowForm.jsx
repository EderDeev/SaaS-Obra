import OsDnitConfirmDialog from '@/Components/OsDnitConfirmDialog';
import { rncDisciplinaLabel } from '@/Support/rnc';
import { Link, useForm } from '@inertiajs/react';
import { Download, Send } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import PhotoRegister from '../InstrucaoCampo/PhotoRegister';
import { Card, Errors } from '../InstrucaoCampo/Ui';

export const workflowStages = {
    proposal: { title: 'Proposta de ação corretiva', route: 'acao-corretiva', submit: 'Enviar proposta' },
    review: { title: 'Análise da proposta', route: 'analisar-proposta', submit: 'Enviar análise' },
    evidence: { title: 'Evidências da correção', route: 'evidencias', submit: 'Registrar evidências' },
};

const dateLabel = (date) => date ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(new Date(/^\d{4}-\d{2}-\d{2}$/.test(date) ? `${date}T12:00:00` : date)) : '—';
const statusLabels = { pending: 'Em análise', approved: 'Aprovada', rejected: 'Reprovada' };

function Text({ title, children }) {
    return <div className="min-w-0"><h3 className="mb-2 text-xs font-semibold text-[var(--ink-500)]">{title}</h3><p className="whitespace-pre-wrap break-words text-sm leading-6">{children || '—'}</p></div>;
}

function Proposal({ action, title }) {
    return <Card title={title}><div className="grid gap-4">
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--ink-500)]"><span>{action.user?.name} · {dateLabel(action.submitted_at)}</span><span className={`sig-pill ${action.status === 'approved' ? 'sig-pill-green' : action.status === 'rejected' ? 'sig-pill-red' : 'sig-pill-amber'}`}>{statusLabels[action.status] || action.status}</span></div>
        <Text title="Descrição da proposta">{action.descricao_proposta}</Text><Text title="Prazo proposto">{action.prazo_execucao_proposto_formatted || dateLabel(action.prazo_execucao_proposto)}</Text>
        {action.review_observation && <Text title="Observações da análise">{action.review_observation}</Text>}
        {(action.download_url || action.url) && <a href={action.download_url || action.url} className="sig-btn sig-btn-secondary justify-self-start" download><Download size={15} /><span className="min-w-0 break-words">{action.attachment_original_name || 'Baixar anexo da proposta'}</span></a>}
    </div></Card>;
}

export default function WorkflowForm({ stage, tenant, rnc, acaoCorretiva, acoesCorretivas = [], resolutionOptions = {}, modal = false, onCancel, onCompleted, onBusy, onDirty }) {
    const config = workflowStages[stage];
    const formId = useId();
    const errors = useRef(null);
    const [photos, setPhotos] = useState([]);
    const [confirm, setConfirm] = useState(false);
    const form = useForm({
        ...(stage === 'proposal' ? { descricao_proposta: '', prazo_execucao_proposto: '', attachment: null }
            : stage === 'review' ? { decision: '', review_observation: '' } : { attachment: null, resolution_result: '', resolution_reason: '' }),
        from_modal: modal, proposal_id: acaoCorretiva?.id || null,
    });
    useEffect(() => { onBusy?.(form.processing); return () => onBusy?.(false); }, [form.processing, onBusy]);
    useEffect(() => { onDirty?.(form.isDirty || photos.length > 0); return () => onDirty?.(false); }, [form.isDirty, photos.length, onDirty]);
    useEffect(() => {
        if (Object.keys(form.errors).length) { errors.current?.focus(); errors.current?.scrollIntoView({ block: 'nearest' }); }
    }, [form.errors]);

    const needsReason = stage === 'evidence' && ['partial', 'no'].includes(form.data.resolution_result);
    const ready = stage === 'proposal' ? Boolean(form.data.descricao_proposta.trim() && form.data.prazo_execucao_proposto && form.data.attachment)
        : stage === 'review' ? Boolean(form.data.decision && form.data.review_observation.trim())
            : Boolean(photos.length && form.data.attachment && form.data.resolution_result && (!needsReason || form.data.resolution_reason.trim()));
    const chooseZip = (event) => {
        const file = event.target.files?.[0] || null;
        if (file && (!/\.zip$/i.test(file.name) || file.size > 30 * 1024 * 1024)) {
            event.target.value = ''; form.setData('attachment', null); form.setError('attachment', 'Envie um arquivo ZIP de até 30 MB.'); return;
        }
        form.clearErrors('attachment'); form.setData('attachment', file);
    };
    const submit = () => {
        if (form.processing || !ready) return;
        setConfirm(false);
        form.transform((data) => stage === 'evidence' ? { ...data,
            evidence_photos: photos.map((photo) => photo.file), evidence_photo_comments: photos.map((photo) => photo.comment),
            evidence_photo_positions: photos.map((_, index) => index + 1),
        } : data);
        form.post(route(`tenant.qualidade.rnc.${config.route}.store`, [tenant.slug, rnc.id]), {
            forceFormData: stage !== 'review', preserveScroll: true, onSuccess: () => onCompleted?.(),
        });
    };
    const photoErrors = Object.fromEntries(Object.entries(form.errors).filter(([key]) => key.startsWith('evidence_photo')));
    if (Object.keys(photoErrors).length) photoErrors.photos = Object.values(photoErrors).join(' ');
    const history = acoesCorretivas.filter((action) => action.id !== acaoCorretiva?.id);

    return <>
        <div className={modal ? 'os-dnit-analysis-form flex min-h-0 flex-1 flex-col' : 'grid gap-5'} aria-busy={form.processing}>
            <div className={modal ? 'os-dnit-analysis-body min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-7' : ''}>
                <div ref={errors} tabIndex={-1} className="mb-4 focus:outline-none"><Errors errors={form.errors} /></div>
                <div className="grid items-start gap-5 lg:grid-cols-2">
                    <div className="grid min-w-0 gap-5">
                        <Card title="Resumo da não conformidade"><div className="grid gap-4">
                            <div className="flex flex-wrap gap-2"><span className="sig-pill sig-pill-blue">{rncDisciplinaLabel(rnc)}</span><span className="sig-pill">{rnc.gravidade}</span></div>
                            <div className="grid gap-4 sm:grid-cols-2"><Text title="Contrato">{[rnc.contract?.code, rnc.contract?.name].filter(Boolean).join(' · ')}</Text><Text title="Obra">{[rnc.obra?.codigo, rnc.obra?.nome].filter(Boolean).join(' · ')}</Text><Text title="Data de abertura">{dateLabel(rnc.opened_at)}</Text><Text title="Prazo de resposta">{dateLabel(rnc.prazo_resposta_acao_corretiva)}</Text><Text title="Contratante">{rnc.contratante?.nome}</Text><Text title="Contratada">{rnc.contratada?.nome}</Text></div>
                            <Text title="Descrição do problema">{rnc.descricao_problema}</Text><Text title="Ações corretivas recomendadas">{rnc.acoes_corretivas_recomendadas}</Text>
                            {rnc.observacao && <Text title="Observações">{rnc.observacao}</Text>}
                        </div></Card>
                        <Card title="Registro fotográfico da abertura">{rnc.photos?.length ? <div className="grid gap-3 sm:grid-cols-2">{rnc.photos.map((photo) => <figure key={photo.id} className="min-w-0 overflow-hidden rounded-lg border border-[var(--border)]"><a href={photo.url} target="_blank" rel="noreferrer"><img src={photo.url} alt={photo.comment || photo.original_name || 'Foto da abertura'} className="h-44 w-full bg-slate-50 object-contain" loading="lazy" /></a><figcaption className="break-words p-3 text-xs text-[var(--ink-500)]">{photo.comment || photo.original_name}</figcaption></figure>)}</div> : <p className="text-sm text-[var(--ink-500)]">Nenhuma imagem cadastrada nesta RNC.</p>}</Card>
                    </div>
                    <div className="grid min-w-0 gap-5">
                        {acaoCorretiva && <Proposal action={acaoCorretiva} title={stage === 'evidence' ? 'Proposta aprovada' : 'Proposta em análise'} />}
                        <Card title={config.title}><form id={formId} onSubmit={(event) => { event.preventDefault(); if (!form.processing && ready) setConfirm(true); }}>
                            <fieldset disabled={form.processing} className="grid min-w-0 gap-5"><legend className="sr-only">{config.title}</legend>
                                {stage === 'proposal' && <>
                                    <label className="grid gap-2 text-sm font-medium">Descrição da proposta<textarea className="sig-input min-h-40 w-full resize-y" value={form.data.descricao_proposta} onChange={(event) => form.setData('descricao_proposta', event.target.value)} maxLength={10000} required placeholder="Descreva como a não conformidade será tratada." /></label>
                                    <label className="grid gap-2 text-sm font-medium">Prazo proposto para executar a ação<input type="date" className="sig-input w-full" value={form.data.prazo_execucao_proposto} onChange={(event) => form.setData('prazo_execucao_proposto', event.target.value)} required /></label>
                                </>}
                                {stage === 'review' && <>
                                    <fieldset className="grid gap-3"><legend className="mb-3 text-sm font-semibold">Decisão sobre a proposta</legend>{[['approved', 'Aprovar proposta', 'Autorizar o início da correção.'], ['rejected', 'Reprovar proposta', 'Solicitar uma nova proposta.']].map(([value, label, description]) => <label key={value} className={`flex cursor-pointer items-start gap-3 rounded-lg border p-4 ${form.data.decision === value ? value === 'approved' ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50' : 'border-[var(--border)]'}`}><input type="radio" name={`${formId}-decision`} className="mt-1" value={value} checked={form.data.decision === value} onChange={() => form.setData('decision', value)} required /><span className="min-w-0 text-sm"><strong className="block">{label}</strong><span className="mt-1 block text-xs text-[var(--ink-500)]">{description}</span></span></label>)}</fieldset>
                                    <label className="grid gap-2 text-sm font-medium">{form.data.decision === 'rejected' ? 'Motivo da reprovação' : 'Observações da análise'}<textarea className="sig-input min-h-40 w-full resize-y" value={form.data.review_observation} onChange={(event) => form.setData('review_observation', event.target.value)} maxLength={10000} required placeholder="Registre seu parecer sobre a proposta." /></label>
                                </>}
                                {stage === 'evidence' && <>
                                    <PhotoRegister photos={photos} onChange={setPhotos} errors={photoErrors} disabled={form.processing} />
                                    <fieldset className="grid gap-3"><legend className="mb-3 text-sm font-semibold">O problema foi resolvido?</legend><div className="grid gap-3">{Object.entries(resolutionOptions).map(([value, label]) => <label key={value} className={`flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-sm ${form.data.resolution_result === value ? value === 'yes' ? 'border-green-300 bg-green-50' : value === 'partial' ? 'border-amber-300 bg-amber-50' : 'border-red-300 bg-red-50' : 'border-[var(--border)]'}`}><input type="radio" name={`${formId}-resolution`} value={value} checked={form.data.resolution_result === value} onChange={() => { form.setData((current) => ({ ...current, resolution_result: value, resolution_reason: value === 'yes' ? '' : current.resolution_reason })); form.clearErrors('resolution_result', 'resolution_reason'); }} required />{label}</label>)}</div></fieldset>
                                    {needsReason && <label className="grid gap-2 text-sm font-medium">{form.data.resolution_result === 'partial' ? 'Motivo da resolução parcial' : 'Motivo de não resolução'}<textarea className="sig-input min-h-32 w-full resize-y" value={form.data.resolution_reason} onChange={(event) => form.setData('resolution_reason', event.target.value)} maxLength={10000} required placeholder="Explique o que ficou pendente e por quê." /></label>}
                                </>}
                                {stage !== 'review' && <>
                                    <label className="grid gap-2 text-sm font-medium">{stage === 'evidence' ? 'Documento ZIP das evidências' : 'Anexo ZIP da proposta'}<input type="file" className="block w-full min-w-0 text-sm" accept=".zip,application/zip" onChange={chooseZip} required /></label>
                                    <p className="text-xs text-[var(--ink-500)]">Arquivo ZIP de até 30 MB.</p>
                                    {form.data.attachment && <p className="break-words rounded-lg bg-slate-50 p-3 text-sm">{form.data.attachment.name} · {(form.data.attachment.size / 1024 / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} MB</p>}
                                </>}
                                {form.progress && <p role="status" className="text-sm text-[var(--ink-500)]">Enviando anexos: {form.progress.percentage}%</p>}
                            </fieldset>
                        </form></Card>
                        {history.length > 0 && <details className="sig-card p-4"><summary className="cursor-pointer text-sm font-semibold">Histórico de propostas ({history.length})</summary><div className="mt-4 grid gap-4">{history.map((action) => <Proposal key={action.id} action={action} title="Proposta anterior" />)}</div></details>}
                    </div>
                </div>
            </div>
            <footer className={modal ? 'os-dnit-analysis-footer flex shrink-0 justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-7' : 'sig-card flex justify-end p-4'}><div className="flex flex-wrap justify-end gap-3">
                {modal ? <button type="button" onClick={onCancel} disabled={form.processing} className="sig-btn sig-btn-secondary">Cancelar</button> : <Link href={route('tenant.qualidade.rnc.show', [tenant.slug, rnc.id])} className="sig-btn sig-btn-secondary">Voltar à RNC</Link>}
                <button form={formId} type="submit" disabled={form.processing || !ready} className="sig-btn sig-btn-primary"><Send size={16} />{form.processing ? 'Enviando...' : config.submit}</button>
            </div></footer>
        </div>
        <OsDnitConfirmDialog open={confirm} title={config.submit} confirmLabel={config.submit} message={stage === 'review' ? `Confirmar a ${form.data.decision === 'approved' ? 'aprovação' : 'reprovação'} desta proposta?` : stage === 'evidence' ? `Registrar as evidências desta RNC com o resultado “${resolutionOptions[form.data.resolution_result]}”?` : 'Enviar esta proposta de ação corretiva para análise?'} disabled={form.processing} onCancel={() => setConfirm(false)} onConfirm={submit} />
    </>;
}
