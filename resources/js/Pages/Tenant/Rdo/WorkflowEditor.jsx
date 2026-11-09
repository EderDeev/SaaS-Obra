export const partyLabels = { construtora: 'Construtora', gerenciadora: 'Gerenciadora', cliente: 'Cliente' };
export const workflowModels = [
    { value: 'construtora_gerenciadora_cliente', name: 'Construtora + Gerenciadora + Cliente', description: 'As duas partes registram em paralelo. A construtora encaminha e a gerenciadora continua seus registros durante a análise. O cliente dá a decisão final.', steps: 'Preenchimento em paralelo → Envio da construtora → Análise → Cliente' },
    { value: 'gerenciadora_construtora', name: 'Gerenciadora + Construtora', description: 'As duas partes registram em paralelo. A construtora encaminha e a gerenciadora continua seus registros, analisa e decide.', steps: 'Preenchimento em paralelo → Envio da construtora → Análise e aprovação' },
    { value: 'gerenciadora_cliente', name: 'Gerenciadora + Cliente', description: 'A gerenciadora preenche tempo, atividades, recursos, fotos e comentários. O cliente analisa, aprova ou devolve para ajustes.', steps: 'Gerenciadora preenche → Cliente analisa e aprova' },
    { value: 'gerenciadora', name: 'Somente Gerenciadora', description: 'A gerenciadora preenche e salva as frentes. Ao finalizar o RDO, os registros ficam bloqueados para edição.', steps: 'Gerenciadora preenche → Salvar frentes → Finalizar RDO' },
];
export const defaultWorkflow = { model: 'construtora_gerenciadora_cliente' };
export const normalizeWorkflow = value => ({ model: value.model || ((value.fill_parties || [value.fill_party]).includes('construtora') ? 'construtora_gerenciadora_cliente' : (value.review_parties || []).includes('cliente') ? 'gerenciadora_cliente' : 'gerenciadora') });

export default function WorkflowEditor({ value = defaultWorkflow, onChange, errors = {}, renderPreview }) {
    return <section className="sig-card grid gap-4 p-5">
        <div><h2 className="text-base font-semibold">Modelo de funcionamento do RDO</h2><p className="mt-1 text-sm text-[var(--ink-500)]">Escolha o preenchimento e o fluxo para os novos RDOs desta configuração. Os registros já criados preservam seu modelo.</p></div>
        <fieldset className="grid gap-3 lg:grid-cols-2 xl:grid-cols-4"><legend className="sr-only">Modelo de funcionamento</legend>
            {workflowModels.map(model => <div key={model.value} className={`flex min-w-0 flex-col rounded-xl border p-4 ${value.model === model.value ? 'border-blue-500 bg-blue-50/60 ring-1 ring-blue-500' : 'border-[var(--border)]'}`}><label className="flex flex-1 cursor-pointer items-start gap-3">
                <input type="radio" name="rdo-workflow-model" className="mt-1" value={model.value} checked={value.model === model.value} onChange={() => onChange({ model: model.value })} />
                <span><strong className="text-sm">{model.name}</strong><span className="mt-1 block text-sm text-[var(--ink-500)]">{model.description}</span><span className="mt-2 block text-xs font-medium text-blue-700">{model.steps}</span></span>
            </label>{renderPreview?.(model)}</div>)}
        </fieldset>
        {Object.entries(errors).filter(([key]) => key === 'workflow' || key.startsWith('workflow.')).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-600">{error}</p>)}
    </section>;
}
