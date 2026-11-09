import { CheckCircle2, ChevronDown, ClipboardPenLine, FileText, Paperclip, Save, UnfoldVertical, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import referenceImage from '@/assets/rdo-sections.png';
import photoSectionImage from '@/assets/rdo-photo-section.png';
import { partyLabels } from './WorkflowEditor';
import { rdoParty } from './partyAppearance';

export function isImpracticableDay(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

const documentSections = [
    ['clima', 'Condições do tempo', 'Condições e chuva nos três períodos do dia'],
    ['mao_obra', 'Mão de obra', 'Efetivo direto, indireto e subcontratado'],
    ['equipamentos', 'Equipamentos', 'Equipamentos e situação operacional'],
    ['atividades', 'Atividades', 'Serviços executados e ocorrências do dia'],
    ['comentarios', 'Comentários', 'Considerações das partes envolvidas'],
    ['fotos', 'Registro fotográfico', 'Imagens e comentários desta frente'],
];
const imageOffsets = { clima: 74, mao_obra: 253, equipamentos: 433, atividades: 613, comentarios: 793 };

function sectionsForParty(rdo, party) {
    if (!rdo.parallel_manager_filling || party !== 'gerenciadora') return documentSections;

    return documentSections.map(section => section[0] === 'fotos' ? ['fotos', 'Evidências', 'Fotos e anexos desta frente'] : section);
}

function inspectionRecordsForObra(rdo, obraId) {
    return (rdo.inspection_records || []).filter(record => Number(record.obra_id) === Number(obraId) && record.is_current_version);
}

function SectionImage({ section }) {
    return section === 'fotos' ? <span aria-hidden="true" className="block h-10 w-10 shrink-0 rounded-md bg-[#f4f4f4]" style={{ backgroundImage: `url(${photoSectionImage})`, backgroundSize: '66px 66px', backgroundPosition: 'center', backgroundRepeat: 'no-repeat' }} />
        : <span aria-hidden="true" className="block h-10 w-10 shrink-0 rounded-md" style={{ backgroundImage: `url(${referenceImage})`, backgroundSize: '700.5px 160.5px', backgroundPosition: `-${imageOffsets[section] * 0.75}px -35.25px` }} />;
}

export function RdoSection({ section, title, description, children, editing, first = false, collapsed = false }) {
    return <details className="group overflow-hidden rounded-xl border border-[var(--border)] bg-white" open={!collapsed && (!editing || first)}>
        <summary className="flex cursor-pointer list-none items-center gap-2.5 px-3 py-2 marker:content-none [&::-webkit-details-marker]:hidden sm:px-4">
            <SectionImage section={section} />
            <div className="min-w-0 flex-1"><h2 className="text-sm font-semibold">{title}</h2></div>
            <ChevronDown size={17} className="shrink-0 text-slate-400 transition group-open:rotate-180" />
        </summary>
        <div className="border-t border-[var(--border)] px-3 py-3 sm:px-4">{children}</div>
    </details>;
}
const Section = RdoSection;

export default function DocumentWorkspace({ rdo, catalogs, recordCatalogs, form, mode, obraId, onObraChange, partySelector, renderDayFields, renderFields, onSave, canEdit, completeOpen, onCompleteOpenChange, onDownloadPdf, onAttachEvidence, renderInspectionEvidence, renderCompleteExtras, pendingWorks = 0 }) {
    const dialog = useRef(null);
    const dialogTitle = useId();
    useEffect(() => {
        if (!completeOpen || !dialog.current) return;
        const node = dialog.current;
        const previousOverflow = document.body.style.overflow;
        node.showModal();
        document.body.style.overflow = 'hidden';
        return () => { node.close(); document.body.style.overflow = previousOverflow; };
    }, [completeOpen]);
    const allObras = (rdo.obras?.length ? rdo.obras : [rdo.obra]).filter(Boolean);
    const [allFronts, setAllFronts] = useState(false);
    const scope = useRef(null);
    const [expanded, setExpanded] = useState(false);
    const visibleObras = allFronts ? allObras : allObras.filter(obra => Number(obra.id) === Number(obraId));
    const selectedObra = allObras.find(obra => Number(obra.id) === Number(obraId));
    const partySections = sectionsForParty(rdo, rdo.fill_party);
    const managerFilling = rdo.parallel_manager_filling && rdo.fill_party === 'gerenciadora';
    const complementaryEvidence = id => {
        const files = inspectionRecordsForObra(rdo, id).flatMap(record => record.evidence || []);
        return files.length ? renderInspectionEvidence?.(files) : null;
    };
    const submission = (rdo.versions || []).find(version => Number(version.number) === Number(rdo.current_version))
        || [...(rdo.versions || [])].sort((a, b) => Number(b.number) - Number(a.number))[0];
    useEffect(() => {
        scope.current?.querySelectorAll(`[data-workspace-mode="${mode}"] details`).forEach(node => { node.open = expanded; });
    }, [expanded, mode, obraId, allFronts, rdo.fill_party]);
    const errors = [...new Set(Object.values(form.errors || {}))];
    return <div ref={scope} data-rdo-party={rdoParty(rdo.fill_party)} className="mt-3 space-y-3">
        <div className="flex flex-wrap items-center gap-2">
            {partySelector}
            <button type="button" className="sig-btn sig-btn-sm ml-auto" onClick={() => setExpanded(value => !value)}><UnfoldVertical size={16} /> {expanded ? 'Recolher seções' : 'Expandir seções'}</button>
        </div>
        <div className="flex flex-wrap gap-2" aria-label="Frentes de serviço">
            {allObras.map(obra => {
                const selected = Number(obraId) === Number(obra.id) && (mode === 'edit' || !allFronts);
                const disabled = mode === 'edit' && !rdo.editable_obra_ids?.includes(Number(obra.id));
                return <button type="button" key={obra.id} disabled={disabled || form.processing} aria-pressed={selected} onClick={() => { if (onObraChange(obra.id) !== false) setAllFronts(false); }} className={`sig-btn sig-btn-sm !whitespace-normal text-left disabled:opacity-40 ${selected ? 'rdo-party-selected sig-btn-primary ring-2 ring-blue-200 ring-offset-2' : 'border border-[var(--border)] bg-white'}`}>{selected && <CheckCircle2 size={17} className="shrink-0" aria-hidden="true" />}<span>{obra.codigo} · {obra.nome}</span></button>;
            })}
            {mode === 'view' && allObras.length > 1 && <button type="button" className={`sig-btn sig-btn-sm ${allFronts ? 'rdo-party-selected sig-btn-primary' : 'border border-[var(--border)] bg-white'}`} aria-pressed={allFronts} onClick={() => setAllFronts(true)}>Todas as frentes</button>}
        </div>
        <section data-rdo-party={rdoParty(rdo.fill_party)} className="rdo-party-panel overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
            <header className="border-b border-[var(--border)] px-5 py-4"><div className="flex min-h-12 items-center gap-3"><span className="rdo-party-accent rounded-lg bg-blue-50 p-2 text-blue-600"><ClipboardPenLine size={20} aria-hidden="true" /></span><h2 className="rdo-party-label text-base font-semibold">Registros da {partyLabels[rdo.fill_party]?.toLocaleLowerCase('pt-BR')}</h2></div></header>
            <form hidden={mode !== 'edit'} onSubmit={onSave} data-workspace-mode="edit">
                <div className="space-y-2 p-4">
                    {selectedObra && <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <h3 className="font-bold">{selectedObra.codigo} · {selectedObra.nome}</h3>
                        <span className="text-xs text-[var(--ink-500)]">Envio até {rdo.submission_deadline_formatted}</span>
                    </div>}
                    {canEdit && !managerFilling && <div className="mb-3">{renderDayFields?.()}</div>}
                    {canEdit && partySections.map(([key, title, description]) => <Section key={`${obraId}-${key}`} section={key} title={title} description={description} collapsed>
                        {renderFields(key)}
                        {managerFilling && key === 'fotos' && <>
                            {complementaryEvidence(obraId)}
                            {onAttachEvidence && <button type="button" onClick={onAttachEvidence} disabled={form.processing} className="sig-btn mt-3"><Paperclip size={16} /> Anexar outros arquivos</button>}
                        </>}
                    </Section>)}
                    {errors.length > 0 && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{errors.map((error, i) => <p key={i}>{error}</p>)}</div>}
                </div>
                {canEdit && <div className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] p-4"><p className="text-xs text-[var(--ink-500)]">{form.isDirty || pendingWorks ? 'Há alterações pendentes. Todas as obras alteradas serão salvas juntas.' : 'Você pode alternar entre as obras e salvar todos os preenchimentos de uma vez.'}</p><button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary"><Save size={16} /> {form.processing ? 'Salvando…' : 'Salvar todas as obras'}</button></div>}
            </form>
            <div hidden={mode !== 'view'} data-workspace-mode="view">
                <div className="divide-y divide-[var(--border)]" data-contractor-records>{visibleObras.map(obra => <section key={obra.id} aria-label={`Registros de ${obra.nome}`} className="p-4">
                    <div><h3 className="font-bold">{obra.codigo} · {obra.nome}</h3><RecordAttribution submission={managerFilling ? inspectionRecordsForObra(rdo, obra.id)[0] : submission} showPending={!managerFilling} /></div>
                    <div className="mt-3 space-y-2">{partySections.map(([key, title, description]) => <Section key={key} section={key} title={title} description={description} collapsed>
                        <ReadSection section={key} data={rdo.sections?.[obra.id]?.[key] || {}} catalogs={recordCatalogs || catalogs} rdo={rdo} obraId={obra.id} />
                        {managerFilling && key === 'fotos' && complementaryEvidence(obra.id)}
                    </Section>)}</div>
                </section>)}</div>
            </div>
        </section>
        {completeOpen && <dialog ref={dialog} aria-labelledby={dialogTitle} onCancel={event => { event.preventDefault(); onCompleteOpenChange(false); }} onClick={event => { if (event.target === event.currentTarget) { const rect = dialog.current.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) onCompleteOpenChange(false); } }} className="fixed inset-0 m-auto max-h-[92dvh] w-[calc(100%_-_1.5rem)] max-w-6xl rounded-xl border border-[var(--border)] bg-white p-0 text-[var(--ink-900)] shadow-2xl backdrop:bg-slate-950/60 backdrop:backdrop-blur-sm">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
                <div><h2 id={dialogTitle} className="font-bold">{rdo.code} · RDO completo</h2><p className="mt-0.5 text-xs text-[var(--ink-500)]">{rdo.reference_date_formatted} · {rdo.status_label} · {allObras.length} frente(s)</p></div>
                <div className="flex items-center gap-2">{onDownloadPdf && <button type="button" onClick={onDownloadPdf} className="sig-btn sig-btn-sm" aria-haspopup="dialog"><FileText size={15} /> Baixar PDF</button>}<button type="button" className="sig-btn sig-btn-sm" aria-label="Fechar RDO completo" onClick={() => onCompleteOpenChange(false)}><X size={18} /></button></div>
            </header>
            <div data-rdo-complete className="max-h-[calc(92dvh_-_8rem)] overflow-y-auto bg-slate-50 px-4 py-5 sm:px-6">
                <CompleteDocumentContent rdo={rdo} fronts={allObras} catalogs={recordCatalogs || catalogs} submission={submission} renderInspectionEvidence={renderInspectionEvidence} />
                <div className="mt-5 space-y-4">{renderCompleteExtras?.()}</div>
            </div>
            <footer className="flex justify-end border-t border-[var(--border)] px-4 py-3"><button type="button" className="sig-btn sig-btn-sm" onClick={() => onCompleteOpenChange(false)}>Fechar</button></footer>
        </dialog>}
    </div>;
}

function RecordAttribution({ submission, showPending = false }) {
    const author = submission?.user?.name ?? submission?.created_by?.name;
    const date = submission?.submitted_at;
    const label = [author, date].filter(Boolean).join(' · ');
    if (!label && !showPending) return null;
    return <p className="mt-2 text-xs text-[var(--ink-500)]">{label || 'Ainda não submetido'}</p>;
}

function DocumentBlock({ title, section, children }) {
    return <section aria-label={title} className="overflow-hidden rounded-xl border border-[var(--border)] bg-white">
        <header className="flex items-center gap-2 border-b border-[var(--border)] px-4 py-3">{section && <SectionImage section={section} />}<h4 className="text-sm font-semibold">{title}</h4></header>
        <div className="p-4">{children}</div>
    </section>;
}

function CompleteDocumentContent({ rdo, fronts, catalogs, submission, renderInspectionEvidence }) {
    const [frontId, setFrontId] = useState(() => String(fronts[0]?.id || 'all'));
    const visible = frontId === 'all' ? fronts : fronts.filter(front => String(front.id) === frontId);
    const fillers = rdo.fill_parties || [rdo.fill_party || 'construtora'];
    const parties = rdo.participating_parties || [...new Set([...fillers, ...(rdo.workflow?.review_parties || [])])];
    const active = rdo.active_stage || (rdo.status === 'arquivado' ? 'assinaturas' : fillers[0]);
    const stages = [...parties, 'assinaturas'];
    const activeIndex = stages.indexOf(active);
    const signed = rdo.signature?.status === 'completed';
    const identity = [['Contrato', [rdo.contract?.code, rdo.contract?.name].filter(Boolean).join(' · ')], ['Data do RDO', rdo.reference_date_formatted], ['Feriado', rdo.is_holiday == null ? 'Não informado' : rdo.is_holiday ? 'Sim' : 'Não'], ['Responsável', rdo.responsible?.name], ...parties.map(party => [partyLabels[party], rdo.contract?.[party]?.nome])];
    return <div className="space-y-5" data-rdo-complete-document>
        <section aria-label="Etapas do RDO" className="rounded-xl border border-[var(--border)] bg-white p-4"><ol className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">{stages.map((party, index) => <li key={party} data-rdo-party={rdoParty(party)} aria-current={index === activeIndex && !signed ? 'step' : undefined} className={`rdo-flow-party flex items-center gap-3 rounded-lg border px-3 py-3 text-sm ${index === activeIndex && !signed ? 'border-blue-200 bg-blue-50 font-semibold' : index < activeIndex || signed ? 'border-emerald-100 bg-emerald-50/50' : 'border-slate-200 bg-slate-50 text-slate-500'}`}><span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-semibold">{index < activeIndex || signed ? <CheckCircle2 size={16} /> : index + 1}</span><strong>{party === 'assinaturas' ? 'Assinaturas' : fillers.includes(party) ? `Preenchimento · ${partyLabels[party]}` : partyLabels[party]}</strong></li>)}</ol></section>
        <DocumentBlock title="Identificação do RDO"><dl className="grid gap-5 text-sm sm:grid-cols-2 lg:grid-cols-3">{identity.map(([label, value]) => <div key={label}><dt className="text-xs font-medium text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium">{value || 'Não informado'}</dd></div>)}</dl></DocumentBlock>
        <nav aria-label="Obras na visualização completa" className="flex flex-wrap gap-2">{fronts.map(front => <button key={front.id} type="button" aria-pressed={frontId === String(front.id)} onClick={() => setFrontId(String(front.id))} className={`sig-btn sig-btn-sm !whitespace-normal ${frontId === String(front.id) ? 'sig-btn-primary' : 'border border-slate-200 bg-white'}`}>{front.codigo} · {front.nome}</button>)}{fronts.length > 1 && <button type="button" aria-pressed={frontId === 'all'} onClick={() => setFrontId('all')} className={`sig-btn sig-btn-sm ${frontId === 'all' ? 'sig-btn-primary' : 'border border-slate-200 bg-white'}`}>Todas as frentes</button>}</nav>
        {visible.map(front => <section key={front.id} aria-label={`Documento da obra ${front.nome}`} className="space-y-4" data-rdo-complete-front={front.id}>
            <h3 className="border-b border-slate-200 pb-3 text-base font-bold">{front.codigo} · {front.nome}</h3>
            {parties.map(party => {
                const filling = fillers.includes(party);
                const records = inspectionRecordsForObra(rdo, front.id);
                const managerFilling = rdo.parallel_manager_filling && party === 'gerenciadora' && filling;
                const evidence = managerFilling ? records.flatMap(record => record.evidence || []) : [];
                const analysisRecords = managerFilling ? [] : records;
                const ownRdo = { ...rdo, fill_party: party, participating_parties: [party] };
                const data = rdo.sections_by_party?.[party]?.[front.id] || (party === rdo.fill_party ? rdo.sections?.[front.id] : {}) || {};
                const approval = rdo.approval_comments?.[party]?.[front.id];
                return <section key={party} data-rdo-complete-party={party} data-rdo-party={rdoParty(party)} className="rdo-party-panel overflow-hidden rounded-xl border border-[var(--border)] bg-white">
                    <header className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--border)] px-4 py-3"><h3 className="rdo-party-label font-semibold">{partyLabels[party]}</h3>{filling ? <RecordAttribution submission={managerFilling ? records[0] : submission} showPending={!managerFilling} /> : <p className="text-xs text-slate-500">{[approval?.user, approval?.created_at].filter(Boolean).join(' · ')}</p>}</header>
                    <div className="space-y-3 p-3 sm:p-4">
                        {filling && sectionsForParty(rdo, party).map(([key, title]) => <DocumentBlock key={key} section={key} title={title}>
                            <ReadSection section={key} data={data[key] || {}} catalogs={catalogs} rdo={ownRdo} obraId={front.id} />
                            {key === 'fotos' && evidence.length > 0 && renderInspectionEvidence?.(evidence)}
                        </DocumentBlock>)}
                        {party === 'gerenciadora' && (!filling || analysisRecords.length > 0 || approval) && <>
                            {filling && <h4 className="border-t border-[var(--border)] pt-3 text-sm font-semibold">Análise da gerenciadora</h4>}
                            {analysisRecords.length ? analysisRecords.map(record => <div key={record.id} className="space-y-3" data-rdo-complete-inspection={record.id}>
                                <p className="px-1 text-xs text-slate-500">{[record.user?.name, record.submitted_at].filter(Boolean).join(' · ')}</p>
                                {record.weather && Object.keys(record.weather).length > 0 && <DocumentBlock section="clima" title="Condições do tempo"><ReadSection section="clima" data={record.weather} catalogs={catalogs} rdo={ownRdo} obraId={front.id} /></DocumentBlock>}
                                <DocumentBlock section="atividades" title="Atividades"><ReadSection section="atividades" data={{ atividades: record.activities?.length ? record.activities : record.summary ? [{ titulo: 'Registro da gerenciadora', ocorrencia: record.summary }] : [] }} catalogs={catalogs} rdo={ownRdo} obraId={front.id} /></DocumentBlock>
                                <DocumentBlock section="comentarios" title="Comentário"><p className="whitespace-pre-wrap break-words text-sm leading-relaxed">{record.comment || 'Nenhum comentário registrado.'}</p></DocumentBlock>
                                {!managerFilling && <DocumentBlock section="fotos" title="Evidências">{record.evidence?.length ? renderInspectionEvidence?.(record.evidence) : <p className="text-sm text-slate-500">Nenhuma evidência anexada.</p>}</DocumentBlock>}
                            </div>) : !managerFilling && <p className="text-sm text-slate-500">Nenhum registro da gerenciadora nesta obra.</p>}
                            {approval && <DocumentBlock title="Parecer da gerenciadora"><ReadSection section="comentarios" data={{}} catalogs={catalogs} rdo={ownRdo} obraId={front.id} /></DocumentBlock>}
                        </>}
                        {party === 'cliente' && <DocumentBlock section="comentarios" title="Manifestação do cliente">{approval?.decision && <p className="mb-3 inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">{approval.decision}</p>}<ReadSection section="comentarios" data={{}} catalogs={catalogs} rdo={ownRdo} obraId={front.id} /></DocumentBlock>}
                    </div>
                </section>;
            })}
        </section>)}
    </div>;
}

function DataTable({ headings, rows }) {
    if (!rows.length) return <p className="text-sm text-[var(--ink-500)]">Nenhum registro informado.</p>;
    return <div className="overflow-x-auto rounded-lg border border-[var(--border)]"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr>{headings.map(h => <th key={h} className="border-b border-[var(--border)] px-4 py-2.5 text-xs font-semibold text-[var(--ink-500)]">{h}</th>)}</tr></thead><tbody className="divide-y divide-[var(--border)]">{rows.map((row, i) => <tr key={i} className="even:bg-slate-50/60">{row.map((value, j) => <td key={j} className={`px-4 py-3 align-top ${j === 0 ? 'font-medium' : ''} ${headings[j] === 'Quantidade' ? 'font-semibold tabular-nums text-blue-700' : ''}`}>{value ?? '—'}</td>)}</tr>)}</tbody></table></div>;
}
function Text({ children }) { return children ? <p className="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed">{children}</p> : null; }
function Observations({ children }) { return children ? <div className="mt-3 rounded-lg border-l-4 border-blue-200 bg-slate-50 px-4 py-3"><h3 className="text-xs font-semibold text-[var(--ink-500)]">Observações</h3><p className="mt-1 whitespace-pre-wrap break-words text-sm leading-relaxed">{children}</p></div> : null; }
const weatherLabels = { ensolarado: 'Ensolarado', nublado: 'Nublado', chuvoso: 'Chuvoso', nao_aplicavel: 'Não aplicável' };
const situationLabels = { operando: 'Operando', parado: 'Parado', manutencao: 'Manutenção' };
export function ReadSection({ section, data, catalogs, rdo, obraId }) {
    const labor = catalogs.mao_obra || [], equipment = catalogs.equipamentos || [], subcontractors = catalogs.subcontratadas || [];
    const find = (items, id) => items.find(item => String(item.id) === String(id));
    if (section === 'clima') return <><DataTable headings={['Período', 'Condição', 'Chuva (mm)']} rows={['manha', 'tarde', 'noite'].map((period, i) => [['Manhã', 'Tarde', 'Noite'][i], (Array.isArray(data[period]) ? data[period] : [data[period]]).filter(Boolean).map(value => weatherLabels[value] || value).join(', ') || 'Não informado', data[`precipitacao_${period}_mm`] ?? '—'])} />{isImpracticableDay(data.dia_impraticavel) && <p className="mt-3 text-sm font-semibold text-amber-700">Dia impraticável</p>}{data.precipitacao_total_anterior_mm != null && <Text>Pluviosidade anterior sem divisão por período: {data.precipitacao_total_anterior_mm} mm.</Text>}<Text>{data.observacoes}</Text></>;
    if (section === 'mao_obra') return <><DataTable headings={['Função / empresa', 'Classificação', 'Quantidade']} rows={[...Object.entries(data.efetivos || {}).map(([id, quantity]) => { const item = find(labor, id); return [item?.descricao || `Cadastro ${id}`, item?.tipo === 'direta' ? 'Direta' : item?.tipo === 'indireta' ? 'Indireta' : 'Não informado', quantity]; }), ...Object.entries(data.subcontratadas || {}).map(([id, quantity]) => [find(subcontractors, id)?.razao_social || `Empresa ${id}`, 'Subcontratada', quantity])]} /><Observations>{data.observacoes}</Observations></>;
    if (section === 'equipamentos') return <><DataTable headings={['Equipamento', 'Situação', 'Quantidade']} rows={Object.entries(data.registros || {}).map(([id, record]) => [find(equipment, id)?.descricao || `Equipamento ${id}`, situationLabels[record.situacao] || record.situacao || '—', record.quantidade])} /><Observations>{data.observacoes}</Observations></>;
    if (section === 'atividades') return data.atividades?.length ? (
        <div className="space-y-3">
            {data.atividades.map((item, i) => (
                <article key={i} className="rdo-party-activity rounded-r-lg border-l-2 border-blue-500 bg-slate-50 px-4 py-3">
                    <p className="rdo-party-label text-xs font-semibold text-blue-600">Atividade {i + 1}{item.assunto && (rdo.activity_subjects || []).find(subject => subject.value === item.assunto) ? ` · ${rdo.activity_subjects.find(subject => subject.value === item.assunto).label}` : ''}</p>
                    <h3 className="mt-1 break-words text-sm font-semibold">{item.titulo || 'Atividade sem título'}</h3>
                    {(item.ocorrencia || item.descricao) && (
                        <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed">{item.ocorrencia || item.descricao}</p>
                    )}
                </article>
            ))}
        </div>
    ) : <p className="text-sm text-[var(--ink-500)]">Nenhuma atividade registrada.</p>;
    if (section === 'comentarios') return <div className="divide-y divide-[var(--border)]">{(rdo.participating_parties || [rdo.fill_party]).map(party => {
        const own = rdo.sections_by_party?.[party]?.[obraId]?.comentarios?.[party] || data[party] || '';
        const approval = rdo.approval_comments?.[party]?.[obraId];
        if (!own && !approval?.comment) return null;
        return <article key={party} data-rdo-party={rdoParty(party)} className="py-3 first:pt-0"><h3 className="rdo-party-label text-sm font-bold">{partyLabels[party]}{approval?.user ? ` · ${approval.user}` : ''}</h3>{approval?.created_at && <p className="mt-1 text-xs text-[var(--ink-500)]">{approval.created_at}</p>}<Text>{own}</Text>{approval?.comment !== own && <Text>{approval?.comment}</Text>}</article>;
    })}{!(rdo.participating_parties || [rdo.fill_party]).some(party => (rdo.sections_by_party?.[party]?.[obraId]?.comentarios?.[party] || data[party] || rdo.approval_comments?.[party]?.[obraId]?.comment)) && <p className="text-sm text-[var(--ink-500)]">Nenhum comentário registrado.</p>}</div>;
    if (section === 'fotos') return data.arquivos?.length ? <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{[...data.arquivos].sort((a, b) => (a.position || 0) - (b.position || 0)).map((photo, i) => <figure key={photo.path || i} className="overflow-hidden rounded-lg border border-[var(--border)]"><a href={`/storage/${photo.path}`} target="_blank" rel="noreferrer"><img src={`/storage/${photo.path}`} alt={photo.comment || photo.legenda || photo.nome || `Foto ${i + 1}`} className="aspect-[4/3] w-full object-contain bg-slate-50" loading="lazy" /></a><figcaption className="p-3"><span className="text-xs font-semibold text-[var(--ink-500)]">Foto {i + 1}</span><Text>{photo.comment || photo.legenda || 'Sem comentário'}</Text></figcaption></figure>)}</div> : <p className="text-sm text-[var(--ink-500)]">Nenhuma imagem registrada nesta frente.</p>;
    return null;
}
