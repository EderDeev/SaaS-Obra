import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { FileText, FileSpreadsheet, Filter } from 'lucide-react';

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));

const formatDecimal = (value) =>
    new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(value || 0));

const integerToWords = (value) => {
    const units = ['', 'um', 'dois', 'tr\u00EAs', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];
    const teens = ['dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    const tens = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    const hundreds = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

    const belowThousand = (number) => {
        const n = Number(number || 0);
        if (n === 0) return '';
        if (n === 100) return 'cem';

        const parts = [];
        const h = Math.floor(n / 100);
        const rest = n % 100;

        if (h) parts.push(hundreds[h]);
        if (rest >= 10 && rest < 20) {
            parts.push(teens[rest - 10]);
        } else {
            const t = Math.floor(rest / 10);
            const u = rest % 10;
            if (t) parts.push(tens[t]);
            if (u) parts.push(units[u]);
        }

        return parts.filter(Boolean).join(' e ');
    };

    const n = Math.floor(Math.abs(Number(value || 0)));
    if (n === 0) return 'zero';

    const millions = Math.floor(n / 1000000);
    const thousands = Math.floor((n % 1000000) / 1000);
    const rest = n % 1000;
    const parts = [];

    if (millions) parts.push(`${belowThousand(millions)} ${millions === 1 ? 'milh\u00E3o' : 'milh\u00F5es'}`);
    if (thousands) parts.push(thousands === 1 ? 'mil' : `${belowThousand(thousands)} mil`);
    if (rest) parts.push(belowThousand(rest));

    return parts.join(' e ');
};

const moneyToWords = (value) => {
    const amount = Number(value || 0);
    const reais = Math.floor(Math.abs(amount));
    let centavos = Math.round((Math.abs(amount) - reais) * 100);
    let realValue = reais;

    if (centavos === 100) {
        realValue += 1;
        centavos = 0;
    }

    const parts = [
        `${integerToWords(realValue)} ${realValue === 1 ? 'real' : 'reais'}`,
    ];

    if (centavos > 0) {
        parts.push(`${integerToWords(centavos)} ${centavos === 1 ? 'centavo' : 'centavos'}`);
    }

    return `${amount < 0 ? 'menos ' : ''}${parts.join(' e ')}`.toUpperCase();
};

export default function MedicaoRelatoriosIndex({
    selectedContractId,
    selectedBoletimId,
    selectedReport = 'pleito_preliminar',
    contracts = [],
    boletins = [],
    reports = [],
    boletim = null,
    reportData = { title: 'Pleito preliminar', description: '', headers: [], rows: [], totals: {} },
}) {
    const page = usePage();
    const tenant = page.props.currentTenant;

    const applyFilters = (changes = {}) => {
        router.get(
            route('tenant.medicao.relatorios.index', tenant.slug),
            {
                contract_id: changes.contract_id ?? selectedContractId ?? '',
                boletim_id: changes.boletim_id ?? selectedBoletimId ?? '',
                relatorio: changes.relatorio ?? selectedReport,
            },
            { preserveScroll: true, preserveState: false }
        );
    };

    const rows = reportData.rows || [];
    const headers = reportData.headers || [];
    const totals = reportData.totals || {};
    const exportParams = new URLSearchParams({
        contract_id: selectedContractId || '',
        boletim_id: selectedBoletimId || '',
    });
    const exportRoutes = selectedReport === 'resumo'
        ? {
            excel: 'tenant.medicao.relatorios.resumo.excel',
            pdf: 'tenant.medicao.relatorios.resumo.pdf',
        }
        : selectedReport === 'sintetico'
        ? {
            excel: 'tenant.medicao.relatorios.sintetico.excel',
            pdf: 'tenant.medicao.relatorios.sintetico.pdf',
        }
        : selectedReport === 'por_fr'
        ? {
            excel: 'tenant.medicao.relatorios.por-fr.excel',
            pdf: 'tenant.medicao.relatorios.por-fr.pdf',
        }
        : selectedReport === 'analise_pleito'
            ? {
            excel: 'tenant.medicao.relatorios.analise-pleito.excel',
            pdf: 'tenant.medicao.relatorios.analise-pleito.pdf',
        } : {
            excel: 'tenant.medicao.relatorios.pleito-preliminar.excel',
            pdf: 'tenant.medicao.relatorios.pleito-preliminar.pdf',
        };
    const excelUrl = `${route(exportRoutes.excel, tenant.slug)}?${exportParams.toString()}`;
    const pdfUrl = `${route(exportRoutes.pdf, tenant.slug)}?${exportParams.toString()}`;
    const totalMoneyKey = selectedReport === 'resumo'
        ? 'moeda_p0'
        : selectedReport === 'sintetico'
        ? 'valor_acumulado_atual'
        : selectedReport === 'por_fr'
        ? 'valor_no_periodo'
        : selectedReport === 'analise_pleito'
            ? 'total_aprovado_medicao'
            : 'total_reajustado';
    const resumoTotalRow = selectedReport === 'resumo' ? rows.find((row) => row._is_summary) : null;
    const resumoPeriodo = Number(resumoTotalRow?.no_periodo_p0 || totals.no_periodo_p0 || 0);
    const resumoReajuste = Number(resumoTotalRow?.valor_reajuste_periodo || totals.valor_reajuste_periodo || 0);
    const resumoTotal = resumoPeriodo + resumoReajuste;
    const resumoFechamento = [
        { label: '[3] Medido no Per\u00EDodo (H)', value: resumoPeriodo },
        { label: '[4] Valor do Reajuste', value: resumoReajuste },
        { label: '[5] Total [3]+[4]', value: resumoTotal },
    ];
    const empresaAssinaturaLabel = (empresa, fallback) => empresa?.nome || empresa?.sigla || fallback;
    const assinaturasResumo = [
        {
            label: 'Gerenciadora',
            nome: empresaAssinaturaLabel(boletim?.contract?.gerenciadora_empresa, 'Gerenciadora'),
        },
        {
            label: 'Cliente',
            nome: empresaAssinaturaLabel(boletim?.contract?.cliente_empresa, 'Cliente'),
        },
        {
            label: 'Construtora',
            nome: empresaAssinaturaLabel(boletim?.contract?.construtora_empresa, 'Construtora'),
        },
    ];

    return (
        <AuthenticatedLayout>
            <Head title="Relatórios Medição" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span className="eyebrow">Medição</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Relatórios Medição</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                            Gere relatórios por Boletim de Medição. Escolha o tipo de relatório para visualizar e exportar em Excel ou PDF.
                        </p>
                    </div>
                </section>

                <section className="sig-card p-5">
                    <div className="grid gap-4 xl:grid-cols-[minmax(280px,1fr)_220px_220px_140px] xl:items-end">
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Contrato</span>
                            <select
                                value={selectedContractId || ''}
                                onChange={(event) => applyFilters({ contract_id: event.target.value, boletim_id: '' })}
                                className="sig-input"
                            >
                                <option value="">Selecione um contrato</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} - {contract.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="grid gap-1.5 text-sm">
                            <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Boletim</span>
                            <select
                                value={selectedBoletimId || ''}
                                onChange={(event) => applyFilters({ boletim_id: event.target.value })}
                                className="sig-input"
                            >
                                {boletins.length === 0 && <option value="">Selecione um contrato</option>}
                                {boletins.length > 0 && <option value="">Selecione um BM</option>}
                                {boletins.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.codigo} - {item.periodo_formatado} - {item.tipo_label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="grid gap-1.5 text-sm">
                            <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Relatório</span>
                            <select
                                value={selectedReport}
                                onChange={(event) => applyFilters({ relatorio: event.target.value })}
                                className="sig-input"
                            >
                                {reports.map((report) => (
                                    <option key={report.value} value={report.value}>{report.label}</option>
                                ))}
                            </select>
                        </label>

                        <button type="button" onClick={() => applyFilters()} className="sig-btn sig-btn-primary justify-center">
                            <Filter size={16} />
                            Aplicar
                        </button>
                    </div>
                </section>

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2">
                                <FileSpreadsheet size={20} className="text-emerald-700" />
                                <h2 className="text-lg font-bold text-[var(--ink-900)]">{reportData.title}</h2>
                            </div>
                            <p className="mt-1 text-sm text-[var(--ink-600)]">{reportData.description}</p>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                {boletim
                                    ? `${boletim.codigo} · Referência ${boletim.periodo_formatado} · ${boletim.tipo_label} · ${boletim.contract?.code || ''}`
                                    : 'Selecione um Boletim de Medição para gerar o relatório.'}
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center justify-end gap-2">
                            {boletim && (
                                <>
                                    <a href={excelUrl} className="sig-btn bg-emerald-600 text-white hover:bg-emerald-700">
                                        <FileSpreadsheet size={16} />
                                        Exportar Excel
                                    </a>
                                    <a href={pdfUrl} className="sig-btn bg-red-600 text-white hover:bg-red-700">
                                        <FileText size={16} />
                                        Exportar PDF
                                    </a>
                                </>
                            )}
                            <div className="grid gap-1 text-right text-sm">
                                <span className="font-semibold text-[var(--ink-500)]">{rows.length} linha(s)</span>
                                <strong className="text-emerald-700">{formatCurrency(totals[totalMoneyKey])}</strong>
                            </div>
                        </div>
                    </header>

                    {rows.length === 0 ? (
                        <div className="p-10 text-center">
                            <FileSpreadsheet className="mx-auto text-[var(--ink-400)]" size={34} />
                            <p className="mt-3 font-bold text-[var(--ink-900)]">
                                {selectedBoletimId ? 'Nenhum pleito encontrado' : 'Filtre para gerar o relatório'}
                            </p>
                            {!selectedBoletimId && (
                                <p className="mt-1 text-sm text-[var(--ink-500)]">
                                    Selecione um contrato, um BM e o tipo de relatório para carregar os dados.
                                </p>
                            )}
                            <p className={`mt-1 text-sm text-[var(--ink-500)] ${selectedBoletimId ? '' : 'hidden'}`}>
                                Este BM ainda não possui itens pleiteados em Folhas de Rosto.
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-auto">
                            <table className="min-w-[1500px] w-full border-collapse text-left text-xs">
                                <thead className="bg-white">
                                    <tr>
                                        {headers.map((header) => (
                                            <th
                                                key={header.key}
                                                className={`border border-[var(--border)] px-3 py-2 font-black uppercase tracking-wide text-[var(--ink-600)] ${header.numeric ? 'whitespace-nowrap text-right' : ''}`}
                                            >
                                                {header.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row, index) => (
                                        row._is_group ? (
                                            <tr key={`group-${row.group_title || index}`} className="bg-slate-200 text-[var(--ink-900)]">
                                                <td colSpan={headers.length} className="border border-[var(--border)] px-3 py-2 text-center font-black uppercase tracking-wide">
                                                    {row.group_title}
                                                </td>
                                            </tr>
                                        ) : (
                                            <tr
                                                key={`${row.descricao || row.os || ''}-${row.item || ''}-${index}`}
                                                className={row._is_summary || row._is_fr_total ? 'bg-slate-100 font-black text-[var(--ink-900)]' : 'odd:bg-slate-50/60'}
                                            >
                                                {headers.map((header) => (
                                                    <td
                                                        key={`${index}-${header.key}`}
                                                        className={`border border-[var(--border)] px-3 py-2 align-top ${header.numeric ? 'whitespace-nowrap text-right font-semibold tabular-nums' : ''}`}
                                                    >
                                                        {row[header.key] === null || row[header.key] === undefined || row[header.key] === ''
                                                            ? ''
                                                            : header.money
                                                                ? formatCurrency(row[header.key])
                                                                : header.percent
                                                                    ? `${formatDecimal(row[header.key])}%`
                                                                : header.numeric
                                                                    ? formatDecimal(row[header.key])
                                                                    : row[header.key] || '-'}
                                                    </td>
                                                ))}
                                            </tr>
                                        )
                                    ))}
                                </tbody>
                                {selectedReport !== 'resumo' && (
                                <tfoot>
                                    <tr className="bg-slate-100 font-black text-[var(--ink-900)]">
                                        {headers.map((header, index) => (
                                            <td
                                                key={`total-${header.key}`}
                                                className={`border border-[var(--border)] px-3 py-2 ${header.numeric ? 'whitespace-nowrap text-right tabular-nums' : ''}`}
                                            >
                                                {index === 0
                                                    ? 'Total'
                                                    : Object.prototype.hasOwnProperty.call(totals, header.key)
                                                        ? header.money
                                                            ? formatCurrency(totals[header.key])
                                                            : header.percent
                                                                ? `${formatDecimal(totals[header.key])}%`
                                                            : header.numeric
                                                                ? formatDecimal(totals[header.key])
                                                                : formatDecimal(totals[header.key])
                                                        : ''}
                                            </td>
                                        ))}
                                    </tr>
                                </tfoot>
                                )}
                            </table>
                            {selectedReport === 'resumo' && (
                                <div className="min-w-[980px] border-x border-b border-[var(--border)] bg-white">
                                    <table className="w-full border-collapse text-xs">
                                        <thead>
                                            <tr className="bg-slate-100 text-[var(--ink-900)]">
                                                <th className="border border-[var(--border)] px-3 py-2 text-center font-black">Descrição</th>
                                                <th className="border border-[var(--border)] px-3 py-2 text-center font-black">Valores (R$)</th>
                                                <th className="border border-[var(--border)] px-3 py-2 text-center font-black">Por extenso</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {resumoFechamento.map((row) => (
                                                <tr key={row.label}>
                                                    <td className="border border-[var(--border)] px-3 py-2 text-center font-semibold">{row.label}</td>
                                                    <td className="border border-[var(--border)] px-3 py-2 text-right font-bold tabular-nums">{formatCurrency(row.value)}</td>
                                                    <td className="border border-[var(--border)] px-3 py-2 text-center font-black uppercase">{moneyToWords(row.value)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>

                                    <div className="grid gap-6 px-8 py-10 text-center text-sm md:grid-cols-3">
                                        {assinaturasResumo.map((assinatura) => (
                                            <div key={assinatura.label} className="pt-8">
                                                <div className="border-t border-slate-900 pt-2">
                                                    <p>{assinatura.nome}</p>
                                                    <strong className="uppercase">{assinatura.label}</strong>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
