import OsDnitPdfDownload from '@/Components/OsDnitPdfDownload';
import OsDnitDeleteButton from '@/Components/OsDnitDeleteButton';
import { StatusBadge, SupervisionAnalysis, FinalizeAction, DraftActions, ReviewForm, DecisionForm, OrderHistory } from '@/Components/OsDnitWorkflow';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { dateLabel, numberLabel } from '@/Components/OsDnitOrderUi';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Download, Paperclip } from 'lucide-react';

function Detail({ label, children }) {
    return <div className="min-w-0"><dt className="text-xs font-semibold text-[var(--ink-500)]">{label}</dt><dd className="mt-1 whitespace-pre-wrap break-words text-sm text-[var(--ink-900)]">{children || '—'}</dd></div>;
}

export default function ShowOrder({ order, frequencies, attachmentLinks, statuses, capabilities, products, categories, analysisMode = false }) {
    const { currentTenant, flash = {} } = usePage().props;
    const latestReturn = [...order.events].reverse().find((e) => e.action === 'returned');
    return <AuthenticatedLayout>
        <Head title={`Ordem de Serviço · ${analysisMode ? 'Análise · ' : ''}OS ${order.request_number}`} />
        <div className="sig-content os-dnit-page space-y-6">
            <header><Link href={route(analysisMode ? 'tenant.os-dnit.analysis.index' : 'tenant.os-dnit.orders.index', currentTenant.slug)} className="inline-flex items-center gap-2 text-sm text-[var(--ink-500)]"><ArrowLeft size={16} />{analysisMode ? 'Voltar às análises' : 'Voltar às ordens de serviço'}</Link><div className="mt-4 flex flex-wrap items-center gap-3"><h1 className="text-2xl font-semibold">OS {order.request_number}</h1><StatusBadge status={order.status} statuses={statuses} /><OsDnitPdfDownload order={order} tenantSlug={currentTenant.slug} className="ml-auto" /></div><p className="mt-2 break-words text-sm text-[var(--ink-500)]">{order.subject}</p></header>
            {flash.success && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{flash.success}</div>}
            <section className="sig-card p-5" aria-label="Fluxo de análise"><ol className="grid gap-3 sm:grid-cols-4">{[
                ['Analista DNIT', ['draft', 'returned_analyst']], ['Supervisão', ['awaiting_concremat', 'returned_concremat']], ['Fiscal do contrato', ['awaiting_contract', 'approved']], ['Finalização', ['completed']],
            ].map(([label, steps], i) => <li key={label} aria-current={steps.includes(order.status) ? 'step' : undefined} className={`rounded-lg border px-4 py-3 text-sm ${steps.includes(order.status) ? 'border-blue-200 bg-blue-50 font-semibold text-blue-800' : 'border-slate-100 text-slate-500'}`}>{i + 1}. {label}</li>)}</ol>{!capabilities.submit && !capabilities.review && !capabilities.decide && !['approved', 'completed'].includes(order.status) && <p className="mt-3 text-xs text-slate-500">As ações desta etapa são disponibilizadas aos usuários cadastrados em <Link href={route('tenant.os-dnit.responsibles.index', currentTenant.slug)} className="font-medium text-blue-700 underline">Responsáveis</Link> para este contrato.</p>}</section>
            {!analysisMode && (capabilities.review || capabilities.decide) && <section className="sig-card flex flex-wrap items-center justify-between gap-4 p-5"><p className="text-sm text-slate-600">Esta OS aguarda sua {capabilities.review ? 'análise Supervisão' : 'decisão como fiscal do contrato'}.</p><Link href={route('tenant.os-dnit.orders.index', { tenant: currentTenant.slug, analysis_order_id: order.id })} className="sig-btn sig-btn-primary">Analisar OS</Link></section>}
            {analysisMode && !capabilities.review && !capabilities.decide && <p role="status" className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">Não há análise pendente para você nesta etapa. Consulte o andamento ou volte à lista de análises.</p>}
            {order.status.startsWith('returned') && latestReturn && <div role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900"><p className="font-semibold">{statuses[order.status]}</p><p className="mt-2 whitespace-pre-wrap break-words text-sm">{latestReturn.comment}</p></div>}
            {!analysisMode && capabilities.submit && <DraftActions key={order.workflow_version} order={order} editable={capabilities.edit} />}
            {capabilities.delete && <div className="flex justify-end"><OsDnitDeleteButton url={route('tenant.os-dnit.orders.destroy', [currentTenant.slug, order.id])} data={{ version: order.workflow_version }} label={`Excluir OS ${order.request_number}`} title="Excluir demanda?" message={`Deseja excluir a demanda da OS ${order.request_number}? Ele deixará de aparecer nas listagens.`} /></div>}
            <section className="sig-card"><h2 className="border-b border-slate-200 px-5 py-4 text-lg font-semibold">Identificação da demanda</h2><dl className="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
                <Detail label="Número da solicitação">{order.request_number}</Detail><Detail label="Contrato de supervisão">{order.contract.code} — {order.contract.name}</Detail><Detail label="Lote de supervisão">{order.supervision_lot}</Detail>
                <Detail label="Rodovia">{order.highway_name}</Detail><Detail label="Km inicial">{numberLabel(order.km_start)}</Detail><Detail label="Km final">{numberLabel(order.km_end)}</Detail>
                <Detail label="Assunto da solicitação">{order.subject}</Detail><Detail label="Prazo pretendido">{dateLabel(order.desired_deadline)}</Detail><Detail label="Referência SEI / processo">{order.sei_reference}</Detail>
                <div className="sm:col-span-2 lg:col-span-3"><Detail label="Descrição da necessidade técnica">{order.technical_need}</Detail></div><div className="sm:col-span-2 lg:col-span-3"><Detail label="Justificativa / resultado esperado">{order.justification}</Detail></div>
                <Detail label="Solicitante">{order.creator.name}</Detail><Detail label="Data do cadastro">{dateLabel(order.created_at)}</Detail>
            </dl></section>
            {order.review_items && <section className="sig-card">
                <h2 className="border-b border-slate-200 px-5 py-4 text-lg font-semibold">Análise Supervisão</h2>
                {order.status !== 'awaiting_contract' && !['approved', 'completed'].includes(order.status) && <p className="px-5 pt-4 text-xs text-slate-500">Análise Supervisão · Em elaboração</p>}
                <SupervisionAnalysis items={order.review_items} deadline={order.estimated_deadline} comment={order.review_comment} />
            </section>}
            {analysisMode && capabilities.review && <ReviewForm key={order.workflow_version} order={order} products={products} categories={categories} frequencies={frequencies} />}
            {analysisMode && capabilities.decide && <DecisionForm key={order.workflow_version} order={order} />}
            <section className="sig-card"><h2 className="flex items-center gap-2 border-b border-slate-200 px-5 py-4 text-lg font-semibold"><Paperclip size={18} />Anexos</h2><div className="divide-y divide-slate-100 px-5">{order.attachments.map((attachment) => <div key={attachment.id} className="flex flex-wrap items-center justify-between gap-3 py-4"><div className="min-w-0 flex-1"><p className="break-all text-sm font-medium">{attachment.original_name}</p><p className="mt-1 text-xs text-[var(--ink-500)]">{(attachment.size_bytes / 1024 / 1024).toFixed(2)} MB</p></div><a href={attachmentLinks[attachment.id]} className="sig-btn sig-btn-secondary"><Download size={16} />Baixar</a></div>)}{order.attachments.length === 0 && <p className="py-5 text-sm text-[var(--ink-500)]">Nenhum anexo informado.</p>}</div></section>
            {capabilities.finalize && <section className="sig-card flex flex-wrap items-center justify-between gap-4 p-5"><div><h2 className="font-semibold">Aguardando conclusão do serviço</h2><p className="mt-1 text-sm text-slate-500">A demanda foi aprovada. Finalize a OS quando o serviço estiver concluído.</p></div><FinalizeAction key={order.workflow_version} order={order} tenantSlug={currentTenant.slug} /></section>}
            {order.status === 'completed' && <p className="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">Serviço concluído. OS finalizada em {dateLabel(order.finalized_at)}.</p>}
            <OrderHistory events={order.events} statuses={statuses} />
        </div>
    </AuthenticatedLayout>;
}
