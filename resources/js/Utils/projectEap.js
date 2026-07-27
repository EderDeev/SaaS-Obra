export function projectEap(documentOrCode, versionOrRevision = null) {
    const document = typeof documentOrCode === 'object' && documentOrCode !== null
        ? documentOrCode
        : null;
    const code = String(document?.code ?? documentOrCode ?? '').trim().replace(/-R\d+$/i, '');
    const revision = String(
        typeof versionOrRevision === 'object' && versionOrRevision !== null
            ? versionOrRevision.revision
            : versionOrRevision
                ?? document?.latest_version?.revision
                ?? document?.latest_approved_version?.revision
                ?? '',
    ).trim().toUpperCase();

    return [code, revision].filter(Boolean).join('-');
}
