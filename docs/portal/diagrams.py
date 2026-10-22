"""Diagramas SVG do manual."""
from html import escape
import textwrap

class Diagram:
    def __init__(self, width, height, title, subtitle):
        self.parts=[f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" role="img"><title>{escape(title)}</title><defs><marker id="a" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#58708c"/></marker></defs><rect width="{width}" height="{height}" fill="#ffffff"/>']
        self.text(34,42,title,22,'#1f2328','600')
        if subtitle: self.text(34,69,subtitle,14,'#59636e')
    def text(self,x,y,label,size=15,color='#243e5c',weight='400',anchor='start'):
        self.parts.append(f'<text x="{x}" y="{y}" fill="{color}" font-family="Segoe UI,Arial,sans-serif" font-size="{size}" font-weight="{weight}" text-anchor="{anchor}">{escape(label)}</text>')
    def box(self,x,y,w,h,title,body='',tone='blue'):
        fill,stroke={'blue':('#f6f8fa','#afb8c1'),'dark':('#24476f','#24476f'),'green':('#eef7ee','#8cbf8c'),'amber':('#fff8e1','#d4a72c')}[tone]
        ink='#fff' if tone=='dark' else '#142c49'
        self.parts.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{fill}" stroke="{stroke}" stroke-width="1.5"/>')
        title_lines=textwrap.wrap(title,max(15,int((w-28)/9)))
        for i,line in enumerate(title_lines): self.text(x+16,y+29+i*21,line,17,ink,'700')
        for i,line in enumerate(textwrap.wrap(body,max(18,int((w-28)/7.6)))): self.text(x+16,y+53+(len(title_lines)-1)*21+i*19,line,14,'#dae7f9' if tone=='dark' else '#526982')
    def arrow(self,x1,y1,x2,y2,label='',via=None):
        points=[(x1,y1)]+(via or [])+[(x2,y2)]
        d='M '+' L '.join(f'{x} {y}' for x,y in points)
        self.parts.append(f'<path d="{d}" fill="none" stroke="#58708c" stroke-width="2.3" marker-end="url(#a)"/>')
        if label: self.text((x1+x2)/2,(y1+y2)/2-12,label,12,'#526982','600','middle')
    def save(self,path): path.write_text(''.join(self.parts)+'</svg>',encoding='utf-8')

def build_diagrams(directory):
    d=Diagram(1120,570,'Tenant, contrato e acesso','')
    d.box(34,116,240,102,'Conta da pessoa','Uma identidade global','dark')
    d.box(370,116,300,102,'Empresa Exemplo / tenant','Vínculo ativo e contexto da empresa')
    d.arrow(274,166,370,166,'vínculo')
    d.box(370,290,300,112,'Contrato CT-001','Participação ativa + permissão + papel quando exigido','green')
    d.box(748,290,300,112,'Contrato CT-002','Sem vínculo: acesso não concedido','amber')
    d.arrow(520,218,520,290)
    d.arrow(670,166,898,290,via=[(898,166)])
    d.box(370,457,678,73,'Dados do contrato','OS, atividades, projetos e documentos seguem suas regras próprias')
    d.arrow(520,402,520,457)
    d.text(34,478,'O endereço identifica o ambiente.',14)
    d.text(34,504,'A autorização decide o acesso.',14,'#142c49','700')
    d.save(directory/'tenant.svg')

    d=Diagram(1160,592,'Arquitetura do SigWorks','')
    d.box(34,119,250,108,'Navegador','React + Inertia + Tailwind','dark')
    d.box(386,119,350,108,'Servidor Laravel / PHP','Rotas, middlewares, controllers, permissões e regras')
    d.arrow(284,172,386,172,'HTTPS')
    d.box(860,119,265,108,'Provedores externos','APS, OpenAI, Brevo, OpenSign')
    d.arrow(736,172,860,172,'APIs')
    d.box(34,344,290,112,'Banco PostgreSQL','Usuários, vínculos, registros, eventos e metadados')
    d.box(427,344,290,112,'Armazenamento','Arquivos no disco/volume ou Blob configurado')
    d.box(819,344,306,112,'Filas + workers','Processamento pesado e comunicações assíncronas')
    d.arrow(472,227,178,344,via=[(472,280),(178,280)])
    d.arrow(562,227,572,344)
    d.arrow(657,227,970,344,via=[(657,280),(970,280)])
    d.text(34,523,'Banco e arquivos têm backups separados.',16,'#1f2328','600')
    d.save(directory/'arquitetura.svg')

    d=Diagram(1160,475,'Como o assistente monta a resposta','')
    positions=[34,265,496,727,958]
    titles=['Pergunta','Autorizar','Recuperar','Gerar','Revisar']
    bodies=['Usuário, tenant e tela atual','Contrato, módulo, registro e cota','SQL + guias; busca por termos e prioridade','Contexto limitado enviado à API de IA','Resposta e proposta de ação validada']
    for x,title,body in zip(positions,titles,bodies): d.box(x,139,170,150,title,body,'dark' if title=='Pergunta' else 'blue')
    for i in range(4): d.arrow(positions[i]+170,209,positions[i+1],209)
    d.box(265,347,863,86,'Quem salva é o usuário','O chat só preenche o formulário; salvar e aprovar continuam nas telas.','green')
    d.arrow(1043,289,1043,347)
    d.save(directory/'rag.svg')

    d=Diagram(1160,554,'Fluxo da OS DNIT','')
    d.box(34,127,228,142,'Analista DNIT','Cria rascunho, revisa e envia','dark')
    d.box(358,127,275,142,'Fiscal Concremat','Comenta, estima prazo e analisa produtos e quantidades')
    d.box(729,127,275,142,'Fiscal do contrato','Compara as propostas e decide')
    d.arrow(262,193,358,193,'confirmação')
    d.arrow(633,193,729,193,'encaminhar')
    d.box(729,388,275,105,'Aprovada','Fluxo encerrado','green')
    d.arrow(866,269,866,388,'aprovar')
    d.arrow(760,269,494,269,via=[(760,319),(494,319)])
    d.text(590,308,'Devolver à Concremat',12,'#526982','600','middle')
    d.arrow(1004,196,148,269,'',via=[(1080,196),(1080,356),(148,356)])
    d.text(357,379,'Devolver ao analista: problemas no pedido.',14)
    d.text(34,451,'Depois de enviado, o pedido não pode mais ser editado.',16,'#1f2328','600')
    d.text(34,480,'Toda devolução precisa de comentário e destino.',14)
    d.save(directory/'os-dnit.svg')

    d=Diagram(1160,325,'Do arquivo ao visualizador Autodesk','')
    names=['Arquivo original','Fila APS','OSS + conversão','Manifesto','Viewer']
    bodies=['Cadastro e revisão','Worker inicia envio','Autodesk processa','Sucesso ou falha','Modelo convertido']
    for i,(name,body) in enumerate(zip(names,bodies)):
        x=34+i*231;d.box(x,126,170,115,name,body,'dark' if i==0 else 'blue')
        if i<4: d.arrow(x+170,184,x+231,184)
    d.text(34,292,'O navegador recebe só um token de visualização; o client secret fica no servidor.',15)
    d.save(directory/'aps.svg')

    d=Diagram(1160,440,'Caminho de uma alteração até produção','')
    names=['Alteração','Pull Request','CI','Homologação','Produção']
    bodies=['Branch de trabalho','Revisão da equipe','Testes + build','Validar a jornada','Versão aprovada']
    for i,(name,body) in enumerate(zip(names,bodies)):
        x=34+i*231;d.box(x,126,170,115,name,body,'dark' if i==0 else 'blue')
        if i<4:d.arrow(x+170,184,x+231,184)
    d.box(265,312,863,85,'Antes de publicar, conferir','Banco, arquivos, variáveis, migrations e configuração do ambiente.','green')
    d.save(directory/'cicd.svg')

    d=Diagram(1160,574,'Serviços externos','')
    d.box(421,226,290,124,'SigWorks / backend','Autorização, regras e dados do contrato','dark')
    d.box(34,121,260,112,'Autodesk APS','Upload, conversão e visualização')
    d.box(34,366,260,112,'OpenAI','Resposta ao contexto autorizado')
    d.box(842,121,280,112,'Brevo / IMAP','Enviar avisos / receber e-mails')
    d.box(842,366,280,112,'OpenSign','Solicitação e eventos de assinatura')
    d.box(421,432,290,100,'Azure Blob','Armazenamento quando selecionado')
    d.arrow(421,256,294,177)
    d.arrow(421,318,294,422)
    d.arrow(711,256,842,177)
    d.arrow(711,318,842,422)
    d.arrow(565,350,565,432)
    d.text(421,133,'Mapbox no navegador',17,'#1f2328','600')
    d.text(421,161,'Token público, usado só para o mapa.',14)
    d.save(directory/'integracoes.svg')
