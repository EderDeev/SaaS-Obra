import { useState } from 'react';
import { ArrowLeft, ArrowRight, CheckCircle, Send } from 'lucide-react';
import { ExampleField } from './OsDemandPreview';

const demand = [['Número da solicitação', '001/2026'], ['Lote de supervisão', 'Lote 03'], ['Assunto da solicitação', 'Recuperação dos dispositivos de drenagem'], ['Rodovia / trecho', 'BR-316/PA · km 12,00 ao km 14,50']];
const analysis = 'A vistoria confirmou obstruções nas sarjetas e erosões nas saídas de drenagem. Recomenda-se o levantamento dos dispositivos e o detalhamento das intervenções de recuperação.';
const approval = 'Demanda e produtos compatíveis com a necessidade identificada. Serviço autorizado conforme a análise da supervisão.';
const returnReason = 'Revisar o quantitativo do levantamento e incluir os dispositivos de saída de drenagem no trecho entre os km 12,00 e 14,50.';
const phases = {
    entrega: [
        { label: 'Entregar', title: 'Entrega do produto', fields: [['Arquivo do produto', 'Relatorio-de-drenagem.pdf'], ['Comentário sobre a entrega', 'Entregue o relatório de levantamento e recuperação dos dispositivos de drenagem do km 12,00 ao km 14,50.']], attachment: true, action: 'Enviar entrega' },
    ],
    avaliacao: [
        { label: 'Avaliar', title: 'Avaliação da entrega', fields: [['Arquivo recebido', 'Relatorio-de-drenagem.pdf'], ['Comentário da supervisão', 'Entregue o relatório de levantamento e recuperação dos dispositivos de drenagem do km 12,00 ao km 14,50.']], deliveryDecision: true },
    ],
    supervisao: [
        { label: 'Conferir', title: 'Confira a solicitação do analista', description: 'Leia a necessidade técnica e o resultado esperado antes de definir os produtos.', fields: demand },
        { label: 'Analisar', title: 'Defina os produtos e registre a análise', description: 'Selecione os produtos do contrato e informe quantidades e prazo estimado.', products: true, fields: [['Prazo estimado pela Supervisão', '27/11/2026'], ['Comentário da análise', analysis]] },
        { label: 'Encaminhar', title: 'Encaminhe a análise ao fiscal', description: 'Confira os dados e confirme o envio para decisão do fiscal do contrato.', fields: [['Destino', 'Fiscal do contrato'], ['Confirmação', 'Encaminhar a análise da OS 001/2026 ao fiscal do contrato?']], action: 'Confirmar envio' },
    ],
    'ajuste-supervisao': [
        { label: 'Receber', title: 'Leia o motivo da devolução', description: 'A correção dos produtos retorna à supervisão.', fields: [['Motivo da reprovação', returnReason], ['Destino da devolução', 'Supervisão']] },
        { label: 'Corrigir', title: 'Ajuste os produtos e as quantidades', description: 'Revise os itens indicados pelo fiscal e atualize sua avaliação técnica.', products: true, fields: [['Prazo estimado pela Supervisão', '27/11/2026'], ['Comentário da análise', 'Quantitativo revisado para contemplar todas as saídas de drenagem do trecho. Foram incluídos os dispositivos identificados na vistoria complementar.']] },
        { label: 'Reenviar', title: 'Reencaminhe ao fiscal', description: 'Confirme o novo envio após atender aos ajustes solicitados.', fields: [['Destino', 'Fiscal do contrato'], ['Confirmação', 'Reencaminhar a análise revisada da OS 001/2026?']], action: 'Confirmar envio' },
    ],
    fiscal: [
        { label: 'Conferir', title: 'Confira a demanda recebida', description: 'Verifique o objeto, a localização e a justificativa do analista.', fields: demand },
        { label: 'Comparar', title: 'Confira a análise da supervisão', description: 'Avalie os produtos, as quantidades e o prazo proposto.', products: true, fields: [['Prazo estimado pela Supervisão', '27/11/2026'], ['Comentário da análise', analysis]] },
        { label: 'Decidir', title: 'Aprove ou devolva com justificativa', description: 'Veja um exemplo de cada decisão. A devolução exige escolher quem deve corrigir.', decision: true },
    ],
    decisao: [
        { label: 'Avaliar', title: 'Verifique se a OS pode ser autorizada', description: 'A decisão considera a demanda e a análise técnica da supervisão.', products: true, fields: [['Assunto da solicitação', 'Recuperação dos dispositivos de drenagem'], ['Comentário da análise', analysis]] },
        { label: 'Decidir', title: 'Registre a decisão e o comentário', description: 'Ao devolver, indique o motivo e o responsável pelo ajuste.', decision: true },
        { label: 'Confirmar', title: 'Confirme a decisão escolhida', description: 'A aprovação autoriza a execução. A devolução retorna ao responsável indicado.', fields: [['Decisão', 'Aprovar serviço'], ['Comentário da decisão', approval], ['Confirmação', 'Confirma a aprovação da OS 001/2026?']], action: 'Confirmar aprovação' },
    ],
    aprovada: [
        { label: 'Consultar', title: 'Consulte a OS aprovada', description: 'O fiscal autorizou o serviço, que ainda aguarda a conclusão da execução.', fields: [['Número da solicitação', '001/2026'], ['Situação', 'Aprovada'], ['Assunto da solicitação', 'Recuperação dos dispositivos de drenagem']] },
        { label: 'Executar', title: 'Acompanhe o serviço autorizado', description: 'Use os produtos e o prazo definidos na análise da supervisão como referência.', products: true, fields: [['Prazo estimado pela Supervisão', '27/11/2026'], ['Comentário da decisão', approval]] },
        { label: 'Concluir', title: 'Aguarde a conclusão do serviço', description: 'Quando o produto estiver pronto, a supervisão envia os documentos e as considerações ao analista DNIT solicitante.', fields: [['Situação atual', 'Aprovada · aguardando conclusão'], ['Próxima etapa', 'Supervisão entrega → Analista DNIT avalia']] },
    ],
    finalizada: [
        { label: 'Conferir', title: 'Confira a conclusão do serviço', description: 'A aprovação da entrega pelo analista DNIT solicitante finaliza a OS automaticamente.', fields: [['Número da solicitação', '001/2026'], ['Situação antes do aceite', 'Aguardando avaliação do analista DNIT'], ['Assunto da solicitação', 'Recuperação dos dispositivos de drenagem']] },
        { label: 'Confirmar', title: 'Confirme o aceite da entrega', description: 'A confirmação da aprovação encerra a OS e registra o responsável, a data e as considerações.', fields: [['Título da confirmação', 'Aprovar entrega?'], ['Confirmação', 'Ao confirmar o aceite, a OS será finalizada automaticamente.']], action: 'Confirmar aprovação' },
        { label: 'Consultar', title: 'Consulte a OS finalizada', description: 'Esta é a última etapa do fluxo. Os dados e as análises ficam disponíveis para consulta.', fields: [['Número da solicitação', '001/2026'], ['Situação', 'Finalizada'], ['Fluxo', 'Demanda → Supervisão → Fiscal → Entrega → Analista DNIT → Finalizada']] },
    ],
};

function Products() {
    return <div className="tutorial-example-products wide"><h4>Produtos selecionados</h4>{[['Levantamento dos dispositivos de drenagem', '2,50 km'], ['Detalhamento da recuperação de drenagem', '1,00 un']].map(([name, quantity]) => <div key={name}><span>{name}</span><strong>{quantity}</strong></div>)}</div>;
}

export default function OsStagePreview({ stage }) {
    const [active, setActive] = useState(0);
    const [decision, setDecision] = useState('approve');
    const [destination, setDestination] = useState('Supervisão');
    const [acceptDelivery, setAcceptDelivery] = useState(true);
    const steps = phases[stage];
    const step = steps[active];
    return <section className="tutorial-os-walkthrough" data-os-walkthrough={stage} aria-label="Passo a passo da etapa da OS">
        {steps.length > 1 && <nav className="tutorial-walkthrough-tabs" aria-label="Passos da etapa">{steps.map((item, index) => <button key={item.label} type="button" aria-pressed={active === index} onClick={() => setActive(index)}>{item.label}</button>)}</nav>}
        <div className="tutorial-walkthrough-heading" aria-live="polite">{steps.length > 1 && <span>{active + 1}</span>}<div><h3>{step.title}</h3>{step.description && <p>{step.description}</p>}</div></div>
        <div className="tutorial-example-fields" data-example-phase={active}>
            {step.products && <Products />}
            {step.attachment && <div className="tutorial-example-actions wide"><button type="button" disabled className="sig-btn sig-btn-secondary sig-btn-sm">Anexar arquivo</button></div>}
            {step.fields?.map(([label, value]) => <ExampleField key={label} label={label} value={value} wide={value.length > 45} multiline={value.length > 45} />)}
            {step.deliveryDecision && <>
                <div className="tutorial-example-decision wide" role="group" aria-label="Avaliação da entrega do produto"><button type="button" aria-pressed={acceptDelivery} onClick={() => setAcceptDelivery(true)}>Aprovar entrega</button><button type="button" aria-pressed={!acceptDelivery} onClick={() => setAcceptDelivery(false)}>Não aprovar</button></div>
                <ExampleField label={acceptDelivery ? 'Considerações sobre a entrega' : 'Considerações e motivo da revisão'} value={acceptDelivery ? 'A entrega contempla os dispositivos solicitados e atende à necessidade técnica da demanda.' : 'A entrega não contempla duas saídas de drenagem. Complementar o levantamento e atualizar as pranchas e a memória de cálculo.'} multiline wide />
                <div className="tutorial-example-actions wide"><button type="button" disabled className="sig-btn sig-btn-primary sig-btn-sm">{acceptDelivery ? 'Confirmar aprovação da entrega' : 'Confirmar devolução para revisão'}</button></div>
            </>}
            {step.decision && <>
                <div className="tutorial-example-decision wide" role="group" aria-label="Exemplo de decisão">{[['approve', 'Aprovar serviço'], ['return', 'Reprovar e devolver']].map(([value, label]) => <button key={value} type="button" aria-pressed={decision === value} onClick={() => setDecision(value)}>{label}</button>)}</div>
                {decision === 'return' && <div className="tutorial-example-decision wide" role="group" aria-label="Destino da devolução">{['Supervisão', 'Analista DNIT'].map(value => <button key={value} type="button" aria-pressed={destination === value} onClick={() => setDestination(value)}>{value}</button>)}</div>}
                <ExampleField label={decision === 'return' ? 'Motivo da reprovação' : 'Comentário da decisão'} value={decision === 'return' ? destination === 'Supervisão' ? returnReason : 'Detalhar a necessidade técnica e confirmar os quilômetros inicial e final da demanda.' : approval} multiline wide />
                <div className="tutorial-example-actions wide"><button type="button" disabled className="sig-btn sig-btn-primary sig-btn-sm"><CheckCircle size={16} />{decision === 'return' ? 'Confirmar devolução' : 'Confirmar aprovação'}</button></div>
            </>}
            {step.action && <div className="tutorial-example-actions wide"><button type="button" disabled className="sig-btn sig-btn-primary sig-btn-sm"><Send size={16} />{step.action}</button></div>}
        </div>
        {steps.length > 1 && <div className="tutorial-walkthrough-navigation"><span>Passo {active + 1} de {steps.length}</span><div><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" disabled={active === 0} onClick={() => setActive(value => value - 1)}><ArrowLeft size={15} />Voltar</button><button type="button" className="sig-btn sig-btn-primary sig-btn-sm" disabled={active === steps.length - 1} onClick={() => setActive(value => value + 1)}>Próximo<ArrowRight size={15} /></button></div></div>}
    </section>;
}
