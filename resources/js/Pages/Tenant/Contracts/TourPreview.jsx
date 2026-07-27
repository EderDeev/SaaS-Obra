import ContractTour from '@/Components/ContractTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { AlertTriangle, Calendar, ClipboardCheck, FilePlus2, FileText, MapPin, Plus, Settings, Upload, Users } from 'lucide-react';

const metrics = [
    { label: 'Atividades abertas', value: 8, icon: ClipboardCheck },
    { label: 'Atividades atrasadas', value: 1, icon: AlertTriangle, attention: true },
    { label: 'RNCs abertas', value: 2, icon: FileText, attention: true },
    { label: 'Projetos pendentes', value: 3, icon: FileText },
    { label: 'Projetos aprovados', value: 12, icon: FileText },
];

export default function ContractTourPreview({ tenant }) {
    return (
        <AuthenticatedLayout>
            <Head title="Contrato CT-001" />
            <section className="sig-content fade-in">
                <header data-tour="contract-detail-header" className="flex flex-wrap items-start gap-5">
                    <div className="min-w-0 flex-1">
                        <div className="mb-2 flex items-center gap-2">
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary-50)] text-sm font-bold text-[var(--primary)]">CT</span>
                            <span className="mono text-[13px] text-[var(--ink-500)]">CT-001</span>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-[26px] font-semibold leading-tight text-[var(--ink-900)]">Obra Jardim Central</h1>
                            <span className="sig-pill sig-pill-green text-[12.5px] font-semibold"><Calendar size={13} /> Faltam 529 dias</span>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-x-5 gap-y-2 text-[13.5px] text-[var(--ink-500)]">
                            <span className="flex items-center gap-1.5"><Users size={14} /> Cliente Alpha</span>
                            <span className="flex items-center gap-1.5"><FileText size={14} /> Construtora Horizonte</span>
                            <span className="flex items-center gap-1.5"><MapPin size={14} /> São Paulo - SP</span>
                        </div>
                    </div>
                    <div data-tour="contract-detail-actions" className="flex flex-wrap gap-2">
                        <button type="button" className="sig-btn sig-btn-primary"><Settings size={14} /> Parametrizar</button>
                        <button type="button" className="sig-btn sig-btn-primary"><FilePlus2 size={14} /> Aditivo</button>
                        <button type="button" className="sig-btn sig-btn-secondary"><Plus size={14} /> Nova atividade</button>
                        <button type="button" className="sig-btn sig-btn-secondary"><Upload size={14} /> Submeter projeto</button>
                        <button type="button" className="sig-btn sig-btn-secondary"><ClipboardCheck size={14} /> Nova RNC</button>
                    </div>
                </header>

                <section data-tour="contract-detail-metrics" className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {metrics.map(({ label, value, icon: Icon, attention }) => (
                        <div key={label} className="sig-card p-4">
                            <div className="flex items-center gap-2 text-[var(--ink-500)]"><Icon size={14} /><span className="eyebrow">{label}</span></div>
                            <strong className={`mono mt-3 block text-3xl ${attention ? 'text-[var(--red)]' : 'text-[var(--ink-900)]'}`}>{value}</strong>
                        </div>
                    ))}
                </section>

                <section className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,1fr)]">
                    <div data-tour="contract-detail-modules" className="grid content-start gap-5">
                        <ModuleSummary
                            title="Atividades"
                            subtitle="Acompanhamento das tarefas recentes deste contrato"
                            item="Revisar cronograma executivo"
                            meta="Projeto · 28 jul. 2026"
                            status="Em andamento"
                        />
                        <div className="grid gap-5 lg:grid-cols-2">
                            <ModuleSummary
                                title="Projetos"
                                subtitle="Documentos submetidos recentemente"
                                item="Projeto executivo - Bloco A"
                                meta="ARQ · R02"
                                status="Em análise"
                            />
                            <ModuleSummary
                                title="RNCs"
                                subtitle="Não conformidades mais recentes"
                                item="003-2026"
                                meta="Estruturas · Média"
                                status="Aberta"
                                attention
                            />
                        </div>
                    </div>
                    <aside className="grid content-start gap-5">
                        <section data-tour="contract-detail-data" className="sig-card p-5">
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Dados do contrato</h2>
                            <dl className="mt-4 grid gap-3 text-[13px]">
                                <Row label="Cliente" value="Cliente Alpha" />
                                <Row label="Construtora" value="Construtora Horizonte" />
                                <Row label="Valor" value="R$ 12.500.000,00" />
                                <Row label="Vigência" value="01 jan. 2026 até 31 dez. 2027" />
                            </dl>
                        </section>
                        <section data-tour="contract-detail-additives" className="sig-card p-5">
                            <div className="flex items-center justify-between gap-3">
                                <div><h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Aditivos</h2><p className="mt-0.5 text-xs text-[var(--ink-500)]">1 registro</p></div>
                                <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm">Histórico</button>
                            </div>
                            <div className="mt-4 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3"><span className="sig-pill sig-pill-amber">Aditivo 1 - Custo e prazo</span><strong className="mt-2 block text-sm">Reequilíbrio e prorrogação contratual</strong></div>
                        </section>
                        <section data-tour="contract-detail-team" className="sig-card p-5">
                            <header className="mb-4 flex items-center justify-between">
                                <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Equipe no contrato</h2>
                                <span className="text-xs text-[var(--ink-400)]">2</span>
                            </header>
                            <ul className="grid gap-3">
                                <TeamMember initials="AP" name="Admin Plataforma" role="Gerenciadora · Gestor" />
                                <TeamMember initials="EC" name="Engenheira de Campo" role="Construtora · Responsável técnico" />
                            </ul>
                        </section>
                    </aside>
                </section>
            </section>
            <ContractTour section="detail" />
        </AuthenticatedLayout>
    );
}

function Row({ label, value }) {
    return <div className="grid grid-cols-[92px_minmax(0,1fr)] gap-3"><dt className="text-[var(--ink-500)]">{label}</dt><dd className="text-right font-medium text-[var(--ink-900)]">{value}</dd></div>;
}

function ModuleSummary({ title, subtitle, item, meta, status, attention = false }) {
    return (
        <section className="sig-card overflow-hidden">
            <header className="flex items-start justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
                    <p className="mt-0.5 text-xs text-[var(--ink-500)]">{subtitle}</p>
                </div>
                <button type="button" className="text-xs font-semibold text-[var(--primary)]">Ver módulo</button>
            </header>
            <div className="flex items-center gap-3 px-5 py-3">
                <div className="min-w-0 flex-1">
                    <strong className="block truncate text-sm text-[var(--ink-900)]">{item}</strong>
                    <span className="mt-0.5 block text-xs text-[var(--ink-500)]">{meta}</span>
                </div>
                <span className={`sig-pill ${attention ? 'sig-pill-red' : 'sig-pill-blue'}`}>{status}</span>
            </div>
        </section>
    );
}

function TeamMember({ initials, name, role }) {
    return (
        <li className="flex items-center gap-3">
            <span className="sig-avatar">{initials}</span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[13px] font-semibold text-[var(--ink-900)]">{name}</span>
                <span className="block truncate text-[11.5px] text-[var(--ink-500)]">{role}</span>
            </span>
        </li>
    );
}
