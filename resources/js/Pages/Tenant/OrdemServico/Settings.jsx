import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarClock, FileCheck2, FolderKanban, Save, SlidersHorizontal, UserCheck } from 'lucide-react';
import { useEffect } from 'react';

const requirementOptions = [
    {
        field: 'require_project',
        title: 'Projeto vinculado',
        description: 'A OS precisa estar relacionada a pelo menos um projeto do contrato.',
        icon: FolderKanban,
    },
    {
        field: 'require_document',
        title: 'Documento anexado',
        description: 'Exige um memorial, escopo ou outro documento de apoio antes da análise.',
        icon: FileCheck2,
    },
    {
        field: 'require_deadline',
        title: 'Período de execução',
        description: 'As previsões de início e de finalização devem ser informadas no cadastro da OS.',
        icon: CalendarClock,
    },
    {
        field: 'require_execution_responsible',
        title: 'Responsável da execução',
        description: 'Exige ao menos um usuário responsável por acompanhar a execução.',
        icon: UserCheck,
    },
];

export default function OrdemServicoSettings({ selectedContractId, contracts = [], requirements = {} }) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const form = useForm({
        contract_id: selectedContractId || '',
        require_project: Boolean(requirements.require_project),
        require_document: Boolean(requirements.require_document),
        require_deadline: Boolean(requirements.require_deadline),
        require_execution_responsible: Boolean(requirements.require_execution_responsible),
    });

    useEffect(() => {
        form.setData({
            contract_id: selectedContractId || '',
            require_project: Boolean(requirements.require_project),
            require_document: Boolean(requirements.require_document),
            require_deadline: Boolean(requirements.require_deadline),
            require_execution_responsible: Boolean(requirements.require_execution_responsible),
        });
    }, [selectedContractId, requirements]);

    const changeContract = (contractId) => {
        router.get(
            route('tenant.ordem-servico.settings.index', tenant.slug),
            { contract_id: contractId },
            { preserveScroll: true, preserveState: false }
        );
    };

    const submit = (event) => {
        event.preventDefault();
        form.patch(route('tenant.ordem-servico.settings.update', tenant.slug), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Parametrização da OS" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <header>
                    <span className="eyebrow">Ordem de Serviço</span>
                    <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Parametrização</h1>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                        Defina os dados mínimos que uma OS precisa apresentar antes de seguir para análise.
                    </p>
                </header>

                {page.props.flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                        {page.props.flash.success}
                    </div>
                )}

                {Object.values(page.props.errors || {}).length > 0 && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {Object.values(page.props.errors)[0]}
                    </div>
                )}

                <section className="sig-card p-5">
                    <label className="grid gap-1.5 text-sm">
                        <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Contrato</span>
                        <select
                            value={selectedContractId || ''}
                            onChange={(event) => changeContract(event.target.value)}
                            className="sig-input"
                            disabled={contracts.length === 0}
                        >
                            {contracts.length === 0 && <option value="">Nenhum contrato disponível</option>}
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>
                                    {contract.code} - {contract.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </section>

                <form onSubmit={submit} className="sig-card overflow-hidden">
                    <header className="flex items-center gap-3 border-b border-[var(--border)] px-5 py-4">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                            <SlidersHorizontal size={20} />
                        </span>
                        <div>
                            <h2 className="text-lg font-bold text-[var(--ink-900)]">Requisitos antes da análise</h2>
                            <p className="text-sm text-[var(--ink-500)]">As regras são aplicadas a todas as novas OS do contrato selecionado.</p>
                        </div>
                    </header>

                    <div className="divide-y divide-[var(--border)]">
                        {requirementOptions.map(({ field, title, description, icon: Icon }) => (
                            <label key={field} className="flex cursor-pointer items-center gap-4 px-5 py-4 transition hover:bg-[var(--surface-muted)]">
                                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-md ${form.data[field] ? 'bg-blue-100 text-[var(--primary)]' : 'bg-[var(--surface-muted)] text-[var(--ink-500)]'}`}>
                                    <Icon size={18} />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <strong className="block text-sm text-[var(--ink-900)]">{title}</strong>
                                    <span className="mt-0.5 block text-xs leading-5 text-[var(--ink-500)]">{description}</span>
                                </span>
                                <input
                                    type="checkbox"
                                    checked={form.data[field]}
                                    onChange={(event) => form.setData(field, event.target.checked)}
                                    className="h-5 w-5 shrink-0 accent-[var(--primary)]"
                                />
                            </label>
                        ))}
                    </div>

                    <footer className="flex justify-end border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                        <button type="submit" disabled={form.processing || !selectedContractId} className="sig-btn sig-btn-primary">
                            <Save size={16} />
                            {form.processing ? 'Salvando...' : 'Salvar parametrização'}
                        </button>
                    </footer>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
