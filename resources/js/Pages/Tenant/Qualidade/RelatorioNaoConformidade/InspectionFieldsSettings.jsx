export default function InspectionFieldsSettings({ fields, optionsText, onOptionsChange, errors = {} }) {
    const contractIndex = fields.findIndex(field => field.key === 'document_contract');
    const errorsForOptions = Object.entries(errors).filter(([key]) => key.startsWith(`field_definitions.${contractIndex}.options`));
    return <section className="sig-card grid gap-5 p-5">
        <div><h2 className="text-base font-semibold">Campos do modelo</h2><p className="mt-2 text-sm text-[var(--ink-500)]">Os campos seguem o documento de referência e são preenchidos na abertura de cada RNC.</p></div>
        <dl className="grid gap-4 sm:grid-cols-2">{fields.map(field => <div key={field.key} className="rounded-lg border border-[var(--border)] p-4">
            <dt className="text-sm font-semibold">{field.label}</dt><dd className="mt-1 text-sm text-[var(--ink-500)]">{field.key === 'document_contract' ? 'Seleção entre as opções cadastradas abaixo' : field.type === 'select' ? field.options.join(' ou ') : 'Texto livre preenchido na RNC'}</dd>
        </div>)}</dl>
        <label className="grid gap-2 text-sm font-medium">Opções de contrato do documento
            <textarea className="sig-input min-h-28 w-full" rows={4} value={optionsText} onChange={event => onOptionsChange(event.target.value)} placeholder={'Ex.: Contrato 045/2026\nContrato 072/2026'} />
            <span className="text-xs font-normal text-[var(--ink-500)]">Cadastre uma opção por linha. Elas aparecerão no campo Contrato do documento ao criar novas RNCs. Até 30 opções, com 120 caracteres cada.</span>
        </label>
        {errorsForOptions.map(([key, error]) => <p key={key} role="alert" className="text-xs text-red-600">{error}</p>)}
    </section>;
}
