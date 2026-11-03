import { useState } from 'react';
import { ArrowLeft, ArrowRight, FileText, Save, Send } from 'lucide-react';

const steps = [
    { label: 'Identificar', title: 'Selecione o contrato e o lote', description: 'Comece pelo vínculo contratual da demanda.' },
    { label: 'Descrever', title: 'Explique a necessidade técnica', description: 'Informe rodovia, Km inicial, Km final, assunto e prazo pretendido.' },
    { label: 'Concluir', title: 'Confira e salve a demanda', description: 'Confira a referência SEI e os anexos. Salve e depois confirme o envio para análise.' },
];

export function ExampleField({ label, value, multiline = false, wide = false }) {
    return <label className={`tutorial-example-field ${wide ? 'wide' : ''}`}><span>{label}</span>{multiline ? <textarea readOnly value={value} rows={3} /> : <input readOnly value={value} />}</label>;
}

export default function OsDemandPreview({ editing = false }) {
    const [active, setActive] = useState(0);
    const step = steps[active];
    return <section className="tutorial-demand-walkthrough" aria-label={editing ? 'Passo a passo da correção da demanda' : 'Passo a passo da criação da demanda'}>
        <nav className="tutorial-walkthrough-tabs" aria-label="Passos da demanda">{steps.map((item, index) => <button key={item.label} type="button" aria-pressed={active === index} onClick={() => setActive(index)}>{item.label}</button>)}</nav>
        <div className="tutorial-walkthrough-heading" aria-live="polite"><span>{active + 1}</span><div><h3>{editing && active === 0 ? 'Confira o motivo recebido e o vínculo contratual' : step.title}</h3><p>{step.description}</p></div></div>
        <div className="tutorial-example-fields" data-example-step={active}>
            {active === 0 && <>
                {editing && <ExampleField label="Motivo da devolução" value="Detalhar o trecho e a necessidade de recuperação dos dispositivos de drenagem." multiline wide />}
                <ExampleField label="Número da solicitação" value="001/2026" />
                <ExampleField label="Lote de supervisão" value="Lote 03" />
                <ExampleField label="Contrato de supervisão" value="001-DNIT — Supervisão das obras da BR-316" multiline wide />
            </>}
            {active === 1 && <>
                <ExampleField label="Rodovia" value="BR-316/PA" />
                <ExampleField label="Prazo pretendido" value="20/11/2026" />
                <ExampleField label="Km inicial" value="12,00" />
                <ExampleField label="Km final" value="14,50" />
                <ExampleField label="Assunto da solicitação" value="Recuperação dos dispositivos de drenagem" multiline wide />
                <ExampleField label="Descrição da necessidade técnica" value="Foram identificadas sarjetas obstruídas e erosões junto às saídas de drenagem, entre os km 12,00 e 14,50. Solicita-se avaliação técnica e definição dos serviços necessários à recuperação do trecho." multiline wide />
                <ExampleField label="Justificativa / resultado esperado" value="Restabelecer o escoamento das águas pluviais, reduzir a evolução dos processos erosivos e preservar a segurança dos usuários da rodovia." multiline wide />
            </>}
            {active === 2 && <>
                <ExampleField label="Referência SEI / processo (opcional)" value="50600.012345/2026-10" wide />
                <div className="tutorial-example-attachment wide"><span>Anexos (opcional)</span><div><FileText size={22} /><div><strong>Relatório de vistoria — BR-316.pdf</strong><small>PDF · 2,4 MB</small></div></div></div>
                <div className="tutorial-example-actions wide"><button type="button" disabled className="sig-btn sig-btn-secondary sig-btn-sm"><Save size={16} />{editing ? 'Salvar alterações' : 'Salvar demanda'}</button><ArrowRight size={17} /><button type="button" disabled className="sig-btn sig-btn-primary sig-btn-sm"><Send size={16} />Enviar para análise</button></div>
            </>}
        </div>
        <div className="tutorial-walkthrough-navigation"><span>Passo {active + 1} de {steps.length}</span><div><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" disabled={active === 0} onClick={() => setActive(value => value - 1)}><ArrowLeft size={15} />Voltar</button><button type="button" className="sig-btn sig-btn-primary sig-btn-sm" disabled={active === steps.length - 1} onClick={() => setActive(value => value + 1)}>Próximo<ArrowRight size={15} /></button></div></div>
    </section>;
}
