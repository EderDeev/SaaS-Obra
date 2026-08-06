import axios from 'axios';
import { storeAssistantDraft } from '@/Utils/assistantDraft';
import {
    ArrowRight,
    Bot,
    ClipboardPen,
    ExternalLink,
    LoaderCircle,
    MessageCircle,
    Plus,
    Send,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

function cleanAssistantText(content = '') {
    return content
        .replace(/\s*\[S\d+\]/gi, '')
        .replace(/^\s{0,3}#{1,6}\s*/gm, '')
        .replace(/^\s*[-*+]\s+/gm, '')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/\*\*(.*?)\*\*/gs, '$1')
        .replace(/__(.*?)__/gs, '$1')
        .replace(/\*([^*\r\n]+)\*/g, '$1')
        .replace(/[ \t]+([.,;:!?])/g, '$1')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function formatTokens(value) {
    return new Intl.NumberFormat('pt-BR').format(Number(value || 0));
}

function AssistantMessage({ message, onAction }) {
    const links = message.links || [];
    const actions = message.actions || [];
    const content = message.role === 'assistant' ? cleanAssistantText(message.content) : message.content;

    return (
        <div className={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-[88%] rounded-lg px-3 py-2.5 text-sm leading-6 ${
                    message.role === 'user'
                        ? 'bg-[#155EEF] text-white'
                        : 'border border-[var(--border)] bg-white text-[var(--ink-800)]'
                }`}
            >
                <p className="whitespace-pre-wrap break-words">{content}</p>

                {links.length > 0 && (
                    <div className="mt-3 border-t border-[var(--border)] pt-2">
                        <div className="space-y-1">
                            {links.map((link) => (
                                <a
                                    key={`${message.id}-${link.id}`}
                                    href={link.url}
                                    className="flex min-w-0 items-center gap-2 rounded-md px-1.5 py-1 text-xs font-semibold text-[#155EEF] hover:bg-[#EFF4FF]"
                                >
                                    <ExternalLink size={13} className="shrink-0" />
                                    <span className="min-w-0 flex-1 truncate">Abrir {link.title}</span>
                                </a>
                            ))}
                        </div>
                    </div>
                )}

                {actions.length > 0 && (
                    <div className="mt-3 space-y-2 border-t border-[var(--border)] pt-3">
                        {actions.map((action, index) => (
                            <div key={`${message.id}-action-${index}`} className="rounded-md bg-[#F8FAFC] p-2.5">
                                {action.summary && (
                                    <p className="mb-2 text-xs font-medium text-[var(--ink-600)]">{action.summary}</p>
                                )}
                                <button
                                    type="button"
                                    onClick={() => onAction(action)}
                                    className="flex w-full items-center justify-between gap-2 rounded-md bg-[#155EEF] px-3 py-2 text-xs font-semibold text-white hover:bg-[#004EEB]"
                                >
                                    <span className="flex min-w-0 items-center gap-2">
                                        {action.type === 'draft' ? <ClipboardPen size={14} /> : <ExternalLink size={14} />}
                                        <span className="truncate">{action.label || 'Abrir'}</span>
                                    </span>
                                    <ArrowRight size={14} className="shrink-0" />
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function AiAssistantChat({ tenant }) {
    const [open, setOpen] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [available, setAvailable] = useState(true);
    const [quota, setQuota] = useState(null);
    const [conversationId, setConversationId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [draft, setDraft] = useState('');
    const [loading, setLoading] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const listRef = useRef(null);
    const textareaRef = useRef(null);

    const endpoints = useMemo(() => {
        if (!tenant?.slug) {
            return null;
        }

        return {
            show: route('tenant.assistant.show', tenant.slug),
            send: route('tenant.assistant.messages.store', tenant.slug),
        };
    }, [tenant?.slug]);

    useEffect(() => {
        setLoaded(false);
        setConversationId(null);
        setMessages([]);
        setQuota(null);
        setError('');
    }, [tenant?.id]);

    useEffect(() => {
        if (!open || loaded || !endpoints) {
            return;
        }

        let active = true;
        setLoading(true);

        axios.get(endpoints.show)
            .then(({ data }) => {
                if (!active) return;
                setAvailable(Boolean(data.available));
                setQuota(data.quota || null);
                setConversationId(data.conversation?.id || null);
                setMessages(data.conversation?.messages || []);
                setLoaded(true);
            })
            .catch(() => {
                if (!active) return;
                setError('Não foi possível carregar o assistente.');
            })
            .finally(() => {
                if (active) setLoading(false);
            });

        return () => {
            active = false;
        };
    }, [open, loaded, endpoints]);

    useEffect(() => {
        if (!open) {
            return;
        }

        requestAnimationFrame(() => {
            listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' });
        });
    }, [messages, sending, open]);

    useEffect(() => {
        if (open && loaded && available) {
            textareaRef.current?.focus();
        }
    }, [open, loaded, available]);

    async function sendMessage(event) {
        event?.preventDefault();
        const message = draft.trim();

        if (!message || sending || !available || !endpoints) {
            return;
        }

        const temporaryId = `local-${Date.now()}`;
        setMessages((current) => [...current, { id: temporaryId, role: 'user', content: message, links: [] }]);
        setDraft('');
        setError('');
        setSending(true);

        try {
            const { data } = await axios.post(endpoints.send, {
                conversation_id: conversationId,
                message,
                page_path: window.location.pathname,
                page_title: document.title,
            });

            setConversationId(data.conversation_id);
            setQuota(data.quota || quota);
            setMessages((current) => [
                ...current.filter((item) => item.id !== temporaryId),
                data.user_message,
                data.assistant_message,
            ]);

            const preparedDraft = (data.assistant_message.actions || [])
                .find((action) => action.type === 'draft');

            if (preparedDraft) {
                if (!storeAssistantDraft(tenant.id, preparedDraft)) {
                    setError('Não foi possível preparar este rascunho.');
                    return;
                }

                window.location.assign(preparedDraft.url);
            }
        } catch (requestError) {
            const responseMessage = requestError.response?.data?.message;
            if (requestError.response?.data?.quota) {
                setQuota(requestError.response.data.quota);
            }
            setConversationId(requestError.response?.data?.conversation_id || conversationId);
            setError(responseMessage || 'Não foi possível enviar a mensagem.');
        } finally {
            setSending(false);
        }
    }

    async function startNewConversation() {
        if (sending) {
            return;
        }

        if (conversationId) {
            try {
                await axios.delete(route('tenant.assistant.conversations.destroy', [tenant.slug, conversationId]));
            } catch {
                setError('Não foi possível limpar a conversa.');
                return;
            }
        }

        setConversationId(null);
        setMessages([]);
        setDraft('');
        setError('');
        textareaRef.current?.focus();
    }

    function handleKeyDown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    }

    function handleAction(action) {
        if (!action?.url) {
            return;
        }

        if (action.type === 'draft' && !storeAssistantDraft(tenant.id, action)) {
            setError('Não foi possível preparar este rascunho.');
            return;
        }

        window.location.assign(action.url);
    }

    if (!tenant) {
        return null;
    }

    const quotaAllowed = quota?.allowed !== false;
    const activeQuota = quota?.user?.limit && quota?.tenant?.limit
        ? (quota.user.remaining <= quota.tenant.remaining ? quota.user : quota.tenant)
        : (quota?.user?.limit ? quota.user : quota?.tenant);
    const displayedQuota = quota?.user?.limit ? quota.user : activeQuota;
    const quotaPercentage = Math.min(100, Math.max(0, Number(displayedQuota?.percentage || 0)));
    const quotaTone = quotaPercentage >= 90
        ? 'bg-[#D92D20]'
        : quotaPercentage >= 75
            ? 'bg-[#F79009]'
            : 'bg-[#155EEF]';

    return (
        <>
            {!open && (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="fixed bottom-5 right-5 z-40 grid h-12 w-12 place-items-center rounded-full bg-[#155EEF] text-white shadow-[0_10px_28px_rgba(21,94,239,0.28)] transition hover:bg-[#004EEB] focus:outline-none focus:ring-4 focus:ring-[#D1E0FF]"
                    aria-label="Abrir Assistente Deming"
                    title="Assistente Deming"
                >
                    <MessageCircle size={23} />
                </button>
            )}

            {open && (
                <section
                    className="fixed inset-x-3 bottom-3 z-[70] flex h-[min(680px,calc(100dvh-24px))] flex-col overflow-hidden rounded-lg border border-[var(--border)] bg-[#F8FAFC] shadow-[0_22px_60px_rgba(15,23,42,0.22)] sm:inset-x-auto sm:bottom-5 sm:right-5 sm:h-[min(640px,calc(100dvh-40px))] sm:w-[420px]"
                    aria-label="Assistente Deming"
                >
                    <header className="flex h-16 shrink-0 items-center gap-3 border-b border-[var(--border)] bg-white px-4">
                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-[#EFF4FF] text-[#155EEF]">
                            <Bot size={20} />
                        </span>
                        <div className="min-w-0 flex-1">
                            <h2 className="truncate text-sm font-bold text-[var(--ink-900)]">Assistente Deming</h2>
                            {displayedQuota?.limit ? (
                                <div
                                    className="mt-0.5"
                                    title={`${formatTokens(displayedQuota.used)} de ${formatTokens(displayedQuota.limit)} tokens utilizados em ${quota.period}`}
                                >
                                    <p className="flex items-center gap-1 text-[11px] text-[var(--ink-500)]">
                                        <ShieldCheck size={12} />
                                        {quotaPercentage.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% utilizado em {quota.period}
                                    </p>
                                    <div
                                        className="mt-1 h-1 w-full max-w-[190px] overflow-hidden rounded-full bg-[#E4E7EC]"
                                        role="progressbar"
                                        aria-label="Consumo da cota mensal do assistente"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        aria-valuenow={quotaPercentage}
                                    >
                                        <div className={`h-full rounded-full transition-[width] duration-300 ${quotaTone}`} style={{ width: `${quotaPercentage}%` }} />
                                    </div>
                                </div>
                            ) : (
                                <p className="flex items-center gap-1 text-[11px] text-[var(--ink-500)]">
                                    <ShieldCheck size={12} />
                                    Consulta e prepara rascunhos
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={startNewConversation}
                            className="grid h-8 w-8 place-items-center rounded-md text-[var(--ink-600)] hover:bg-[var(--surface-muted)]"
                            aria-label="Nova conversa"
                            title="Nova conversa"
                        >
                            <Plus size={18} />
                        </button>
                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            className="grid h-8 w-8 place-items-center rounded-md text-[var(--ink-600)] hover:bg-[var(--surface-muted)]"
                            aria-label="Fechar assistente"
                            title="Fechar"
                        >
                            <X size={18} />
                        </button>
                    </header>

                    <div ref={listRef} className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                        {loading && (
                            <div className="grid h-full place-items-center text-[var(--ink-500)]">
                                <LoaderCircle className="animate-spin" size={24} />
                            </div>
                        )}

                        {!loading && messages.length === 0 && (
                            <div className="mx-auto mt-8 max-w-[300px] text-center">
                                <span className="mx-auto mb-3 grid h-11 w-11 place-items-center rounded-md bg-[#EFF4FF] text-[#155EEF]">
                                    <Bot size={23} />
                                </span>
                                <h3 className="text-sm font-bold text-[var(--ink-900)]">Como posso ajudar?</h3>
                                <p className="mt-1 text-xs leading-5 text-[var(--ink-500)]">
                                    Consulte contratos, documentos, atividades, projetos e demais módulos disponíveis para você.
                                </p>
                            </div>
                        )}

                        {messages.map((message) => (
                            <AssistantMessage key={message.id} message={message} onAction={handleAction} />
                        ))}

                        {sending && (
                            <div className="flex justify-start">
                                <div className="flex items-center gap-2 rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-xs text-[var(--ink-500)]">
                                    <LoaderCircle className="animate-spin" size={14} />
                                    Processando...
                                </div>
                            </div>
                        )}
                    </div>

                    {error && (
                        <div className="mx-4 mb-2 rounded-md border border-[#FECDCA] bg-[#FEF3F2] px-3 py-2 text-xs font-medium text-[#B42318]">
                            {error}
                        </div>
                    )}

                    {!loading && !available && (
                        <div className="mx-4 mb-3 rounded-md border border-[#FEDF89] bg-[#FFFAEB] px-3 py-2 text-xs font-medium text-[#93370D]">
                            O assistente ainda não está configurado neste ambiente.
                        </div>
                    )}

                    {!loading && available && !quotaAllowed && (
                        <div className="mx-4 mb-3 rounded-md border border-[#FEDF89] bg-[#FFFAEB] px-3 py-2 text-xs font-medium text-[#93370D]">
                            Cota mensal atingida. O agente volta a ficar disponivel no proximo mes ou apos o ajuste do limite.
                        </div>
                    )}

                    <form onSubmit={sendMessage} className="shrink-0 border-t border-[var(--border)] bg-white p-3">
                        <div className="flex items-end gap-2 rounded-lg border border-[var(--border)] bg-white p-2 focus-within:border-[#84ADFF] focus-within:ring-2 focus-within:ring-[#D1E0FF]">
                            <textarea
                                ref={textareaRef}
                                value={draft}
                                onChange={(event) => setDraft(event.target.value)}
                                onKeyDown={handleKeyDown}
                                rows={1}
                                maxLength={2000}
                                disabled={!available || !quotaAllowed || loading || sending}
                                placeholder="Pergunte sobre o sistema..."
                                className="max-h-28 min-h-[36px] min-w-0 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm text-[var(--ink-900)] outline-none placeholder:text-[var(--ink-400)] focus:border-0 focus:ring-0 disabled:cursor-not-allowed"
                            />
                            <button
                                type="submit"
                                disabled={!draft.trim() || !available || !quotaAllowed || loading || sending}
                                className="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-[#155EEF] text-white hover:bg-[#004EEB] disabled:cursor-not-allowed disabled:bg-[#B2CCFF]"
                                aria-label="Enviar mensagem"
                                title="Enviar"
                            >
                                <Send size={17} />
                            </button>
                        </div>
                    </form>
                </section>
            )}
        </>
    );
}
