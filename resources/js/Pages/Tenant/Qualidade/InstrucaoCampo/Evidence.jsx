import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import EvidenceForm from './EvidenceForm';

export default function Evidence({ tenant, ic, resolutionOptions }) {
    return <AuthenticatedLayout><Head title={`Registrar evidências · IC ${ic.formatted_number}`} />
        <main className="sig-content mx-auto grid w-full max-w-5xl gap-5">
            <header><Link href={route('tenant.qualidade.ic.show', [tenant.slug, ic.id])} className="mb-3 inline-flex items-center gap-2 text-sm text-[var(--ink-500)]"><ArrowLeft size={15} />Acessar IC</Link>
                <h1 className="text-2xl font-semibold">Registrar evidências</h1><p className="mt-2 break-words text-sm text-[var(--ink-500)]">IC {ic.formatted_number}{ic.assunto ? ` · ${ic.assunto}` : ''}</p>
            </header>
            <EvidenceForm tenant={tenant} ic={ic} resolutionOptions={resolutionOptions} />
        </main>
    </AuthenticatedLayout>;
}
