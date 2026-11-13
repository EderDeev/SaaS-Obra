import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';
import { build } from 'vite';

const output = resolve('tmp/os-stage-deadlines-ui');
await build({ logLevel: 'error', ssr: { noExternal: ['echarts-for-react'] }, build: { ssr: 'resources/js/Components/OsDnitStageDeadlines.jsx', outDir: output } });
const dom = new JSDOM('<div id="app"></div>', { url: 'http://localhost', pretendToBeVisual: true });
for (const key of ['window', 'document', 'location', 'navigator', 'HTMLElement', 'Element', 'Node', 'Event', 'MouseEvent', 'HTMLCanvasElement']) Object.defineProperty(globalThis, key, { value: dom.window[key], configurable: true });
Object.defineProperty(HTMLElement.prototype, 'clientWidth', { get: () => 800 });
Object.defineProperty(HTMLElement.prototype, 'clientHeight', { get: () => 310 });
HTMLCanvasElement.prototype.getContext = () => ({ measureText: (text) => ({ width: String(text).length * 7 }) });
window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
globalThis.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
globalThis.route = (name) => `/test/${name}`;
const React = await import('react');
const { createRoot } = await import('react-dom/client');
const echarts = await import('echarts');
const axios = (await import('axios')).default;
const calls = [];
const item = { id: 2, number: '002/2026', subject: 'Relatório de drenagem', contract: 'CT-01', outcome: 'missed', revision: 1,
    started_at: '2026-11-07T12:00:00-03:00', deadline_at: '2026-11-04T23:59:59-03:00', finished_at: '2026-11-08T12:00:00-03:00',
    elapsed_minutes: 1440, delivered_at: '2026-11-08T12:00:00-03:00', evaluated_at: '2026-11-10T12:00:00-03:00', evaluation_minutes: 2880, evaluation_result: 'approve', url: '/private/os/2' };
axios.get = async (url, options) => { calls.push({ url, ...options.params }); return { data: {
    items: [item, { ...item, id: 1, number: '001/2026', outcome: 'attended' }], totals: { attended: 1, missed: 1, pending: 0, unapproved: 0 },
    calculated_at: '2026-11-12T12:00:00-03:00', deadline_reference: 'Prazo registrado na OS.',
} }; };
const errors = [];
window.addEventListener('error', (event) => errors.push(event.error));
const module = await import(pathToFileURL(resolve(output, 'OsDnitStageDeadlines.js')).href);
const root = createRoot(document.getElementById('app'), { onUncaughtError: (error) => errors.push(error) });
const wait = async (predicate) => { for (let n = 0; n < 150; n++) { if (errors.length) throw errors[0]; if (predicate()) return; await new Promise((done) => setTimeout(done, 15)); } assert.fail('A interface não estabilizou.'); };
root.render(React.createElement(module.default, { tenantSlug: 'test', contracts: [{ id: 1, code: 'CT-01', name: 'Contrato' }], initialFilters: { date_from: '2026-11-01', date_to: '2026-11-12' } }));
await wait(() => document.querySelector('svg') && !document.querySelector('[aria-busy=true]'));
assert.equal(calls[0].stage, 'delivery');
const chart = echarts.getInstanceByDom(document.querySelector('.echarts-for-react'));
assert.ok(chart);
await wait(() => { echarts.getInstanceByDom(document.querySelector('.echarts-for-react'))?.trigger('click', { data: { outcome: 'missed' } }); return document.querySelector('dialog[open]'); });
const modal = document.querySelector('dialog');
assert.match(modal.textContent, /002\/2026/);
assert.doesNotMatch(modal.textContent, /001\/2026/);
assert.match(modal.textContent, /08\/11\/2026/);
assert.match(modal.textContent, /10\/11\/2026/);
assert.match(modal.textContent, /2 dia\(s\)/);
assert.match(modal.textContent, /Aprovado pelo analista/);
assert.equal(calls.length, 1, 'O modal usa os registros do mesmo cálculo exibido no gráfico.');
modal.querySelector('[aria-label="Fechar detalhes dos prazos"]').click();
await wait(() => !document.querySelector('dialog[open]'));
const select = document.querySelector('select');
select.value = 'evaluation'; select.dispatchEvent(new Event('change', { bubbles: true }));
await wait(() => calls.length === 2 && !document.querySelector('[aria-busy=true]'));
assert.equal(calls[1].stage, 'evaluation');
assert.equal(module.duration(1500), '1 dia(s) · 1h 0min');
assert.equal(module.stageDeadlineOption({ attended: 1, missed: 2, pending: 3, unapproved: 4 }).series[0].data.length, 4);
root.unmount();
console.log('OS: filtros próprios, clique na pizza, modal, datas e duração da avaliação validados.');
