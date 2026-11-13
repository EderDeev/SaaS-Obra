import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';
import { build } from 'vite';

const output = resolve('tmp/os-delivery-ui');
await build({ logLevel: 'error', build: { ssr: 'resources/js/Components/OsDnitDelivery.jsx', outDir: output } });
const dom = new JSDOM('<div id="app"></div>', { url: 'http://localhost', pretendToBeVisual: true });
for (const key of ['window', 'document', 'location', 'history', 'navigator', 'HTMLElement', 'Element', 'Node', 'Event', 'MouseEvent', 'FormData', 'File', 'Blob']) {
    Object.defineProperty(globalThis, key, { value: dom.window[key], configurable: true });
}
window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
globalThis.route = (name) => `/test/${name}`;
const React = await import('react');
const { createRoot } = await import('react-dom/client');
const { router } = await import('@inertiajs/react');
let submitted;
router.post = (url, data, options) => { submitted = { url, data, options }; options.onFinish?.(); };
const module = await import(pathToFileURL(resolve(output, 'OsDnitDelivery.js')).href);
const Component = module.default;
const root = createRoot(document.getElementById('app'));
const wait = async (predicate) => {
    for (let n = 0; n < 100; n++) { if (predicate()) return; await new Promise((done) => setTimeout(done, 10)); }
    assert.fail('Interface não estabilizou.');
};
const click = (label) => {
    const button = [...document.querySelectorAll('button')].find((button) => button.textContent === label);
    assert.ok(button, `Botão ${label}`); button.click();
};
const delivery = { id: 30, revision: 1, original_name: 'memorial.pdf', comment: 'Memória de cálculo e desenhos revisados.', creator: { name: 'Camila Ferreira' }, created_at: '2026-11-11T12:00:00Z' };
const render = async (status, capabilities, deliveries, key) => {
    root.render(React.createElement(Component, { key, order: { id: 1, request_number: '001/2026', workflow_version: 9, status, deliveries }, capabilities, links: { 30: '/private/30' }, tenantSlug: 'test' }));
    await wait(() => document.querySelector('section')?.textContent.includes(status === 'approved' ? 'Aguardando entrega da Supervisão' : status === 'delivery_revision' ? 'Revisão 2 solicitada' : 'Aguardando avaliação do analista DNIT solicitante'));
};
await render('approved', { deliver: true }, [], 'initial');
click('Entregar produto'); await wait(() => document.querySelector('dialog[open]'));
assert.equal(document.querySelector('input[type=file]').required, true);
assert.equal(document.querySelectorAll('textarea').length, 1);
assert.equal(document.querySelector('textarea').required, true);
assert.doesNotMatch(document.querySelector('dialog').textContent, /Entrega inicial/);
assert.match(document.querySelector('dialog').textContent, /30 MB/);
click('Cancelar'); await wait(() => !document.querySelector('dialog[open]'));

await render('delivery_revision', { deliver: true }, [{ ...delivery, decision: 'reject', review_comment: 'Corrigir dimensionamento hidráulico.' }], 'revision');
click('Enviar revisão'); await wait(() => document.querySelector('dialog[open]'));
assert.match(document.querySelector('dialog').textContent, /Revisão 2/);
assert.match(document.querySelector('dialog').textContent, /Corrigir dimensionamento hidráulico/);
click('Cancelar'); await wait(() => !document.querySelector('dialog[open]'));

await render('awaiting_delivery_review', { reviewDelivery: true }, [delivery], 'review');
click('Avaliar entrega'); await wait(() => document.querySelector('dialog[open]'));
assert.equal(document.querySelector('input[type=file]'), null);
assert.equal(document.querySelector('dialog a').getAttribute('href'), '/private/30');
assert.match(document.querySelector('dialog').textContent, /Memória de cálculo e desenhos revisados/);
click('Não aprovar'); await wait(() => document.querySelector('button[aria-pressed=true]')?.textContent === 'Não aprovar');
assert.match(document.querySelector('button[aria-pressed=true]').className, /bg-red-600/);
assert.match(document.querySelector('textarea').parentElement.textContent, /Por que a entrega não atendeu/);
click('Aprovar entrega'); await wait(() => document.querySelector('button[aria-pressed=true]')?.textContent === 'Aprovar entrega');
assert.match(document.querySelector('button[aria-pressed=true]').className, /bg-emerald-600/);
document.querySelector('dialog form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
await wait(() => document.querySelector('[role=alertdialog][open]'));
assert.match(document.querySelector('[role=alertdialog]').textContent, /Aprovar entrega/);
assert.match(document.querySelector('[role=alertdialog]').textContent, /finalizada automaticamente/);
assert.doesNotMatch(document.querySelector('[role=alertdialog]').textContent, /fiscal do contrato/);
click('Confirmar aprovação'); await wait(() => submitted);
assert.equal(submitted.url, '/test/tenant.os-dnit.orders.review-delivery');
assert.equal(submitted.data.decision, 'approve');
assert.equal(submitted.data.version, 9);
assert.equal(Object.hasOwn(submitted.data, 'file'), false);
const axios = (await import('axios')).default;
let requested;
axios.get = async (url) => { requested = url; return { data: { order: { id: 1, request_number: '001/2026', workflow_version: 10, status: 'approved', deliveries: [] }, deliveryLinks: {} } }; };
root.render(React.createElement(module.OsDnitDeliveryAction, { order: { id: 1 }, tenantSlug: 'test' }));
await wait(() => [...document.querySelectorAll('button')].some((button) => button.textContent === 'Entregar demanda'));
click('Entregar demanda');
await wait(() => document.querySelector('dialog[open] input[type=file]'));
assert.equal(requested, '/test/tenant.os-dnit.orders.delivery-form');
assert.equal(document.querySelector('dialog textarea').required, true);
assert.equal(document.querySelector('dialog input[type=file]').required, true);
click('Cancelar'); await wait(() => !document.querySelector('dialog[open]'));
axios.get = async () => ({ data: { order: { id: 1, request_number: '001/2026', workflow_version: 11, status: 'awaiting_delivery_review', deliveries: [delivery] }, deliveryLinks: { 30: '/private/30' } } });
root.render(React.createElement(module.OsDnitDeliveryAction, { key: 'list-review', order: { id: 1 }, tenantSlug: 'test', review: true }));
await wait(() => [...document.querySelectorAll('button')].some((button) => button.textContent === 'Avaliar entrega'));
click('Avaliar entrega'); await wait(() => document.querySelector('dialog[open] textarea'));
assert.equal(document.querySelector('dialog input[type=file]'), null);
assert.match(document.querySelector('dialog').textContent, /Memória de cálculo e desenhos revisados/);
assert.ok(document.querySelector('button[aria-pressed]'));
click('Cancelar'); await wait(() => !document.querySelector('dialog[open]'));
axios.get = async () => { throw { response: { status: 403 } }; };
click('Avaliar entrega'); await wait(() => document.querySelector('[role=alert]'));
assert.match(document.querySelector('[role=alert]').textContent, /entrega não está disponível/);
assert.equal(document.querySelector('dialog[open]'), null);
root.unmount();
console.log('OS: entrega, revisão, avaliação, cores, confirmação e envio validados.');
