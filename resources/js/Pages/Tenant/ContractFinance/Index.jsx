import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, FolderOpen, Pencil, Plus, Save, Settings, Trash2, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

const sectionRoutes = { Financeiro: 'index', Dashboard: 'dashboard', Parametrização: 'settings' };
const emptyMonth = { reference_month: '', revenue_amount: '', operating_cost: '', measured_amount: '', billed_amount: '', billed_date: '', receipts: [], revision: 0 };
const money = (value, currency) => value == null ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency }).format(Number(value));
const date = (value) => value ? value.slice(0, 10).split('-').reverse().join('/') : '—';
const monthLabel = (value) => value.slice(0, 7).split('-').reverse().join('/');
const percent = (value) => value == null ? '—' : `${Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;

export default function Index({ section, contracts, selectedContract, canManage, manageableContractIds, baseline, months, year, contractTypes, filters, target, report }) {
    const { currentTenant: tenant, flash } = usePage().props;
    const contract = selectedContract;
    const currency = contract?.currency || 'BRL';
    const sectionUrl = route(`tenant.contract-finance.${sectionRoutes[section]}`, tenant.slug);
    const dashboard = section === 'Dashboard';
    const settings = section === 'Parametrização';
    const financeUrl = route('tenant.contract-finance.index', tenant.slug);
    const settingsUrl = route('tenant.contract-finance.settings', tenant.slug);
    const visit = (url, data = {}) => router.get(url, data, { preserveScroll: true, preserveState: true });

    return (
        <AuthenticatedLayout>
            <Head title={`${dashboard ? 'Dashboard' : 'Financeiro'} · Financeiro do Contrato`} />

            <section className="sig-content fade-in space-y-5">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="eyebrow">Financeiro do Contrato</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{dashboard ? 'Dashboard' : 'Financeiro'}</h1>
                        {!dashboard && <p className="mt-1 text-sm text-[var(--ink-500)]">Acompanhe os lançamentos financeiros por contrato.</p>}
                    </div>
                </header>

                {!dashboard && <ContractCards contracts={contracts} contractTypes={contractTypes} manageableIds={manageableContractIds}
                    onAccess={(id) => visit(financeUrl, { contract_id: id })}
                    onSettings={(id) => visit(settingsUrl, { contract_id: id })} />}
                {!dashboard && contract && (
                    <ContractFinanceDialog key={`${section}-${contract.id}`} contract={contract} settings={settings}
                        contractTypes={contractTypes} flash={flash?.success} onClose={() => visit(financeUrl)}>
                        {settings ? <>
                            <Baseline key={`baseline-${contract.id}-${year}`} baseline={baseline} year={year} currency={currency} canManage={canManage}
                                url={route('tenant.contract-finance.baseline.update', [tenant.slug, contract.id])} />
                            <AnnualTarget key={`${contract.id}-${year}`} target={target} year={year} currency={currency} canManage={canManage}
                                onYear={(value) => visit(settingsUrl, { contract_id: contract.id, year: value })}
                                url={route('tenant.contract-finance.targets.update', [tenant.slug, contract.id])} />
                        </> : <MonthlyLedger months={months} currency={currency} canManage={canManage}
                            url={route('tenant.contract-finance.months.update', [tenant.slug, contract.id])} />}
                    </ContractFinanceDialog>
                )}
                {dashboard && <>
                    <DashboardFilters key={JSON.stringify([contract?.id, year, filters])} contracts={contracts} contract={contract}
                        contractTypes={contractTypes} year={year} filters={filters} url={sectionUrl} />
                    <Dashboard report={report} contractTypes={contractTypes} year={year} />
                </>}
            </section>
        </AuthenticatedLayout>
    );
}

function ContractCards({ contracts, contractTypes, manageableIds, onAccess, onSettings }) {
    if (!contracts.length) return <p className="sig-card p-5 text-sm text-[var(--ink-500)]">Nenhum contrato disponível para acompanhamento financeiro.</p>;

    return <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {contracts.map((contract) => <article key={contract.id} className="sig-card flex min-w-0 flex-col gap-4 p-5">
            <div className="flex items-start gap-3">
                <span className="shrink-0 rounded-lg bg-blue-50 p-2.5 text-blue-700"><Building2 size={20} aria-hidden="true" /></span>
                <div className="min-w-0">
                    <p className="text-xs font-medium text-[var(--ink-500)]">{[contract.code, contract.state].filter(Boolean).join(' · ')}</p>
                    <h2 className="mt-1 break-words text-base font-semibold">{contract.name}</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">{contractTypes[contract.contract_type] ?? 'Tipo não informado'}</p>
                </div>
            </div>
            <dl className="border-t border-[var(--border)] pt-3">
                <dt className="text-xs text-[var(--ink-500)]">Faturado a receber</dt>
                <dd className="mt-1 text-lg font-semibold tabular-nums">
                    {money(Number(contract.billed_total ?? 0) - Number(contract.received_total ?? 0), contract.currency || 'BRL')}
                </dd>
            </dl>
            <div className="mt-auto flex flex-wrap justify-end gap-2">
                {(manageableIds === null || manageableIds.includes(contract.id)) && (
                    <button type="button" className="sig-btn sig-btn-secondary" aria-haspopup="dialog"
                        aria-label={`Parametrizar ${contract.code}`} onClick={() => onSettings(contract.id)}><Settings size={16} /> Parametrizar</button>
                )}
                <button type="button" className="sig-btn sig-btn-primary" aria-haspopup="dialog"
                    aria-label={`Acessar ${contract.code}`} onClick={() => onAccess(contract.id)}>Acessar <ArrowRight size={16} /></button>
            </div>
        </article>)}
    </div>;
}

function ContractFinanceDialog({ contract, settings, contractTypes, flash, onClose, children }) {
    const dialog = useRef(null);
    const closeButton = useRef(null);
    const titleId = useId();
    useEffect(() => {
        const node = dialog.current;
        const focus = document.activeElement;
        const overflow = document.body.style.overflow;
        node.showModal();
        closeButton.current?.focus();
        document.body.style.overflow = 'hidden';
        return () => {
            node.close();
            document.body.style.overflow = overflow;
            if (focus?.isConnected) focus.focus();
        };
    }, []);

    return <dialog ref={dialog} aria-labelledby={titleId}
        onCancel={(event) => { event.preventDefault(); onClose(); }}
        className="fixed inset-0 !m-auto max-h-[90dvh] w-[calc(100%_-_1.5rem)] max-w-3xl overflow-y-auto rounded-xl border border-[var(--border)] bg-white p-0 text-[var(--ink-900)] shadow-2xl backdrop:bg-slate-950/55">
        <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-[var(--border)] bg-white px-5 py-4">
            <div>
                <p className="text-xs font-medium text-[var(--ink-500)]">{settings ? 'Parametrização' : 'Lançamentos'} · {contract.code} · {contractTypes[contract.contract_type] ?? 'Tipo não informado'}</p>
                <h2 id={titleId} className="mt-1 text-lg font-semibold">{contract.name}</h2>
            </div>
            <button type="button" ref={closeButton} className="sig-btn sig-btn-secondary shrink-0" onClick={onClose}><X size={16} /> Fechar</button>
        </header>
        <div className="space-y-4 p-4 sm:p-5">
            {flash && <p role="status" className="rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{flash}</p>}
            {children}
        </div>
    </dialog>;
}

function Field({ form, name, label, type, currency, required = false, min = '0', max }) {
    const amount = form.data[name];
    const formatter = currency && new Intl.NumberFormat('pt-BR', {
        style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2,
    });
    const value = formatter && amount !== '' && amount != null ? formatter.format(amount) : amount;
    const updateValue = (event) => {
        const input = event.target.value;
        if (!formatter) {
            form.setData(name, input);
            return;
        }

        const digits = input.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        if (digits.length > 15) return;
        const padded = digits.padStart(3, '0');
        form.setData(name, digits ? `${padded.slice(0, -2)}.${padded.slice(-2)}` : '');
    };

    return <label className="grid content-start gap-1.5 text-sm font-medium">
        {label}
        <input className="sig-input" type={formatter ? 'text' : type} value={value} required={required}
            inputMode={formatter ? 'numeric' : undefined} placeholder={formatter ? formatter.format(0) : undefined}
            min={type === 'number' ? min : undefined} max={max} step={type === 'number' ? '0.01' : undefined}
            aria-invalid={Boolean(form.errors[name])} onChange={updateValue} />
        {form.errors[name] && <span className="text-xs text-red-700">{form.errors[name]}</span>}
    </label>;
}

function SaveButton({ processing }) {
    return <button type="submit" disabled={processing} className="sig-btn sig-btn-primary"><Save size={16} /> {processing ? 'Salvando…' : 'Salvar'}</button>;
}

function Baseline({ baseline, year, currency, canManage, url }) {
    const form = useForm({
        year,
        expected_amount: baseline?.expected_amount ?? '',
        expected_date: baseline?.expected_date ?? '',
    });

    return <section className="sig-card space-y-4 p-5">
        <div>
            <h2 className="font-semibold">Linha de base do contrato</h2>
            <p className="mt-1 text-sm text-[var(--ink-500)]">Informe quanto se espera receber pelo contrato e até qual data.</p>
        </div>
        {canManage ? <form className="space-y-4" onSubmit={(event) => {
            event.preventDefault();
            form.put(url, { preserveScroll: true });
        }}>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field form={form} name="expected_amount" label={`Valor previsto (${currency})`} currency={currency} required />
                <Field form={form} name="expected_date" label="Receber até" type="date" required />
            </div>
            <div className="flex justify-end"><SaveButton processing={form.processing} /></div>
        </form> : <p className="text-sm">{baseline ? `${money(baseline.expected_amount, currency)} até ${date(baseline.expected_date)}` : 'Linha de base ainda não configurada.'}</p>}
    </section>;
}

function AnnualTarget({ target, year, currency, canManage, url, onYear }) {
    const form = useForm({ year, revenue_target: target?.revenue_target ?? '', margin_target: target?.margin_target ?? '' });
    return <section className="sig-card space-y-4 p-5">
        <h2 className="font-semibold">Metas anuais do contrato</h2>
        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
            event.preventDefault();
            onYear(new FormData(event.currentTarget).get('year'));
        }}>
            <label className="grid gap-1.5 text-sm font-medium">Ano
                <input className="sig-input w-28" name="year" type="number" required min="1900" max="9999" defaultValue={year} />
            </label>
            <button className="sig-btn sig-btn-secondary" type="submit">Consultar ano</button>
        </form>
        {canManage ? <form className="space-y-4" onSubmit={(event) => {
            event.preventDefault();
            form.put(url, { preserveScroll: true });
        }}>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field form={form} name="revenue_target" label={`Meta de receita de ${year} (${currency})`} currency={currency} />
                <Field form={form} name="margin_target" label={`Meta de margem de ${year} (%)`} type="number" min="-100" max="100" />
            </div>
            <div className="flex justify-end"><SaveButton processing={form.processing} /></div>
        </form> : <p className="text-sm">Receita {money(target?.revenue_target, currency)} · Margem {percent(target?.margin_target)}</p>}
    </section>;
}

function MonthlyLedger({ months, currency, canManage, url }) {
    const form = useForm(emptyMonth);
    const [view, setView] = useState('menu');
    const [openedMonth, setOpenedMonth] = useState(null);
    const panelHeading = useRef(null);
    const opened = months.find((item) => item.reference_month === openedMonth);
    useEffect(() => {
        const heading = panelHeading.current;
        if (!heading) return;
        heading.closest('dialog').scrollTop = 0;
        heading.focus({ preventScroll: true });
    }, [view, openedMonth]);

    const editMonth = (month) => {
        const saved = months.find((item) => item.reference_month.slice(0, 7) === month);
        form.clearErrors();
        form.setData({
            ...emptyMonth,
            reference_month: month,
            revenue_amount: saved?.revenue_amount ?? '',
            operating_cost: saved?.operating_cost ?? '',
            measured_amount: saved?.measured_amount ?? '',
            billed_amount: saved?.billed_amount ?? '',
            billed_date: saved?.billed_date ?? '',
            receipts: saved?.receipts.map(({ received_amount, received_date }) => ({ received_amount, received_date })) ?? [],
            revision: saved?.revision ?? 0,
        });
    };
    const editReceipt = (index, field, value) => form.setData('receipts', form.data.receipts.map((receipt, i) => (
        i === index ? { ...receipt, [field]: value } : receipt
    )));

    return <>
        <div className="flex flex-wrap gap-2">
            <button type="button" className="sig-btn sig-btn-secondary" disabled={form.processing} onClick={() => setView('history')}>
                <FolderOpen size={16} /> Abrir histórico
            </button>
            {canManage && <button type="button" className="sig-btn sig-btn-primary" disabled={form.processing} onClick={() => {
                setOpenedMonth(null);
                editMonth('');
                setView('edit');
            }}><Plus size={16} /> Preencher competência</button>}
        </div>
        {view === 'edit' && canManage && <section className="sig-card space-y-4 p-5">
            <div>
                <h3 ref={panelHeading} tabIndex={-1} className="font-semibold">{form.data.revision ? 'Editar competência' : 'Preencher competência'}</h3>
                <p className="mt-1 text-sm text-[var(--ink-500)]">Selecione a competência. As datas de faturamento e recebimento podem cair em meses diferentes.</p>
            </div>
            <form className="space-y-4" onSubmit={(event) => {
                event.preventDefault();
                form.put(url, { preserveScroll: true, onSuccess: () => {
                    form.reset();
                    setView('history');
                } });
            }}>
                <label className="grid max-w-xs gap-1.5 text-sm font-medium">
                    Competência
                    <input className="sig-input" type="month" required value={form.data.reference_month} readOnly={Boolean(openedMonth)}
                        onChange={(event) => editMonth(event.target.value)} aria-invalid={Boolean(form.errors.reference_month)} />
                    {form.errors.reference_month && <span className="text-xs text-red-700">{form.errors.reference_month}</span>}
                </label>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field form={form} name="revenue_amount" label={`Receita da competência (${currency})`} currency={currency} />
                    <Field form={form} name="operating_cost" label={`Custo operacional (${currency})`} currency={currency} />
                    <Field form={form} name="measured_amount" label={`Valor medido (${currency})`} currency={currency} />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field form={form} name="billed_amount" label={`Valor faturado (${currency})`} currency={currency} required={Boolean(form.data.billed_date)} />
                    <Field form={form} name="billed_date" label="Data do faturamento" type="date" required={form.data.billed_amount !== ''} />
                </div>
                <fieldset className="space-y-3 rounded-lg border border-[var(--border)] p-4">
                    <legend className="px-1 text-sm font-semibold">Recebimentos</legend>
                    {form.data.receipts.map((receipt, index) => {
                        const receiptForm = {
                            data: receipt,
                            errors: Object.fromEntries(Object.keys(receipt).map((field) => [field, form.errors[`receipts.${index}.${field}`]])),
                            setData: (field, value) => editReceipt(index, field, value),
                        };
                        return <div key={index} className="flex flex-wrap items-start gap-3">
                            <Field form={receiptForm} name="received_amount" label={`Valor da parcela ${index + 1} (${currency})`} currency={currency} required />
                            <Field form={receiptForm} name="received_date" label={`Data da parcela ${index + 1}`} type="date" required />
                            <button className="sig-btn sig-btn-secondary mt-6" type="button" aria-label={`Remover parcela ${index + 1}`}
                                onClick={() => form.setData('receipts', form.data.receipts.filter((_, i) => i !== index))}><Trash2 size={16} /></button>
                        </div>;
                    })}
                    <button className="sig-btn sig-btn-secondary" type="button" disabled={form.data.receipts.length >= 200}
                        onClick={() => form.setData('receipts', [...form.data.receipts, { received_amount: '', received_date: '' }])}><Plus size={16} /> Adicionar recebimento</button>
                </fieldset>
                {(form.errors.revision || form.errors.receipts) && <p role="alert" className="text-sm text-red-700">{form.errors.revision || form.errors.receipts}</p>}
                <div className="flex flex-wrap justify-end gap-2">
                    <button type="button" className="sig-btn sig-btn-secondary" disabled={form.processing} onClick={() => setView(openedMonth ? 'record' : 'history')}>Cancelar</button>
                    <SaveButton processing={form.processing} />
                </div>
            </form>
        </section>}
        {view === 'history' && <section className="sig-card overflow-hidden">
            <h3 ref={panelHeading} tabIndex={-1} className="px-5 py-4 font-semibold">Histórico mensal</h3>
            {months.length ? <ul className="divide-y divide-[var(--border)] border-t border-[var(--border)]">
                {months.map((item) => {
                    const label = monthLabel(item.reference_month);
                    const received = item.receipts.reduce((sum, receipt) => sum + Math.round(Number(receipt.received_amount) * 100), 0) / 100;
                    return <li key={item.reference_month} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div>
                            <p className="font-semibold">{label}</p>
                            <p className="mt-1 text-xs text-[var(--ink-500)]">Faturado {money(item.billed_amount, currency)} · Recebido {money(received, currency)}</p>
                        </div>
                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" aria-label={`Abrir competência ${label}`} onClick={() => {
                            setOpenedMonth(item.reference_month);
                            setView('record');
                        }}>Abrir</button>
                    </li>;
                })}
            </ul> : <p className="border-t border-[var(--border)] p-5 text-sm text-[var(--ink-500)]">Nenhum lançamento registrado neste contrato.</p>}
        </section>}
        {view === 'record' && opened && <section className="sig-card space-y-4 p-5">
            <h3 ref={panelHeading} tabIndex={-1} className="font-semibold">Competência {monthLabel(opened.reference_month)}</h3>
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {[
                    ['Receita', money(opened.revenue_amount, currency)],
                    ['Custo operacional', money(opened.operating_cost, currency)],
                    ['Valor medido', money(opened.measured_amount, currency)],
                    ['Faturado', money(opened.billed_amount, currency)],
                    ['Data do faturamento', date(opened.billed_date)],
                ].map(([label, value]) => <div key={label}><dt className="text-xs text-[var(--ink-500)]">{label}</dt><dd className="mt-1 font-medium">{value}</dd></div>)}
            </dl>
            <div className="border-t border-[var(--border)] pt-3">
                <h4 className="text-sm font-semibold">Recebimentos</h4>
                {opened.receipts.length ? <ul className="mt-2 space-y-1 text-sm">
                    {opened.receipts.map(({ id, received_amount, received_date }) => <li key={id}>{money(received_amount, currency)} · {date(received_date)}</li>)}
                </ul> : <p className="mt-2 text-sm text-[var(--ink-500)]">Nenhum recebimento registrado.</p>}
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="button" className="sig-btn sig-btn-secondary" onClick={() => setView('history')}>Voltar ao histórico</button>
                {canManage && <button type="button" className="sig-btn sig-btn-primary" onClick={() => {
                    editMonth(opened.reference_month.slice(0, 7));
                    setView('edit');
                }}><Pencil size={16} /> Editar lançamento</button>}
            </div>
        </section>}
    </>;
}

function DashboardFilters({ contracts, contract, contractTypes, year, filters, url }) {
    const states = [...new Set(contracts.map(({ state }) => state).filter(Boolean))].sort();
    return <form className="sig-card flex flex-wrap items-end gap-3 p-4" onSubmit={(event) => {
        event.preventDefault();
        const params = Object.fromEntries([...new FormData(event.currentTarget)].filter(([, value]) => value !== ''));
        router.get(url, params, { preserveScroll: true });
    }}>
        <label className="grid min-w-48 flex-1 gap-1.5 text-sm font-medium">Contrato
            <select name="contract_id" className="sig-input" defaultValue={contract?.id ?? ''}>
                <option value="">Todos os contratos</option>
                {contracts.map(({ id, code, name }) => <option key={id} value={id}>{code} · {name}</option>)}
            </select>
        </label>
        <label className="grid gap-1.5 text-sm font-medium">Tipo de contrato
            <select name="contract_type" className="sig-input" defaultValue={filters.contract_type}>
                <option value="">Todos</option>
                {Object.entries(contractTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
        </label>
        <label className="grid gap-1.5 text-sm font-medium">UF
            <select name="state" className="sig-input" defaultValue={filters.state}>
                <option value="">Todas</option>
                {states.map((state) => <option key={state} value={state}>{state}</option>)}
            </select>
        </label>
        <label className="grid gap-1.5 text-sm font-medium">Ano
            <input name="year" className="sig-input w-28" type="number" min="1900" max="9999" placeholder="Todos" defaultValue={year ?? ''} />
        </label>
        <button type="submit" className="sig-btn sig-btn-primary">Filtrar</button>
    </form>;
}

function MetricCards({ items }) {
    return <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {items.map(([label, value, note]) => <div key={label} className="sig-card p-4">
            <dt className="text-sm font-medium text-[var(--ink-500)]">{label}</dt>
            <dd className="mt-2 text-xl font-semibold">{value}</dd>
            {note && <dd className="mt-1 text-xs text-[var(--ink-500)]">{note}</dd>}
        </div>)}
    </dl>;
}

function ReportTable({ headings, rows }) {
    return <div className="overflow-x-auto">
        <table className="w-full whitespace-nowrap text-left text-sm">
            <thead className="bg-slate-50 text-xs text-[var(--ink-500)]">
                <tr>{headings.map((label) => <th key={label} scope="col" className="px-4 py-3">{label}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-[var(--border)]">
                {rows.map(([key, label, ...cells]) => <tr key={key}>
                    <th scope="row" className="px-4 py-3 font-medium">{label}</th>
                    {cells.map((value, index) => <td key={headings[index + 1]} className="px-4 py-3">{value}</td>)}
                </tr>)}
            </tbody>
        </table>
    </div>;
}

function Dashboard({ report, contractTypes, year }) {
    if (!report.length) return <p className="sig-card p-5 text-sm text-[var(--ink-500)]">Nenhum contrato encontrado para os filtros.</p>;

    return <>
        <p className="text-sm text-[var(--ink-500)]">{year ? `Exercício de ${year}` : 'Desde o início dos contratos'}. Recebimentos entram no mês em que foram pagos.</p>
        {report.map((group) => {
            const amount = (value) => money(value, group.currency);
            return <section key={group.currency} className="space-y-4">
                <h2 className="font-semibold">Resumo · {group.currency}</h2>
                <MetricCards items={[
                    ['Receita', amount(group.revenue)], ['Custo operacional', amount(group.cost)],
                    ['Resultado operacional', amount(group.result)], ['Margem operacional', percent(group.margin)],
                    ['Faturado', amount(group.billed)], ['Recebido', amount(group.received)],
                ]} />
                {group.contracts.length > 1 && <section className="sig-card overflow-hidden">
                    <h3 className="px-4 py-3 font-semibold">Receita por tipo de contrato</h3>
                    <ReportTable headings={['Tipo', 'Receita']} rows={group.types.map(({ type, revenue }) => [
                        type, contractTypes[type] ?? 'Não informado', amount(revenue),
                    ])} />
                </section>}
                <section className="sig-card overflow-hidden">
                    <h3 className="px-4 py-3 font-semibold">Participação por contrato</h3>
                    <ReportTable headings={['Contrato', 'Tipo', 'UF', 'Receita', 'Recebido', 'Participação na receita']} rows={group.contracts.map((row) => [
                        row.contract.id, row.contract.code, contractTypes[row.contract.contract_type] ?? 'Não informado',
                        row.contract.state ?? '—', amount(row.revenue), amount(row.received), percent(row.participation),
                    ])} />
                </section>
                {group.contracts.map((row) => <details key={row.contract.id} open={group.contracts.length === 1} className="sig-card">
                    <summary className="cursor-pointer px-4 py-3 font-semibold">{row.contract.code} · {row.contract.name}</summary>
                    <div className="space-y-4 border-t border-[var(--border)] p-4">
                        <MetricCards items={[
                            ['Resultado operacional', amount(row.result)],
                            ['Margem operacional', percent(row.margin)],
                            ['Faturado a receber', amount(row.outstanding), 'Acumulado do contrato'],
                            ['Saldo da linha de base', amount(row.baseline_balance), row.baseline ? `Previsto ${amount(row.baseline.expected_amount)} até ${date(row.baseline.expected_date)}` : 'Linha de base ainda não configurada'],
                            ['Valor contratual', amount(row.contract.total_value), `Término ${date(row.contract.ends_at)}`],
                            ['Percentual medido', percent(row.measured_percent), 'Acumulado sobre o valor contratual'],
                            ...(year ? [['Meta anual de receita', amount(row.target?.revenue_target)], ['Meta anual de margem', percent(row.target?.margin_target)]] : []),
                        ]} />
                        {row.series.length ? <ReportTable headings={['Mês', 'Receita', 'Receita acumulada', 'Custo', 'Faturado', 'Recebido']}
                            rows={row.series.map((item) => [item.month, monthLabel(item.month), amount(item.revenue), amount(item.accumulated), amount(item.cost), amount(item.billed), amount(item.received)])}
                        /> : <p className="text-sm text-[var(--ink-500)]">Nenhum movimento registrado neste período.</p>}
                    </div>
                </details>)}
            </section>;
        })}
    </>;
}
