import SigLogo from '@/Components/SigLogo';
import { Head, useForm } from '@inertiajs/react';

export default function ConfirmPassword() {
    const form = useForm({ password: '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(route('password.confirm'), { onFinish: () => form.reset('password') });
    };

    return (
        <main className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4 py-10">
            <Head title="Confirmar senha" />
            <section className="sig-card w-full max-w-md p-6">
                <SigLogo size={26} />
                <h1 className="mt-6 text-2xl font-semibold">Confirmar senha</h1>
                <form onSubmit={submit} className="mt-5 grid gap-3">
                    <label><span className="eyebrow mb-1 block">Senha</span><span className="sig-input"><input type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required /></span>{form.errors.password && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.password}</span>}</label>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing}>Confirmar</button>
                </form>
            </section>
        </main>
    );
}
