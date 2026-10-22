export function toggleOsDnitPermission(current = [], permission) {
    const removing = current.includes(permission);
    let next = removing ? current.filter((item) => item !== permission) : [...current, permission];
    if (removing && permission === 'view_orders') return [];
    const productWrites = ['create_products', 'edit_products', 'delete_products'];
    const settingsWrites = ['create_settings', 'edit_settings', 'delete_settings'];
    if (removing && permission === 'view_products') next = next.filter((item) => !productWrites.includes(item));
    if (removing && permission === 'view_settings') next = next.filter((item) => !settingsWrites.includes(item));
    if (next.length && !next.includes('view_orders')) next.push('view_orders');
    if (next.some((item) => productWrites.includes(item)) && !next.includes('view_products')) next.push('view_products');
    if (next.some((item) => settingsWrites.includes(item)) && !next.includes('view_settings')) next.push('view_settings');
    return next;
}
