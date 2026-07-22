import ContractTour from '@/Components/ContractTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { AlertTriangle, Calendar, ClipboardCheck, FilePlus2, FileText, MapPin, Settings, Users } from 'lucide-react';

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
                            <span className="flex items-center gap-1.5"><MapPin size={14} /> Sao Paulo - SP</span>
                        </div>
                    </div>
                    <div data-tour="contract-detail-actions" className="flex flex-wrap gap-2">
                        <button type="button" className="sig-btn sig-btn-primary"><Settings size={14} /> Parametrizar</button>
                        <button type="button" className="sig-btn sig-btn-primary"><FilePlus2 size={14} /> Aditivo</button>
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
                    <div className="sig-card p-5">
                        <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Acompanhamento do contrato</h2>
                        <p className="mt-2 text-sm text-[var(--ink-500)]">Acompanhe aqui as atividades, os projetos e as RNCs relacionados a operacao do contrato.</p>
                    </div>
                    <aside className="grid content-start gap-5">
                        <section data-tour="contract-detail-data" className="sig-card p-5">
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Dados do contrato</h2>
                            <dl className="mt-4 grid gap-3 text-[13px]">
                                <Row label="Cliente" value="Cliente Alpha" />
                                <Row label="Construtora" value="Construtora Horizonte" />
                                <Row label="Valor" value="R$ 12.500.000,00" />
                                <Row label="Vigencia" value="01 jan. 2026 ate 31 dez. 2027" />
                            </dl>
                        </section>
                        <section data-tour="contract-detail-additives" className="sig-card p-5">
                            <div className="flex items-center justify-between gap-3">
                                <div><h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Aditivos</h2><p className="mt-0.5 text-xs text-[var(--ink-500)]">1 registro</p></div>
                                <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm">Historico</button>
                            </div>
                            <div className="mt-4 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3"><span className="sig-pill sig-pill-amber">Aditivo 1 - Custo e prazo</span><strong className="mt-2 block text-sm">Reequilibrio e prorrogacao contratual</strong></div>
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
