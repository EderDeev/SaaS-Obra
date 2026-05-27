import SigLogo from '@/Components/SigLogo';
import { Head, Link, useForm } from '@inertiajs/react';

export default function VerifyEmail({ status }) {
    const form = useForm({});

    return (
        <main className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4 py-10">
            <Head title="Verificar email" />
            <section className="sig-card w-full max-w-md p-6">
                <SigLogo size={26} />
                <h1 className="mt-6 text-2xl font-semibold">Verifique seu email</h1>
                <p className="mt-2 text-sm text-[var(--ink-500)]">Enviamos um link de verificação para sua caixa de entrada.</p>
                {status === 'verification-link-sent' && <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">Novo link enviado.</div>}
                <div className="mt-5 flex flex-wrap gap-2">
                    <button className="sig-btn sig-btn-primary" onClick={() => form.post(route('verification.send'))} disabled={form.processing}>Reenviar</button>
                    <Link href={route('logout')} method="post" as="button" className="sig-btn sig-btn-secondary">Sair</Link>
                </div>
            </section>
        </main>
    );
}
