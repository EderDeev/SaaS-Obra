import SigLogo from '@/Components/SigLogo';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('register'), { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AuthShell title="Criar conta">
            <Head title="Criar conta" />
            <form onSubmit={submit} className="grid gap-3">
                <Field label="Nome" error={form.errors.name}><input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
                <Field label="Email" error={form.errors.email}><input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /></Field>
                <Field label="Senha" error={form.errors.password}><input type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required /></Field>
                <Field label="Confirmar senha"><input type="password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} required /></Field>
                <button className="sig-btn sig-btn-primary mt-2" disabled={form.processing}>Criar conta</button>
                <Link href={route('login')} className="text-sm font-semibold text-[var(--primary)]">Já tenho conta</Link>
            </form>
        </AuthShell>
    );
}

function AuthShell({ title, children }) {
    return (
        <main className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4 py-10">
            <section className="sig-card w-full max-w-md p-6">
                <SigLogo size={26} />
                <h1 className="mt-6 text-2xl font-semibold">{title}</h1>
                <div className="mt-5">{children}</div>
            </section>
        </main>
    );
}

function Field({ label, error, children }) {
    return <label><span className="eyebrow mb-1 block">{label}</span><span className="sig-input">{children}</span>{error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}</label>;
}
