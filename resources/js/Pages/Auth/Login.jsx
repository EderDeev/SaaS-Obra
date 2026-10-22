import SigLogo from '@/Components/SigLogo';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, HardHat } from 'lucide-react';

export default function Login({ canResetPassword, status }) {
    const form = useForm({
        email: '',
        password: '',
        remember: true,
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(route('login'), {
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <>
            <Head title="Acessar SigWorks" />

            <main className="sig-login grid min-h-screen grid-cols-[1fr_1.05fr] bg-[var(--bg)]">
                <section className="flex flex-col px-6 py-8 sm:px-10 lg:px-14">
                    <SigLogo size={26} />

                    <div className="flex flex-1 items-center justify-center py-12">
                        <form className="w-full max-w-[380px]" onSubmit={submit}>
                            <div className="eyebrow text-[var(--primary)]">Sistema de gestão de obras civis</div>
                            <h1 className="mt-3 text-[32px] font-semibold leading-tight text-[var(--ink-900)]">
                                Bem-vindo de volta.
                            </h1>

                            {status && (
                                <div className="mt-6 rounded-lg border border-[var(--green-50)] bg-[var(--green-50)] px-4 py-3 text-sm text-[var(--green)]">
                                    {status}
                                </div>
                            )}

                            <label className="mt-7 block">
                                <span className="mb-1.5 block text-[12.5px] font-semibold text-[var(--ink-700)]">
                                    Usuário ou e-mail
                                </span>
                                <span className="sig-input">
                                    <input
                                        value={form.data.email}
                                        onChange={(event) => form.setData('email', event.target.value)}
                                        type="text"
                                        required
                                        autoFocus
                                        autoComplete="username"
                                        placeholder="admin@obras.test"
                                    />
                                </span>
                                {form.errors.email && <span className="mt-2 block text-sm text-[var(--red)]">{form.errors.email}</span>}
                            </label>

                            <label className="mt-4 block">
                                <span className="mb-1.5 flex items-center justify-between gap-4">
                                    <span className="text-[12.5px] font-semibold text-[var(--ink-700)]">Senha</span>
                                    {canResetPassword && (
                                        <Link href={route('password.request')} className="text-[12.5px] font-semibold text-[var(--primary)]">
                                            Esqueci minha senha
                                        </Link>
                                    )}
                                </span>
                                <span className="sig-input">
                                    <input
                                        value={form.data.password}
                                        onChange={(event) => form.setData('password', event.target.value)}
                                        type="password"
                                        required
                                        autoComplete="current-password"
                                        placeholder="Sua senha"
                                    />
                                </span>
                                {form.errors.password && <span className="mt-2 block text-sm text-[var(--red)]">{form.errors.password}</span>}
                            </label>

                            <label className="mt-5 flex items-center gap-2 text-[13px] text-[var(--ink-700)]">
                                <input
                                    checked={form.data.remember}
                                    onChange={(event) => form.setData('remember', event.target.checked)}
                                    type="checkbox"
                                    className="h-4 w-4 rounded border-[var(--border)] text-[var(--primary)] focus:ring-[var(--primary)]"
                                />
                                Manter conectado neste dispositivo
                            </label>

                            <button
                                className={`sig-btn sig-btn-primary mt-6 w-full ${form.processing ? 'opacity-60' : ''}`}
                                disabled={form.processing}
                            >
                                Acessar SigWorks
                                <ArrowRight size={16} />
                            </button>

                            <div className="sig-card mt-8 flex items-start gap-3 p-4">
                                <HardHat size={20} className="mt-0.5 text-[var(--primary)]" />
                                <p className="text-[12.5px] leading-5 text-[var(--ink-500)]">
                                    Suporte operacional · <a href="mailto:suporte@sigworks.com.br" className="font-semibold text-[var(--primary)]">suporte@sigworks.com.br</a>
                                </p>
                            </div>
                        </form>
                    </div>

                    <footer className="flex flex-wrap justify-between gap-3 text-xs text-[var(--ink-400)]">
                        <span>SigWorks · Gestão de obras civis</span>
                    </footer>
                </section>

                <section className="sig-login-art relative flex flex-col justify-center overflow-hidden bg-[#0b1020] p-14 text-white">
                    <svg className="absolute inset-0 h-full w-full opacity-[0.18]" aria-hidden="true">
                        <defs>
                            <pattern id="sig-bp" width="36" height="36" patternUnits="userSpaceOnUse">
                                <path d="M36 0H0V36" stroke="#9ab1ff" strokeWidth="0.5" fill="none" />
                            </pattern>
                            <pattern id="sig-bp2" width="180" height="180" patternUnits="userSpaceOnUse">
                                <rect width="180" height="180" fill="url(#sig-bp)" />
                                <path d="M180 0H0V180" stroke="#9ab1ff" strokeWidth="1" fill="none" />
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#sig-bp2)" />
                    </svg>

                    <div className="relative z-10">
                        <h2 className="max-w-[480px] text-4xl font-semibold leading-tight">
                            Toda a gestão do contrato em uma única plataforma.
                        </h2>
                        <p className="mt-4 max-w-[440px] text-[14.5px] leading-6 text-white/70">
                            Acompanhe os serviços, organize as tarefas da equipe e consulte os documentos do contrato.
                        </p>
                    </div>
                </section>
            </main>
        </>
    );
}
