import BudgetTour from '@/Components/BudgetTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    Calculator,
    Check,
    ClipboardList,
    Download,
    FileText,
    Filter,
    Layers3,
    Package,
    Plus,
    Search,
    Settings2,
    Upload,
} from 'lucide-react';

const inputRows = [
    { code: '00000370', description: 'Areia média - posto jazida/fornecedor', type: 'Material', unit: 'M3', value: 'R$ 118,40' },
    { code: '00004750', description: 'Pedreiro com encargos complementares', type: 'Mão de obra', unit: 'H', value: 'R$ 29,96' },
    { code: '00001379', description: 'Cimento Portland composto CP II-32', type: 'Material', unit: 'KG', value: 'R$ 0,82' },
];

const compositionItems = [
    { code: '00004750', description: 'Pedreiro com encargos complementares', unit: 'H', coefficient: '0,8000', value: 'R$ 23,97' },
    { code: '00000370', description: 'Areia média - posto jazida/fornecedor', unit: 'M3', coefficient: '0,0360', value: 'R$ 4,26' },
    { code: '00001379', description: 'Cimento Portland composto CP II-32', unit: 'KG', coefficient: '6,2000', value: 'R$ 5,08' },
];

export default function BudgetTourPreview({ tenant, screen = 'insumos' }) {
    return (
        <AuthenticatedLayout>
            <Head title="Tutorial de orçamentos" />
            {screen === 'insumos' && <InputsPreview />}
            {screen === 'composicoes' && <CompositionsPreview />}
            {screen === 'orcamento' && <BudgetPreview />}
            <BudgetTour section={screen} tenantSlug={tenant.slug} />
        </AuthenticatedLayout>
    );
}

function InputsPreview() {
    return (
        <PreviewPage>
            <PreviewHeader
                target="budget-inputs-header"
                eyebrow="Orçamentos · Bases de preço"
                title="Insumos"
                subtitle="Consulte insumos das bases de referência por estado, tipo e data."
                actions={(
                    <div data-tour="budget-inputs-actions" className="flex flex-wrap gap-2">
                        <PreviewButton icon={Upload}>Importar base própria</PreviewButton>
                        <PreviewButton icon={Upload}>Importar CSV global</PreviewButton>
                        <PreviewButton icon={Plus} primary>Novo insumo</PreviewButton>
                    </div>
                )}
            />

            <div className="mt-6 flex flex-wrap gap-2">
                <Pill active>Todos <span className="mono text-[var(--ink-400)]">164.651</span></Pill>
                <Pill>Material <span className="mono text-[var(--ink-400)]">121.334</span></Pill>
                <Pill>Serviços <span className="mono text-[var(--ink-400)]">41.902</span></Pill>
                <Pill>Mão de obra <span className="mono text-[var(--ink-400)]">1.415</span></Pill>
            </div>

            <div className="mt-4 grid gap-3 lg:grid-cols-[1.6fr_repeat(4,minmax(150px,0.8fr))_auto]">
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={16} />
                    <input className="w-full !pl-10" readOnly value="" placeholder="Buscar por descrição ou código" />
                </div>
                <FilterBox label="Banco" value="SINAPI" />
                <FilterBox label="Estado" value="Pará" />
                <FilterBox label="Data de referência" value="04/2026" />
                <FilterBox label="Ordenar por" value="Descrição (A-Z)" />
                <button type="button" className="sig-btn sig-btn-primary"><Filter size={15} /> Filtrar</button>
            </div>

            <section data-tour="budget-inputs-list" className="sig-card mt-6 overflow-hidden">
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <span className="eyebrow">Base SINAPI · Pará · 04/2026</span>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Exibindo 1-3 de 164.651 insumos.</p>
                    </div>
                    <span className="text-xs text-[var(--ink-500)]">Página <strong>1</strong> de 54.884</span>
                </header>
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[860px] text-left">
                        <thead className="bg-[var(--surface-muted)] text-[10px] uppercase text-[var(--ink-400)]">
                            <tr><Th>Código</Th><Th>Descrição</Th><Th>Tipo</Th><Th>Unidade</Th><Th>Não desonerado</Th></tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--border)]">
                            {inputRows.map((row) => (
                                <tr key={row.code}>
                                    <Td><span className="mono font-bold text-[var(--primary)]">{row.code}</span></Td>
                                    <Td><strong className="text-sm text-[var(--ink-900)]">{row.description}</strong><p className="mt-1 text-xs text-[var(--ink-400)]">SINAPI · PA</p></Td>
                                    <Td><span className="sig-pill sig-pill-blue">{row.type}</span></Td>
                                    <Td>{row.unit}</Td>
                                    <Td><strong className="mono">{row.value}</strong></Td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </PreviewPage>
    );
}

function CompositionsPreview() {
    return (
        <PreviewPage>
            <PreviewHeader
                target="budget-compositions-header"
                eyebrow="Orçamentos · Bases de preço"
                title="Composições"
                subtitle="Estruture serviços combinando insumos, coeficientes e custos unitários."
                actions={(
                    <div data-tour="budget-compositions-actions" className="flex flex-wrap gap-2">
                        <PreviewButton icon={Upload}>Importar base própria</PreviewButton>
                        <PreviewButton icon={Upload}>Importar global</PreviewButton>
                        <PreviewButton icon={Plus} primary>Criar composição</PreviewButton>
                    </div>
                )}
            />

            <div className="mt-6 grid gap-3 lg:grid-cols-[1.5fr_repeat(3,minmax(170px,0.75fr))_auto]">
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={16} />
                    <input className="w-full !pl-10" readOnly value="" placeholder="Buscar por descrição ou código" />
                </div>
                <FilterBox label="Banco" value="Própria" />
                <FilterBox label="Estado" value="Pará" />
                <FilterBox label="Tipo" value="Todos os tipos" />
                <button type="button" className="sig-btn sig-btn-primary"><Filter size={15} /> Filtrar</button>
            </div>

            <section data-tour="budget-compositions-detail" className="sig-card mt-6 overflow-hidden">
                <header className="flex flex-wrap items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="mono text-base font-bold text-[var(--primary)]">COMP-001</span>
                            <span className="sig-pill sig-pill-blue">Própria</span>
                        </div>
                        <h2 className="mt-2 text-lg font-semibold text-[var(--ink-900)]">Assentamento de alvenaria de vedação</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Unidade: M2 · 3 insumos vinculados</p>
                    </div>
                    <div className="text-right">
                        <span className="eyebrow">Custo unitário</span>
                        <strong className="mono mt-1 block text-2xl text-[var(--ink-900)]">R$ 33,31</strong>
                    </div>
                </header>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[820px] text-left">
                        <thead className="bg-[var(--surface-muted)] text-[10px] uppercase text-[var(--ink-400)]">
                            <tr><Th>Código</Th><Th>Insumo</Th><Th>Unidade</Th><Th>Coeficiente</Th><Th>Custo total</Th></tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--border)]">
                            {compositionItems.map((item) => (
                                <tr key={item.code}>
                                    <Td><span className="mono font-bold text-[var(--primary)]">{item.code}</span></Td>
                                    <Td><strong className="text-sm text-[var(--ink-900)]">{item.description}</strong></Td>
                                    <Td>{item.unit}</Td>
                                    <Td><span className="mono">{item.coefficient}</span></Td>
                                    <Td><strong className="mono">{item.value}</strong></Td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t border-[var(--border)] bg-[var(--surface-muted)]">
                            <tr><td className="px-5 py-3 text-sm font-semibold" colSpan="4">Total da composição</td><td className="px-5 py-3"><strong className="mono">R$ 33,31</strong></td></tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </PreviewPage>
    );
}

function BudgetPreview() {
    return (
        <PreviewPage>
            <PreviewHeader
                target="budget-sheet-header"
                eyebrow="Orçamentos · ORC-001"
                title="ORC-001 · Reforma do edifício administrativo"
                subtitle="Monte a estrutura analítica do orçamento por etapas, composições e insumos."
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <PreviewButton icon={FileText}>Relatórios</PreviewButton>
                        <PreviewButton icon={Check} primary>Finalizar orçamento</PreviewButton>
                    </div>
                )}
            />

            <section data-tour="budget-sheet-summary" className="mt-6 grid gap-4 lg:grid-cols-3">
                <SummaryCard icon={ClipboardList} title="Orçamento">
                    <strong className="text-base text-[var(--ink-900)]">Reforma administrativa</strong>
                    <p className="mt-3 text-sm text-[var(--ink-500)]">Cliente: X Cliente</p>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">Prazo: 30/12/2026</p>
                </SummaryCard>
                <SummaryCard icon={Layers3} title="Bases de preço">
                    <div className="flex flex-wrap gap-2"><span className="sig-pill sig-pill-blue">SINAPI · PA · 04/2026</span><span className="sig-pill sig-pill-amber">SICRO3 · PA · 01/2026</span></div>
                </SummaryCard>
                <SummaryCard icon={Settings2} title="Parâmetros">
                    <p className="text-sm text-[var(--ink-600)]">Encargos: <strong>Não desonerado</strong></p>
                    <p className="mt-2 text-sm text-[var(--ink-600)]">BDI: <strong>18,00%</strong></p>
                </SummaryCard>
            </section>

            <div className="mt-5 flex flex-wrap gap-2">
                <PreviewButton icon={Plus} primary>Adicionar etapa</PreviewButton>
                <PreviewButton icon={Layers3}>Adicionar composição</PreviewButton>
                <PreviewButton icon={Package}>Adicionar insumo</PreviewButton>
            </div>

            <section data-tour="budget-sheet-structure" className="sig-card mt-4 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[980px] text-left">
                        <thead className="bg-[var(--surface-muted)] text-[10px] uppercase text-[var(--ink-400)]">
                            <tr><Th>Item</Th><Th>Código</Th><Th>Banco</Th><Th>Descrição</Th><Th>Unid.</Th><Th>Quant.</Th><Th>Valor unit.</Th><Th>Total</Th></tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--border)]">
                            <tr className="bg-blue-50"><Td><strong>1</strong></Td><Td /><Td /><Td><strong>Serviços preliminares</strong></Td><Td /><Td /><Td /><Td><strong className="mono">R$ 18.438,00</strong></Td></tr>
                            <tr className="bg-emerald-50/60"><Td>1.1</Td><Td><span className="mono text-[var(--primary)]">COMP-001</span></Td><Td><span className="sig-pill sig-pill-blue">PRÓPRIA</span></Td><Td>Assentamento de alvenaria de vedação</Td><Td>M2</Td><Td><span className="mono">300,00</span></Td><Td><span className="mono">R$ 33,31</span></Td><Td><strong className="mono">R$ 9.993,00</strong></Td></tr>
                            <tr className="bg-amber-50/70"><Td>1.2</Td><Td><span className="mono text-[var(--primary)]">00001379</span></Td><Td><span className="sig-pill sig-pill-amber">SINAPI</span></Td><Td>Cimento Portland composto CP II-32</Td><Td>KG</Td><Td><span className="mono">10.298,78</span></Td><Td><span className="mono">R$ 0,82</span></Td><Td><strong className="mono">R$ 8.445,00</strong></Td></tr>
                        </tbody>
                    </table>
                </div>

                <div data-tour="budget-sheet-total" className="ml-auto grid max-w-md gap-2 border-t border-[var(--border)] px-5 py-4 text-sm">
                    <TotalLine label="Total sem BDI" value="R$ 18.438,00" />
                    <TotalLine label="Total do BDI" value="R$ 3.318,84" />
                    <TotalLine label="Total" value="R$ 21.756,84" prominent />
                </div>
            </section>
        </PreviewPage>
    );
}

function PreviewPage({ children }) {
    return <section className="sig-content fade-in">{children}</section>;
}

function PreviewHeader({ target, eyebrow, title, subtitle, actions }) {
    return (
        <header data-tour={target} className="flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0 flex-1">
                <div className="eyebrow flex items-center gap-2"><Calculator size={14} />{eyebrow}</div>
                <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{title}</h1>
                <p className="mt-1 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">{subtitle}</p>
            </div>
            {actions}
        </header>
    );
}

function PreviewButton({ children, icon: Icon, primary = false }) {
    return <button type="button" className={`sig-btn ${primary ? 'sig-btn-primary' : 'sig-btn-secondary'}`}><Icon size={15} />{children}</button>;
}

function Pill({ children, active = false }) {
    return <button type="button" className={`sig-btn ${active ? 'sig-btn-primary' : 'sig-btn-secondary'} !rounded-full`}>{children}</button>;
}

function FilterBox({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-white px-3 py-2">
            <span className="block text-[9px] font-bold uppercase text-[var(--ink-400)]">{label}</span>
            <strong className="mt-0.5 block truncate text-sm text-[var(--ink-900)]">{value}</strong>
        </div>
    );
}

function SummaryCard({ icon: Icon, title, children }) {
    return (
        <article className="sig-card p-5">
            <div className="mb-4 flex items-center gap-2 text-[var(--ink-500)]"><Icon size={16} /><span className="eyebrow">{title}</span></div>
            {children}
        </article>
    );
}

function TotalLine({ label, value, prominent = false }) {
    return (
        <div className={`flex items-center justify-between gap-5 ${prominent ? 'mt-1 border-t border-[var(--border)] pt-3' : ''}`}>
            <span className={prominent ? 'font-bold uppercase text-[var(--ink-500)]' : 'text-[var(--ink-500)]'}>{label}</span>
            <strong className={`mono text-[var(--ink-900)] ${prominent ? 'text-xl' : ''}`}>{value}</strong>
        </div>
    );
}

function Th({ children }) {
    return <th className="px-5 py-3 font-bold">{children}</th>;
}

function Td({ children }) {
    return <td className="px-5 py-3 text-sm text-[var(--ink-600)]">{children}</td>;
}
