"""Gera um portal offline sem dependências e sem ler banco ou .env."""
from pathlib import Path
import base64
import copy
import hashlib
import json
import re
import shutil
import subprocess
import zipfile
from datetime import datetime, timezone
from html import escape
from html.parser import HTMLParser

from content import CHAPTERS, CREDENTIALS, table
from diagrams import build_diagrams

BASE=Path(__file__).resolve().parent
ROOT=BASE.parent.parent
DIST=BASE/'dist'

def git(*args):
    return subprocess.check_output(['git',*args],cwd=ROOT,text=True,encoding='utf-8').strip()

TECHNICAL_GROUPS=('Técnico','Operação')

def static_portal(chapters, meta):
    """Versão completa no HTML, legível sem JavaScript; o app.js reescreve por capítulo."""
    nav=[];group=None;sections=[]
    for i,ch in enumerate(chapters,1):
        if ch['group']!=group:
            group=ch['group'];nav.append(f'<div class="nav-group">{escape(group)}</div>')
        nav.append(f'<a class="nav-link" href="#{ch["id"]}"><span class="num">{i}</span>{escape(ch["title"])}</a>')
        articles=[]
        for j,a in enumerate(ch['articles'],1):
            technical=f'<div class="tech-note"><h4>Detalhes técnicos</h4>{a["technical"]}</div>' if a['technical'] else ''
            refs='<p class="code-refs">Código: '+', '.join(f'<code>{escape(s)}</code>' for s in a['sources'])+'</p>' if ch['group'] in TECHNICAL_GROUPS and a['sources'] else ''
            articles.append(f'<section class="topic" id="{ch["id"]}/{a["id"]}"><h2><span class="num">{i}.{j}</span>{escape(a["title"])}</h2><div class="article-body">{a["body"]}{technical}{refs}</div></section>')
        sections.append(f'<section id="{ch["id"]}" class="chapter"><h1><span class="num">{i}.</span>{escape(ch["title"])}</h1><p class="chapter-intro">{escape(ch["intro"])}</p>'+''.join(articles)+'</section>')
    title=f'<header class="doc-title"><h1>Manual do SigWorks</h1><p>Uso, administração e operação do sistema. Revisão de {meta["reviewDate"]}.</p></header>'
    return ''.join(nav),title+''.join(sections)

class Markdown(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts=[];self.links=[];self.code=False;self.header=False;self.columns=0;self.lists=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag in ('ul','ol'):self.parts.append('\n\n');self.lists.append([tag,0])
        elif tag in ('p','div','aside','figure','details'):self.parts.append('\n\n')
        elif tag=='li':
            if self.lists:self.lists[-1][1]+=1
            self.parts.append('\n'+(str(self.lists[-1][1])+'. ' if self.lists and self.lists[-1][0]=='ol' else '- '))
        elif tag=='thead':self.header=True
        elif tag=='tr':self.parts.append('\n');self.columns=0
        elif tag in ('td','th'):self.parts.append(' | ');self.columns+=1
        elif tag=='pre':self.parts.append('\n\n```text\n');self.code=True
        elif tag=='code' and not self.code:self.parts.append('`')
        elif tag in ('h3','h4'):self.parts.append('\n\n#### ')
        elif tag=='strong':self.parts.append('**')
        elif tag=='a':self.parts.append('[');self.links.append(a.get('href',''))
        elif tag=='img':self.parts.append(f"\n![{a.get('alt','Imagem')}]({a.get('src','')})\n")
        elif tag=='br':self.parts.append('\n')
    def handle_endtag(self,tag):
        if tag in ('ul','ol'):self.parts.append('\n\n');self.lists.pop()
        elif tag in ('p','div','aside','figure','details','summary'):self.parts.append('\n\n')
        elif tag=='tr':
            self.parts.append(' |')
            if self.header:self.parts.append('\n'+' | ---'*self.columns+' |')
        elif tag=='thead':self.header=False
        elif tag=='pre':self.parts.append('\n```\n');self.code=False
        elif tag=='code' and not self.code:self.parts.append('`')
        elif tag=='strong':self.parts.append('**')
        elif tag=='a':self.parts.append(']('+self.links.pop()+')')
    def handle_data(self,data):self.parts.append(data)
    def text(self):return re.sub(r'\n{3,}','\n\n',''.join(self.parts)).strip()

def main():
    DIST.mkdir(exist_ok=True)
    assets=DIST/'assets';assets.mkdir(exist_ok=True)
    (assets/'logos').mkdir(exist_ok=True)
    for logo in (BASE/'assets/logos').glob('*.svg'):shutil.copy2(logo,assets/'logos'/logo.name)
    build_diagrams(assets)
    shutil.copy2(ROOT/'public/brand/sigworks-mark.svg',assets/'sigworks-mark.svg')
    chapters=copy.deepcopy(CHAPTERS)
    raw=(BASE/'routes-snapshot.json').read_bytes()
    routes=json.loads(raw.decode('utf-16' if raw.startswith((b'\xff\xfe',b'\xfe\xff')) else 'utf-8-sig'))
    route_groups={}
    for route in routes:
        uri=route['uri']
        group='API / webhooks' if uri.startswith('api/') else ('Administração da plataforma' if uri.startswith('admin') else ('Aplicação por tenant' if uri.startswith('t/') else 'Autenticação e rotas gerais'))
        route_groups.setdefault(group,[]).append(route)
    route_html='<p class="route-count">'+str(len(routes))+' rotas. O prefixo /t/{tenant} indica a empresa; nomes entre chaves são parâmetros.</p>'
    for group,items in route_groups.items():
        rows=[[f'<span class="route-method">{escape(r["method"])}</span>',f'<code>/{escape(r["uri"])}</code>',escape(r['name'] or '—'),'<br>'.join(escape(m.replace('App\\Http\\Middleware\\','').replace('Illuminate\\Auth\\Middleware\\','')) for m in r['middleware'])] for r in items]
        route_html+=f'<details class="route-group"><summary>{escape(group)} ({len(items)})</summary><div>'+table(['Método','Caminho','Nome','Middlewares'],rows)+'</div></details>'
    env={}
    for config in sorted((ROOT/'config').glob('*.php')):
        for name in re.findall(r"\benv\(\s*['\"]([A-Z][A-Z0-9_]+)['\"]",config.read_text(encoding='utf-8')):env.setdefault(name,set()).add(config.relative_to(ROOT).as_posix())
    env_html='<details class="env-list"><summary>Todas as variáveis lidas em config/*.php ('+str(len(env))+')</summary><div>'+table(['Variável','Arquivo de configuração'],[[f'<code>{escape(k)}</code>','<br>'.join(f'<code>{s}</code>' for s in sorted(v))] for k,v in sorted(env.items())])+'</div></details>'
    ids=set();refs=set();images=set();topics=0
    for ch in chapters:
        if ch['id'] in ids:raise ValueError('ID duplicado: '+ch['id'])
        ids.add(ch['id'])
        for a in ch['articles']:
            topics+=1
            if a['id'] in ids:raise ValueError('ID duplicado: '+a['id'])
            ids.add(a['id']);refs.update(a['sources'])
            a['body']=a['body'].replace('<div id="route-catalog"></div>',route_html).replace('<div id="credential-catalog"></div>',table(['Variável','Para quê','Onde fica'],[[f'<code>{escape(x[0])}</code>',escape(x[1]),escape(x[2])] for x in CREDENTIALS])).replace('<div id="env-catalog"></div>',env_html)
            for src in re.findall(r'<img[^>]+src="([^"]+)"',a['body']+a['technical']):
                if not src.startswith('assets/'):raise ValueError('Imagem remota inesperada: '+src)
                images.add(src)
    for src in sorted(images):
        target=DIST/src
        if target.suffix=='.png':shutil.copy2(ROOT/'public/images/tutorials'/target.name,target)
        if not target.is_file():raise FileNotFoundError(target)
    missing=[s for s in refs if not (ROOT/s).is_file()]
    if missing:raise FileNotFoundError('Fontes ausentes: '+', '.join(sorted(missing)))
    for name in ['app.js','styles.css']:shutil.copy2(BASE/name,DIST/name)
    meta={'reviewDate':'21/10/2026','generatedAt':datetime.now(timezone.utc).isoformat(),'baseCommit':git('rev-parse','HEAD'),'branch':git('branch','--show-current'),'hasLocalChanges':bool(git('status','--porcelain')),'chapters':len(chapters),'topics':topics,'routeCount':len(routes),'configurationNames':len(env)}
    data=json.dumps({'chapters':chapters,'meta':meta},ensure_ascii=False,separators=(',',':')).replace('</',r'<\/')
    navigation,static_content=static_portal(chapters,meta)
    script=(BASE/'app.js').read_text(encoding='utf-8').replace('</script',r'<\/script')
    styles=(BASE/'styles.css').read_text(encoding='utf-8')
    html=(BASE/'shell.html').read_text(encoding='utf-8')
    # Arquivo único: as imagens vão embutidas uma vez no conteúdo estático; o app.js reaproveita de lá.
    def inline(path):
        mime='image/svg+xml' if path.endswith('.svg') else 'image/png'
        return f'data:{mime};base64,'+base64.b64encode((DIST/path).read_bytes()).decode()
    static_content=re.sub(r'<img src="(assets/[^"]+)"',lambda m:f'<img data-asset="{m.group(1)}" src="{inline(m.group(1))}"',static_content)
    for marker,value in [('__DOCS_STYLES__',styles),('__DOCS_NAV__',navigation),('__DOCS_CONTENT__',static_content),('__DOCS_DATA__',data),('__DOCS_SCRIPT__',script),('__DOCS_ICON__',inline('assets/sigworks-mark.svg'))]:
        html=html.replace(marker,value)
    (DIST/'index.html').write_text(html,encoding='utf-8')
    manual=['# Manual do SigWorks','Revisão de 21/10/2026.','## Sumário']
    manual.extend(f'{i}. [{ch["title"]}](#{ch["id"]})' for i,ch in enumerate(chapters,1))
    for i,ch in enumerate(chapters,1):
        manual.extend([f'<a id="{ch["id"]}"></a>',f'## {i}. {ch["title"]}',ch['intro']])
        for j,a in enumerate(ch['articles'],1):
            parser=Markdown();parser.feed(a['body']+ ('<h4>Detalhes técnicos</h4>'+a['technical'] if a['technical'] else ''))
            manual.extend([f'### {i}.{j} {a["title"]}',parser.text()])
            if ch['group'] in TECHNICAL_GROUPS and a['sources']:manual.append('Código: '+', '.join('`'+s+'`' for s in a['sources']))
    (DIST/'manual.md').write_text('\n\n'.join(manual)+'\n',encoding='utf-8')
    audited=sorted(refs|{'docs/portal/routes-snapshot.json','docs/portal/shell.html','docs/portal/styles.css','docs/portal/app.js','docs/portal/diagrams.py'})
    audit={'snapshot':meta,'notes':['Texto conferido em fontes locais; infraestrutura em execução não auditada.','Capturas ilustrativas do acervo do projeto; nomenclaturas antigas podem aparecer.','Inventário de configuração extraído apenas de config/*.php; sem ler .env.'],'sources':[{'path':s,'sha256':hashlib.sha256((ROOT/s).read_bytes()).hexdigest()} for s in audited],'images':sorted(images),'externalAssets':[]}
    (DIST/'auditoria.json').write_text(json.dumps(audit,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    (DIST/'LEIA-ME.txt').write_text('Manual do SigWorks\n\nExtraia a pasta inteira e abra o index.html no navegador. Não precisa de internet.\nO manual.md tem o mesmo conteúdo em texto.\n\nRevisão de 21/10/2026.\n',encoding='utf-8')
    with zipfile.ZipFile(BASE/'sigworks-documentacao.zip','w',zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(DIST.rglob('*')):
            if path.is_file():archive.write(path,path.relative_to(DIST))
    validation={'structural':{'chapters':len(chapters),'topics':topics,'sourceFiles':len(audited),'localImages':len(images),'routes':len(routes),'configurationNames':len(env),'missingSources':0,'missingImages':0,'uniqueIds':True,'runtimeNetworkDependencies':0},'visualReview':'Pendente'}
    (BASE/'validacao.json').write_text(json.dumps(validation,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(validation['structural'],ensure_ascii=False))
    print('Portal: '+str(DIST/'index.html'))
    print('Pacote: '+str(BASE/'sigworks-documentacao.zip'))

if __name__=='__main__':main()
