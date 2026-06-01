import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    Activity,
    Bell,
    BookOpen,
    Building2,
    CheckCircle2,
    ChevronRight,
    CircleAlert,
    ClipboardList,
    Expand,
    FolderOpen,
    Image as ImageIcon,
    Lightbulb,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const screenshot = (file, title, caption) => ({
    src: `/images/tutorials/${file}.png`,
    title,
    caption,
});

const tutorials = [
    {
        id: 'primeiros-passos',
        group: 'Comece por aqui',
        title: 'Ordem recomendada de configuração',
        icon: BookOpen,
        path: 'Visão geral',
        summary: 'Prepare a estrutura do tenant antes de iniciar a operação dos contratos.',
        audience: 'Owner, administradores e gestores',
        prerequisites: [
            'Tenant criado pelo Super Admin.',
            'Owner com acesso ao ambiente da empresa.',
            'Dados básicos dos contratos disponíveis.',
        ],
        steps: [
            ['Confirme o tenant', 'O Super Admin cria o tenant e define o owner. O owner recebe uma senha provisória por e-mail e troca a senha no primeiro acesso.'],
            ['Crie o contrato', 'Cadastre código, valor, moeda, vigência, estado e cidade para abrir o ambiente de trabalho.'],
            ['Parametrize o contrato', 'Cadastre empresas, obras, disciplinas e vincule os usuários que poderão acessar o contrato.'],
            ['Distribua permissões', 'Defina o que cada usuário pode visualizar e operar dentro de cada contrato.'],
            ['Inicie a operação', 'Com a base pronta, utilize Atividades, Projetos e Qualidade conforme a rotina da obra.'],
        ],
        screenshots: [
            screenshot('contratos', 'Contratos como ponto de partida', 'A listagem concentra os ambientes de trabalho disponíveis para o usuário.'),
            screenshot('parametrizacao-empresas', 'Parametrização contratual', 'Cadastre a estrutura operacional antes de iniciar os módulos de execução.'),
        ],
        tips: [
            'Cadastre primeiro um contrato piloto e valide o fluxo com uma equipe pequena.',
            'Use códigos curtos e padronizados: eles reaparecem em filtros, relatórios e EAPs.',
        ],
        outcome: 'Ao finalizar, o tenant estará pronto para receber tarefas, projetos e RNCs sem cadastros incompletos.',
        related: ['configurando-tenant', 'criando-contrato', 'parametrizando-contrato'],
    },
    {
        id: 'configurando-tenant',
        group: 'Configuração inicial',
        title: 'Configurando o tenant',
        icon: Building2,
        path: 'Super Admin > Tenants',
        summary: 'Crie o ambiente da empresa gerenciadora e defina o primeiro usuário responsável.',
        audience: 'Super Admin da plataforma',
        prerequisites: [
            'Nome e CNPJ da empresa gerenciadora.',
            'E-mail válido do owner.',
            'Plano inicial definido.',
        ],
        steps: [
            ['Crie o tenant', 'Informe nome, identificador do tenant, CNPJ, plano e situação inicial.'],
            ['Defina o owner', 'Informe nome e e-mail do owner. O sistema gera a senha provisória e envia as instruções de acesso.'],
            ['Valide o primeiro acesso', 'O owner utiliza o link recebido e cria sua senha definitiva antes de acessar os módulos.'],
            ['Revise plano e situação', 'No painel Super Admin, mantenha plano e situação atualizados conforme o atendimento da empresa.'],
        ],
        screenshots: [
            screenshot('login', 'Primeiro acesso ao Deming', 'O owner recebe o endereço de acesso e utiliza as credenciais provisórias recebidas por e-mail.'),
        ],
        tips: [
            'O identificador do tenant é utilizado na URL: mantenha-o curto, único e sem espaços.',
            'Confirme o recebimento do e-mail antes de iniciar a implantação com a equipe.',
        ],
        outcome: 'O owner terá acesso ao ambiente isolado da empresa e poderá iniciar a configuração dos contratos.',
        related: ['primeiros-passos', 'criando-contrato', 'usuarios-permissoes'],
    },
    {
        id: 'criando-contrato',
        group: 'Configuração inicial',
        title: 'Criando um contrato',
        icon: ClipboardList,
        path: 'Contratos',
        summary: 'Abra um novo ambiente contratual com vigência, moeda e localização.',
        audience: 'Owner, administradores e gestores autorizados',
        prerequisites: [
            'Código oficial do contrato.',
            'Vigência, valor e moeda definidos.',
            'Estado e cidade da obra conhecidos.',
        ],
        steps: [
            ['Abra Contratos', 'No menu lateral, acesse Contratos e clique em Novo contrato.'],
            ['Preencha a identificação', 'Informe o código contratual. O código será utilizado na EAP dos projetos.'],
            ['Informe valor e moeda', 'Selecione Real, Dólar, Yen, Yuan ou Euro e preencha o valor conforme a formatação apresentada.'],
            ['Defina vigência e local', 'Selecione início, término, estado e cidade. As cidades são filtradas automaticamente após selecionar o estado.'],
            ['Conclua o cadastro', 'O novo contrato aparecerá na listagem e poderá receber parametrizações específicas.'],
        ],
        screenshots: [
            screenshot('contratos', 'Listagem de contratos', 'Use Novo contrato para iniciar o cadastro. Os cards resumem situação, prazo, cliente e construtora.'),
        ],
        tips: [
            'Evite alterar o código após iniciar a submissão de projetos.',
            'Confira a localização: ela ajuda a diferenciar contratos com nomes semelhantes.',
        ],
        outcome: 'O contrato aparecerá na listagem e poderá receber empresas, obras, disciplinas e usuários.',
        related: ['primeiros-passos', 'parametrizando-contrato'],
    },
    {
        id: 'parametrizando-contrato',
        group: 'Configuração inicial',
        title: 'Parametrizando um contrato',
        icon: SlidersHorizontal,
        path: 'Parametrização',
        summary: 'Cadastre a estrutura operacional que será usada pelos demais módulos.',
        audience: 'Owner e administradores com permissão',
        prerequisites: [
            'Contrato previamente cadastrado.',
            'Empresas participantes identificadas.',
            'Estrutura de obras e disciplinas alinhada com a equipe.',
        ],
        steps: [
            ['Cadastre as empresas', 'Em Empresas, informe nome, CNPJ, sigla, tipo e logo opcional. Utilize os filtros para localizar cadastros existentes.'],
            ['Cadastre as obras', 'Em Obras, selecione o contrato e informe nome, código e tipo. Obras filhas devem ser vinculadas a uma obra pai.'],
            ['Vincule empresas e obra', 'Em Contrato, selecione a obra principal e relacione cliente, construtora e gerenciadora conforme o contrato.'],
            ['Cadastre disciplinas', 'Em Disciplinas, crie as especialidades que serão usadas na organização e análise de projetos.'],
            ['Vincule usuários', 'Em Usuários x Contratos, escolha quais contratos cada usuário poderá acessar.'],
        ],
        screenshots: [
            screenshot('parametrizacao-empresas', 'Cadastro de empresas', 'A tela separa o cadastro à esquerda e a consulta das empresas existentes à direita.'),
        ],
        tips: [
            'Sempre confira o contrato selecionado antes de salvar um cadastro.',
            'Use siglas consistentes para melhorar a leitura das EAPs dos projetos.',
        ],
        outcome: 'O contrato terá a estrutura mínima para operar projetos, atividades e RNCs com dados separados corretamente.',
        related: ['criando-contrato', 'usuarios-permissoes', 'projetos'],
    },
    {
        id: 'usuarios-permissoes',
        group: 'Configuração inicial',
        title: 'Configurando usuários e permissões',
        icon: Users,
        path: 'Usuários e Permissões',
        summary: 'Crie acessos individuais e libere somente os módulos necessários para cada contrato.',
        audience: 'Owner e administradores',
        prerequisites: [
            'Empresas participantes cadastradas.',
            'Contratos disponíveis para vínculo.',
            'Responsabilidades de cada usuário alinhadas.',
        ],
        steps: [
            ['Crie o usuário', 'Em Usuários, informe nome, e-mail, empresa e papel profissional. O sistema envia uma senha provisória por e-mail.'],
            ['Vincule contratos', 'Em Parametrização > Usuários x Contratos, selecione os ambientes que o usuário poderá acessar.'],
            ['Abra Permissões', 'Escolha o usuário e o contrato. As permissões ficam organizadas por módulo para facilitar a revisão.'],
            ['Libere somente o necessário', 'Ative permissões de Atividades, Projetos, RNC, Usuários e Parametrização conforme a responsabilidade da pessoa.'],
            ['Revise acessos periodicamente', 'Desative usuários ou remova vínculos de contratos quando houver mudança de equipe.'],
        ],
        screenshots: [
            screenshot('usuarios', 'Cadastro e gestão de usuários', 'Novas contas recebem senha provisória por e-mail e podem ser editadas ou desativadas.'),
            screenshot('permissoes', 'Permissões por módulo e contrato', 'Selecione a pessoa e revise as ações permitidas em cada módulo.'),
        ],
        tips: [
            'O owner possui acesso integral; os demais usuários devem receber somente os acessos necessários.',
            'Faça uma revisão de acessos sempre que houver troca de equipe ou encerramento de contrato.',
        ],
        outcome: 'Cada usuário acessará apenas os contratos e operações compatíveis com sua responsabilidade.',
        related: ['parametrizando-contrato', 'atividades', 'projetos', 'rnc'],
    },
    {
        id: 'atividades',
        group: 'Operação',
        title: 'Módulo de Atividades',
        icon: Activity,
        path: 'Atividades',
        summary: 'Organize tarefas em quadro Kanban e acompanhe responsáveis, prazos e interações.',
        audience: 'Equipes operacionais e gestores',
        prerequisites: [
            'Usuários vinculados aos contratos.',
            'Permissões de visualizar ou criar atividades liberadas.',
            'Prazos e responsáveis definidos.',
        ],
        steps: [
            ['Crie a atividade', 'Informe título, contrato, prioridade, prazo e descrição.'],
            ['Selecione responsáveis', 'Pesquise usuários vinculados ao contrato e atribua uma ou mais pessoas.'],
            ['Acompanhe pelo Kanban', 'Arraste o card entre os status conforme o avanço da tarefa. O sistema registra a mudança e envia alertas.'],
            ['Centralize a comunicação', 'Abra o card para registrar comentários e anexar arquivos relacionados à atividade.'],
            ['Conclua a tarefa', 'Atividades concluídas deixam de aparecer após cinco dias, mas permanecem registradas no banco.'],
        ],
        screenshots: [
            screenshot('atividades', 'Quadro Kanban de atividades', 'Use os filtros para localizar tarefas e acompanhe o fluxo entre as quatro colunas.'),
        ],
        tips: [
            'Use descrições objetivas e um prazo verificável.',
            'Registre comentários dentro da atividade para preservar o histórico da decisão.',
        ],
        outcome: 'A equipe acompanhará pendências, responsáveis e prazos em um fluxo visual único.',
        related: ['usuarios-permissoes', 'notificacoes-perfil'],
    },
    {
        id: 'projetos',
        group: 'Operação',
        title: 'Módulo de Projetos',
        icon: FolderOpen,
        path: 'Projetos',
        summary: 'Submeta arquivos técnicos, processe no Autodesk APS e controle análise, aprovação e revisões.',
        audience: 'Coordenadores, projetistas, analistas e aprovadores',
        prerequisites: [
            'Obras, disciplinas e fases parametrizadas.',
            'Analistas e aprovadores cadastrados por disciplina.',
            'Arquivo técnico em um dos formatos aceitos.',
        ],
        steps: [
            ['Cadastre responsáveis', 'Em Projetos > Responsáveis, vincule analistas e aprovadores às disciplinas de cada contrato.'],
            ['Submeta o arquivo', 'Informe contrato, obra, disciplina, fase, tipo e sequencial. A EAP é montada automaticamente conforme o preenchimento.'],
            ['Aguarde o APS', 'Após a submissão, o processamento APS entra na fila automaticamente. Arquivos com falha podem ser reprocessados manualmente.'],
            ['Analise o projeto', 'O responsável abre o Checklist, verifica os itens técnicos e pode registrar comentários diretamente no viewer.'],
            ['Aprove para a árvore', 'Após análise e aprovação, o projeto aparece em Visualizar projetos. Antes disso, permanece fora da árvore principal.'],
            ['Controle revisões', 'Ao submeter uma nova revisão do mesmo sequencial, preencha a CAP com motivo, alterações e impactos.'],
            ['Inative quando necessário', 'Projetos aprovados podem ser inativados com justificativa. O histórico permanece preservado.'],
        ],
        screenshots: [
            screenshot('projetos', 'Submissão e consulta de projetos', 'A tela reúne filtros, status APS, submissão e acesso ao processo de análise.'),
        ],
        tips: [
            'Formatos aceitos: .dwg, .rvt, .ifc, .pdf, .dwfx e .dwf.',
            'O sequencial deve permanecer igual quando o arquivo for uma revisão do mesmo projeto.',
            'Preencha a CAP com atenção: ela preserva a justificativa técnica das alterações.',
        ],
        outcome: 'Os projetos aprovados aparecerão na árvore principal com histórico de versões, comentários e CAPs preservados.',
        related: ['parametrizando-contrato', 'usuarios-permissoes', 'notificacoes-perfil'],
    },
    {
        id: 'rnc',
        group: 'Operação',
        title: 'Módulo de RNC',
        icon: ShieldCheck,
        path: 'Qualidade',
        summary: 'Registre não conformidades e acompanhe o ciclo completo de resposta, análise e evidências.',
        audience: 'Qualidade, fiscalização, contratante e contratada',
        prerequisites: [
            'Obra e empresas vinculadas ao contrato.',
            'Usuários configurados em Qualidade > Alertas.',
            'Permissões de RNC distribuídas conforme o fluxo.',
        ],
        steps: [
            ['Configure alertas', 'Em Qualidade > Alertas, cadastre os usuários que receberão notificações das RNCs.'],
            ['Crie a RNC', 'Informe obra, empresas, data, localização, disciplina, gravidade, descrição, observações, recomendações e fotos.'],
            ['Notifique responsáveis', 'Após conferir a RNC, utilize Notificar para registrar o envio e disparar os alertas do sistema e e-mails.'],
            ['Receba a proposta', 'O responsável envia a proposta de ação corretiva, prazo de execução e arquivo compactado quando necessário.'],
            ['Analise a proposta', 'A proposta pode ser aprovada ou reprovada. Em caso de reprovação, o fluxo retorna para uma nova proposta.'],
            ['Registre evidências', 'Após aprovação, anexe fotos comentadas e documentos para comprovar a correção.'],
            ['Consulte histórico e PDF', 'A RNC finalizada mantém fluxo, responsáveis, imagens, proposta e evidências disponíveis para consulta.'],
        ],
        screenshots: [
            screenshot('rnc', 'Listagem de RNCs', 'Use Nova RNC para iniciar o fluxo. A listagem reúne status, disciplina, gravidade e ações disponíveis.'),
        ],
        tips: [
            'Use fotos claras e comentários por imagem para reduzir dúvidas na resposta.',
            'Acompanhe separadamente o prazo de resposta e o prazo de execução aprovado.',
        ],
        outcome: 'A não conformidade terá rastreabilidade desde a abertura até as evidências de correção e encerramento.',
        related: ['usuarios-permissoes', 'notificacoes-perfil'],
    },
    {
        id: 'notificacoes-perfil',
        group: 'Apoio',
        title: 'Notificações e perfil',
        icon: Bell,
        path: 'Sino de alertas e Configurações',
        summary: 'Acompanhe eventos importantes e mantenha seus dados pessoais atualizados.',
        audience: 'Todos os usuários',
        prerequisites: [
            'Conta ativa e senha definitiva cadastrada.',
            'E-mail atualizado no perfil.',
        ],
        steps: [
            ['Consulte notificações', 'Use o sino no topo da tela para abrir os alertas recebidos pelo seu usuário.'],
            ['Acesse o evento', 'Quando o alerta possuir link, abra o registro relacionado para continuar o fluxo.'],
            ['Atualize o perfil', 'Em Configurações, edite seus dados e adicione uma foto de perfil com enquadramento circular.'],
            ['Troque sua senha', 'Utilize uma senha forte e individual. Nunca compartilhe suas credenciais com outros usuários.'],
        ],
        screenshots: [
            screenshot('perfil', 'Configurações do perfil', 'Mantenha dados pessoais, foto e senha atualizados para facilitar a colaboração.'),
        ],
        tips: [
            'Use uma foto nítida: ela também aparece nos cards de atividades.',
            'Confira o sino de alertas ao iniciar o expediente.',
        ],
        outcome: 'O usuário manterá sua conta segura e terá acesso rápido aos eventos que exigem atenção.',
        related: ['usuarios-permissoes', 'atividades', 'projetos', 'rnc'],
    },
];

const rolloutSteps = [
    'Tenant',
    'Contrato',
    'Empresas e obras',
    'Disciplinas',
    'Usuários',
    'Permissões',
    'Operação',
];

function ScreenshotFigure({ image, onExpand }) {
    return (
        <figure className="overflow-hidden rounded-lg border border-[var(--border)] bg-white">
            <button
                type="button"
                className="group relative block w-full overflow-hidden bg-[var(--surface-muted)] text-left"
                onClick={() => onExpand(image)}
            >
                <img
                    src={image.src}
                    alt={image.title}
                    loading="lazy"
                    className="aspect-[16/10] w-full object-cover object-top transition duration-200 group-hover:scale-[1.015]"
                />
                <span className="absolute bottom-3 right-3 inline-flex items-center gap-1.5 rounded-md border border-white/80 bg-white/95 px-2.5 py-1.5 text-[11.5px] font-semibold text-[var(--ink-700)] shadow-sm">
                    <Expand size={13} />
                    Ampliar
                </span>
            </button>
            <figcaption className="border-t border-[var(--border)] px-4 py-3">
                <div className="text-[13px] font-semibold text-[var(--ink-900)]">{image.title}</div>
                <p className="mt-1 text-[12.5px] leading-5 text-[var(--ink-500)]">{image.caption}</p>
            </figcaption>
        </figure>
    );
}

function ScreenshotModal({ image, onClose }) {
    useEffect(() => {
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', handleKeyDown);

        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    if (!image) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[130] flex items-center justify-center bg-[rgba(11,16,32,0.72)] px-4 py-5" onMouseDown={onClose}>
            <section
                className="flex max-h-[94vh] w-full max-w-7xl flex-col overflow-hidden rounded-lg border border-white/20 bg-white shadow-[0_24px_80px_rgba(11,16,32,0.32)]"
                role="dialog"
                aria-modal="true"
                aria-label={image.title}
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <div className="eyebrow">Captura do sistema</div>
                        <h2 className="mt-1 text-base font-semibold text-[var(--ink-900)]">{image.title}</h2>
                        <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">{image.caption}</p>
                    </div>
                    <button type="button" className="sig-icon-btn shrink-0" onClick={onClose} title="Fechar imagem">
                        <X size={17} />
                    </button>
                </header>
                <div className="overflow-auto bg-[var(--surface-muted)] p-3">
                    <img src={image.src} alt={image.title} className="mx-auto h-auto max-w-full border border-[var(--border)] bg-white" />
                </div>
            </section>
        </div>
    );
}

export default function TutorialsIndex({ tenant }) {
    const [activeTutorialId, setActiveTutorialId] = useState('primeiros-passos');
    const [expandedImage, setExpandedImage] = useState(null);
    const [query, setQuery] = useState('');
    const filteredTutorials = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (!term) {
            return tutorials;
        }

        return tutorials.filter((tutorial) => [
            tutorial.title,
            tutorial.summary,
            tutorial.path,
            tutorial.group,
            ...tutorial.prerequisites,
            ...tutorial.steps.flat(),
            ...tutorial.tips,
        ].join(' ').toLowerCase().includes(term));
    }, [query]);
    const activeTutorial = tutorials.find((tutorial) => tutorial.id === activeTutorialId) || tutorials[0];
    const groupedTutorials = useMemo(
        () => filteredTutorials.reduce((groups, tutorial) => {
            groups[tutorial.group] = [...(groups[tutorial.group] || []), tutorial];

            return groups;
        }, {}),
        [filteredTutorials],
    );
    const ActiveIcon = activeTutorial.icon;

    const selectTutorial = (tutorialId) => {
        setActiveTutorialId(tutorialId);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Tutoriais" />

            <section className="sig-content grid gap-6">
                <header className="flex flex-wrap items-end gap-5">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <BookOpen size={15} />
                            <span className="eyebrow">Central de tutoriais</span>
                        </div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Guia de utilização do Deming</h1>
                        <p className="mt-1 max-w-3xl text-sm text-[var(--ink-500)]">
                            Consulte fluxos completos, boas práticas e telas reais da aplicação para implantar e operar cada módulo.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <span className="sig-pill sig-pill-blue">{tutorials.length} guias</span>
                        <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)]">{tenant.name}</span>
                    </div>
                </header>

                <section className="border-y border-[var(--border)] bg-white px-5 py-4">
                    <div className="eyebrow">Sequência recomendada para implantação</div>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        {rolloutSteps.map((step, index) => (
                            <div key={step} className="flex items-center gap-2">
                                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-[var(--primary-50)] text-[12px] font-bold text-[var(--primary)]">
                                    {index + 1}
                                </span>
                                <span className="text-[13px] font-semibold text-[var(--ink-700)]">{step}</span>
                                {index < rolloutSteps.length - 1 && <ChevronRight size={14} className="text-[var(--ink-300)]" />}
                            </div>
                        ))}
                    </div>
                </section>

                <section data-testid="tutorial-layout" className="tutorials-layout grid min-h-[720px] overflow-hidden border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
                    <aside className="border-b border-[var(--border)] bg-[var(--surface-muted)] p-4 xl:border-b-0 xl:border-r">
                        <label className="sig-input flex items-center gap-2 bg-white">
                            <Search size={15} className="text-[var(--ink-500)]" />
                            <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar tutorial" />
                        </label>

                        <div className="mt-4 grid gap-5">
                            {Object.entries(groupedTutorials).map(([group, items]) => (
                                <section key={group}>
                                    <div className="eyebrow px-2">{group}</div>
                                    <div className="mt-2 grid gap-1">
                                        {items.map((tutorial) => {
                                            const Icon = tutorial.icon;
                                            const active = tutorial.id === activeTutorial.id;

                                            return (
                                                <button
                                                    key={tutorial.id}
                                                    type="button"
                                                    className={`flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left transition ${active ? 'bg-[var(--primary-50)] text-[var(--primary)]' : 'text-[var(--ink-700)] hover:bg-white'}`}
                                                    onClick={() => selectTutorial(tutorial.id)}
                                                >
                                                    <Icon size={16} strokeWidth={1.8} />
                                                    <span className="min-w-0 flex-1 text-[13px] font-semibold">{tutorial.title}</span>
                                                    <ChevronRight size={14} className={active ? 'text-[var(--primary)]' : 'text-[var(--ink-300)]'} />
                                                </button>
                                            );
                                        })}
                                    </div>
                                </section>
                            ))}
                            {filteredTutorials.length === 0 && (
                                <p className="px-2 py-4 text-sm text-[var(--ink-500)]">Nenhum tutorial encontrado.</p>
                            )}
                        </div>
                    </aside>

                    <article className="min-w-0">
                        <header className="border-b border-[var(--border)] px-5 py-5 sm:px-6">
                            <div className="flex flex-wrap items-start gap-4">
                                <span className="flex h-11 w-11 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                    <ActiveIcon size={21} strokeWidth={1.8} />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="eyebrow">{activeTutorial.path}</div>
                                    <h2 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">{activeTutorial.title}</h2>
                                    <p className="mt-1 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">{activeTutorial.summary}</p>
                                </div>
                                <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)]">
                                    {activeTutorial.steps.length} etapas
                                </span>
                            </div>
                            <div className="mt-4 text-[12.5px] text-[var(--ink-500)]">
                                <span className="font-semibold text-[var(--ink-700)]">Indicado para:</span> {activeTutorial.audience}
                            </div>
                        </header>

                        <div className="grid gap-7 px-5 py-5 sm:px-6">
                            <section>
                                <div className="flex items-center gap-2 text-[var(--ink-700)]">
                                    <CircleAlert size={15} className="text-[var(--primary)]" />
                                    <h3 className="eyebrow">Antes de começar</h3>
                                </div>
                                <div className="mt-3 grid gap-2 md:grid-cols-2">
                                    {activeTutorial.prerequisites.map((item) => (
                                        <div key={item} className="flex gap-2 border-l-2 border-[var(--primary-100)] bg-[var(--surface-muted)] px-3 py-2 text-[12.5px] leading-5 text-[var(--ink-600)]">
                                            <CheckCircle2 size={15} className="mt-0.5 shrink-0 text-[var(--green)]" />
                                            <span>{item}</span>
                                        </div>
                                    ))}
                                </div>
                            </section>

                            <section>
                                <div className="flex items-center gap-2 text-[var(--ink-700)]">
                                    <ClipboardList size={15} className="text-[var(--primary)]" />
                                    <h3 className="eyebrow">Passo a passo</h3>
                                </div>
                                <div className="mt-2 grid gap-0">
                                    {activeTutorial.steps.map(([title, description], index) => (
                                        <section key={title} className="grid gap-3 border-b border-[var(--border)] py-4 last:border-b-0 sm:grid-cols-[44px_minmax(0,1fr)]">
                                            <span className="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--primary-50)] text-[12px] font-bold text-[var(--primary)]">
                                                {index + 1}
                                            </span>
                                            <div>
                                                <h4 className="text-[14px] font-semibold text-[var(--ink-900)]">{title}</h4>
                                                <p className="mt-1 text-[13.5px] leading-6 text-[var(--ink-500)]">{description}</p>
                                            </div>
                                        </section>
                                    ))}
                                </div>
                            </section>

                            <section>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex items-center gap-2 text-[var(--ink-700)]">
                                        <ImageIcon size={15} className="text-[var(--primary)]" />
                                        <h3 className="eyebrow">Telas do sistema</h3>
                                    </div>
                                    <span className="text-[12px] text-[var(--ink-500)]">Clique em uma imagem para ampliar</span>
                                </div>
                                <div className={`mt-3 grid gap-4 ${activeTutorial.screenshots.length > 1 ? 'lg:grid-cols-2' : ''}`}>
                                    {activeTutorial.screenshots.map((image) => (
                                        <ScreenshotFigure key={image.src} image={image} onExpand={setExpandedImage} />
                                    ))}
                                </div>
                            </section>

                            <section className="border-l-4 border-[var(--primary)] bg-[var(--primary-50)] px-4 py-4">
                                <div className="flex items-center gap-2 text-[var(--primary)]">
                                    <Lightbulb size={16} />
                                    <h3 className="eyebrow">Boas práticas</h3>
                                </div>
                                <ul className="mt-3 grid gap-2">
                                    {activeTutorial.tips.map((tip) => (
                                        <li key={tip} className="flex gap-2 text-[13px] leading-5 text-[var(--ink-700)]">
                                            <CheckCircle2 size={15} className="mt-0.5 shrink-0 text-[var(--primary)]" />
                                            <span>{tip}</span>
                                        </li>
                                    ))}
                                </ul>
                            </section>

                            <section className="border-t border-[var(--border)] pt-5">
                                <div className="eyebrow">Resultado esperado</div>
                                <p className="mt-2 text-[13.5px] leading-6 text-[var(--ink-600)]">{activeTutorial.outcome}</p>
                            </section>

                            <section className="border-t border-[var(--border)] pt-5">
                                <div className="eyebrow">Continue por aqui</div>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {activeTutorial.related.map((tutorialId) => {
                                        const tutorial = tutorials.find((item) => item.id === tutorialId);

                                        return (
                                            <button
                                                key={tutorial.id}
                                                type="button"
                                                className="sig-btn sig-btn-secondary sig-btn-sm"
                                                onClick={() => selectTutorial(tutorial.id)}
                                            >
                                                {tutorial.title}
                                                <ChevronRight size={13} />
                                            </button>
                                        );
                                    })}
                                </div>
                            </section>
                        </div>
                    </article>
                </section>
            </section>

            <ScreenshotModal image={expandedImage} onClose={() => setExpandedImage(null)} />
        </AuthenticatedLayout>
    );
}
