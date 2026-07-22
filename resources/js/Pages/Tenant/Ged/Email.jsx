import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GedTour from '@/Components/GedTour';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Edit3,
    HelpCircle,
    KeyRound,
    Mail,
    PlusCircle,
    RefreshCw,
    RotateCw,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';

const tourDemoAccount = {
    id: 'tour-demo-account',
    name: 'Caixa de documentos do contrato',
    host: 'imap.empresa.com.br',
    username: 'documentos@empresa.com.br',
    _tourDemo: true,
};

const tourDemoRule = {
    id: 'tour-demo-rule',
    name: 'Documentos do contrato',
    priority: 1,
    is_active: true,
    processed_messages_count: 12,
    account: tourDemoAccount,
    _tourDemo: true,
};

function FieldError({ children }) {
    if (!children) return null;

    return <span className="mt-1 block text-xs text-rose-600">{children}</span>;
}

function ContractOptions({ contracts }) {
    return (
        <>
            <option value="">Selecione um contrato</option>
            {contracts.map((contract) => (
                <option key={contract.id} value={contract.id}>
                    {contract.code} - {contract.name}
                </option>
            ))}
        </>
    );
}

function TooltipLabel({ children, tip }) {
    return (
        <span className="ged-label inline-flex items-center gap-1.5">
            {children}
            <span className="group relative inline-flex text-[var(--ink-400)]">
                <HelpCircle size={14} aria-hidden="true" />
                <span className="pointer-events-none absolute left-1/2 top-full z-[240] mt-2 hidden w-80 max-w-[min(20rem,calc(100vw-2rem))] -translate-x-1/2 rounded-xl bg-slate-900 px-3 py-2 text-xs font-medium normal-case leading-relaxed tracking-normal text-white shadow-lg group-hover:block">
                    {tip}
                </span>
            </span>
        </span>
    );
}

function ActionButton({ children, tone = 'default', ...props }) {
    const toneClass = {
        default: 'border-slate-300 text-slate-600 hover:bg-slate-50',
        danger: 'border-rose-300 text-rose-600 hover:bg-rose-50',
        green: 'border-emerald-800 text-emerald-800 hover:bg-emerald-50',
    }[tone];

    return (
        <button
            type="button"
            className={`inline-flex min-h-11 items-center justify-center gap-2 border px-4 py-2 text-sm font-medium transition ${toneClass}`}
            {...props}
        >
            {children}
        </button>
    );
}

function Modal({ title, onClose, children, maxWidth = 'max-w-6xl' }) {
    return (
        <div className="fixed inset-0 z-[180] flex items-center justify-center bg-slate-950/50 p-4">
            <div className={`flex max-h-[94vh] w-full ${maxWidth} flex-col overflow-hidden rounded-2xl bg-white shadow-2xl`}>
                <div className="flex items-center justify-between gap-4 border-b border-[var(--border)] px-6 py-5">
                    <h2 className="text-2xl font-bold text-[var(--ink-900)]">{title}</h2>
                    <button type="button" className="rounded-lg p-2 text-[var(--ink-500)] hover:bg-slate-100" onClick={onClose}>
                        <X size={28} />
                    </button>
                </div>

                <div className="overflow-y-auto px-6 py-6">{children}</div>
            </div>
        </div>
    );
}

function AccountModal({ tenant, contracts, account = null, onClose }) {
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);
    const form = useForm({
        contract_id: account?.contract_id ? String(account.contract_id) : (contracts[0]?.id ? String(contracts[0].id) : ''),
        name: account?.name || '',
        host: account?.host || 'imap.gmail.com',
        port: account?.port || 993,
        encryption: account?.encryption || 'ssl',
        username: account?.username || '',
        password: '',
        password_is_token: account?.settings?.password_is_token ?? true,
        charset: account?.settings?.charset || 'UTF-8',
        mailbox: account?.mailbox || 'INBOX',
        post_action: account?.post_action || 'mark_read',
        move_to: account?.move_to || '',
        is_active: account?.is_active ?? true,
    });

    function submit(event = null) {
        event?.preventDefault();

        const payload = (data) => ({
            ...data,
            email: data.username,
            mailbox: 'INBOX',
        });

        const options = {
            preserveScroll: true,
            onSuccess: onClose,
        };

        form.transform(payload);

        if (account?.id) {
            form.patch(route('tenant.ged.email.accounts.update', [tenant.slug, account.id]), options);
            return;
        }

        form.post(route('tenant.ged.email.accounts.store', tenant.slug), options);
    }

    async function testConnection() {
        setTesting(true);
        setTestResult(null);

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch(route('tenant.ged.email.accounts.test', tenant.slug), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                body: JSON.stringify({
                    contract_id: form.data.contract_id,
                    host: form.data.host,
                    port: form.data.port,
                    encryption: form.data.encryption,
                    username: form.data.username,
                    password: form.data.password,
                }),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                const validationMessage = payload?.message || Object.values(payload?.errors || {})?.flat()?.[0];

                setTestResult({
                    ok: false,
                    message: validationMessage || 'Não foi possível testar a conexão.',
                    detail: payload?.detail,
                });

                return;
            }

            setTestResult({
                ok: true,
                message: payload?.message || 'Conexão IMAP testada com sucesso.',
                detail: payload?.detail,
            });
        } catch (error) {
            setTestResult({
                ok: false,
                message: 'Falha ao chamar o teste de conexão.',
                detail: error?.message,
            });
        } finally {
            setTesting(false);
        }
    }

    return (
        <Modal title={account?.id ? 'Editar conta de e-mail' : 'Criar nova conta de e-mail'} onClose={onClose}>
            <form id="ged-email-account-form" onSubmit={submit} className="grid gap-x-6 gap-y-5 lg:grid-cols-2">
                <label className="ged-field lg:col-span-2">
                    <TooltipLabel tip="Contrato onde os documentos importados por esta conta serão cadastrados.">
                        Contrato
                    </TooltipLabel>
                    <select className="ged-control" required value={form.data.contract_id} onChange={(event) => form.setData('contract_id', event.target.value)}>
                        <ContractOptions contracts={contracts} />
                    </select>
                    <FieldError>{form.errors.contract_id}</FieldError>
                </label>

                <label className="ged-field">
                    <span className="ged-label normal-case text-base font-medium tracking-normal text-[var(--ink-700)]">Nome</span>
                    <input className="ged-control" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                    <FieldError>{form.errors.name}</FieldError>
                </label>

                <label className="ged-field">
                    <span className="ged-label normal-case text-base font-medium tracking-normal text-[var(--ink-700)]">Usuário</span>
                    <input className="ged-control" required value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} />
                    <FieldError>{form.errors.username}</FieldError>
                </label>

                <label className="ged-field">
                    <span className="ged-label normal-case text-base font-medium tracking-normal text-[var(--ink-700)]">Senha</span>
                    <input className="ged-control" type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                    <FieldError>{form.errors.password}</FieldError>
                </label>

                <label className="ged-field">
                    <span className="ged-label normal-case text-base font-medium tracking-normal text-[var(--ink-700)]">Servidor IMAP</span>
                    <input className="ged-control" required value={form.data.host} onChange={(event) => form.setData('host', event.target.value)} />
                    <FieldError>{form.errors.host}</FieldError>
                </label>

                <label className="mt-8 flex items-start gap-3 text-base text-[var(--ink-700)]">
                    <input
                        type="checkbox"
                        className="mt-1 rounded border-slate-300"
                        checked={form.data.password_is_token}
                        onChange={(event) => form.setData('password_is_token', event.target.checked)}
                    />
                    <span>
                        A senha é o token
                        <small className="mt-2 block text-sm text-[var(--ink-500)]">
                            Para Gmail, use uma senha de app gerada na conta Google.
                        </small>
                    </span>
                </label>

                <label className="ged-field">
                    <span className="ged-label normal-case text-base font-medium tracking-normal text-[var(--ink-700)]">Porta IMAP</span>
                    <input className="ged-control" required type="number" value={form.data.port} onChange={(event) => form.setData('port', event.target.value)} />
                    <FieldError>{form.errors.port}</FieldError>
                </label>

                <label className="ged-field">
                    <span className="ged-label normal-case text-base font-medium tracking-normal text-[var(--ink-700)]">Conjunto de caracteres</span>
                    <input className="ged-control" value={form.data.charset} onChange={(event) => form.setData('charset', event.target.value)} placeholder="UTF-8" />
                    <FieldError>{form.errors.charset}</FieldError>
                </label>

                <label className="ged-field">
                    <span className="ged-label normal-case text-base font-medium tracking-normal text-[var(--ink-700)]">Segurança IMAP</span>
                    <select className="ged-control" value={form.data.encryption} onChange={(event) => form.setData('encryption', event.target.value)}>
                        <option value="ssl">SSL</option>
                        <option value="tls">TLS</option>
                        <option value="starttls">STARTTLS</option>
                        <option value="none">Nenhuma</option>
                    </select>
                    <FieldError>{form.errors.encryption}</FieldError>
                </label>

                {testResult && (
                    <div className={`rounded-xl border px-4 py-3 text-sm lg:col-span-2 ${
                        testResult.ok
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                            : 'border-rose-200 bg-rose-50 text-rose-800'
                    }`}
                    >
                        <div className="font-semibold">{testResult.message}</div>
                        {testResult.detail && <div className="mt-1 break-words text-xs opacity-80">{testResult.detail}</div>}
                    </div>
                )}

                <div className="-mx-6 -mb-6 mt-2 flex justify-end gap-2 border-t border-[var(--border)] bg-slate-50 px-6 py-4 lg:col-span-2">
                    <button type="button" className="sig-btn sig-btn-secondary border-emerald-800 text-emerald-800 disabled:opacity-60" onClick={testConnection} disabled={testing}>
                        {testing ? 'Testando...' : 'Teste'}
                    </button>
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose}>Cancelar</button>
                    <button type="submit" className="sig-btn bg-emerald-800 text-white hover:bg-emerald-900" disabled={form.processing}>
                        {form.processing ? 'Salvando...' : 'Salvar'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function RuleModal({ tenant, contracts, accounts, types, tags, correspondents, rule = null, onClose, onCreateAccount }) {
    const form = useForm({
        account_id: rule?.account_id ? String(rule.account_id) : (accounts[0]?.id ? String(accounts[0].id) : ''),
        contract_id: rule?.contract_id ? String(rule.contract_id) : (accounts[0]?.contract_id ? String(accounts[0].contract_id) : (contracts[0]?.id ? String(contracts[0].id) : '')),
        name: rule?.name || '',
        mailbox: rule?.mailbox || 'INBOX',
        max_age_days: rule?.max_age_days || '',
        from_contains: rule?.from_contains || '',
        to_contains: rule?.to_contains || '',
        subject_contains: rule?.subject_contains || '',
        body_contains: rule?.body_contains || '',
        attachment_name_contains: rule?.attachment_name_contains || '',
        include_attachment_patterns: rule?.include_attachment_patterns || '',
        exclude_attachment_patterns: rule?.exclude_attachment_patterns || '',
        consume_scope: rule?.consume_scope || 'attachments',
        attachment_type: rule?.attachment_type || 'attachments',
        pdf_layout: rule?.pdf_layout || 'system',
        post_action: rule?.post_action || 'mark_read',
        title_source: rule?.title_source || 'subject',
        assign_owner_from_rule: rule?.assign_owner_from_rule ?? false,
        document_type_id: rule?.document_type_id ? String(rule.document_type_id) : '',
        correspondent_id: rule?.correspondent_id ? String(rule.correspondent_id) : '',
        tag_ids: rule?.tag_ids || [],
        consume_attachments: rule?.consume_attachments ?? true,
        priority: rule?.priority || 10,
        is_active: rule?.is_active ?? true,
    });

    const contractId = Number(form.data.contract_id || 0);
    const filteredTypes = types.filter((type) => Number(type.contract_id) === contractId);
    const filteredTags = tags.filter((tag) => Number(tag.contract_id) === contractId);
    const filteredCorrespondents = correspondents.filter((correspondent) => Number(correspondent.contract_id) === contractId);
    const filteredAccounts = accounts.filter((account) => Number(account.contract_id) === contractId);

    function changeContract(value) {
        const nextAccount = accounts.find((account) => Number(account.contract_id) === Number(value));

        form.setData({
            ...form.data,
            contract_id: value,
            account_id: nextAccount?.id ? String(nextAccount.id) : '',
            document_type_id: '',
            correspondent_id: '',
            tag_ids: [],
        });
    }

    function toggleTag(tagId) {
        const id = Number(tagId);
        const selected = form.data.tag_ids.map(Number);
        form.setData('tag_ids', selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]);
    }

    function submit(event = null) {
        event?.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: onClose,
        };

        if (rule?.id) {
            form.patch(route('tenant.ged.email.rules.update', [tenant.slug, rule.id]), options);
            return;
        }

        form.post(route('tenant.ged.email.rules.store', tenant.slug), options);
    }

    if (accounts.length === 0) {
        return (
            <Modal title="Criar nova regra de e-mail" onClose={onClose} maxWidth="max-w-2xl">
                <div className="space-y-5 text-[var(--ink-700)]">
                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                        <h3 className="text-lg font-bold text-amber-900">Cadastre uma conta de e-mail primeiro</h3>
                        <p className="mt-2 text-sm leading-relaxed text-amber-900">
                            A regra precisa estar vinculada a uma conta IMAP. Depois que a conta for cadastrada, você poderá definir os filtros e ações da regra.
                        </p>
                    </div>

                    <div className="flex justify-end gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose}>Cancelar</button>
                        <button type="button" className="sig-btn bg-emerald-800 text-white hover:bg-emerald-900" onClick={onCreateAccount}>
                            <PlusCircle size={18} />
                            Adicionar Conta
                        </button>
                    </div>
                </div>
            </Modal>
        );
    }

    return (
        <Modal title={rule?.id ? 'Editar regra de e-mail' : 'Criar nova regra de e-mail'} onClose={onClose}>
            <form id="ged-email-rule-form" onSubmit={submit} className="space-y-6">
                <div className="grid items-end gap-4 lg:grid-cols-[1.2fr_0.9fr_0.9fr_auto]">
                    <label className="ged-field">
                        <TooltipLabel tip="Nome interno para identificar a regra. Exemplo: contratos recebidos por e-mail.">
                            Nome
                        </TooltipLabel>
                        <input className="ged-control" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        <FieldError>{form.errors.name}</FieldError>
                    </label>

                    <label className="ged-field">
                        <TooltipLabel tip="Conta IMAP que será consultada quando esta regra for processada.">
                            Conta
                        </TooltipLabel>
                        <select className="ged-control" required value={form.data.account_id} onChange={(event) => form.setData('account_id', event.target.value)}>
                            <option value="">Selecione uma conta</option>
                            {filteredAccounts.map((account) => (
                                <option key={account.id} value={account.id}>{account.name} - {account.email}</option>
                            ))}
                        </select>
                    </label>

                    <label className="ged-field">
                        <TooltipLabel tip="Prioridade de execução. Números menores são processados primeiro.">
                            Ordem
                        </TooltipLabel>
                        <input className="ged-control" type="number" min="1" max="999" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)} />
                    </label>

                    <label className="mb-3 flex items-center gap-2 text-sm font-semibold text-[var(--ink-700)]">
                        <input type="checkbox" checked={!form.data.is_active} onChange={(event) => form.setData('is_active', !event.target.checked)} />
                        Inabilitado
                    </label>
                </div>

                <label className="ged-field">
                    <TooltipLabel tip="Contrato onde os documentos importados por esta regra serão cadastrados.">
                        Contrato
                    </TooltipLabel>
                    <select className="ged-control" required value={form.data.contract_id} onChange={(event) => changeContract(event.target.value)}>
                        <ContractOptions contracts={contracts} />
                    </select>
                </label>

                <div className="border-t border-[var(--border)] pt-5">
                    <p className="mb-4 text-sm text-[var(--ink-600)]">O sistema processará somente e-mails que se encaixem em todos os filtros abaixo.</p>
                    <div className="grid gap-x-6 gap-y-4 lg:grid-cols-2">
                        <label className="ged-field">
                            <TooltipLabel tip="Pasta IMAP a consultar. No Gmail normalmente é INBOX. Subpastas podem variar conforme o provedor.">
                                Pasta
                            </TooltipLabel>
                            <input className="ged-control" value={form.data.mailbox} onChange={(event) => form.setData('mailbox', event.target.value)} placeholder="INBOX" />
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Filtra e-mails pelo remetente. Exemplo: @cliente.com.br.">
                                Filtrar de
                            </TooltipLabel>
                            <input className="ged-control" value={form.data.from_contains} onChange={(event) => form.setData('from_contains', event.target.value)} />
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Ignora e-mails mais antigos do que a quantidade de dias informada. Deixe vazio para não limitar.">
                                Idade máxima (dias)
                            </TooltipLabel>
                            <input className="ged-control" type="number" min="1" value={form.data.max_age_days} onChange={(event) => form.setData('max_age_days', event.target.value)} />
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Filtra e-mails pelo destinatário. Útil para caixas compartilhadas ou aliases.">
                                Filtrar para
                            </TooltipLabel>
                            <input className="ged-control" value={form.data.to_contains} onChange={(event) => form.setData('to_contains', event.target.value)} />
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Filtra e-mails por texto no assunto.">
                                Filtrar assunto
                            </TooltipLabel>
                            <input className="ged-control" value={form.data.subject_contains} onChange={(event) => form.setData('subject_contains', event.target.value)} />
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Filtra e-mails por texto no corpo da mensagem.">
                                Filtrar corpo
                            </TooltipLabel>
                            <input className="ged-control" value={form.data.body_contains} onChange={(event) => form.setData('body_contains', event.target.value)} />
                        </label>
                    </div>
                </div>

                <div className="border-t border-[var(--border)] pt-5">
                    <div className="grid gap-x-6 gap-y-4 lg:grid-cols-2">
                        <label className="ged-field">
                            <TooltipLabel tip="Define se a regra vai importar PDFs anexados ou converter o corpo do e-mail em PDF e vincular os anexos nele.">
                                Escopo de consumo
                            </TooltipLabel>
                            <select className="ged-control" value={form.data.consume_scope} onChange={(event) => form.setData('consume_scope', event.target.value)}>
                                <option value="attachments">Processar somente anexos</option>
                                <option value="everything">Processar e-mail e anexos</option>
                            </select>
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Lista de padrões permitidos para anexos. Use vírgula para separar. Exemplo: *.pdf, *invoice*.">
                                Incluir somente arquivos correspondentes
                            </TooltipLabel>
                            <input className="ged-control" value={form.data.include_attachment_patterns} onChange={(event) => form.setData('include_attachment_patterns', event.target.value)} />
                            <small className="text-xs text-[var(--ink-500)]">Opcional. Aceita curingas como *.pdf.</small>
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Define quais anexos serão importados. O GED mantém os arquivos originais como anexos do documento principal.">
                                Tipo de anexo
                            </TooltipLabel>
                            <select className="ged-control" value={form.data.attachment_type} onChange={(event) => form.setData('attachment_type', event.target.value)}>
                                <option value="attachments">Processar somente anexos</option>
                                <option value="originals">Arquivos originais</option>
                            </select>
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Lista de padrões bloqueados para anexos. Use vírgula para separar. Exemplo: *.png, assinatura*.">
                                Excluir arquivos correspondentes
                            </TooltipLabel>
                            <input className="ged-control" value={form.data.exclude_attachment_patterns} onChange={(event) => form.setData('exclude_attachment_patterns', event.target.value)} />
                            <small className="text-xs text-[var(--ink-500)]">Opcional. Caso um anexo bata aqui, ele não será importado.</small>
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Layout usado quando o e-mail for convertido para PDF.">
                                Layout do PDF
                            </TooltipLabel>
                            <select className="ged-control" value={form.data.pdf_layout} onChange={(event) => form.setData('pdf_layout', event.target.value)}>
                                <option value="system">Padrão do sistema</option>
                                <option value="none">Sem layout</option>
                            </select>
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Filtro simples pelo nome do anexo. Exemplo: medição, contrato ou .pdf.">
                                Nome do anexo contém
                            </TooltipLabel>
                            <input className="ged-control" value={form.data.attachment_name_contains} onChange={(event) => form.setData('attachment_name_contains', event.target.value)} />
                        </label>
                    </div>
                </div>

                <div className="border-t border-[var(--border)] pt-5">
                    <div className="grid gap-x-6 gap-y-4 lg:grid-cols-2">
                        <label className="ged-field">
                            <TooltipLabel tip="O que fazer com o e-mail depois de processar.">
                                Ação
                            </TooltipLabel>
                            <select className="ged-control" value={form.data.post_action} onChange={(event) => form.setData('post_action', event.target.value)}>
                                <option value="mark_read">Marcar como lido, não processar e-mails lidos</option>
                                <option value="none">Não alterar o e-mail</option>
                                <option value="delete">Excluir e-mail após processar</option>
                                <option value="move">Mover e-mail após processar</option>
                            </select>
                            <small className="text-xs text-[var(--ink-500)]">Só é executado se o e-mail for processado.</small>
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Etiquetas aplicadas automaticamente nos documentos importados.">
                                Etiquetas
                            </TooltipLabel>
                            <div className="flex min-h-11 flex-wrap gap-2 rounded-xl border border-[var(--border)] bg-white px-3 py-2">
                                {filteredTags.length === 0 ? (
                                    <span className="text-sm text-[var(--ink-500)]">Nenhuma etiqueta cadastrada.</span>
                                ) : filteredTags.map((tag) => {
                                    const checked = form.data.tag_ids.map(Number).includes(Number(tag.id));

                                    return (
                                        <button
                                            key={tag.id}
                                            type="button"
                                            className={`rounded-full border px-3 py-1 text-xs font-semibold ${checked ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-[var(--border)] bg-white text-[var(--ink-600)]'}`}
                                            onClick={() => toggleTag(tag.id)}
                                        >
                                            <span className="mr-1 inline-block h-2 w-2 rounded-full" style={{ backgroundColor: tag.color }} />
                                            {tag.name}
                                        </button>
                                    );
                                })}
                            </div>
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Define o título inicial do documento criado.">
                                Atribuir título de
                            </TooltipLabel>
                            <select className="ged-control" value={form.data.title_source} onChange={(event) => form.setData('title_source', event.target.value)}>
                                <option value="subject">Usar assunto como título</option>
                                <option value="filename">Usar nome do anexo como título</option>
                            </select>
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Tipo documental atribuído automaticamente ao documento importado.">
                                Atribuir tipo de documento
                            </TooltipLabel>
                            <select className="ged-control" value={form.data.document_type_id} onChange={(event) => form.setData('document_type_id', event.target.value)}>
                                <option value="">Sem tipo</option>
                                {filteredTypes.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                            </select>
                        </label>

                        <label className="flex items-center gap-2 text-sm font-semibold text-[var(--ink-700)]">
                            <input type="checkbox" checked={form.data.assign_owner_from_rule} onChange={(event) => form.setData('assign_owner_from_rule', event.target.checked)} />
                            Atribuir proprietário a partir da regra
                        </label>

                        <label className="ged-field">
                            <TooltipLabel tip="Correspondente/remetente vinculado ao documento criado.">
                                Atribuir correspondente de
                            </TooltipLabel>
                            <select className="ged-control" value={form.data.correspondent_id} onChange={(event) => form.setData('correspondent_id', event.target.value)}>
                                <option value="">Não atribuir um correspondente</option>
                                {filteredCorrespondents.map((correspondent) => <option key={correspondent.id} value={correspondent.id}>{correspondent.name}</option>)}
                            </select>
                        </label>

                        <label className="flex items-center gap-2 text-sm font-semibold text-[var(--ink-700)]">
                            <input type="checkbox" checked={form.data.consume_attachments} onChange={(event) => form.setData('consume_attachments', event.target.checked)} />
                            Processar anexos
                        </label>
                    </div>
                </div>

                <div className="-mx-6 -mb-6 mt-2 flex justify-end gap-2 border-t border-[var(--border)] bg-slate-50 px-6 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose}>Cancelar</button>
                    <button type="submit" className="sig-btn bg-emerald-800 text-white hover:bg-emerald-900" disabled={form.processing}>
                        {form.processing ? 'Salvando...' : 'Salvar'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function PermissionsModal({ item, onClose }) {
    return (
        <Modal title="Permissões da conta de e-mail" onClose={onClose} maxWidth="max-w-2xl">
            <div className="space-y-4 text-[var(--ink-700)]">
                <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-4">
                    <div className="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-800">Conta</div>
                    <div className="mt-1 text-xl font-bold text-[var(--ink-900)]">{item?.name}</div>
                    <div className="text-sm">{item?.username}</div>
                </div>

                <p>
                    O acesso a esta conta de e-mail segue o vínculo do contrato
                    {item?.contract ? ` ${item.contract.code} - ${item.contract.name}` : ''}.
                </p>
                <p>
                    Para alterar quem pode visualizar e operar este recurso, ajuste o vínculo do usuário ao contrato e as permissões do módulo Documentação/GED.
                </p>
                <div className="flex justify-end">
                    <button type="button" className="sig-btn bg-emerald-800 text-white hover:bg-emerald-900" onClick={onClose}>
                        Entendi
                    </button>
                </div>
            </div>
        </Modal>
    );
}

function ProcessedEmailsModal({ rule, onClose }) {
    const [selectedIds, setSelectedIds] = useState([]);
    const rows = rule?.processed_messages || [];
    const allSelected = rows.length > 0 && selectedIds.length === rows.length;

    function toggleAll() {
        setSelectedIds(allSelected ? [] : rows.map((row) => row.id));
    }

    function toggleOne(id) {
        setSelectedIds((current) => current.includes(id) ? current.filter((value) => value !== id) : [...current, id]);
    }

    return (
        <Modal title={`E-mails processados para regra ${rule?.name || ''}`} onClose={onClose}>
            <div className="overflow-hidden rounded-xl border border-[var(--border)]">
                <div className="grid grid-cols-[40px_1.4fr_1fr_1fr_0.6fr_1fr] border-b border-[var(--border)] bg-slate-50 px-4 py-3 text-sm font-semibold text-[var(--ink-800)]">
                    <label className="flex items-center">
                        <input type="checkbox" checked={allSelected} onChange={toggleAll} />
                    </label>
                    <div>Assunto</div>
                    <div>Recebido</div>
                    <div>Processado</div>
                    <div>Estado</div>
                    <div>Erro</div>
                </div>

                {rows.length === 0 ? (
                    <div className="px-4 py-5 text-sm text-[var(--ink-500)]">Nenhum e-mail processado para esta regra.</div>
                ) : rows.map((row) => (
                    <div key={row.id} className="grid grid-cols-[40px_1.4fr_1fr_1fr_0.6fr_1fr] items-center border-b border-[var(--border)] px-4 py-3 text-sm last:border-b-0">
                        <label className="flex items-center">
                            <input type="checkbox" checked={selectedIds.includes(row.id)} onChange={() => toggleOne(row.id)} />
                        </label>
                        <div className="truncate font-medium">{row.subject || 'Sem assunto'}</div>
                        <div>{formatDateTime(row.received_at)}</div>
                        <div>{formatDateTime(row.processed_at)}</div>
                        <div>
                            {row.status === 'success' && <CheckCircle2 size={18} className="text-emerald-700" />}
                            {row.status === 'pending_triage' && <span className="sig-pill sig-pill-amber">Triagem</span>}
                            {row.status !== 'success' && row.status !== 'pending_triage' && <span className="sig-pill sig-pill-red">Erro</span>}
                        </div>
                        <div className="truncate text-xs text-[var(--ink-500)]" title={row.error || ''}>
                            {row.error || '—'}
                        </div>
                    </div>
                ))}
            </div>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                <div className="text-sm text-[var(--ink-500)]">
                    {rows.length} registro(s), {rows.reduce((total, row) => total + (row.imported_count || 0), 0)} anexo(s) importado(s).
                </div>
                <button type="button" className="sig-btn sig-btn-secondary" disabled>
                    Excluir os itens selecionados
                </button>
            </div>
        </Modal>
    );
}

function formatDateTime(value) {
    if (!value) return '—';

    const date = new Date(value.replace(' ', 'T'));

    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

export default function GedEmail({ tenant, contracts = [], accounts = [], rules = [], types = [], tags = [], correspondents = [] }) {
    const { flash = {} } = usePage().props;
    const [showTourDemos, setShowTourDemos] = useState(() => typeof window !== 'undefined'
        && window.sessionStorage.getItem('ged:tour-active') === '1'
        && window.sessionStorage.getItem('ged:tour-section') === 'email');
    const [modal, setModal] = useState(null);
    const visibleAccounts = showTourDemos ? [tourDemoAccount] : accounts;
    const visibleRules = showTourDemos ? [tourDemoRule] : rules;

    function closeModal() {
        setModal(null);
    }

    function destroyAccount(account) {
        if (!window.confirm(`Remover a conta de e-mail "${account.name}"? As regras vinculadas também serão removidas.`)) {
            return;
        }

        router.delete(route('tenant.ged.email.accounts.destroy', [tenant.slug, account.id]), {
            preserveScroll: true,
        });
    }

    function processAccount(account) {
        router.post(route('tenant.ged.email.accounts.process', [tenant.slug, account.id]), {}, {
            preserveScroll: true,
        });
    }

    function destroyRule(rule) {
        if (!window.confirm(`Remover a regra de e-mail "${rule.name}"?`)) {
            return;
        }

        router.delete(route('tenant.ged.email.rules.destroy', [tenant.slug, rule.id]), {
            preserveScroll: true,
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title="E-mail" />

            <div className="mx-auto w-full max-w-[calc(100vw-3rem)] space-y-10 px-4 pb-8 pt-4 sm:max-w-[calc(100vw-4rem)] sm:px-6 lg:px-8">
                {(flash.success || flash.error) && (
                    <div className={`rounded-2xl border px-5 py-4 text-sm font-semibold ${flash.success ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>
                        {flash.success || flash.error}
                    </div>
                )}

                <section data-tour="ged-email-overview">
                    <div className="mb-3 flex flex-wrap items-center gap-4">
                        <h1 className="text-3xl font-bold text-[var(--ink-900)]">Contas de e-mail</h1>
                        <button type="button" className="sig-btn sig-btn-secondary border-emerald-800 text-emerald-800" onClick={() => setModal('account')}>
                            <PlusCircle size={18} />
                            Adicionar Conta
                        </button>
                    </div>

                    <div data-tour="ged-email-accounts" className="overflow-hidden rounded-xl border border-[var(--border)] bg-white">
                        <div className="grid grid-cols-12 border-b border-[var(--border)] px-5 py-4 text-base font-medium text-[var(--ink-900)]">
                            <div className="col-span-3">Nome</div>
                            <div className="col-span-3">Servidor</div>
                            <div className="col-span-3">Usuário</div>
                            <div className="col-span-3">Ações</div>
                        </div>

                        {visibleAccounts.length === 0 ? (
                            <div className="px-5 py-5 text-[var(--ink-600)]">Nenhuma conta de e-mail definida.</div>
                        ) : visibleAccounts.map((account, index) => (
                            <div key={account.id} data-tour={index === 0 ? 'ged-email-account-example' : undefined} className="grid min-h-28 grid-cols-12 items-center border-b border-[var(--border)] px-5 py-4 text-base text-[var(--ink-900)] last:border-b-0">
                                <div className="col-span-3 flex items-center gap-2 text-emerald-800">
                                    <span>{account.name}</span>
                                    <Mail size={20} />
                                </div>
                                <div className="col-span-3">{account.host}</div>
                                <div className="col-span-3">{account.username}</div>
                                <div className="col-span-3 flex flex-wrap items-center gap-3">
                                    <div className="inline-flex overflow-hidden rounded-md border border-slate-400 bg-white">
                                        <ActionButton title="Editar" disabled={account._tourDemo} onClick={() => setModal({ type: 'account', account })}>
                                            <Edit3 size={20} />
                                            Editar
                                        </ActionButton>
                                        <ActionButton title="Permissões" disabled={account._tourDemo} onClick={() => setModal({ type: 'permissions', item: account })}>
                                            <KeyRound size={20} />
                                            Permissões
                                        </ActionButton>
                                        <ActionButton title="Excluir" tone="danger" disabled={account._tourDemo} onClick={() => destroyAccount(account)}>
                                            <Trash2 size={20} />
                                            Excluir
                                        </ActionButton>
                                    </div>
                                    <ActionButton title="Processar e-mail" disabled={account._tourDemo} onClick={() => processAccount(account)}>
                                        <RotateCw size={18} />
                                        Processar E-mail
                                    </ActionButton>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                <section data-tour="ged-email-rules">
                    <div className="mb-3 flex flex-wrap items-center gap-4">
                        <h2 className="text-3xl font-bold text-[var(--ink-900)]">Regras de e-mail</h2>
                        <button type="button" className="sig-btn sig-btn-secondary border-emerald-800 text-emerald-800" onClick={() => setModal('rule')}>
                            <PlusCircle size={18} />
                            Adicionar Regra
                        </button>
                    </div>

                    <div className="overflow-hidden rounded-xl border border-[var(--border)] bg-white">
                        <div className="grid grid-cols-12 border-b border-[var(--border)] px-5 py-4 text-base font-medium text-[var(--ink-900)]">
                            <div className="col-span-2">Nome</div>
                            <div className="col-span-1">Ordem de exibição</div>
                            <div className="col-span-2">Conta</div>
                            <div className="col-span-2">Estado</div>
                            <div className="col-span-2">E-mails processados</div>
                            <div className="col-span-3">Ações</div>
                        </div>

                        {visibleRules.length === 0 ? (
                            <div className="px-5 py-5 text-base text-[var(--ink-900)]">Nenhuma regra de e-mail definida.</div>
                        ) : visibleRules.map((rule, index) => (
                            <div key={rule.id} data-tour={index === 0 ? 'ged-email-rule-example' : undefined} className="grid grid-cols-12 items-center border-b border-[var(--border)] px-5 py-4 text-base last:border-b-0">
                                <div className="col-span-2 font-medium">{rule.name}</div>
                                <div className="col-span-1">{rule.priority}</div>
                                <div className="col-span-2">{rule.account?.name || '—'}</div>
                                <div className="col-span-2">
                                    <span className={rule.is_active ? 'sig-pill sig-pill-green' : 'sig-pill sig-pill-muted'}>
                                        {rule.is_active ? 'Ativa' : 'Inativa'}
                                    </span>
                                </div>
                                <div className="col-span-2">
                                    <button
                                        type="button"
                                        className="sig-btn sig-btn-secondary min-h-9 px-3 py-1 text-xs"
                                        disabled={rule._tourDemo}
                                        onClick={() => setModal({ type: 'processed', rule })}
                                    >
                                        <RefreshCw size={14} />
                                        Ver e-mails processados
                                        {rule.processed_messages_count ? ` (${rule.processed_messages_count})` : ''}
                                    </button>
                                </div>
                                <div className="col-span-3">
                                    <div className="inline-flex overflow-hidden rounded-md border border-slate-400 bg-white">
                                        <ActionButton title="Editar" disabled={rule._tourDemo} onClick={() => setModal({ type: 'rule', rule })}>
                                            <Edit3 size={18} />
                                            Editar
                                        </ActionButton>
                                        <ActionButton title="Excluir" tone="danger" disabled={rule._tourDemo} onClick={() => destroyRule(rule)}>
                                            <Trash2 size={18} />
                                            Excluir
                                        </ActionButton>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

            </div>

            {(modal === 'account' || modal?.type === 'account') && (
                <AccountModal tenant={tenant} contracts={contracts} account={modal?.account} onClose={closeModal} />
            )}
            {(modal === 'rule' || modal?.type === 'rule') && (
                <RuleModal
                    tenant={tenant}
                    contracts={contracts}
                    accounts={accounts}
                    types={types}
                    tags={tags}
                    correspondents={correspondents}
                    rule={modal?.rule}
                    onClose={closeModal}
                    onCreateAccount={() => setModal('account')}
                />
            )}
            {modal?.type === 'permissions' && <PermissionsModal item={modal.item} onClose={closeModal} />}
            {modal?.type === 'processed' && <ProcessedEmailsModal rule={modal.rule} onClose={closeModal} />}
            <GedTour tenant={tenant} section="email" onExit={() => setShowTourDemos(false)} />
        </AuthenticatedLayout>
    );
}
