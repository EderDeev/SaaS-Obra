export default function ModelFields({ fields = [], values = {}, errors = {}, onChange, name }) {
    if (!fields.length) return null;
    return <section className="rounded-xl border border-[var(--border)] bg-blue-50/30 p-4">
        <h2 className="text-sm font-semibold">Informações do modelo{name ? ` · ${name}` : ''}</h2>
        <p className="mt-1 text-xs text-[var(--ink-500)]">Preencha os dados específicos desta RNC.</p>
        <div className="mt-4 grid min-w-0 gap-4 sm:grid-cols-2">{fields.map((field) => {
            const common = { className: 'sig-input w-full', value: values[field.key] ?? '', required: field.required,
                onChange: (event) => onChange(field.key, event.target.value), 'aria-invalid': !!errors[`model_field_values.${field.key}`] };
            return <label key={field.key} className={`grid min-w-0 gap-2 text-sm ${field.type === 'textarea' ? 'sm:col-span-2' : ''}`}>
                <span className="font-medium">{field.label}{field.required ? ' *' : ' (opcional)'}</span>
                {field.type === 'select' || field.type === 'boolean'
                    ? <select {...common}><option value="">{field.type === 'select' && !field.options.length ? 'Nenhuma opção cadastrada' : 'Selecione'}</option>{(field.type === 'boolean' ? [{ value: 'yes', label: 'Sim' }, { value: 'no', label: 'Não' }] : field.options.map(value => ({ value, label: value }))).map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
                    : field.type === 'textarea' ? <textarea {...common} rows={3} maxLength={10000} />
                        : <input {...common} type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'} step={field.type === 'number' ? 'any' : undefined} maxLength={1000} placeholder={field.key === 'document_contract' ? 'Digite o contrato que deve constar no formulário' : field.key === 'document_km' ? 'Ex.: 12+500 ao 14+200' : undefined} />}
                {field.key === 'document_contract' && <span className="text-xs text-[var(--ink-500)]">{field.type === 'select' ? 'Selecione uma opção cadastrada na parametrização do documento. Esse campo é independente do contrato selecionado no sistema.' : 'Texto livre exibido no PDF. Pode ser diferente do contrato selecionado no sistema.'}</span>}
                {errors[`model_field_values.${field.key}`] && <span role="alert" className="text-xs text-red-600">{errors[`model_field_values.${field.key}`]}</span>}
            </label>;
        })}</div>
        {errors.model_field_values && <p role="alert" className="mt-2 text-xs text-red-600">{errors.model_field_values}</p>}
    </section>;
}
