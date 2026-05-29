import PasswordStrengthMeter, { passwordChecklistStatus } from '@/Components/PasswordStrengthMeter';
import SigLogo from '@/Components/SigLogo';
import { Head, useForm } from '@inertiajs/react';

export default function ResetPassword({ token, email }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    const passwordStatus = passwordChecklistStatus(form.data.password, form.data.password_confirmation);
    const canSubmitPassword = passwordStatus.passwordValid && passwordStatus.confirmationMatches;

    const submit = (event) => {
        event.preventDefault();
        form.post(route('password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <main className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4 py-10">
            <Head title="Redefinir senha" />
            <section className="sig-card w-full max-w-md p-6">
                <SigLogo size={26} />
                <h1 className="mt-6 text-2xl font-semibold">Redefinir senha</h1>
                <form onSubmit={submit} className="mt-5 grid gap-3">
                    <Field label="Email"><input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /></Field>
                    <Field label="Nova senha" error={form.errors.password}>
                        <input
                            type="password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            required
                            autoComplete="new-password"
                        />
                    </Field>
                    <PasswordStrengthMeter
                        password={form.data.password}
                        confirmation={form.data.password_confirmation}
                        showConfirmation
                    />
                    <Field label="Confirmar senha" error={form.errors.password_confirmation}>
                        <input
                            type="password"
                            value={form.data.password_confirmation}
                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                            required
                            autoComplete="new-password"
                        />
                    </Field>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing || !canSubmitPassword}>Redefinir senha</button>
                </form>
            </section>
        </main>
    );
}

function Field({ label, error, children }) {
    return <label><span className="eyebrow mb-1 block">{label}</span><span className="sig-input">{children}</span>{error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}</label>;
}
