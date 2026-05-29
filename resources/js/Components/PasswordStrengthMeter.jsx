import { CheckCircle2, Circle } from 'lucide-react';

const checks = [
    {
        key: 'length',
        label: 'No minimo 6 caracteres',
        test: (password) => password.length >= 6,
    },
    {
        key: 'upper',
        label: 'Uma letra maiuscula',
        test: (password) => /[A-Z]/.test(password),
    },
    {
        key: 'lower',
        label: 'Uma letra minuscula',
        test: (password) => /[a-z]/.test(password),
    },
    {
        key: 'number',
        label: 'Um numero',
        test: (password) => /\d/.test(password),
    },
    {
        key: 'symbol',
        label: 'Um simbolo',
        test: (password) => /[^A-Za-z0-9]/.test(password),
    },
];

export function passwordChecklistStatus(password = '', confirmation = '') {
    const requirements = checks.map((check) => check.test(password));

    return {
        requirements,
        passwordValid: requirements.every(Boolean),
        confirmationMatches: password.length > 0 && password === confirmation,
    };
}

const levels = [
    { label: 'Muito fraca', color: 'bg-[var(--red)]', text: 'text-[var(--red)]' },
    { label: 'Fraca', color: 'bg-[var(--red)]', text: 'text-[var(--red)]' },
    { label: 'Regular', color: 'bg-amber-500', text: 'text-amber-600' },
    { label: 'Boa', color: 'bg-[var(--primary)]', text: 'text-[var(--primary)]' },
    { label: 'Forte', color: 'bg-[var(--green)]', text: 'text-[var(--green)]' },
    { label: 'Muito forte', color: 'bg-[var(--green)]', text: 'text-[var(--green)]' },
];

export default function PasswordStrengthMeter({ password = '', confirmation = '', showConfirmation = false }) {
    const results = checks.map((check) => ({
        ...check,
        valid: check.test(password),
    }));
    const validCount = results.filter((check) => check.valid).length;
    const confirmationFilled = confirmation.length > 0;
    const confirmationMatches = password.length > 0 && password === confirmation;
    const totalScore = validCount;
    const maxScore = checks.length;
    const percent = maxScore > 0 ? Math.round((totalScore / maxScore) * 100) : 0;
    const strength = levels[strengthIndex(password, percent)];

    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
            <div className="flex items-center justify-between gap-3">
                <span className="text-[12.5px] font-semibold text-[var(--ink-600)]">Forca da senha</span>
                <span className={`text-[12.5px] font-semibold ${strength.text}`}>{password ? strength.label : 'Digite a senha'}</span>
            </div>

            <div className="mt-2 h-2 overflow-hidden rounded-full bg-white">
                <span
                    className={`block h-full rounded-full transition-all duration-300 ${password ? strength.color : 'bg-[var(--ink-200)]'}`}
                    style={{ width: `${password ? percent : 0}%` }}
                />
            </div>

            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                {results.map((check) => (
                    <Requirement key={check.key} valid={check.valid} label={check.label} />
                ))}
                {showConfirmation && (
                    <Requirement
                        valid={confirmationMatches}
                        muted={!confirmationFilled}
                        label="Confirmacao igual"
                    />
                )}
            </div>
        </div>
    );
}

function Requirement({ valid, muted = false, label }) {
    const Icon = valid ? CheckCircle2 : Circle;

    return (
        <div className={`flex items-center gap-2 text-[12px] font-semibold transition ${valid ? 'text-[var(--green)]' : muted ? 'text-[var(--ink-400)]' : 'text-[var(--ink-500)]'}`}>
            <Icon size={14} />
            <span>{label}</span>
        </div>
    );
}

function strengthIndex(password, percent) {
    if (!password) {
        return 0;
    }

    if (percent < 34) {
        return 1;
    }

    if (percent < 50) {
        return 2;
    }

    if (percent < 67) {
        return 3;
    }

    if (percent < 100) {
        return 4;
    }

    return 5;
}
