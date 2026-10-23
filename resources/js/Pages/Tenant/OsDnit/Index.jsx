import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { ClipboardList, Package, SlidersHorizontal, Users } from 'lucide-react';

const sections = {
    orders: {
        title: 'Ordem de Serviço',
        routeName: 'tenant.os-dnit.orders.index',
        icon: ClipboardList,
        description: 'Solicitações de ordens de serviço do DNIT e acompanhamento das etapas de análise e autorização.',
        emptyTitle: 'Ordens de serviço DNIT',
        emptyDescription: 'Esta área receberá a identificação da demanda, os produtos sugeridos e o acompanhamento da solicitação.',
    },
    products: {
        title: 'Produtos',
        routeName: 'tenant.os-dnit.products.index',
        icon: Package,
        description: 'Produtos do contrato utilizados nas solicitações de ordens de serviço do DNIT.',
        emptyTitle: 'Produtos do contrato',
        emptyDescription: 'Esta área receberá o cadastro dos produtos, com código, descrição e unidade de medida.',
    },
    settings: {
        title: 'Parametrização',
        routeName: 'tenant.os-dnit.settings.index',
        icon: SlidersHorizontal,
        description: 'Configurações contratuais e rodovias para as ordens de serviço do DNIT.',
        emptyTitle: 'Configurações do módulo',
        emptyDescription: 'Esta área receberá os vínculos dos produtos e das rodovias com o contrato.',
    },
    responsibles: {
        title: 'Responsáveis',
        routeName: 'tenant.os-dnit.responsibles.index',
        icon: Users,
        description: 'Responsáveis pela solicitação, validação e autorização das ordens de serviço do DNIT.',
        emptyTitle: 'Responsáveis pelo fluxo',
        emptyDescription: 'Esta área receberá a definição do analista DNIT, do supervisor da fiscalizadora e do fiscal do contrato.',
    },
};

export default function Index({ section = 'orders' }) {
    const current = sections[section] || sections.orders;
    const Icon = current.icon;

    return (
        <AuthenticatedLayout>
            <Head title={`Ordem de Serviço · ${current.title}`} />

            <div className="sig-content os-dnit-page space-y-6">
                <header>
                    <div className="eyebrow">Ordem de Serviço</div>
                    <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{current.title}</h1>
                    <p className="mt-2 text-sm text-[var(--ink-500)]">{current.description}</p>
                </header>

                <section className="rounded-xl border border-slate-200 bg-white px-6 py-14 text-center">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-xl bg-blue-50 text-[var(--primary)]">
                        <Icon size={26} strokeWidth={1.6} />
                    </div>
                    <h2 className="mt-5 text-lg font-semibold text-[var(--ink-900)]">{current.emptyTitle}</h2>
                    <p className="mx-auto mt-2 max-w-xl text-sm text-[var(--ink-500)]">{current.emptyDescription}</p>
                    <span className="mt-5 inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-[var(--ink-500)]">
                        Em preparação
                    </span>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
