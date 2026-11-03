"""Build a read-only, offline technical reference from explicit source paths.

No .env, database access, network calls or application commands are used here.
The Laravel route registry must be exported separately to fontes/rotas.json.
"""
from __future__ import annotations

import hashlib
import html
import json
import re
import shutil
import subprocess
import sys
import zipfile
from collections import defaultdict
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

try:
    from markdown_it import MarkdownIt
except ImportError:
    raise SystemExit('Instale requirements.txt em um ambiente virtual Python antes de gerar.')

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
MOBILE = ROOT.parent / 'mobile-rnc'
DIST = HERE / 'dist'
CHAPTERS = ['README.md', *[p.name for p in sorted(HERE.glob('[0-9][0-9]-*.md'))],
            'inventario-rotas.md', 'inventario-banco.md', 'inventario-arquivos.md']


def write(path: Path, value: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(value, encoding='utf-8', newline='\n')


def relative(path: Path) -> str:
    return path.relative_to(ROOT).as_posix() if path.is_relative_to(ROOT) else '../mobile-rnc/' + path.relative_to(MOBILE).as_posix()


def source_link(path: Path) -> str:
    return f'[{relative(path)}](../../{relative(path)})'


def cell(value) -> str:
    return str(value or '—').replace('|', '\\|').replace('\n', ' ')


def git_info(path: Path) -> dict:
    def run(*args):
        result = subprocess.run(['git', '-C', str(path), *args], capture_output=True, text=True, encoding='utf-8', errors='replace')
        return result.stdout.strip() if result.returncode == 0 else None
    return {'commit_base': run('rev-parse', 'HEAD'), 'branch': run('branch', '--show-current'),
            'alteracoes_locais': run('status', '--porcelain')}


def inventory_routes() -> list[dict]:
    file = HERE / 'fontes/rotas.json'
    routes = json.loads(file.read_text(encoding='utf-8-sig'))
    lines = ['# Inventário de rotas', '',
             'Registro exportado do Laravel para esta revisão. Não executa endpoints, não comprova acesso de um usuário e não é um contrato OpenAPI.', '',
             f'**{len(routes)} rotas registradas.** Fonte: [rotas.json](fontes/rotas.json).', '']
    groups = defaultdict(list)
    for route in routes:
        uri = route['uri']
        group = 'API mobile' if uri.startswith('api/mobile') else 'Webhooks' if uri.startswith('api/webhooks') else 'Plataforma' if uri.startswith('admin') else 'Tenant' if uri.startswith('t/') else 'Autenticação e infraestrutura'
        groups[group].append(route)
    for group, records in groups.items():
        lines += [f'## {group}', '', '| Método | URI | Nome | Ação | Middleware |', '| --- | --- | --- | --- | --- |']
        for route in sorted(records, key=lambda r: (r['uri'], r['method'])):
            action = route.get('action', '')
            action_text = cell(action)
            controller = action.split('@')[0]
            if controller.startswith('App\\'):
                candidate = ROOT / 'app' / (controller[4:].replace('\\', '/') + '.php')
                if candidate.exists():
                    action_text = f'[{cell(action)}](../../{relative(candidate)})'
            middleware = route.get('middleware', [])
            if isinstance(middleware, list):
                middleware = ', '.join(middleware)
            lines.append(f"| {cell(route['method'])} | `{cell(route['uri'])}` | {cell(route.get('name'))} | {action_text} | {cell(middleware)} |")
        lines.append('')
    write(HERE / 'inventario-rotas.md', '\n'.join(lines))
    return routes


def php_block(text: str, opening: int) -> str:
    """Read closure braces, ignoring quoted strings and PHP comments."""
    depth, i, quote = 1, opening + 1, None
    start = i
    while i < len(text):
        char = text[i]
        if quote:
            if char == '\\':
                i += 2
                continue
            if char == quote:
                quote = None
        elif text.startswith('//', i) or char == '#':
            end = text.find('\n', i)
            i = len(text) if end == -1 else end
            continue
        elif text.startswith('/*', i):
            end = text.find('*/', i + 2)
            i = len(text) if end == -1 else end + 2
            continue
        elif char in "'\"":
            quote = char
        elif char == '{':
            depth += 1
        elif char == '}':
            depth -= 1
            if depth == 0:
                return text[start:i]
        i += 1
    raise ValueError('Bloco PHP sem fechamento na leitura estática')


def inventory_database() -> dict:
    tables = defaultdict(lambda: {'operations': [], 'columns': defaultdict(list)})
    types = set('id uuid ulid foreignId foreignUuid foreignUlid bigIncrements increments smallIncrements mediumIncrements tinyIncrements string text mediumText longText char boolean integer bigInteger smallInteger tinyInteger mediumInteger unsignedInteger unsignedBigInteger unsignedSmallInteger unsignedTinyInteger float double decimal unsignedDecimal date dateTime dateTimeTz timestamp timestampTz time timeTz year json jsonb enum set binary ipAddress macAddress rememberToken'.split())
    for path in sorted((ROOT / 'database/migrations').glob('*.php')):
        source = path.read_text(encoding='utf-8-sig')
        up = re.split(r'public\s+function\s+down\s*\(', source)[0]
        for match in re.finditer(r"Schema::(create|table)\(\s*['\"]([a-zA-Z0-9_]+)['\"]\s*,\s*(?:static\s+)?function[^\{]*\{", up):
            operation, name = match.group(1, 2)
            block = php_block(up, match.end() - 1)
            line = up.count('\n', 0, match.start()) + 1
            tables[name]['operations'].append({'operation': operation, 'source': relative(path), 'line': line})
            for column in re.finditer(r"\$table->(\w+)\(\s*['\"]([^'\"]+)['\"]([^;]*);", block):
                kind, name_column, chain = column.groups()
                if kind not in types:
                    continue
                flags = [flag for flag in ['nullable', 'unique', 'index', 'constrained', 'cascadeOnDelete', 'nullOnDelete', 'change'] if re.search(r'\b' + flag + r'\s*\(', chain)]
                tables[name]['columns'][name_column].append({'type': kind, 'flags': flags, 'source': relative(path)})
            for method, cols in [('id', ['id']), ('timestamps', ['created_at', 'updated_at']), ('timestampsTz', ['created_at', 'updated_at']), ('softDeletes', ['deleted_at']), ('softDeletesTz', ['deleted_at']), ('rememberToken', ['remember_token'])]:
                if re.search(r'\$table->' + method + r'\(\s*\)', block):
                    for col in cols:
                        tables[name]['columns'][col].append({'type': method, 'flags': [], 'source': relative(path)})
    serializable = {name: {'operations': value['operations'], 'columns': dict(value['columns'])} for name, value in sorted(tables.items())}
    lines = ['# Inventário de tabelas e declarações', '',
             '**Levantamento estático, sem conexão com banco.** Inclui `Schema::create` e `Schema::table` com nomes literais no trecho anterior a `down`. Colunas removidas/renomeadas, SQL bruto, loops e alterações condicionais podem exigir leitura manual. Os tipos abaixo são métodos do Schema Builder; não são introspecção dos tipos finais do PostgreSQL.', '',
             f'Foram identificados **{len(tables)} nomes de tabela** nas migrations. `change` indica alteração declarada; ocorrências repetidas preservam o histórico e não significam colunas duplicadas no banco.', '']
    for name, value in serializable.items():
        lines += [f'## {name}', '', '**Migrations relacionadas:**', '']
        for op in value['operations']:
            lines.append(f"- `{op['operation']}` — [{op['source']}](../../{op['source']}) (linha {op['line']}).")
        lines += ['', '| Campo declarado | Tipos/ocorrências | Características identificadas |', '| --- | --- | --- |']
        for col, declarations in sorted(value['columns'].items()):
            kinds = ', '.join(dict.fromkeys(item['type'] for item in declarations))
            flags = ', '.join(sorted({flag for item in declarations for flag in item['flags']}))
            lines.append(f'| `{col}` | {kinds} | {flags or "—"} |')
        if not value['columns']:
            lines.append('| — | Nenhuma coluna extraída automaticamente | Leia as migrations relacionadas |')
        lines.append('')
    write(HERE / 'inventario-banco.md', '\n'.join(lines))
    return serializable


def inventory_files() -> list[Path]:
    groups = {'Controllers': ('app/Http/Controllers', '*.php'), 'Middleware': ('app/Http/Middleware', '*.php'),
              'Models': ('app/Models', '*.php'), 'Support': ('app/Support', '*.php'), 'Serviços': ('app/Services', '*.php'),
              'Jobs': ('app/Jobs', '*.php'), 'Notificações': ('app/Notifications', '*.php'), 'Comandos': ('app/Console/Commands', '*.php'),
              'Páginas web': ('resources/js/Pages', '*.jsx'), 'Componentes': ('resources/js/Components', '*.jsx'),
              'Templates PDF': ('resources/views/pdf', '*.php'), 'Testes backend': ('tests', '*.php'),
              'Testes frontend': ('tests/Frontend', '*.mjs')}
    paths = []
    lines = ['# Inventário de arquivos', '', 'Lista gerada do workspace. Inclui arquivos locais ainda não publicados; não é uma lista de rotas ou de recursos habilitados.', '']
    for title, (folder, pattern) in groups.items():
        files = sorted((ROOT / folder).rglob(pattern))
        paths.extend(files)
        lines += [f'## {title} ({len(files)})', '']
        for file in files:
            source = file.read_text(encoding='utf-8-sig')
            functions = re.findall(r'public\s+function\s+(\w+)\s*\(', source)
            description = ' Métodos públicos: ' + ', '.join(f'`{f}`' for f in functions if not f.startswith('__')) + '.' if functions and title in ['Controllers', 'Serviços', 'Comandos'] else ''
            lines.append(f'- {source_link(file)}.{description}')
        lines.append('')
    lines += ['## Mobile', '']
    mobile_files = [MOBILE / name for name in ['App.tsx', 'app.json', 'eas.json', 'package.json', 'src/types.ts', 'src/config.ts', 'src/services/context.ts', 'src/services/sync.ts', 'src/storage/database.ts', 'scripts/test-mobile-sync.cjs']]
    for file in mobile_files:
        if file.exists():
            paths.append(file)
            lines.append(f'- {source_link(file)}.')
    write(HERE / 'inventario-arquivos.md', '\n'.join(lines) + '\n')
    return paths


def diagram(filename: str, title: str, cards: list[tuple[str, str]], footer: str) -> None:
    width, height = 1000, 130 + len(cards) * 95
    elements = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" role="img" aria-label="{html.escape(title)}">',
                '<rect width="100%" height="100%" rx="20" fill="#f4f7fb"/>',
                f'<text x="35" y="42" fill="#142b49" font-size="23" font-family="Arial,sans-serif" font-weight="bold">{html.escape(title)}</text>']
    for index, (heading, body) in enumerate(cards):
        y = 65 + index * 95
        elements += [f'<rect x="35" y="{y}" width="930" height="72" rx="10" fill="white" stroke="#cad6e5"/>',
                     f'<rect x="35" y="{y}" width="5" height="72" fill="#0b5fff"/>',
                     f'<text x="57" y="{y + 27}" fill="#142b49" font-size="18" font-family="Arial,sans-serif" font-weight="bold">{html.escape(heading)}</text>',
                     f'<text x="57" y="{y + 52}" fill="#4d6078" font-size="15" font-family="Arial,sans-serif">{html.escape(body)}</text>']
        if index < len(cards) - 1:
            elements.append(f'<text x="490" y="{y + 91}" font-size="20" fill="#0b5fff">↓</text>')
    elements.append(f'<text x="35" y="{height - 18}" fill="#4d6078" font-size="14" font-family="Arial,sans-serif">{html.escape(footer)}</text></svg>')
    write(HERE / 'assets' / filename, ''.join(elements))


def main() -> None:
    routes = inventory_routes()
    tables = inventory_database()
    sources = inventory_files()
    diagram('arquitetura.svg', 'SigWorks · componentes e caminho dos dados', [
        ('Interfaces', 'Navegador React/Inertia · Aplicativo Expo com SQLite offline'),
        ('Entrada HTTP no Laravel', 'Rotas → sessão/token → tenant → permissão → controller'),
        ('Regras e serviços', 'Models Eloquent · Support · geração de PDF · fluxos e snapshots'),
        ('Persistência e processos', 'PostgreSQL · arquivos locais/remotos · filas e scheduler'),
        ('Serviços externos', 'Autodesk APS · OpenSign · e-mail · OCR · mapas · assistente')],
        'Representação lógica do código; não confirma topologia da infraestrutura em produção.')
    diagram('escopos.svg', 'Identidade global · diretoria · contrato · operação', [
        ('Usuário global (users)', 'Uma pessoa pode acessar várias diretorias, conforme os vínculos ativos.'),
        ('Diretoria / Tenant (tenants + tenant_users)', 'Diretoria selecionada na sessão web ou associada ao token mobile.'),
        ('Contrato (contracts + contract_participants)', 'Pertence à diretoria; possui empresas participantes e obras vinculadas.'),
        ('Documento e etapa', 'Obra/frente · parte responsável · status · permissão · snapshot do modelo')],
        'A interface orienta; o backend revalida tenant, contrato, recurso e etapa em cada operação.')
    DIST.mkdir(exist_ok=True)
    shutil.copytree(HERE / 'assets', DIST / 'assets', dirs_exist_ok=True)
    shutil.copyfile(ROOT / 'public/brand/sigworks-mark.svg', DIST / 'assets/sigworks-mark.svg')
    shutil.copytree(HERE / 'fontes', DIST / 'fontes', dirs_exist_ok=True)
    write(DIST / 'index.html', '')
    md = MarkdownIt('commonmark', {'html': False}).enable('table')
    errors = []
    page_ids = {name: 'inicio' if name == 'README.md' else Path(name).stem for name in CHAPTERS}
    pages, navigation = [], []
    for name in CHAPTERS:
        file = HERE / name
        text = file.read_text(encoding='utf-8')
        tokens = md.parse(text)
        for token in tokens:
            for child in token.children or []:
                attribute = 'href' if child.type == 'link_open' else 'src' if child.type == 'image' else None
                if not attribute:
                    continue
                value = child.attrGet(attribute) or ''
                if re.match(r'^(https?:|mailto:|#)', value):
                    continue
                path_only, _, fragment = value.partition('#')
                target = (HERE / path_only).resolve()
                if not target.exists():
                    errors.append(f'{name}: referência ausente: {value}')
                if path_only == 'dist/index.html':
                    child.attrSet(attribute, '#inicio')
                elif target.parent == HERE and target.name in page_ids:
                    child.attrSet(attribute, '#' + page_ids[target.name])
                elif path_only.startswith(('assets/', 'fontes/')):
                    pass
                elif not path_only.startswith('dist/'):
                    child.attrSet(attribute, '../' + value)
        title_match = re.search(r'^# (.+)$', text, re.M)
        title = title_match.group(1) if title_match else name
        body = md.renderer.render(tokens, md.options, {})
        body = re.sub(r'<table>', '<div class="table-scroll"><table>', body).replace('</table>', '</table></div>')
        page_id = page_ids[name]
        pages.append(f'<section class="chapter" id="{page_id}" data-page><a class="source-md" href="markdown/{name}">Fonte em Markdown ↗</a>{body}</section>')
        navigation.append(f'<a href="#{page_id}" data-target="{page_id}">{html.escape(title)}</a>')
    if errors:
        raise SystemExit('\n'.join(errors))
    now = datetime.now(ZoneInfo('America/Sao_Paulo')).isoformat(timespec='seconds')
    shell = (HERE / 'shell.html').read_text(encoding='utf-8')
    shell = shell.replace('<!-- NAVIGATION -->', '\n'.join(navigation)).replace('<!-- CONTENT -->', '\n'.join(pages))
    shell = shell.replace('/* INLINE_CSS */', (HERE / 'styles.css').read_text(encoding='utf-8'))
    shell = shell.replace('/* INLINE_JS */', (HERE / 'app.js').read_text(encoding='utf-8'))
    write(DIST / 'index.html', shell)
    for name in CHAPTERS:
        write(DIST / 'markdown' / name, (HERE / name).read_text(encoding='utf-8').replace('(dist/index.html)', '(../index.html)'))
    shutil.copytree(HERE / 'assets', DIST / 'markdown/assets', dirs_exist_ok=True)
    shutil.copytree(HERE / 'fontes', DIST / 'markdown/fontes', dirs_exist_ok=True)
    for folder in ['app', 'config', 'routes', 'database/migrations']:
        sources += list((ROOT / folder).rglob('*.php'))
    sources += [ROOT / f for f in ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'AGENTS.md', 'railway.toml', 'railway/start-app.sh', 'railway/init-app.sh']]
    sources += [HERE / name for name in CHAPTERS] + [HERE / 'fontes/rotas.json', Path(__file__).resolve(), HERE / 'styles.css', HERE / 'app.js', HERE / 'shell.html', HERE / 'validate.mjs', HERE / 'requirements.txt']
    sources = sorted(set(sources))
    audit = {'generated_at': now, 'scope': 'workspace local; não é certificação de produção', 'application': git_info(ROOT), 'mobile': git_info(MOBILE),
             'counts': {'chapters': len(CHAPTERS), 'routes': len(routes), 'table_names': len(tables), 'source_files': len(sources)},
             'sources': [{'path': relative(p), 'sha256': hashlib.sha256(p.read_bytes()).hexdigest()} for p in sources if p.is_file()],
             'database_inventory': 'static migration declarations, not live database introspection'}
    write(DIST / 'auditoria.json', json.dumps(audit, ensure_ascii=False, indent=2))
    write(DIST / 'inventario-banco.json', json.dumps(tables, ensure_ascii=False, indent=2))
    checks = {'generated_at': now, 'status': 'passed', 'checks': {'markdown_local_references': 'passed', 'chapter_rendering': 'passed', 'route_snapshot_json': 'passed', 'assets_copied': 'passed', 'no_external_runtime_dependencies': 'passed'},
              'visual_browser_review': 'not performed by this generator', 'database_queries': False, 'production_access': False, 'counts': audit['counts']}
    if '--validate-ui' in sys.argv:
        result = subprocess.run(['node', str(HERE / 'validate.mjs')], cwd=ROOT, check=True, capture_output=True, text=True, encoding='utf-8')
        checks['dom_validation'] = json.loads(result.stdout)
    write(DIST / 'validacao.json', json.dumps(checks, ensure_ascii=False, indent=2))
    write(DIST / 'LEIA-ME.txt', 'SigWorks · documentação técnica\nAbra index.html. Extraia e preserve toda a pasta para consultar imagens e fontes.\nReferências de código-fonte exigem o checkout da aplicação.\nAuditoria e limites: auditoria.json, validacao.json e o capítulo inicial.\n')
    archive = HERE / 'sigworks-tecnica.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as package:
        for file in sorted(DIST.rglob('*')):
            if file.is_file():
                package.write(file, 'sigworks-tecnica/' + file.relative_to(DIST).as_posix())
    print(json.dumps({'status': 'passed', **audit['counts'], 'portal': str(DIST / 'index.html'), 'zip': str(archive)}, ensure_ascii=False))


if __name__ == '__main__':
    main()
