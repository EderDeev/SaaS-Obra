import OrcamentoShell from './Partials/OrcamentoShell';

export default function OrcamentosIndex({ tenant }) {
    return (
        <OrcamentoShell
            tenant={tenant}
            active="orcamentos"
            title="Listar Orçamentos"
            subtitle="Central para acompanhar orçamentos por contrato, revisar versões e enxergar o valor previsto antes de detalharmos o fluxo de aprovação."
        >
            <div className="grid gap-4 lg:grid-cols-3">
                <InfoCard title="Orçamentos por contrato" value="0" description="Cada orçamento será vinculado a um contrato e poderá receber composições, insumos e revisões." />
                <InfoCard title="Em elaboração" value="0" description="Área reservada para orçamentos que ainda estão sendo montados pela equipe." />
                <InfoCard title="Aprovados" value="0" description="Quando o fluxo for criado, os orçamentos aprovados poderão alimentar o controle de custos." />
            </div>

            <section className="sig-card mt-5 overflow-hidden">
                <header className="border-b border-[var(--border)] px-5 py-4">
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Orçamentos cadastrados</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">A listagem real será conectada ao cadastro na próxima etapa.</p>
                </header>
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Nenhum orçamento cadastrado ainda.
                </div>
            </section>
        </OrcamentoShell>
    );
}

function InfoCard({ title, value, description }) {
    return (
        <article className="sig-card p-5">
            <span className="eyebrow">{title}</span>
            <strong className="mono mt-3 block text-3xl text-[var(--ink-900)]">{value}</strong>
            <p className="mt-2 text-sm leading-6 text-[var(--ink-500)]">{description}</p>
        </article>
    );
}
