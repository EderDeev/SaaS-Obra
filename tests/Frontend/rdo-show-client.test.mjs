import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';
import { build } from 'vite';

// Exercise client effects as well as rendering: server rendering alone does not run useEffect.
const output = resolve('tmp/rdo-ui-regression');
assert.ok(output.startsWith(resolve('tmp') + '/'.replace('/', process.platform === 'win32' ? '\\' : '/')));
await build({ logLevel: 'error', build: { ssr: 'resources/js/Pages/Tenant/Rdo/Show.jsx', outDir: output } });
const dom = new JSDOM('<!doctype html><html><head></head><body><div id="app"></div></body></html>', {
    url: 'http://localhost/t/rdo-ui-test/diario-obra/rdo/61', pretendToBeVisual: true,
});
for (const key of ['window', 'document', 'location', 'history', 'navigator', 'HTMLElement', 'Element', 'Node', 'CustomEvent', 'Event', 'MutationObserver', 'DOMParser', 'FormData', 'File', 'FileList', 'Blob', 'XMLHttpRequest', 'sessionStorage', 'localStorage']) {
    Object.defineProperty(globalThis, key, { value: dom.window[key], configurable: true, writable: true });
}
window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
window.scrollTo = () => {};
URL.createObjectURL = () => 'blob:test-evidence';
URL.revokeObjectURL = () => {};
window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
window.HTMLMediaElement.prototype.pause = () => {};
window.HTMLMediaElement.prototype.load = () => {};
globalThis.requestAnimationFrame = window.requestAnimationFrame.bind(window);
globalThis.cancelAnimationFrame = window.cancelAnimationFrame.bind(window);
globalThis.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
globalThis.route = name => name ? `/test/${name}` : ({ current: () => false, has: () => true });
const errors = [];
window.addEventListener('error', event => errors.push(event.error || event.message));
const React = await import('react');
const { createRoot } = await import('react-dom/client');
const { createInertiaApp, router } = await import('@inertiajs/react');
let submitted;
router.post = (url, data, options) => { submitted = { url, data }; options?.onFinish?.(); };
const axios = (await import('axios')).default;
axios.get = async () => ({ data: { unread_count: 0, items: [] } });
const Show = (await import(pathToFileURL(resolve(output, 'Show.js')).href)).default;
const waitFor = async condition => {
    for (let attempt = 0; attempt < 60; attempt++) {
        if (condition()) return;
        if (errors.length) throw errors[0];
        await new Promise(done => setTimeout(done, 20));
    }
    assert.fail('Client state did not settle');
};
const propsFor = editable => ({
    auth: { user: { id: 1, name: 'Rafael Costa' } },
    currentTenant: { slug: 'rdo-ui-test', name: 'Homologação' }, currentTenantRole: 'tenant_owner',
    catalogs: {
        mao_obra: [{ id: 1, descricao: 'Pedreiro', tipo: 'direta', unidade: 'pessoa' }, { id: 2, descricao: 'Encarregado', tipo: 'indireta', unidade: 'pessoa' }],
        equipamentos: [{ id: 1, codigo: 'EQ-01', descricao: 'Escavadeira', unidade: 'hora' }],
        subcontratadas: [{ id: 1, razao_social: 'Terraplanagem Belém' }],
    },
    record_catalogs: { mao_obra: [{ id: 7, descricao: 'Operador histórico', tipo: 'direta' }] },
    rdo: {
        id: 61, code: 'RDO-0061', reference_date: '2026-10-28', reference_date_formatted: '28/10/2026',
        contract: { id: 10, code: 'HML', name: 'Drenagem' }, obra: { id: 42, codigo: '101', nome: 'Frente de drenagem' },
        obras: [{ id: 42, codigo: '101', nome: 'Frente de drenagem' }, { id: 43, codigo: '102', nome: 'Frente de pavimentação' }],
        status: editable ? 'rascunho' : 'arquivado', status_label: editable ? 'Em preenchimento' : 'Concluído',
        workflow: { model: 'construtora_gerenciadora_cliente', review_parties: ['gerenciadora', 'cliente'] },
        fill_party: 'construtora', fill_parties: ['construtora'], participating_parties: ['construtora', 'gerenciadora', 'cliente'],
        can_edit: editable, can_fill: editable, editable_obra_ids: editable ? [42, 43] : [], flow_obra_ids: [], flow_actions: {},
        submission_deadline_formatted: '30/10/2026', sections: {
            42: { clima: { manha: 'ensolarado' }, mao_obra: { efetivos: { 1: '4', 7: '2' }, observacoes: 'Efetivo preservado' }, atividades: { atividades: [{ titulo: 'Assentamento de tubos', ocorrencia: '24 metros executados' }] }, comentarios: { construtora: 'Registro preservado da frente' } },
        },
    },
});

try {
    for (const scenario of ['archived', 'editable', 'review', 'review-blocked', 'manager-final', 'client']) {
        const editable = scenario === 'editable';
        let appRoot;
        const props = propsFor(editable);
        if (editable) props.rdo.activity_subjects = [{value:'engenharia',label:'Engenharia'}, {value:'seguranca_trabalho',label:'Segurança do trabalho'}, {value:'meio_ambiente',label:'Meio ambiente'}];
        if (scenario.startsWith('review')) Object.assign(props.rdo, {
            status: 'em_analise_gerenciadora', status_label: 'Na gerenciadora', active_stage: 'gerenciadora',
            flow_obra_ids: [42, 43], flow_actions: { approve: { label: 'Aprovar', tone: 'success' }, return: { label: 'Rejeitar e devolver', comment_required: true } },
            can_manage_inspection: true, can_create_divergence: true, current_version: 1,
            manager_forward_ready: scenario === 'review',
        });
        if (scenario === 'manager-final') Object.assign(props.rdo, {
            status: 'em_analise_gerenciadora', status_label: 'Na gerenciadora', active_stage: 'gerenciadora',
            workflow: { model: 'gerenciadora_construtora', review_parties: ['gerenciadora'] }, participating_parties: ['construtora','gerenciadora'],
            manager_forward_ready: true, flow_obra_ids: [42,43], current_version: 1,
            flow_actions: { approve: { label:'Aprovar',tone:'success' }, approve_with_reservations: {label:'Aprovar com ressalvas',tone:'warning'}, return: {label:'Devolver',tone:'danger'} },
            can_manage_inspection: true, can_create_divergence: true,
            inspection_records: [{id:1,obra_id:42,is_current_version:true,summary:'Drenagem conferida'},{id:2,obra_id:43,is_current_version:true,summary:'Pavimentação conferida'}],
        });
        if (scenario === 'client') {
            Object.assign(props.rdo, {
                status: 'em_aprovacao_cliente', status_label: 'Aguardando cliente', active_stage: 'cliente',
                flow_obra_ids: [42, 43], flow_actions: { approve: { label: 'Aprovar', tone: 'success' }, approve_with_reservations: { label: 'Aprovar com ressalvas', tone: 'warning' }, return: { label: 'Devolver', tone: 'danger' } },
                inspection_records: [
                    { id: 1, obra_id: 42, is_current_version: true, activities: [{ titulo: 'Inspeção dos tubos', ocorrencia: '24 metros conferidos pela supervisão' }], comment: 'Drenagem conferida', weather: { manha: 'chuvoso', precipitacao_manha_mm: '12.5' }, user: { name: 'Camila Ferreira' }, evidence: [{ name: 'laudo.pdf', mime_type: 'application/pdf', size: 1200, url: '/protected/laudo.pdf' }] },
                    { id: 2, obra_id: 43, is_current_version: true, activities: [{ titulo: 'Inspeção da pavimentação', ocorrencia: 'Base verificada' }], comment: 'Pavimentação conferida', user: { name: 'Camila Ferreira' }, evidence: [] },
                ],
            });
            props.rdo.sections[43] = { atividades: { atividades: [{ titulo: 'Compactação da base', ocorrencia: 'Trecho final compactado' }] } };
        }
        if (scenario === 'archived') props.rdo.signature = { status: 'completed', signed_download_url: '/original-assinado.pdf', signers: [] };
        await createInertiaApp({
            page: { component: 'Tenant/Rdo/Show', props, url: location.pathname, version: 'test' }, resolve: () => Show,
            setup: ({ el, App, props }) => { appRoot = createRoot(el); appRoot.render(React.createElement(App, props)); },
        });
        const panel = mode => document.querySelector(`[data-workspace-mode="${mode}"]`);
        await waitFor(() => panel(editable ? 'edit' : 'view') && document.body.textContent.includes('Registro preservado da frente'));
        // Wait for form initialisation effects, including the API call that previously crashed this page.
        await new Promise(done => setTimeout(done, 150));
        assert.equal(errors.length, 0);
        assert.equal(panel(editable ? 'edit' : 'view').hidden, false);
        assert.ok(![...document.querySelectorAll('button')].some(button => button.textContent.includes('Preencher RDO')));
        assert.ok([...panel(editable ? 'edit' : 'view').querySelectorAll('details')].every(section => !section.open));
        const downloadButton = [...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Baixar PDF');
        const documentActions = document.querySelector('[data-rdo-document-actions]');
        const completeButton = [...document.querySelectorAll('button')].filter(button => button.textContent.trim() === 'Visualizar RDO completo');
        assert.equal(completeButton.length, 1);
        assert.equal(completeButton[0].parentElement, downloadButton.parentElement);
        for (const button of [completeButton[0], downloadButton]) assert.ok(button.className.includes('px-4 py-2.5'));
        assert.equal(documentActions.children[0], completeButton[0]);
        assert.equal(documentActions.children[1], downloadButton);
        downloadButton.click();
        await waitFor(() => document.querySelector('dialog[open] input[type="radio"]'));
        const pdfModal = document.querySelector('dialog[open]');
        const choices = [...pdfModal.querySelectorAll('input[type="radio"]')];
        assert.equal(choices.length, scenario === 'manager-final' ? 3 : 4);
        assert.equal(choices.at(-1).disabled, scenario !== 'archived');
        choices[0].click();
        await waitFor(() => pdfModal.querySelector('a')?.getAttribute('href').includes('stage=construtora'));
        let href = new URL(pdfModal.querySelector('a').href);
        assert.deepEqual(href.searchParams.getAll('obra_ids[]'), ['42','43']);
        assert.equal(href.searchParams.get('viewer'), '1');
        pdfModal.querySelectorAll('input[type="checkbox"]')[1].click();
        await waitFor(() => new URL(pdfModal.querySelector('a').href).searchParams.getAll('obra_ids[]').length === 1);
        pdfModal.querySelectorAll('input[type="checkbox"]')[0].click();
        await waitFor(() => !pdfModal.querySelector('a'));
        assert.ok(pdfModal.textContent.includes('Selecione ao menos uma frente'));
        if (scenario === 'archived') {
            choices.at(-1).click();
            await waitFor(() => pdfModal.querySelector('a')?.getAttribute('href') === '/original-assinado.pdf');
            assert.ok([...pdfModal.querySelectorAll('input[type="checkbox"]')].every(input => input.checked && input.closest('fieldset').disabled));
        }
        pdfModal.querySelector('[aria-label="Fechar opções do PDF"]').click();
        await waitFor(() => !document.querySelector('dialog'));
        if (editable) {
            const holiday = document.querySelector('[aria-label="O dia do RDO foi feriado?"]');
            assert.equal(holiday.value, '');
            holiday.value = '1';
            holiday.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const frontButtons = document.querySelector('[aria-label="Frentes de serviço"]');
        assert.equal(frontButtons.querySelector('button').getAttribute('aria-pressed'), 'true');
        if (!editable) {
            assert.equal(frontButtons.lastElementChild.textContent, 'Todas as frentes');
            assert.equal(panel('view').querySelector('[data-contractor-records]').children.length, 1);
            assert.ok(panel('view').querySelector('[data-contractor-records]').firstElementChild.getAttribute('aria-label').includes('drenagem'));
            frontButtons.querySelectorAll('button')[1].click();
            await waitFor(() => panel('view').querySelector('[data-contractor-records]').firstElementChild.getAttribute('aria-label').includes('pavimentação'));
            frontButtons.lastElementChild.click();
            await waitFor(() => panel('view').querySelector('[data-contractor-records]').children.length === 2);
            frontButtons.querySelector('button').click();
            await waitFor(() => panel('view').querySelector('[data-contractor-records]').children.length === 1);
        }
        if (scenario === 'review') {
            assert.ok(!document.body.textContent.includes('Selecione as frentes desta decisão'));
            assert.ok(![...document.querySelectorAll('button')].some(button => /Aprovar|Rejeitar e devolver/.test(button.textContent)));
            assert.ok(document.body.textContent.includes('Fluxo do RDO'));
            [...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Registrar').click();
            await waitFor(() => document.querySelector('[aria-label="Registro da gerenciadora"]'));
            const modal = document.querySelector('[aria-label="Registro da gerenciadora"]');
            assert.ok(!/Situação do registro|Serviços atestados|Observações da gerenciadora|Orientações da fiscalização/.test(modal.textContent));
            assert.equal(modal.querySelector('[aria-label="Obras do registro da gerenciadora"] button').getAttribute('aria-pressed'), 'true');
            [...modal.querySelectorAll('button')].find(button => button.textContent.includes('Adicionar atividade')).click();
            await waitFor(() => modal.querySelector('input[type="text"]'));
            const edit = async (input, value) => {
                const proto = input.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
                Object.getOwnPropertyDescriptor(proto, 'value').set.call(input, value);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                await new Promise(done => setTimeout(done, 30));
            };
            await edit(modal.querySelector('input[type="text"]'), 'Inspeção da drenagem');
            await edit(modal.querySelector('textarea[placeholder^="Registre a ocorrência"]'), 'Verificação do assentamento dos tubos.');
            const weather = modal.querySelector('details');
            const condition = weather.querySelector('select');
            condition.value = 'chuvoso'; condition.dispatchEvent(new Event('change', { bubbles: true }));
            await new Promise(done => setTimeout(done, 30));
            await edit(weather.querySelector('input[type="number"]'), '12.5');
            await edit(modal.querySelector('[aria-label="Comentário do registro da gerenciadora"]'), 'Frente vistoriada sem interferências.');
            const upload = modal.querySelector('input[type="file"]');
            Object.defineProperty(upload, 'files', { value: [new File(['image'], 'vistoria.png', { type: 'image/png' }), new File(['pdf'], 'laudo.pdf', { type: 'application/pdf' })], configurable: true });
            upload.dispatchEvent(new Event('change', { bubbles: true }));
            await waitFor(() => modal.querySelector('[aria-label="Comentário de vistoria.png"]'));
            await waitFor(() => modal.querySelector('img[alt="vistoria.png"]'));
            const documentComment = modal.querySelector('[aria-label="Comentário de laudo.pdf"]');
            assert.equal(documentComment.tagName, 'INPUT');
            assert.ok(documentComment.parentElement.textContent.includes('PDF ·'));
            assert.ok(!documentComment.parentElement.querySelector('img'));
            await edit(modal.querySelector('[aria-label="Comentário de vistoria.png"]'), 'Tubos conferidos em campo.');
            modal.querySelector('[aria-label="Mover evidência 1 para baixo"]').click();
            await new Promise(done => setTimeout(done, 30));
            modal.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await new Promise(done => setTimeout(done, 40));
            assert.equal(submitted.data.obras[0].atividades[0].titulo, 'Inspeção da drenagem');
            assert.equal(submitted.data.obras[0].atividades[0].ocorrencia, 'Verificação do assentamento dos tubos.');
            assert.equal(submitted.data.obras[0].status, 'concluido');
            assert.equal(submitted.data.obras[0].comentario, 'Frente vistoriada sem interferências.');
            assert.equal(submitted.data.obras[0].clima.manha, 'chuvoso');
            assert.equal(submitted.data.obras[0].clima.precipitacao_manha_mm, '12.5');
            assert.deepEqual(submitted.data.obras[0].evidencias_ordem, ['new:1', 'new:0']);
            assert.equal(submitted.data.obras[0].evidencias_metadados[0].comment, 'Tubos conferidos em campo.');
            const managerFronts = modal.querySelector('[aria-label="Obras do registro da gerenciadora"]');
            managerFronts.querySelectorAll('button')[1].click();
            await waitFor(() => !modal.querySelector('input[type="text"]'));
            [...modal.querySelectorAll('button')].find(button => button.textContent.includes('Adicionar atividade')).click();
            await waitFor(() => modal.querySelector('input[type="text"]'));
            await edit(modal.querySelector('input[type="text"]'), 'Vistoria da pavimentação');
            await edit(modal.querySelector('textarea[placeholder^="Registre a ocorrência"]'), 'Base conferida na segunda obra.');
            managerFronts.querySelectorAll('button')[0].click();
            await waitFor(() => modal.querySelector('input[type="text"]')?.value === 'Inspeção da drenagem');
            assert.equal(modal.querySelector('[aria-label="Comentário de vistoria.png"]').value, 'Tubos conferidos em campo.');
            modal.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await new Promise(done => setTimeout(done, 40));
            assert.equal(submitted.data.obras.length, 2);
            assert.equal(submitted.data.obras.find(obra => obra.obra_id === 43).atividades[0].titulo, 'Vistoria da pavimentação');
            assert.equal(submitted.data.obras.find(obra => obra.obra_id === 42).evidencias[0].name, 'vistoria.png');
            modal.querySelector('[aria-label="Fechar registro"]').click();
            await waitFor(() => !document.querySelector('[aria-label="Registro da gerenciadora"]'));
            console.log('PASS: modal da gerenciadora: obra, atividades, miniatura, comentário e ordem de evidências');
            assert.ok(!document.body.textContent.includes('Nova divergência'));
            [...document.querySelectorAll('button')].find(button => button.textContent.includes('Devolver para a construtora')).click();
            await waitFor(() => document.querySelector('[aria-label="Devolver RDO para a construtora"]'));
            const returnModal = document.querySelector('[aria-label="Devolver RDO para a construtora"]');
            await edit(returnModal.querySelector('input'), 'Serviço incompleto');
            await edit(returnModal.querySelectorAll('textarea')[0], 'Serviço informado como concluído.');
            await edit(returnModal.querySelectorAll('textarea')[1], 'Falta executar o trecho final.');
            returnModal.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await new Promise(done => setTimeout(done, 40));
            assert.equal(submitted.data.action, 'return');
            assert.equal(submitted.data.divergencia.titulo, 'Serviço incompleto');
            assert.equal(submitted.data.comments_by_obra[42], 'Falta executar o trecho final.');
            assert.deepEqual(submitted.data.obra_ids, [42, 43]);
            assert.equal(submitted.data.divergencia.obra_id, 42);
            assert.equal(submitted.data.comments_by_obra[43], 'Falta executar o trecho final.');
            returnModal.querySelector('[aria-label="Fechar decisão"]').click();
            await waitFor(() => !document.querySelector('[aria-label="Devolver RDO para a construtora"]'));
            [...document.querySelectorAll('button')].find(button => button.textContent.includes('Encaminhar para aprovação do cliente')).click();
            await waitFor(() => document.querySelector('[aria-label="Encaminhar RDO ao cliente"]'));
            const approveModal = document.querySelector('[aria-label="Encaminhar RDO ao cliente"]');
            assert.ok(!approveModal.textContent.includes('Título da divergência'));
            await edit(approveModal.querySelector('textarea'), 'Serviços verificados e aprovados.');
            await edit(approveModal.querySelectorAll('textarea')[1], 'Pavimentação conferida.');
            assert.equal(approveModal.querySelector('[aria-label="Obras desta decisão"]').querySelectorAll('button').length, 0);
            approveModal.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await new Promise(done => setTimeout(done, 40));
            assert.equal(submitted.data.action, 'approve');
            assert.equal(submitted.data.divergencia, undefined);
            assert.equal(submitted.data.comments_by_obra[42], 'Serviços verificados e aprovados.');
            assert.equal(submitted.data.comments_by_obra[43], 'Pavimentação conferida.');
            assert.deepEqual(submitted.data.obra_ids, [42, 43]);
            approveModal.querySelector('[aria-label="Fechar decisão"]').click();
            await waitFor(() => !document.querySelector('[aria-label="Encaminhar RDO ao cliente"]'));
            console.log('PASS: encaminhamento ao cliente e devolução com divergência em modais separados');
        }
        if (scenario === 'review-blocked') {
            const forward = [...document.querySelectorAll('button')].find(button => button.textContent.includes('Encaminhar para aprovação do cliente'));
            assert.equal(forward.disabled, true);
            assert.ok(document.body.textContent.includes('Conclua os registros da gerenciadora em todas as obras'));
        }
        if (scenario === 'manager-final') {
            assert.ok(!document.body.textContent.includes('Encaminhar para aprovação do cliente'));
            [...document.querySelectorAll('button')].find(button => button.textContent.includes('Aprovar RDO')).click();
            await waitFor(() => document.querySelector('[aria-label="Aprovação final da gerenciadora"]'));
            const approval = document.querySelector('[aria-label="Aprovação final da gerenciadora"]');
            assert.equal(approval.querySelectorAll('input[type="radio"]').length,4);
            approval.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
            await new Promise(done=>setTimeout(done,40));
            assert.equal(submitted.data.action,'approve');
            assert.deepEqual(submitted.data.obra_ids,[42,43]);
            approval.querySelector('input[type="radio"][value="true"]').click();
            await waitFor(()=>approval.querySelector('details'));
            const details=approval.querySelector('details');
            Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype,'value').set.call(details.querySelector('textarea'),'Corrigir acabamento');
            details.querySelector('textarea').dispatchEvent(new Event('input',{bubbles:true}));
            await new Promise(done=>setTimeout(done,30));
            details.querySelector('select').value='Construtora';details.querySelector('select').dispatchEvent(new Event('change',{bubbles:true}));
            await new Promise(done=>setTimeout(done,30));
            Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set.call(details.querySelector('input[type="date"]'),'2026-11-02');
            details.querySelector('input[type="date"]').dispatchEvent(new Event('input',{bubbles:true}));
            await new Promise(done=>setTimeout(done,30));
            approval.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
            await new Promise(done=>setTimeout(done,40));
            assert.equal(submitted.data.action,'approve_with_reservations');
            assert.deepEqual(submitted.data.reservation_obra_ids,[42]);
            assert.equal(submitted.data.reservations_by_obra[42].responsible,'Construtora');
            approval.querySelector('[aria-label="Fechar decisão"]').click();
            await waitFor(()=>!document.querySelector('[aria-label="Aprovação final da gerenciadora"]'));
            console.log('PASS: gerenciadora aprova e indica ressalvas por obra sem etapa de cliente');
        }
        if (scenario === 'client') {
            const client = document.querySelector('[aria-label="Manifestação do cliente"]');
            const workflow = [...document.querySelectorAll('h2')].find(heading => heading.textContent === 'Fluxo do RDO').closest('section');
            assert.ok(client.compareDocumentPosition(workflow) & Node.DOCUMENT_POSITION_FOLLOWING);
            assert.equal(workflow.querySelectorAll('textarea').length, 0);
            assert.equal(workflow.querySelectorAll('button').length, 0);
            const setText = async (input, value) => {
                Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set.call(input, value);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                await new Promise(done => setTimeout(done, 30));
            };
            assert.equal(client.querySelectorAll('textarea').length, 0);
            assert.ok(!client.textContent.includes('Confira os registros e preencha'));
            [...client.querySelectorAll('button')].find(button => button.textContent.includes('Comentários e ressalvas')).click();
            await waitFor(() => document.querySelector('[aria-label="Comentários e decisão do cliente"][open]'));
            const draft = document.querySelector('[aria-label="Comentários e decisão do cliente"]');
            assert.equal(draft.querySelectorAll('details').length, 0);
            assert.equal(draft.querySelector('input[type="radio"][value="false"]').checked, true);
            draft.querySelector('input[type="radio"][value="true"]').click();
            await waitFor(() => draft.querySelectorAll('details').length === 1);
            assert.equal(draft.querySelectorAll('input[type="radio"][value="false"]')[1].checked, true);
            draft.querySelector('input[type="radio"][value="false"]').click();
            await waitFor(() => draft.querySelectorAll('details').length === 0);
            await setText(draft.querySelector('textarea'), 'Drenagem aprovada pelo cliente');
            await setText([...draft.querySelectorAll('textarea')].find(input => input.getAttribute('aria-label')?.includes('102')), 'Pavimentação aprovada pelo cliente');
            draft.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await waitFor(() => !document.querySelector('[aria-label="Comentários e decisão do cliente"]'));
            [...client.querySelectorAll('button')].find(button => button.textContent.includes('Comparar registros')).click();
            await waitFor(() => document.querySelector('dialog[open]'));
            const compare = document.querySelector('dialog');
            assert.ok(compare.querySelector('[aria-label="Comparação: Construtora"]').textContent.includes('Assentamento de tubos'));
            assert.ok(compare.querySelector('[aria-label="Comparação: Gerenciadora"]').textContent.includes('24 metros conferidos pela supervisão'));
            [...compare.querySelector('[aria-label="Registro em comparação"]').querySelectorAll('button')].find(button => button.textContent === 'Condições do tempo').click();
            await waitFor(() => compare.querySelector('[aria-label="Comparação: Gerenciadora"]').textContent.includes('Chuvoso'));
            assert.ok(compare.querySelector('[aria-label="Comparação: Construtora"]').textContent.includes('Ensolarado'));
            assert.ok(compare.querySelector('[aria-label="Comparação: Gerenciadora"]').textContent.includes('12.5'));
            compare.querySelector('[aria-label="Registro em comparação"]').querySelectorAll('button')[2].click();
            await waitFor(() => compare.textContent.includes('laudo.pdf'));
            compare.querySelector('[aria-label="Obra em comparação"]').querySelectorAll('button')[1].click();
            await waitFor(() => compare.textContent.includes('Nenhuma evidência da gerenciadora nesta obra.'));
            compare.querySelector('[aria-label="Registro em comparação"]').querySelectorAll('button')[0].click();
            await waitFor(() => compare.textContent.includes('Compactação da base'));
            assert.ok(compare.textContent.includes('Inspeção da pavimentação'));
            assert.ok(!compare.textContent.includes('Assentamento de tubos'));
            compare.querySelector('[aria-label="Fechar comparação"]').click();
            await waitFor(() => !document.querySelector('dialog'));
            [...client.querySelectorAll('button')].find(button => button.textContent.trim() === 'Aprovar').click();
            await waitFor(() => document.querySelector('[aria-label="Comentários e decisão do cliente"][open]'));
            const decision = document.querySelector('[aria-label="Comentários e decisão do cliente"]');
            assert.equal(decision.querySelector('textarea').value, 'Drenagem aprovada pelo cliente');
            submitted = null;
            decision.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await waitFor(() => document.querySelector('[data-rdo-flow-confirmation]'));
            assert.equal(submitted, null);
            assert.equal(document.querySelector('[data-confirm-rdo-action]').textContent, 'Confirmar aprovação');
            [...document.querySelector('[data-rdo-flow-confirmation]').querySelectorAll('button')].find(button => button.hasAttribute('data-confirm-rdo-action')).click();
            await waitFor(() => submitted);
            assert.deepEqual(submitted.data.obra_ids, [42, 43]);
            assert.equal(submitted.data.comments_by_obra[42], 'Drenagem aprovada pelo cliente');
            assert.equal(submitted.data.comments_by_obra[43], 'Pavimentação aprovada pelo cliente');
            document.querySelector('[data-rdo-flow-confirmation] button').click();
            await waitFor(() => !document.querySelector('[data-rdo-flow-confirmation]'));
            [...client.querySelectorAll('button')].find(button => button.textContent.trim() === 'Devolver').click();
            await waitFor(() => document.querySelector('[aria-label="Destino da devolução"]'));
            const target = document.querySelector('[aria-label="Destino da devolução"]');
            assert.equal(target.required, true);
            target.value = 'gerenciadora'; target.dispatchEvent(new Event('change', { bubbles: true }));
            await setText(document.querySelector('[aria-label="Justificativa da devolução"]'), 'Revisar a conferência técnica.');
            assert.equal(document.querySelector('[aria-label="Justificativa da devolução"]').required, true);
            document.querySelector('[aria-label="Comentários e decisão do cliente"] form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await waitFor(() => document.querySelector('[data-rdo-flow-confirmation]'));
            [...document.querySelector('[data-rdo-flow-confirmation]').querySelectorAll('button')].find(button => button.hasAttribute('data-confirm-rdo-action')).click();
            await waitFor(() => submitted?.data.return_to === 'gerenciadora');
            assert.equal(document.querySelector('[data-confirm-rdo-action]').textContent, 'Confirmar devolução');
            assert.equal(submitted.data.action, 'return');
            assert.equal(submitted.data.comments_by_obra[43], 'Revisar a conferência técnica.');
            assert.deepEqual(submitted.data.obra_ids, [42, 43]);
            document.querySelector('[data-rdo-flow-confirmation] button').click();
            await waitFor(() => !document.querySelector('[data-rdo-flow-confirmation]'));
            console.log('PASS: comparação, comentário em modal, confirmação e destino obrigatório na devolução');
        }
        if (editable) {
            assert.ok(!/camada da gerenciadora|rastreabilidade/i.test(document.body.textContent));
            const labor = panel('edit').querySelector('[aria-label="Mão de obra e subcontratadas"]');
            const equipment = panel('edit').querySelector('[aria-label="Equipamentos"]');
            assert.equal(labor.querySelector('[aria-label="Quantidade de Pedreiro"]').value, '4');
            assert.equal(labor.querySelector('[aria-label="Quantidade de Operador histórico"]').value, '2');
            const search = labor.querySelector('input[type="search"]');
            const searchFor = async text => {
                Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(search, text);
                search.dispatchEvent(new Event('input', { bubbles: true }));
                await new Promise(done => setTimeout(done, 40));
            };
            await searchFor('histórico');
            assert.ok(labor.querySelector('[aria-label="Quantidade de Operador histórico"]'));
            assert.ok(!labor.querySelector('[aria-label="Quantidade de Pedreiro"]'));
            await searchFor('');
            assert.equal(labor.querySelector('[aria-label="Quantidade de Pedreiro"]').value, '4');
            const tick = (picker, name) => [...picker.querySelectorAll('input[type="checkbox"]')].find(input => input.closest('label').textContent.includes(name));
            tick(labor, 'Encarregado').click();
            await waitFor(() => labor.querySelector('[aria-label="Quantidade de Encarregado"]'));
            tick(labor, 'Terraplanagem Belém').click();
            await waitFor(() => labor.querySelector('[aria-label="Quantidade de Terraplanagem Belém"]'));
            tick(equipment, 'Escavadeira').click();
            await waitFor(() => equipment.querySelector('[aria-label="Quantidade de Escavadeira"]'));
            const quantity = equipment.querySelector('[aria-label="Quantidade de Escavadeira"]');
            Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(quantity, '7.5');
            quantity.dispatchEvent(new Event('input', { bubbles: true }));
            await new Promise(done => setTimeout(done, 40));
            const situation = equipment.querySelector('select');
            situation.value = 'manutencao';
            situation.dispatchEvent(new Event('change', { bubbles: true }));
            await new Promise(done => setTimeout(done, 40));
            panel('edit').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await waitFor(() => submitted);
            assert.equal(submitted.data.obras[0].secoes.mao_obra.efetivos[1], '4');
            assert.equal(submitted.data.obras[0].secoes.mao_obra.efetivos[2], '1');
            assert.equal(submitted.data.obras[0].secoes.mao_obra.efetivos[7], '2');
            assert.equal(submitted.data.obras[0].secoes.mao_obra.subcontratadas[1], '1');
            assert.equal(submitted.data.obras[0].secoes.mao_obra.observacoes, 'Efetivo preservado');
            assert.equal(submitted.data.obras[0].secoes.equipamentos.registros[1].quantidade, '7.5');
            assert.equal(submitted.data.obras[0].secoes.equipamentos.registros[1].situacao, 'manutencao');
            tick(labor, 'Encarregado').click();
            await waitFor(() => !labor.querySelector('[aria-label="Quantidade de Encarregado"]'));
            panel('edit').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await new Promise(done => setTimeout(done, 40));
            assert.equal(submitted.data.obras[0].secoes.mao_obra.efetivos[2], undefined);
            const upload = panel('edit').querySelector('input[type="file"]');
            Object.defineProperty(upload, 'files', { value: [new File(['image'], 'frente-a.jpg', { type: 'image/jpeg' })], configurable: true });
            upload.dispatchEvent(new Event('change', { bubbles: true }));
            await new Promise(done => setTimeout(done, 40));
            const workButtons = document.querySelector('[aria-label="Frentes de serviço"]');
            const subject = panel('edit').querySelector('[aria-label="Assunto da atividade 1"]');
            assert.equal(subject.value, 'engenharia');
            subject.value = 'meio_ambiente';
            subject.dispatchEvent(new Event('change', { bubbles: true }));
            await new Promise(done => setTimeout(done, 40));
            workButtons.querySelectorAll('button')[1].click();
            await waitFor(() => !panel('edit').querySelector('input[placeholder="Ex.: Concretagem da base"]'));
            [...panel('edit').querySelectorAll('button')].find(button => button.textContent.includes('Adicionar atividade')).click();
            await waitFor(() => panel('edit').querySelector('input[placeholder="Ex.: Concretagem da base"]'));
            const title = panel('edit').querySelector('input[placeholder="Ex.: Concretagem da base"]');
            Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(title, 'Pavimentação do dia');
            title.dispatchEvent(new Event('input', { bubbles: true }));
            await new Promise(done => setTimeout(done, 40));
            workButtons.querySelectorAll('button')[0].click();
            await waitFor(() => panel('edit').querySelector('input[placeholder="Ex.: Concretagem da base"]')?.value === 'Assentamento de tubos');
            assert.ok(panel('edit').querySelector('img[src="blob:test-evidence"]'));
            panel('edit').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            await new Promise(done => setTimeout(done, 40));
            assert.equal(submitted.data.obras.length, 2);
            assert.equal(submitted.data.obras.find(obra => obra.obra_id === 42).fotos[0].name, 'frente-a.jpg');
            assert.equal(submitted.data.is_holiday, true);
            assert.equal(submitted.data.obras.find(obra => obra.obra_id === 42).secoes.atividades.atividades[0].assunto, 'meio_ambiente');
            assert.equal(submitted.data.obras.find(obra => obra.obra_id === 43).secoes.atividades.atividades[0].titulo, 'Pavimentação do dia');
            console.log('PASS: seleção, quantidade, situação, desmarcar e envio dos cadastros preservam os registros existentes');
        }
        const viewButton = [...document.querySelectorAll('button')].find(button => button.textContent.includes('Visualizar RDO completo'));
        assert.ok(viewButton);
        viewButton.click();
        await waitFor(() => document.querySelector('dialog[open]'));
        const dialog = document.querySelector('dialog');
        assert.equal(dialog.getAttribute('aria-labelledby')?.length > 0, true);
        assert.ok(dialog.textContent.includes('24 metros executados'));
        assert.ok(dialog.textContent.includes('Frente de pavimentação'));
        assert.ok(!dialog.textContent.includes('Histórico de envios'));
        assert.ok(dialog.querySelector('[aria-label="Identificação do RDO"]'));
        assert.ok(dialog.querySelector('[aria-label="Etapas do RDO"]'));
        assert.equal(dialog.querySelector('[data-rdo-complete-front]').getAttribute('data-rdo-complete-front'), '42');
        const completeFronts = dialog.querySelector('[aria-label="Obras na visualização completa"]');
        assert.equal(completeFronts.lastElementChild.textContent, 'Todas as frentes');
        assert.equal(completeFronts.firstElementChild.getAttribute('aria-pressed'), 'true');
        assert.equal(dialog.querySelector('[data-rdo-complete-party="construtora"]').getAttribute('data-rdo-party'), 'construtora');
        assert.equal(dialog.querySelector('[data-rdo-complete-party="gerenciadora"]').getAttribute('data-rdo-party'), 'gerenciadora');
        assert.equal(dialog.querySelectorAll('[data-rdo-complete-document] details').length, 0);
        completeFronts.children[1].click();
        await waitFor(() => dialog.querySelector('[data-rdo-complete-front]')?.getAttribute('data-rdo-complete-front') === '43');
        assert.ok(!dialog.querySelector('[data-rdo-complete-document]').textContent.includes('24 metros executados'));
        completeFronts.lastElementChild.click();
        await waitFor(() => dialog.querySelectorAll('[data-rdo-complete-front]').length === 2);
        completeFronts.firstElementChild.click();
        await waitFor(() => dialog.querySelectorAll('[data-rdo-complete-front]').length === 1);
        if (scenario === 'client' || scenario === 'manager-final') {
            assert.ok(dialog.querySelector('[data-rdo-complete-party="gerenciadora"] [data-rdo-complete-inspection]'));
        }

        assert.equal(document.body.style.overflow, 'hidden');
        assert.equal(panel(editable ? 'edit' : 'view').hidden, false);
        [...dialog.querySelectorAll('button')].find(button => button.textContent.trim() === 'Baixar PDF').click();
        await waitFor(() => document.querySelectorAll('dialog[open]').length === 2);
        const pdfFromComplete = document.querySelector('[aria-label="Fechar opções do PDF"]');
        pdfFromComplete.click();
        await waitFor(() => document.querySelectorAll('dialog[open]').length === 1);
        dialog.querySelector('[aria-label="Fechar RDO completo"]').click();
        await waitFor(() => !document.querySelector('dialog'));
        assert.equal(document.body.style.overflow, '');
        assert.equal(panel(editable ? 'edit' : 'view').hidden, false);
        viewButton.click();
        await waitFor(() => document.querySelector('dialog[open]'));
        document.querySelector('dialog').dispatchEvent(new Event('cancel', { cancelable: true }));
        await waitFor(() => !document.querySelector('dialog'));
        appRoot.unmount();
        console.log(`PASS: RDO ${scenario}: seleção de frentes, modal completo, fechar e cancelar`);
    }
    const calendarOutput = resolve('tmp/rdo-calendar-ui-regression');
    await build({ logLevel: 'error', build: { ssr: 'resources/js/Pages/Tenant/Rdo/Calendar.jsx', outDir: calendarOutput } });
    const Calendar = (await import(pathToFileURL(resolve(calendarOutput, 'Calendar.js')).href)).default;
    const preferenceKey = 'sigworks:rdo:view:rdo-ui-test:1';
    localStorage.setItem(preferenceKey, 'list');
    let calendarRoot, filtered;
    router.get = (url, data) => { filtered = data; };
    const calendarProps = {
        ...propsFor(false), contracts: [{ id: 10, code: 'HML', name: 'Contrato de drenagem' }], obras: [],
        filters: { contract_id: 10, month: '2026-10', date_from: '2026-10-01', date_to: '2026-10-31' }, configuration: null,
        rdos: [
            { id: 1, code: 'RDO-001', reference_date: '2026-10-28', reference_date_formatted: '28/10/2026', status: 'em_analise_gerenciadora', status_label: 'Na gerenciadora', show_url: '/rdo/1', has_reservations: true },
            { id: 2, code: 'RDO-002', reference_date: '2026-10-27', reference_date_formatted: '27/10/2026', status: 'rascunho', status_label: 'Em preenchimento', show_url: '/rdo/2', can_fill: true },
        ],
    };
    await createInertiaApp({ page: { component: 'Tenant/Rdo/Calendar', props: calendarProps, url: location.pathname, version: 'test' }, resolve: () => Calendar,
        setup: ({ el, App, props }) => { calendarRoot = createRoot(el); calendarRoot.render(React.createElement(App, props)); },
    });
    await waitFor(() => document.querySelector('[aria-label="RDOs em lista"]'));
    const list = document.querySelector('[aria-label="RDOs em lista"]');
    assert.equal(list.querySelectorAll('article').length, 2);
    assert.ok(list.querySelector('article').textContent.includes('RDO-001'));
    assert.equal(list.querySelector('article').dataset.rdoStage, 'gerenciadora');
    assert.equal(list.querySelectorAll('article')[1].dataset.rdoStage, 'construtora');
    assert.ok(list.querySelector('article').textContent.includes('Com ressalvas'));
    assert.ok(list.querySelector('article a').textContent.includes('Analisar'));
    assert.ok(list.querySelectorAll('article')[1].querySelector('a').textContent.includes('Preencher'));
    const toggle = document.querySelector('[aria-label="Visualização dos RDOs"]');
    toggle.querySelectorAll('button')[0].click();
    await waitFor(() => document.querySelector('.rdo-calendar'));
    assert.ok(document.querySelector('.rdo-calendar [data-rdo-stage="gerenciadora"]'));
    assert.ok(document.querySelector('.rdo-calendar [data-rdo-stage="construtora"]'));
    assert.equal(localStorage.getItem(preferenceKey), 'calendar');
    toggle.querySelectorAll('button')[1].click();
    await waitFor(() => document.querySelector('[aria-label="RDOs em lista"]'));
    assert.equal(localStorage.getItem(preferenceKey), 'list');
    const dateFrom = document.querySelector('input[type="date"]');
    Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(dateFrom, '2026-10-05');
    dateFrom.dispatchEvent(new Event('change', { bubbles: true }));
    dateFrom.dispatchEvent(new Event('input', { bubbles: true }));
    await waitFor(() => filtered);
    assert.equal(filtered.date_from, '2026-10-05');
    assert.equal(filtered.contract_id, 10);
    calendarRoot.unmount();
    localStorage.removeItem(preferenceKey);
    console.log('PASS: calendário/lista, preferência por usuário, ordem, ressalvas, ações e filtros preservados');
    const { rdoCalendarStage } = await import(pathToFileURL(resolve('resources/js/Pages/Tenant/Rdo/calendarStageAppearance.js')).href);
    assert.equal(rdoCalendarStage({ status: 'rascunho', fill_party: 'gerenciadora' }), 'gerenciadora');
    assert.equal(rdoCalendarStage({ status: 'devolvido_construtora', fill_party: 'gerenciadora' }), 'gerenciadora');
    assert.equal(rdoCalendarStage({ status: 'em_aprovacao_cliente' }), 'cliente');
    assert.equal(rdoCalendarStage({ status: 'arquivado', signature_status: 'waiting' }), 'assinatura');
    assert.equal(errors.length, 0);
} finally {
    dom.window.close();
}
