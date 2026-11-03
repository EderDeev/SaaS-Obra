import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'vite';
import { JSDOM } from 'jsdom';

const output = resolve('tmp/user-contract-link-ui');
await build({ logLevel: 'error', plugins: [{ name: 'user-link-context', enforce: 'pre',
    resolveId(id) { if (id === '@inertiajs/react') return '\0user-link-inertia'; },
    load(id) {
        if (id.replaceAll('\\', '/').endsWith('/resources/js/Layouts/AuthenticatedLayout.jsx')) return "import React from 'react';export default ({children})=>React.createElement('div',null,children);";
        if (id === '\0user-link-inertia') return `import {useState} from 'react';
            export const Head=()=>null;export const router={};
            export const usePage=()=>({props:{auth:{user:{id:1}},currentTenant:{slug:'tests'},flash:{}}});
            export function useForm(initial){const [data,set]=useState(initial);let transform=d=>d;
                const api={data,errors:{},processing:false,clearErrors(){},setData(key,value){set(old=>typeof key==='function'?key(old):typeof key==='object'?key:{...old,[key]:value});},
                transform(fn){transform=fn;return api;},patch(url){globalThis.__linkedRequest={url,data:transform(data)};},post(){throw Error('Unexpected create request');}};return api;}`;
    },
}], build: { ssr: 'resources/js/Pages/Tenant/Users/Index.jsx', outDir: output } });
const dom = new JSDOM('<div id="app"></div>', { url: 'http://localhost', pretendToBeVisual: true });
for (const key of ['window','document','navigator','HTMLElement','Element','Node','Event','MouseEvent']) Object.defineProperty(globalThis, key, { value: dom.window[key], configurable: true });
let scrolled = null;
HTMLElement.prototype.scrollIntoView = function () { scrolled = this; };
globalThis.route = name => `/${name}`;
const React = await import('react');
const { createRoot } = await import('react-dom/client');
const Page = (await import(pathToFileURL(resolve(output, 'Index.js')).href)).default;
const root = createRoot(document.getElementById('app'));
const wait = async predicate => { for (let n=0;n<100;n++) { if (predicate()) return; await new Promise(done=>setTimeout(done,10)); } assert.fail('UI state did not settle'); };
root.render(React.createElement(Page, {
    tenant: { name: 'Diretoria' }, empresas: [], contracts: [{ id: 10, code: 'CT-001', name: 'Contrato 1' }],
    memberships: [{ id: 2, user_id: 2, role: 'engenheiro_planejamento', status: 'active', empresa_id: null, user: { id: 2, name: 'Mariana Costa', email: 'mariana@example.test' }, contract_accesses: [] }],
    userPermissionCan: { edit_user: true }, contractRolesBySide: { manager: ['manager','team_member'] },
}));
await wait(()=>[...document.querySelectorAll('button')].some(button=>button.textContent.trim()==='Vincular'));
[...document.querySelectorAll('button')].find(button=>button.textContent.trim()==='Vincular').click();
await wait(()=>document.querySelector('form h1'));
assert.equal(document.querySelector('form h1').textContent, 'Vincular contratos');
assert.equal(scrolled, document.querySelector('form h1'));
assert.equal(document.activeElement, scrolled);
const save = [...document.querySelectorAll('form button')].find(button=>button.textContent.includes('Salvar vínculos'));
assert.ok(save && !save.disabled, 'Linking must remain available when the directorate has no companies');
document.querySelector('form input[type=checkbox]').click();
await wait(()=>document.querySelector('form').textContent.includes('1 contrato(s)'));
document.querySelector('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
await wait(()=>globalThis.__linkedRequest);
assert.equal(globalThis.__linkedRequest.url, '/tenant.users.contracts.update');
assert.deepEqual(Object.keys(globalThis.__linkedRequest.data), ['contract_accesses']);
assert.equal(globalThis.__linkedRequest.data.contract_accesses[0].contract_id, 10);
root.unmount();dom.window.close();
console.log('PASS: Vincular opens and focuses form, saves without company, sends contract-only payload to dedicated route.');
