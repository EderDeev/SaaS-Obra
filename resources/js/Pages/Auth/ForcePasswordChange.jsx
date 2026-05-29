import PasswordStrengthMeter, { passwordChecklistStatus } from '@/Components/PasswordStrengthMeter';
import SigLogo from '@/Components/SigLogo';
import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

export default function ForcePasswordChange() {
    const form = useForm({
        password: '',
        password_confirmation: '',
    });
    const passwordStatus = passwordChecklistStatus(form.data.password, form.data.password_confirmation);
    const canSubmitPassword = passwordStatus.passwordValid && passwordStatus.confirmationMatches;

    const submit = (event) => {
        event.preventDefault();

        form.put(route('password.force.update'), {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <main className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4 py-10">
            <Head title="Alterar senha provisoria" />

            <section className="sig-card w-full max-w-md p-6">
                <SigLogo size={28} />

                <div className="mt-7 flex items-start gap-3">
                    <span className="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                        <CheckCircle2 size={18} />
                    </span>
                    <div>
                        <h1 className="text-2xl font-semibold text-[var(--ink-900)]">Crie sua senha definitiva</h1>
                        <p className="mt-2 text-sm leading-6 text-[var(--ink-500)]">
                            Sua conta foi criada com uma senha provisoria. Antes de acessar o sistema, defina uma nova senha pessoal.
                        </p>
                    </div>
                </div>

                <form onSubmit={submit} className="mt-6 grid gap-4">
                    <Field label="Nova senha" error={form.errors.password}>
                        <input
                            type="password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            autoFocus
                            required
                            autoComplete="new-password"
                        />
                    </Field>

                    <Field label="Confirmar nova senha" error={form.errors.password_confirmation}>
                        <input
                            type="password"
                            value={form.data.password_confirmation}
                            onChange={(event) => form.setData('password_confirmation', event.target.value)}
                            required
                            autoComplete="new-password"
                        />
                    </Field>

                    <PasswordStrengthMeter
                        password={form.data.password}
                        confirmation={form.data.password_confirmation}
                        showConfirmation
                    />

                    <button className="sig-btn sig-btn-primary w-full" disabled={form.processing || !canSubmitPassword}>
                        Salvar nova senha
                    </button>
                </form>
            </section>
        </main>
    );
}

function Field({ label, error, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">{children}</span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}
