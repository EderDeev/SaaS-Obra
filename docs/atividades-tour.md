# Tour de Atividades

Em `/atividades`, clique em **Iniciar tour**. O percurso tem 12 passos:
cadastro, checklist e responsáveis → quadro → execução/comentários → andamento
→ métricas. Usa o mesmo balão, progresso, destaques e controles do OS DNIT.

As telas de demonstração existentes são reutilizadas. Os registros têm IDs
fictícios; os handlers de criação, alteração, comentário, arquivo e movimentação
retornam sem gravar quando `tourMode` está ativo. O tour não concede permissões
nem exibe dados reais de contratos adicionais. A rota exige visualizar atividades.

O botão Voltar funciona também entre telas. O parâmetro `step` conserva o número
global na transição, e valores inválidos retornam ao primeiro passo da tela.
Fechar, Escape ou Concluir levam à listagem real.

- `resources/js/Support/activityTour.js`: texto, ordem e resolução do passo.
- `resources/js/Components/ActivityTour.jsx`: navegação entre telas.
- `resources/js/Components/GuidedModuleTour.jsx`: apresentação compartilhada.
- `resources/js/Pages/Tenant/Activities/Index.jsx`: cadastro, quadro e detalhe.
- `resources/js/Pages/Tenant/Activities/Metrics.jsx`: indicadores de demonstração.

O tour do OS DNIT usa um adaptador para o mesmo componente de apresentação.
