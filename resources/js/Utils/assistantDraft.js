const STORAGE_KEY = 'deming:assistant-draft';

export function storeAssistantDraft(tenantId, action) {
    if (!tenantId || action?.type !== 'draft' || !action?.draft_type) {
        return false;
    }

    sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        tenant_id: Number(tenantId),
        draft_type: action.draft_type,
        payload: action.payload || {},
        created_at: Date.now(),
    }));

    return true;
}

export function consumeAssistantDraft(tenantId, draftType) {
    const raw = sessionStorage.getItem(STORAGE_KEY);

    if (!raw) {
        return null;
    }

    sessionStorage.removeItem(STORAGE_KEY);

    try {
        const draft = JSON.parse(raw);
        const isFresh = Number(draft.created_at || 0) > Date.now() - (10 * 60 * 1000);

        if (
            Number(draft.tenant_id) !== Number(tenantId)
            || draft.draft_type !== draftType
            || !isFresh
            || !draft.payload
        ) {
            return null;
        }

        return draft.payload;
    } catch {
        return null;
    }
}
