export default function SigLogo({ wordmark = true, size = 28 }) {
    return (
        <span className="sig-logo">
            <svg
                className="sig-logo-mark"
                width={size}
                height={size}
                viewBox="0 0 32 32"
                fill="none"
                aria-hidden="true"
            >
                <path d="M16 3 3 11l13 8 13-8z" fill="currentColor" opacity="0.95" />
                <path d="M3 16l13 8 13-8" stroke="currentColor" strokeWidth="2.2" opacity="0.55" />
                <path d="M3 21l13 8 13-8" stroke="currentColor" strokeWidth="2.2" opacity="0.28" />
            </svg>
            {wordmark && <span>SIGWORKS</span>}
        </span>
    );
}
