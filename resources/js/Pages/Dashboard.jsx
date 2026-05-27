import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Dashboard() {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <section className="sig-content">
                <div className="sig-card p-6">Carregando seu workspace...</div>
            </section>
        </AuthenticatedLayout>
    );
}
