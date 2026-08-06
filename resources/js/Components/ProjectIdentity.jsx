export default function ProjectIdentity({
    eap,
    fileName,
    title = null,
    className = '',
    eapClassName = 'text-[15px]',
    fileClassName = 'text-sm',
    titleClassName = 'text-xs',
    eapWrapClassName = 'break-all',
    fileWrapClassName = 'break-all',
}) {
    const normalizedFileName = fileName || 'Arquivo nao informado';
    const showTitle = title && title !== normalizedFileName;

    return (
        <div className={`min-w-0 ${className}`}>
            <div className={`mono font-bold leading-snug text-[var(--primary)] ${eapWrapClassName} ${eapClassName}`}>
                {eap || 'EAP nao informada'}
            </div>
            <div className={`mt-1 font-medium leading-snug text-[var(--ink-700)] ${fileWrapClassName} ${fileClassName}`} title={normalizedFileName}>
                {normalizedFileName}
            </div>
            {showTitle && (
                <div className={`mt-1 break-words text-[var(--ink-500)] ${titleClassName}`}>
                    {title}
                </div>
            )}
        </div>
    );
}
