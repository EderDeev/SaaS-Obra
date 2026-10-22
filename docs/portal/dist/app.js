(() => {
  'use strict';
  try {
  const {chapters,meta}=JSON.parse(document.getElementById('docs-data').textContent);
  const $=selector=>document.querySelector(selector);
  const escape=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const normalize=value=>value.normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase();
  const strip=html=>{const node=document.createElement('div');node.innerHTML=html;return node.textContent;};
  const all=chapters.flatMap((ch,i)=>ch.articles.map((a,j)=>({...a,chapter:ch,number:`${i+1}.${j+1}`,indexText:normalize([ch.title,a.title,a.summary,strip(a.body),strip(a.technical)].join(' '))})));
  const technicalGroups=['Técnico','Operação'];
  const nav=$('#navigation'),content=$('#content'),search=$('#search'),dialog=$('#image-dialog');
  let active=chapters[0],searchTimer;
  // As imagens vêm embutidas uma única vez na versão estática; guarda antes de redesenhar.
  const images={};
  content.querySelectorAll('img[data-asset]').forEach(img=>{images[img.dataset.asset]=img.src;});
  const fillImages=root=>root.querySelectorAll('img[src^="assets/"]').forEach(img=>{const src=images[img.getAttribute('src')];if(src)img.src=src;});

  const mobile=window.matchMedia('(max-width:900px)');
  function drawer(open){$('#sidebar').classList.toggle('is-open',open);$('#sidebar').inert=mobile.matches&&!open;$('#drawer-backdrop').hidden=!open;$('#drawer-backdrop').classList.toggle('is-open',open);$('#menu-toggle').setAttribute('aria-expanded',String(open));}
  mobile.addEventListener('change',()=>drawer(false));
  drawer(false);
  $('#menu-toggle').addEventListener('click',()=>drawer($('#menu-toggle').getAttribute('aria-expanded')!=='true'));
  $('#drawer-backdrop').addEventListener('click',()=>drawer(false));
  nav.addEventListener('click',e=>{if(e.target.closest('a'))drawer(false);});
  document.addEventListener('keydown',e=>{
    if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();search.focus();}
    if(e.key==='Escape'){drawer(false);if(!dialog.open&&search.value)clearSearch();}
  });

  function renderNav(){
    let lastGroup='';
    nav.innerHTML=chapters.map((ch,i)=>{
      const group=ch.group!==lastGroup?`<div class="nav-group">${escape(ch.group)}</div>`:'';lastGroup=ch.group;
      const current=ch===active&&!search.value.trim();
      const sub=current?`<ul class="nav-sub">${ch.articles.map(a=>`<li><a href="#${ch.id}/${a.id}">${escape(a.title)}</a></li>`).join('')}</ul>`:'';
      return `${group}<a class="nav-link" href="#${ch.id}"${current?' aria-current="page"':''}><span class="num">${i+1}</span>${escape(ch.title)}</a>${sub}`;
    }).join('');
  }
  const refs=(ch,a)=>technicalGroups.includes(ch.group)&&a.sources.length?`<p class="code-refs">Código: ${a.sources.map(s=>`<code>${escape(s)}</code>`).join(', ')}</p>`:'';
  const body=(ch,a)=>`<div class="article-body">${a.body}${a.technical?`<div class="tech-note"><h4>Detalhes técnicos</h4>${a.technical}</div>`:''}${refs(ch,a)}</div>`;
  const chapterHtml=(ch,i,topicId=a=>a.id)=>`<section class="chapter" id="${ch.id}"><h1><span class="num">${i+1}.</span>${escape(ch.title)}</h1><p class="chapter-intro">${escape(ch.intro)}</p>${ch.articles.map((a,j)=>`<section class="topic" id="${topicId(a)}"><h2><span class="num">${i+1}.${j+1}</span>${escape(a.title)}</h2>${body(ch,a)}</section>`).join('')}</section>`;
  const titleBlock=()=>`<header class="doc-title"><h1>Manual do SigWorks</h1><p>Uso, administração e operação do sistema. Revisão de ${meta.reviewDate}.</p></header>`;

  function render(scroll=false){
    const [chapterId,topicId]=(location.hash.slice(1)||'inicio').split('/');
    active=chapters.find(ch=>ch.id===chapterId)||chapters[0];
    document.title=active===chapters[0]?'Manual do SigWorks':`${active.title} · Manual do SigWorks`;
    renderNav();
    if(search.value.trim()){renderSearch();return;}
    const index=chapters.indexOf(active),prev=chapters[index-1],next=chapters[index+1];
    content.innerHTML=`${index===0?titleBlock():''}${chapterHtml(active,index)}<nav class="chapter-pager" aria-label="Navegação entre capítulos">${prev?`<a href="#${prev.id}"><small>Anterior</small>${index}. ${escape(prev.title)}</a>`:''}${next?`<a href="#${next.id}"><small>Próximo</small>${index+2}. ${escape(next.title)}</a>`:''}</nav>`;
    fillImages(content);
    if(topicId){const topic=document.getElementById(topicId);if(topic)requestAnimationFrame(()=>topic.scrollIntoView({block:'start'}));}
    else if(scroll)window.scrollTo({top:0});
  }
  function renderSearch(){
    const query=search.value.trim(),terms=normalize(query).split(/\s+/).filter(Boolean);
    const matches=all.filter(a=>terms.every(t=>a.indexText.includes(t)));
    $('#clear-search').hidden=!query;
    renderNav();
    content.innerHTML=`<div class="search-heading"><h1>Busca: ${escape(query)}</h1><p role="status" aria-live="polite">${matches.length===0?'Nada encontrado.':matches.length===1?'1 resultado.':`${matches.length} resultados.`}</p></div>${matches.length?`<ul class="search-results">${matches.map(a=>`<li><small>${a.number.split('.')[0]}. ${escape(a.chapter.title)}</small><a href="#${a.chapter.id}/${a.id}">${a.number} ${escape(a.title)}</a><p>${escape(a.summary)}</p></li>`).join('')}</ul>`:'<p>Tente uma palavra mais curta ou outro termo.</p>'}`;
  }
  function clearSearch(){clearTimeout(searchTimer);search.value='';$('#clear-search').hidden=true;render();}
  $('#clear-search').addEventListener('click',()=>{clearSearch();search.focus();});
  search.addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>search.value.trim()?renderSearch():clearSearch(),120);});
  content.addEventListener('click',e=>{
    const result=e.target.closest('.search-results a');
    if(result){e.preventDefault();search.value='';$('#clear-search').hidden=true;if(location.hash===result.hash)render(true);else location.hash=result.hash;return;}
    const zoom=e.target.closest('.zoom-image');if(!zoom)return;
    const img=zoom.querySelector('img');$('#expanded-image').src=img.getAttribute('src');$('#expanded-image').alt=img.alt;$('#image-caption').textContent=img.alt;dialog.showModal();
  });
  $('#close-image').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
  window.addEventListener('hashchange',()=>{search.value='';$('#clear-search').hidden=true;drawer(false);render(true);});

  function preparePrint(){
    $('#print-root').innerHTML=`<div class="print-cover"><h1>Manual do SigWorks</h1><p>Uso, administração e operação do sistema.</p><p>Revisão de ${meta.reviewDate}</p></div>${chapters.map((ch,i)=>chapterHtml(ch,i,a=>`print-${a.id}`)).join('')}`;
    fillImages($('#print-root'));
    $('#print-root').querySelectorAll('details').forEach(d=>d.open=true);
    $('#print-root').querySelectorAll('img').forEach(img=>img.loading='eager');
  }
  $('#print-guide').addEventListener('click',async()=>{preparePrint();await Promise.all([...$('#print-root').querySelectorAll('img')].map(img=>img.decode().catch(()=>{})));window.print();});
  window.addEventListener('beforeprint',preparePrint);
  render();
  document.documentElement.dataset.enhanced='true';
  } catch(error) {
    document.documentElement.removeAttribute('data-enhanced');
    document.getElementById('sidebar').inert=false;
    console.error('Falha ao iniciar a navegação do manual.',error);
  }
})();
