import SigLogo from '@/Components/SigLogo';
import { Head, Link, useForm } from '@inertiajs/react';

export default function ForgotPassword({ status }) {
    const form = useForm({ email: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('password.email'));
    };

    return (
        <main className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4 py-10">
            <Head title="Recuperar senha" />
            <section className="sig-card w-full max-w-md p-6">
                <SigLogo size={26} />
                <h1 className="mt-6 text-2xl font-semibold">Recuperar senha</h1>
                <p className="mt-2 text-sm text-[var(--ink-500)]">Informe seu email para receber o link de recuperação.</p>
                {status && <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">{status}</div>}
                <form onSubmit={submit} className="mt-5 grid gap-3">
                    <label><span className="eyebrow mb-1 block">Email</span><span className="sig-input"><input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /></span>{form.errors.email && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.email}</span>}</label>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing}>Enviar link</button>
                    <Link href={route('login')} className="text-sm font-semibold text-[var(--primary)]">Voltar ao login</Link>
                </form>
            </section>
        </main>
    );
}
