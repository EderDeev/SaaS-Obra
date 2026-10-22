import OsDnitConfirmDialog from '@/Components/OsDnitConfirmDialog';
import { useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';

export default function OsDnitDeleteButton({ url, title, message, data = {}, label = 'Excluir' }) {
    const [open, setOpen] = useState(false);
    const form = useForm(data);
    return <div className="min-w-0">
        <button type="button" disabled={form.processing} onClick={() => { form.clearErrors(); setOpen(true); }} className="sig-btn sig-btn-secondary text-red-700" aria-label={label}><Trash2 size={15} />{form.processing ? 'Excluindo…' : 'Excluir'}</button>
        <OsDnitConfirmDialog open={open} title={title} message={message} icon={Trash2} confirmLabel="Confirmar exclusão" disabled={form.processing}
            onCancel={() => setOpen(false)} onConfirm={() => { setOpen(false); if (!form.processing) form.delete(url, { preserveScroll: true }); }} />
        {Object.keys(form.errors).length > 0 && <div role="alert" className="mt-2 max-w-sm text-sm text-red-700">{[...new Set(Object.values(form.errors))].map((error) => <p key={error}>{error}</p>)}</div>}
    </div>;
}
