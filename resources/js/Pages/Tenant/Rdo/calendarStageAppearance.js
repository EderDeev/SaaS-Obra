export const rdoStageColors = {
    construtora: { label: 'Construtora', badge: 'bg-orange-50 text-orange-700', border: '!border-l-orange-700', dot: 'bg-orange-700' },
    gerenciadora: { label: 'Gerenciadora', badge: 'bg-blue-50 text-blue-700', border: '!border-l-blue-600', dot: 'bg-blue-600' },
    cliente: { label: 'Cliente', badge: 'bg-emerald-50 text-emerald-700', border: '!border-l-emerald-700', dot: 'bg-emerald-700' },
    assinatura: { label: 'Assinatura', badge: 'bg-violet-50 text-violet-700', border: '!border-l-violet-600', dot: 'bg-violet-600' },
};

export function rdoCalendarStage(rdo) {
    if (rdo.status === 'arquivado' || ['aguardando_assinatura', 'pronto_assinatura', 'assinado'].includes(rdo.status)) return 'assinatura';
    if (rdo.status === 'em_aprovacao_cliente') return 'cliente';
    if (rdo.status === 'em_analise_gerenciadora') return 'gerenciadora';
    if (rdo.status === 'em_analise_construtora') return 'construtora';
    return rdo.fill_party === 'gerenciadora' ? 'gerenciadora' : 'construtora';
}
