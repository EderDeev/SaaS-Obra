import EvidenceButton from './EvidenceButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmActionButton from '@/Components/ConfirmActionButton';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Pencil, Send } from 'lucide-react';
import { Attachments, Card, Detail, Errors, Photos, Status, events, shortDate, timeLabel } from './Ui';

export default function Show({ tenant, ic, permissions, statuses, resolutionOptions }) {
    const { flash, errors } = usePage().props;
    const finished = ic.status === 'closed';
    const active = finished ? 2 : ic.status === 'notified' ? 1 : 0;
    const evidenceEvents = ic.events.filter((event) => ['finalized', 'correction_submitted'].includes(event.type));
    return <AuthenticatedLayout><Head title={`IC ${ic.formatted_number}`} />
        <main className="sig-content mx-auto grid w-full max-w-7xl gap-5">
            <header className="flex flex-wrap items-center justify-between gap-4">
                <div className="min-w-0"><Link className="mb-3 inline-flex items-center gap-2 text-sm text-[var(--ink-500)]" href={route('tenant.qualidade.ic.index', tenant.slug)}><ArrowLeft size={15} />Instruções de Campo</Link><h1 className="break-words text-2xl font-semibold">{ic.assunto || `IC ${ic.formatted_number}`}</h1><p className="mt-1 text-sm text-[var(--ink-500)]">IC {ic.formatted_number} · {ic.contract?.code}</p></div>
                <div className="flex flex-wrap items-center gap-2"><Status value={ic.status} statuses={statuses} />
                    {ic.status === 'notified' && permissions.close_ic && <EvidenceButton tenant={tenant} icId={ic.id} />}
                    {ic.status === 'draft' && permissions.edit_ic && <Link className="sig-btn sig-btn-secondary" href={route('tenant.qualidade.ic.edit', [tenant.slug, ic.id])}><Pencil size={15} />Editar</Link>}
                    {ic.status === 'draft' && permissions.notify_ic && <ConfirmActionButton title="Comunicar IC" message="Comunicar esta instrução à construtora, ao cliente e à Supervisão?" confirmLabel="Comunicar IC" className="sig-btn sig-btn-primary" tone="success" onConfirm={() => router.post(route('tenant.qualidade.ic.notify', [tenant.slug, ic.id]), {}, { preserveScroll: true })}><Send size={15} />Comunicar IC</ConfirmActionButton>}
                </div>
            </header>
            {flash?.success && <p role="status" className="rounded-xl bg-green-50 p-4 text-sm text-green-800">{flash.success}</p>}<Errors errors={errors} />
            <ol className="grid gap-3 sm:grid-cols-3">{['Instrução', 'Comunicação aos envolvidos', 'Evidências e finalização'].map((label, index) => <li key={label} className={`sig-card flex items-center gap-3 p-4 ${index === active ? 'border-blue-400 bg-blue-50' : ''}`}><span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold ${index <= active ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500'}`}>{finished || index < active ? <Check size={15} /> : index + 1}</span><span className="text-sm font-medium">{label}</span></li>)}</ol>
            <Card title="Identificação"><dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><Detail label="Contrato">{ic.contract?.code} · {ic.contract?.name}</Detail><Detail label="Obra">{ic.obra?.codigo} · {ic.obra?.nome}</Detail><Detail label="Data de abertura">{shortDate(ic.opened_at)}</Detail><Detail label="Contratante / cliente">{ic.contratante?.nome}</Detail><Detail label="Construtora">{ic.contratada?.nome}</Detail><Detail label="Disciplina">{ic.disciplina?.nome}</Detail><Detail label="Gravidade">{ic.gravidade}</Detail><Detail label="Registrada por">{ic.creator?.name}</Detail></dl></Card>
            <Card title="Instrução"><p className="whitespace-pre-wrap break-words text-sm leading-7">{ic.instrucao}</p></Card>
            <Card title="Registro fotográfico da abertura"><Photos tenant={tenant} ic={ic} photos={ic.photos.filter((photo) => !photo.event_id)} /></Card>
            {evidenceEvents.map((event) => <Card key={event.id} title={event.type === 'finalized' ? 'Evidências da Supervisão' : 'Registro anterior da correção'}><p className="mb-3 text-xs text-[var(--ink-500)]">{event.user?.name} · {timeLabel(event.created_at)}</p>{event.comment && <p className="mb-4 whitespace-pre-wrap break-words text-sm leading-7">{event.comment}</p>}{ic.photos.some((photo) => photo.event_id === event.id) && <Photos tenant={tenant} ic={ic} photos={ic.photos.filter((photo) => photo.event_id === event.id)} />}<Attachments tenant={tenant} ic={ic} attachments={(ic.attachments || []).filter((attachment) => attachment.event_id === event.id)} /></Card>)}
            {finished && <Card title="Finalização"><dl className="grid gap-5 sm:grid-cols-3"><Detail label="O problema foi resolvido?"><span className={`sig-pill ${ic.resolution_result === 'yes' ? 'sig-pill-green' : ic.resolution_result === 'no' ? 'sig-pill-red' : 'sig-pill-blue'}`}>{resolutionOptions[ic.resolution_result] || 'Não informado'}</span></Detail><Detail label="Finalizada por">{ic.closed_by?.name}</Detail><Detail label="Data de finalização">{timeLabel(ic.closed_at)}</Detail></dl>{ic.closing_comment && !ic.events.some((event) => event.type === 'finalized') && <p className="mt-4 whitespace-pre-wrap break-words text-sm">{ic.closing_comment}</p>}</Card>}
            <Card title="Fluxo da IC"><ol className="grid gap-5">{ic.events.map((event) => <li key={event.id} className="border-l-2 border-blue-200 pl-4"><div className="flex flex-wrap items-center justify-between gap-2"><h3 className="text-sm font-semibold">{events[event.type] || event.type}</h3><time className="text-xs text-[var(--ink-500)]">{timeLabel(event.created_at)}</time></div><p className="mt-1 text-xs text-[var(--ink-500)]">{event.user?.name}</p>{event.comment && !['finalized', 'correction_submitted'].includes(event.type) && <p className="mt-2 whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-sm">{event.comment}</p>}</li>)}</ol></Card>
        </main>
    </AuthenticatedLayout>;
}
