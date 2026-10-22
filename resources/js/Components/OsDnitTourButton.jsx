import { Link } from '@inertiajs/react';
import { Plane } from 'lucide-react';

export default function OsDnitTourButton({ tenantSlug }) {
    return <Link href={route('tenant.os-dnit.tour-preview', tenantSlug)} className="sig-btn sig-btn-secondary"><Plane size={16} />Iniciar tour</Link>;
}
