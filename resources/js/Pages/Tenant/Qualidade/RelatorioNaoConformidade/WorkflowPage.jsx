import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import WorkflowForm, { workflowStages } from './WorkflowForm';

export default function WorkflowPage({ stage, ...data }) {
    return <AuthenticatedLayout><Head title={`${workflowStages[stage].title} - RNC ${data.rnc.formatted_number}`} />
        <main className="sig-content quality-mobile-page mx-auto grid w-full max-w-7xl gap-5"><header>
            <Link href={route('tenant.qualidade.rnc.show', [data.tenant.slug, data.rnc.id])} className="mb-3 inline-flex items-center gap-2 text-sm text-[var(--ink-500)]"><ArrowLeft size={15} />Acessar RNC</Link>
            <h1 className="text-2xl font-semibold">{workflowStages[stage].title}</h1><p className="mt-1 text-sm text-[var(--ink-500)]">RNC {data.rnc.formatted_number}{data.rnc.assunto ? ` · ${data.rnc.assunto}` : ''}</p>
        </header><WorkflowForm {...data} stage={stage} /></main>
    </AuthenticatedLayout>;
}
