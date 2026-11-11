import { useState } from 'react';
import { Building2, ClipboardList, Users, LayoutDashboard, ChartNoAxesCombined } from 'lucide-react';

const panels = {
    diretorias: {
        text: 'Tenant é o espaço de uma diretoria dentro do SigWorks. Ao selecionar uma diretoria, você trabalha no contexto dos contratos e acessos dela.',
        cards: [['CMAT N/NE', 'Diretoria Norte e Nordeste'], ['CMAT S/SE', 'Diretoria Sul e Sudeste'], ['CMAT Projetos', 'Diretoria de Projetos']],
    },
    contratos: {
        text: 'Cada diretoria reúne seus contratos. No contrato ficam as empresas participantes, obras, equipe, cadastros e configurações dos documentos e fluxos.',
        cards: [['FIS-MA-2026', 'Fiscalização e acompanhamento de obras'], ['615/2025 - DNIT', 'Supervisão de serviços rodoviários'], ['002/2026', 'Gerenciamento de projetos e serviços']],
        footer: 'Um contrato pode usar somente RDO, outro Qualidade e outro OS e Atividades. A operação acompanha a necessidade de cada contrato.',
    },
    usuarios: {
        text: 'As pessoas acessam com sua própria conta e participam dos contratos conforme os vínculos e permissões concedidos.',
        cards: [['Cliente', 'Acompanha as informações e participa das análises, aprovações e assinaturas configuradas.'], ['Gerenciadora · Concremat', 'Registra o acompanhamento, analisa documentos e coordena as rotinas do contrato.'], ['Construtoras', 'Registram a execução, enviam evidências e atendem às solicitações do fluxo.']],
        footer: 'O papel da empresa não libera todas as funções automaticamente. O acesso depende das permissões do usuário. Uma conta autorizada em várias diretorias pode alternar entre elas.',
    },
    modulos: {
        text: 'Os módulos conectam o planejamento, os registros de campo e o acompanhamento dos contratos.',
        cards: [['Atividades', 'Tarefas, checklists, prazos e acompanhamento.'], ['Orçamentos', 'Insumos, composições, quantidades e custos.'], ['Financeiro do Contrato', 'Previsões, faturamento e recebimentos.'], ['Medição', 'Boletins, quantitativos e análises.'], ['Liberação de Serviço', 'Solicitação e acompanhamento de liberações.'], ['Ordem de Serviço', 'Demanda, análise da supervisão e decisão do fiscal.'], ['Diário de Obra · RDO', 'Registros por obra, evidências e fluxos por modelo.'], ['Qualidade · IC e RNC', 'Instruções, não conformidades e comprovação das correções.'], ['Projetos', 'Arquivos, revisões, análises e visualização dos modelos.'], ['Documentação', 'Acervo, pesquisa, anexos e controle de acesso.']],
    },
    indicadores: {
        text: 'Os registros dos módulos se transformam em indicadores para acompanhar a evolução, as pendências e os resultados de cada contrato.',
    },
};
export const sigWorksSteps = [
    { id: 'diretorias', title: 'Tenants / Diretorias', role: 'Organização do ambiente', icon: Building2 },
    { id: 'contratos', title: 'Contratos', role: 'Contexto da operação', icon: ClipboardList },
    { id: 'usuarios', title: 'Usuários', role: 'Equipe e acessos', icon: Users },
    { id: 'modulos', title: 'Módulos', role: 'Rotinas do contrato', icon: LayoutDashboard },
    { id: 'indicadores', title: 'Indicadores', role: 'Performance dos contratos', icon: ChartNoAxesCombined },
].map(step => ({ ...step, preview: 'sigworks', description: panels[step.id].text }));

const dashboards = [
    { contract: 'FIS-MA-2026', area: 'Campo e qualidade', modules: [
        { name: 'Diário de Obra · RDO', metric: 'RDOs finalizados', groups: [['Finalizados', 24, '#315bff'], ['Pendentes', 6, '#f59e0b']], note: 'Os preenchimentos e as etapas do fluxo mostram quantos diários já foram finalizados e quantos precisam de acompanhamento.' },
        { name: 'Qualidade · RNC', metric: 'Problemas resolvidos', groups: [['Resolvidos', 6, '#059669'], ['Parcialmente resolvidos', 3, '#f59e0b'], ['Não resolvidos', 1, '#ef4444']], note: 'O resultado informado nas evidências permite avaliar se as correções resolveram os problemas registrados.' },
    ] },
    { contract: '615/2025 - DNIT', area: 'Serviços e entregas', modules: [
        { name: 'Ordem de Serviço', metric: 'OSs finalizadas', groups: [['Finalizadas', 12, '#315bff'], ['Aprovadas · em execução', 5, '#059669'], ['Em análise', 3, '#f59e0b']], note: 'As movimentações das OSs permitem distinguir o que está em análise, o que já foi aprovado e quais serviços foram concluídos.' },
        { name: 'Atividades', metric: 'Atividades concluídas', groups: [['Concluídas', 15, '#059669'], ['Em andamento', 3, '#315bff'], ['Em revisão', 2, '#f59e0b']], note: 'As mudanças de status do quadro mostram o avanço das tarefas e as entregas que ainda dependem da equipe.' },
    ] },
    { contract: '002/2026', area: 'Projetos e acompanhamento', modules: [
        { name: 'Projetos', metric: 'Projetos aprovados', groups: [['Aprovados', 14, '#315bff'], ['Em revisão', 6, '#f59e0b']], note: 'As análises e revisões ajudam a identificar os projetos liberados e os que ainda precisam de ajustes.' },
        { name: 'Diário de Obra · RDO', metric: 'RDOs finalizados', groups: [['Finalizados', 16, '#315bff'], ['Pendentes', 4, '#f59e0b']], note: 'Os registros diários ajudam a acompanhar a conclusão dos documentos e as pendências do contrato.' },
    ] },
];

function IndicatorsPreview() {
    const [active, setActive] = useState(0);
    const dashboard = dashboards[active];
    return <div className="sigworks-indicators">
        <nav className="tutorial-walkthrough-tabs" aria-label="Contratos dos indicadores">{dashboards.map((item, index) => <button key={item.contract} type="button" aria-pressed={active === index} onClick={() => setActive(index)}>{item.contract}</button>)}</nav>
        <div className="sigworks-indicators-contract" aria-live="polite"><strong>{dashboard.contract}</strong><span>{dashboard.area}</span></div>
        <div className="sigworks-indicators-grid">{dashboard.modules.map(module => {
            const total = module.groups.reduce((sum, [, count]) => sum + count, 0);
            const percentage = Math.round(module.groups[0][1] / total * 100);
            return <article className="sigworks-indicator" key={module.name}>
                <h3>{module.name}</h3>
                <div className="sigworks-indicator-summary"><div><span>{module.metric}</span><strong>{module.groups[0][1]} <small>de {total}</small></strong></div><div className="sigworks-indicator-ring" style={{ background: `conic-gradient(${module.groups[0][2]} ${percentage}%, #e9eef5 0)` }} role="img" aria-label={`${module.metric}: ${percentage}%`}><span>{percentage}%</span></div></div>
                <div className="sigworks-indicator-bars">{module.groups.map(([label, value, color]) => <div key={label}><div><span>{label}</span><b>{value}</b></div><div className="sigworks-indicator-track" aria-hidden="true"><span style={{ width: `${value / total * 100}%`, background: color }} /></div></div>)}</div>
                <p>{module.note}</p>
            </article>;
        })}</div>
        <div className="sigworks-indicators-source"><span>Registros da operação</span><b aria-hidden="true">→</b><span>Situações e resultados</span><b aria-hidden="true">→</b><span>Indicadores do contrato</span></div>
    </div>;
}

export default function SigWorksOverview({ step }) {
    const panel = panels[step.id];
    return <section className="sigworks-overview" aria-label={`Conheça: ${step.title}`}>
        <p className="sigworks-overview-intro">{panel.text}</p>
        {step.id === 'indicadores' ? <IndicatorsPreview /> : <div className="sigworks-overview-cards">{panel.cards.map(([title, text], index) => <article key={title}><span className="sigworks-overview-number">{String(index + 1).padStart(2, '0')}</span><h3>{title}</h3><p>{text}</p></article>)}</div>}
        {panel.footer && <p className="sigworks-overview-footer">{panel.footer}</p>}
    </section>;
}
