import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {JSDOM} from 'jsdom';
import {build} from 'vite';

// The catalog is pure PHP data: no Laravel bootstrap, database or external services.
const guides=JSON.parse(execFileSync(process.env.PHP_BINARY || 'php',['-r','require "vendor/autoload.php"; echo json_encode(App\\Support\\TutorialCatalog::all());'],{encoding:'utf8'}));
const output=resolve('tmp/tutorials-visual-ui');
await build({logLevel:'error',build:{ssr:'resources/js/Pages/Tenant/Tutorials/VisualTutorial.jsx',outDir:output}});
const dom=new JSDOM('<div id="app"></div>',{url:'http://localhost/t/tests/tutoriais',pretendToBeVisual:true});
for(const key of ['window','document','location','history','navigator','HTMLElement','Element','Node','Event','MouseEvent','KeyboardEvent','localStorage'])Object.defineProperty(globalThis,key,{value:dom.window[key],configurable:true,writable:true});
window.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
window.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};
window.HTMLDialogElement.prototype.close=function(){this.removeAttribute('open');};
globalThis.ResizeObserver=class{observe(){}disconnect(){}};
const positions={analista:[0,0],supervisao:[260,0],fiscal:[520,0],'ajuste-analista':[0,165],'ajuste-supervisao':[260,165],decisao:[520,165],aprovada:[260,330],entrega:[520,330],avaliacao:[520,495],atendeu:[520,660],revisao:[260,495],finalizada:[260,660]};
HTMLElement.prototype.getBoundingClientRect=function(){let point=positions[this.dataset.osNode];if(this.dataset.flowIndex!==undefined){const n=Number(this.dataset.flowIndex),r=Math.floor(n/3),c=r%2?2-n%3:n%3;point=[c*260,r*165];}return point?{left:point[0],top:point[1],right:point[0]+205,bottom:point[1]+115,width:205,height:115}:{left:0,top:0,right:740,bottom:445,width:740,height:445};};
const errors=[];
window.addEventListener('error',event=>errors.push(event.error));
const React=await import('react');
const {createRoot}=await import('react-dom/client');
const Visual=(await import(pathToFileURL(resolve(output,'VisualTutorial.js')).href)).default;
const root=createRoot(document.getElementById('app'),{onUncaughtError:error=>errors.push(error)});
const wait=async predicate=>{for(let n=0;n<100;n++){if(errors.length)throw errors[0];if(predicate())return;await new Promise(done=>setTimeout(done,10));}assert.fail('Estado da interface não estabilizou');};
const pointer=(element,type,pointerType='mouse',relatedTarget=null)=>{const event=new window.MouseEvent(type,{bubbles:true,relatedTarget});Object.defineProperty(event,'pointerType',{value:pointerType});element.dispatchEvent(event);};
let checked=0;
assert.equal(guides.length,19);
for(const guide of guides){
 root.render(React.createElement(Visual,{key:guide.id,tutorial:guide}));
 await wait(()=>document.querySelector('.os-tutorial')?.getAttribute('aria-label')===(guide.id==='os-dnit'?'Tutorial visual de Ordem de Serviço':`Tutorial visual: ${guide.title}`));
 const cards=[...document.querySelectorAll('[data-flow-index],[data-os-node]')];
 assert.ok(cards.length>=2,guide.id);
 if(guide.id==='atividades'){
  assert.deepEqual(cards.map(card=>card.querySelector('strong').textContent),['Atividade ou checklist','Visibilidade','Fluxo da atividade','Métricas']);
  assert.deepEqual([...document.querySelectorAll('.tutorial-flow-statuses>span')].map(status=>status.textContent),['A fazer','Em andamento','Em revisão','Concluída']);
  assert.equal(cards.at(-1).dataset.informational,'true');
 }
 assert.equal(document.querySelector('.os-tutorial-route'),null,'A faixa inferior deve estar removida');
 if(guide.id==='os-dnit'){assert.equal(document.querySelectorAll('[data-os-node][aria-haspopup=dialog]').length,5);assert.equal(document.querySelectorAll('[data-informational=true]').length,7);}
 assert.equal(document.querySelectorAll('.os-tutorial-edge').length,guide.id==='os-dnit'?8:cards.length-1);
 for(const card of cards){
  pointer(card,'pointerover');await wait(()=>document.querySelector('[role=tooltip]'));
  assert.equal(card.getAttribute('aria-describedby'),document.querySelector('[role=tooltip]').id);
  pointer(card,'pointerout','mouse',document.body);await wait(()=>!document.querySelector('[role=tooltip]'));
  if(card.dataset.informational === 'true') {
    assert.equal(card.getAttribute('aria-haspopup'),null);
    card.click();assert.equal(document.querySelector('dialog[open]'),null);
    card.focus();await wait(()=>document.querySelector('[role=tooltip]'));
    assert.ok(!document.querySelector('[role=tooltip]').textContent.includes('Clique para ver'));
    card.blur();await wait(()=>!document.querySelector('[role=tooltip]'));
    continue;
  }
  card.click();await wait(()=>document.querySelector('dialog[open]'));
  assert.equal(document.getElementById('tutorial-stage-title').textContent,card.querySelector('strong').textContent);
  assert.ok(document.querySelector('.tutorial-stage-body').textContent.length>50);
  assert.ok(document.querySelector('.tutorial-demo,.tutorial-demand-walkthrough,.tutorial-os-walkthrough,.tutorial-guided-walkthrough,.sigworks-overview'));
  if(guide.id==='sigworks'){
   const expected=[['CMAT N/NE','CMAT S/SE','CMAT Projetos'],['FIS-MA-2026','615/2025 - DNIT','002/2026'],['Cliente','Gerenciadora · Concremat','Construtoras']];
   const titles=[...document.querySelectorAll('.sigworks-overview-cards h3')].map(item=>item.textContent);
   if(Number(card.dataset.flowIndex)<3)assert.deepEqual(titles,expected[Number(card.dataset.flowIndex)]);
   else if(Number(card.dataset.flowIndex)===3){assert.equal(titles.length,10);assert.ok(titles.includes('Ordem de Serviço'));assert.ok(titles.includes('Qualidade · IC e RNC'));}
   else {
    assert.equal(card.querySelector('strong').textContent,'Indicadores');
    assert.doesNotMatch(document.querySelector('.sigworks-indicators').textContent,/Dados ilustrativos|Dashboards de exemplo/);
    assert.equal(document.querySelectorAll('.sigworks-indicator').length,2);
    const contracts=document.querySelectorAll('[aria-label="Contratos dos indicadores"] button');
    assert.equal(contracts.length,3);
    for(const [index,expected] of ['Diário de Obra · RDO','Ordem de Serviço','Projetos'].entries()){
     contracts[index].click();await wait(()=>document.querySelector('.sigworks-indicator h3').textContent===expected);
     assert.equal(document.querySelectorAll('.sigworks-indicator-ring').length,2);
     assert.match(document.querySelector('.sigworks-indicators-source').textContent,/Registros da operação.*Indicadores do contrato/);
    }
   }
   assert.equal(document.querySelector('.sigworks-overview form'),null);
  }
  assert.equal(document.querySelectorAll('.tutorial-demo form').length,0,'Prévia não opera documentos reais');
  for (const control of document.querySelectorAll('.tutorial-demo button,.tutorial-demo input,.tutorial-demo select,.tutorial-demo textarea')) assert.ok(control.matches(':disabled'), 'Controles da demonstração não operam registros');
  if (document.querySelector('.tutorial-demand-walkthrough')) {
    assert.equal(document.querySelectorAll('[aria-label="Passos da demanda"] button').length,3);
    const nav=()=>document.querySelectorAll('.tutorial-walkthrough-navigation button');
    assert.ok(nav()[0].disabled);
    nav()[1].click(); await wait(()=>document.querySelector('.tutorial-walkthrough-heading h3').textContent.includes('necessidade'));
    assert.ok(document.querySelector('[data-example-step="1"]'));
    assert.ok(document.querySelector('textarea').value.includes('drenagem'));
    nav()[1].click(); await wait(()=>document.querySelector('[data-example-step="2"]'));
    assert.ok(nav()[1].disabled);
    nav()[0].click(); await wait(()=>document.querySelector('[data-example-step="1"]'));
    document.querySelector('[aria-label="Passos da demanda"] button').click(); await wait(()=>document.querySelector('[data-example-step="0"]'));
    assert.equal(document.querySelectorAll('.tutorial-demand-walkthrough form,.tutorial-demand-walkthrough img,.tutorial-demand-walkthrough input[type=file]').length,0);
    for(const field of document.querySelectorAll('.tutorial-example-fields input,.tutorial-example-fields textarea')) {assert.ok(field.readOnly); assert.ok(field.value.length>0);}
  }
  if (document.querySelector('[data-os-walkthrough]')) {
    const walkthrough=document.querySelector('[data-os-walkthrough]');
    const phases=walkthrough.querySelectorAll('[aria-label="Passos da etapa"] button');
    const compact=['entrega','avaliacao'].includes(walkthrough.dataset.osWalkthrough);
    assert.equal(phases.length,compact?0:3);
    if(compact){assert.equal(walkthrough.querySelector('.tutorial-walkthrough-navigation'),null);assert.equal(walkthrough.querySelectorAll('.tutorial-example-field').length,walkthrough.dataset.osWalkthrough==='entrega'?2:3);}
    for(let phase=0;phase<(compact?1:3);phase++) {
      phases[phase]?.click();await wait(()=>walkthrough.querySelector(`[data-example-phase="${phase}"]`));
      for(const field of walkthrough.querySelectorAll('input,textarea')) {assert.ok(field.readOnly);assert.ok(field.value.length>0);}
      const deliveryChoices=walkthrough.querySelectorAll('[aria-label="Avaliação da entrega do produto"] button');
      if(deliveryChoices.length){deliveryChoices[1].click();await wait(()=>walkthrough.textContent.includes('Considerações e motivo da revisão'));assert.ok([...walkthrough.querySelectorAll('textarea')].some(field=>field.value.includes('não contempla duas saídas')));deliveryChoices[0].click();await wait(()=>walkthrough.textContent.includes('Considerações sobre a entrega'));}
      const choices=walkthrough.querySelectorAll('[aria-label="Exemplo de decisão"] button');
      if(choices.length){
        choices[1].click();await wait(()=>walkthrough.textContent.includes('Motivo da reprovação'));
        const destinations=walkthrough.querySelectorAll('[aria-label="Destino da devolução"] button');
        assert.equal(destinations.length,2);destinations[1].click();await wait(()=>walkthrough.querySelector('textarea').value.includes('quilômetros'));
        choices[0].click();await wait(()=>walkthrough.textContent.includes('Comentário da decisão'));
      }
    }
    assert.equal(walkthrough.querySelectorAll('form,img,input[type=file]').length,0);
    if(!compact)assert.ok(walkthrough.querySelectorAll('.tutorial-walkthrough-navigation button')[1].disabled);
  }
  if(document.querySelector('[data-tutorial-walkthrough]')) {
    const walkthrough=document.querySelector('[data-tutorial-walkthrough]');
    const tabs=walkthrough.querySelectorAll('[aria-label="Passos da etapa"] button,[aria-label="Tipo de atividade"] button');
    const sceneCount=tabs.length || 1;
    if(guide.id==='orcamentos' && card.dataset.flowIndex==='0') {assert.equal(sceneCount,1);assert.equal(walkthrough.querySelector('.tutorial-walkthrough-navigation'),null);assert.ok(walkthrough.querySelector('[aria-label="Seleção demonstrativa de insumos"]'));}
    if(guide.id==='orcamentos' && card.dataset.flowIndex==='3') {assert.equal(sceneCount,7);assert.deepEqual([...tabs].map(button=>button.textContent),['Dados gerais','Cálculo','Bases','Etapa','Insumo','Composição','Acessos']);}
    if(guide.id==='diario-obra' && card.dataset.flowIndex==='0')assert.equal(sceneCount,6);
    if(guide.id==='atividades' && card.dataset.flowIndex==='0'){
     assert.equal(sceneCount,2);assert.deepEqual([...tabs].map(button=>button.textContent),['Atividade normal','Checklist']);
     assert.equal(walkthrough.querySelector('.tutorial-walkthrough-navigation'),null);
    }
    if(guide.id==='atividades' && card.dataset.flowIndex==='2'){
     assert.deepEqual([...tabs].map(button=>button.textContent),['A fazer','Em andamento','Em revisão','Concluída']);
    }
    for(let phase=0;phase<sceneCount;phase++) {
      tabs[phase]?.click();await wait(()=>walkthrough.querySelector(`[data-guided-phase="${phase}"]`));
      if(guide.id==='atividades' && card.dataset.flowIndex==='0'){
       assert.match(walkthrough.textContent,/Título.*Descrição.*Contrato.*Categoria.*Prioridade.*Prazo/);
       assert.doesNotMatch(walkthrough.textContent,/Responsáveis|Notificações/);
       assert.equal(walkthrough.textContent.includes('Itens do checklist'),phase===1);
      }
      for(const field of walkthrough.querySelectorAll('input:not([type=checkbox]),textarea')) {assert.ok(field.readOnly);assert.ok(field.value.length>0);assert.doesNotMatch(field.value,/Informação desta etapa|Contrato selecionado/);}
      const picker=walkthrough.querySelector('.tutorial-example-picker');
      if(picker){const first=picker.querySelector('input');const before=first.checked;first.click();await wait(()=>first.checked!==before);}
      const results=walkthrough.querySelectorAll('[aria-label="O problema foi resolvido?"] button');
      if(results.length){results[1].click();await wait(()=>walkthrough.textContent.includes('Motivo'));assert.ok([...walkthrough.querySelectorAll('textarea')].some(field=>field.value.includes('talude')));}
      const choices=walkthrough.querySelectorAll('[aria-label="Exemplo de avaliação"] button');
      if(choices.length){choices[1].click();await wait(()=>walkthrough.textContent.includes('Motivo da devolução'));}
    }
    assert.equal(walkthrough.querySelectorAll('form,img,input[type=file]').length,0);
  }
  assert.equal(document.body.style.overflow,'hidden');
  assert.equal(document.querySelector('.tutorial-stage-tabs'),null);
  assert.equal(document.getElementById('tutorial-panel-media'),null);
  document.querySelector('[aria-label="Fechar detalhes da etapa"]').click();
  await wait(()=>!document.querySelector('dialog'));
  assert.equal(document.body.style.overflow,'');checked++;
 }
}
const rdo=guides.find(guide=>guide.id==='diario-obra');
root.render(React.createElement(Visual,{key:'rdo-models',tutorial:rdo}));
await wait(()=>document.querySelector('.tutorial-model-picker button'));
assert.equal(document.querySelector('.tutorial-model-picker select'),null);
assert.equal(document.querySelectorAll('.tutorial-model-picker button').length,4);
assert.doesNotMatch(document.querySelector('.os-tutorial').textContent,/Parametrização e geração/);
for(const [model,count] of [['construtora_gerenciadora_cliente',5],['gerenciadora_construtora',4],['gerenciadora_cliente',4],['gerenciadora',2]]){
 document.querySelector(`[data-rdo-model="${model}"]`).click();
 await wait(()=>document.querySelector(`[data-rdo-model="${model}"]`).getAttribute('aria-pressed')==='true');
 await wait(()=>document.querySelectorAll('[data-flow-index]').length===count);
 const titles=[...document.querySelectorAll('[data-flow-index] strong')].map(el=>el.textContent).join(' ');
 if(model==='gerenciadora')assert.doesNotMatch(titles,/aprova|analisa|Assinaturas/i);
 if(model==='gerenciadora_construtora')assert.doesNotMatch(titles,/Cliente/);
 document.querySelector('[data-flow-index="0"]').click();await wait(()=>document.querySelector('dialog[open]'));
 const tabs=[...document.querySelectorAll('[aria-label="Passos da etapa"] button')];
 assert.deepEqual(tabs.map(button=>button.textContent),['Tempo','Mão de obra','Equipamentos','Atividades','Comentário','Fotos']);
 tabs.at(-1).click();await wait(()=>document.querySelector('[data-guided-phase="5"]'));
 if(model==='gerenciadora')assert.ok(document.querySelector('.tutorial-example-actions').textContent.includes('Salvar o RDO completo'));
 if(model==='gerenciadora')document.querySelector('dialog').dispatchEvent(new window.Event('cancel',{cancelable:true}));
 else document.querySelector('[aria-label="Fechar detalhes da etapa"]').click();
 await wait(()=>!document.querySelector('dialog'));

}
const os=guides.find(guide=>guide.id==='os-dnit');
root.render(React.createElement(Visual,{key:'os-touch',tutorial:os}));
await wait(()=>document.querySelector('[data-os-node="analista"]'));
const analyst=document.querySelector('[data-os-node="analista"]');
analyst.focus();await wait(()=>document.querySelector('[role=tooltip]'));analyst.blur();await wait(()=>!document.querySelector('[role=tooltip]'));
pointer(analyst,'pointerover','touch');assert.equal(document.querySelector('[role=tooltip]'),null);
analyst.click();await wait(()=>document.querySelector('dialog[open]'));
assert.match(document.querySelector('.tutorial-stage-body').textContent,/Selecione o contrato e o lote/);
document.querySelector('dialog').dispatchEvent(new window.MouseEvent('click',{bubbles:true,clientX:10000,clientY:10000}));await wait(()=>!document.querySelector('dialog'));
for(const label of ['Corrigir produtos','Corrigir demanda','Aprovar']){
 [...document.querySelectorAll('.os-tutorial-choice')].find(button=>button.textContent.includes(label)).click();
 await wait(()=>document.querySelectorAll('.os-tutorial-edge.return').length===(label==='Aprovar'?0:2));
}
const deliveryButtons=document.querySelectorAll('[aria-label="Decisão sobre a entrega"] button');
deliveryButtons[1].click();await wait(()=>document.querySelector('[data-os-node="revisao"] strong').textContent==='Revisão 1');
assert.equal(document.querySelector('[data-os-node="finalizada"]').dataset.dim,'true');
assert.equal(document.querySelectorAll('.os-tutorial-edge.return').length,2);
deliveryButtons[1].click();await wait(()=>document.querySelector('[data-os-node="revisao"] strong').textContent==='Revisão 2');
deliveryButtons[0].click();await wait(()=>document.querySelectorAll('.os-tutorial-edge.return').length===0);
assert.equal(document.querySelector('[data-os-node="finalizada"]').dataset.dim,'false');
root.unmount();

// Test the page shell with only global chrome and Inertia's context stubbed.
const workspaceOutput=resolve('tmp/tutorials-workspace-ui');
await build({logLevel:'error',plugins:[{name:'tutorial-context-test',enforce:'pre',resolveId(id){if(id==='@/Layouts/AuthenticatedLayout')return '\0tutorial-layout';if(id==='@inertiajs/react')return '\0tutorial-inertia';},load(id){if(id==='\0tutorial-layout'||id.replaceAll('\\','/').endsWith('/resources/js/Layouts/AuthenticatedLayout.jsx'))return "import React from 'react'; export default function Layout({children}) {return React.createElement('div',null,children)}";if(id==='\0tutorial-inertia')return 'export const Link = () => null; export const router = {}; export const Head = () => null; export const usePage = () => ({props:{auth:{user:{id:7}}}});';}}],build:{ssr:'resources/js/Pages/Tenant/Tutorials/TutorialWorkspace.jsx',outDir:workspaceOutput}});
const Workspace=(await import(pathToFileURL(resolve(workspaceOutput,'TutorialWorkspace.js')).href)).default;
const pageRoot=createRoot(document.getElementById('app'));
pageRoot.render(React.createElement(Workspace,{tenant:{id:1,slug:'tests'},tutorials:guides}));
await wait(()=>document.querySelectorAll('.tutorial-menu-item').length===12);
assert.equal(document.querySelector('.tutorial-menu-item').getAttribute('aria-label'),'SigWorks');
for(const id of ['primeiros-passos','administracao-plataforma','visao-geral','contratos','notificacoes-perfil','assistente-deming']){
 const title=guides.find(guide=>guide.id===id).title;
 assert.equal([...document.querySelectorAll('.tutorial-menu-item')].some(item=>item.getAttribute('aria-label')===title),false);
}
assert.equal([...document.querySelectorAll('.tutorial-menu-group h2')].some(title=>['Plataforma','Ajuda'].includes(title.textContent)),false);
const toggle=()=>document.querySelector('[aria-controls="tutorial-menu"]');
toggle().click();await wait(()=>toggle().getAttribute('aria-expanded')==='false');
assert.equal(localStorage.getItem('sigworks:tutorials:menu:1:7'),'collapsed');
document.querySelector('.tutorial-menu-item[aria-label="Qualidade"]').click();
await wait(()=>document.querySelector('#qualidade-geral'));
assert.equal(location.hash,'#qualidade');
assert.equal(document.querySelector('.tutorial-menu-item[aria-label="Instrução de Campo — IC"]'),null);
assert.equal(document.querySelector('.tutorial-menu-item[aria-label="Qualidade e RNC"]'),null);
assert.deepEqual([...document.querySelectorAll('.quality-tutorial>.os-tutorial-panel')].map(section=>section.id),['qualidade-geral']);
assert.equal(document.querySelectorAll('[data-quality-node]').length,8);
const qualityNode=document.querySelector('[data-quality-node="resolvido"]');
pointer(qualityNode,'pointerover');await wait(()=>document.querySelector('[role=tooltip]'));
assert.match(document.querySelector('[role=tooltip]').textContent,/Parcialmente/);
pointer(qualityNode,'pointerout');await wait(()=>!document.querySelector('[role=tooltip]'));
for(const sectionId of ['qualidade-fluxo-ic','qualidade-fluxo-rnc']){
 const label=sectionId.endsWith('-ic')?'IC':'RNC';
 [...document.querySelectorAll('.quality-tutorial-nav button')].find(button=>button.textContent===label).click();
 await wait(()=>document.getElementById(sectionId));
 assert.equal(document.querySelectorAll('.quality-tutorial>.os-tutorial-panel').length,1);
 assert.equal(document.querySelector('.quality-tutorial-nav [aria-pressed=true]').textContent,label);
 assert.equal(document.getElementById('qualidade-geral'),null);
 const section=document.getElementById(sectionId);
 assert.ok(section.querySelector('[data-informational=true]'));
 assert.equal(section.querySelectorAll('[aria-haspopup=dialog]').length,label==='IC'?2:4);
 const passiveTitles=label==='IC'?['Comunicação','Finalização']:['Comunicação','Assinaturas'];
 for(const title of passiveTitles){
  const card=[...section.querySelectorAll('[data-flow-index]')].find(node=>node.querySelector('strong')?.textContent===title);
  assert.equal(card.dataset.informational,'true');
  card.click();assert.equal(document.querySelector('dialog'),null);
 }
 section.querySelector('[aria-haspopup=dialog]').click();await wait(()=>document.querySelector('dialog[open]'));
 assert.equal(document.querySelector('.tutorial-stage-tabs'),null);
 assert.equal(document.getElementById('tutorial-panel-media'),null);
 assert.match(document.querySelector('[data-tutorial-walkthrough]').dataset.tutorialWalkthrough,/qualidade-(ic|rnc)/);
 assert.ok([...document.querySelectorAll('.tutorial-stage-body input')].some(input=>input.value.includes('Obstrução na saída de drenagem')));
 document.querySelector('[aria-label="Fechar detalhes da etapa"]').click();await wait(()=>!document.querySelector('dialog'));
}
const proposalChoices=document.querySelector('[aria-label="Decisão da supervisão sobre a proposta"]');
proposalChoices.querySelectorAll('button')[1].click();
await wait(()=>document.querySelector('#qualidade-fluxo-rnc .os-tutorial-edge.return'));
assert.equal(document.querySelector('#qualidade-fluxo-rnc [data-return=true] strong').textContent,'Proposta');
proposalChoices.querySelectorAll('button')[0].click();
await wait(()=>!document.querySelector('#qualidade-fluxo-rnc .os-tutorial-edge.return'));
document.querySelector('.quality-tutorial-nav button').click();
await wait(()=>document.querySelector('#qualidade-geral'));
assert.equal(document.querySelectorAll('.quality-tutorial>.os-tutorial-panel').length,1);
window.location.hash='#qualidade-fluxo-rnc';
await wait(()=>document.querySelector('#qualidade-fluxo-rnc'));
window.location.hash='#qualidade-ic';
await wait(()=>document.querySelector('#qualidade-fluxo-ic'));
window.location.hash='#qualidade-rnc';
await wait(()=>document.querySelector('#qualidade-fluxo-rnc'));
toggle().click();await wait(()=>toggle().getAttribute('aria-expanded')==='true');
assert.equal(document.querySelector('[aria-label="Buscar tutorial"]'),null);
assert.ok(document.getElementById('tutorial-menu').contains(toggle()));
assert.equal(document.querySelectorAll('.tutorial-menu-item').length,12);
pageRoot.unmount();assert.deepEqual(errors,[]);dom.window.close();
console.log(`PASS: ${guides.length} guides; ${checked} stage modals; hover/focus/touch; close/cancel/outside; 4 RDO models; removed footer; collapsible scoped menu; menu without search and navigation.`);
