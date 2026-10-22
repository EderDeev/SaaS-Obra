import assert from 'node:assert/strict';
import { toggleOsDnitPermission } from '../../resources/js/Support/osDnitPermissions.js';

let selected = toggleOsDnitPermission([], 'create_products');
assert.deepEqual(new Set(selected), new Set(['view_orders', 'view_products', 'create_products']));
selected = toggleOsDnitPermission(selected, 'view_products');
assert.deepEqual(selected, ['view_orders']);
assert.deepEqual(toggleOsDnitPermission(['view_orders', 'view_dashboard', 'edit_orders'], 'view_orders'), []);
assert.deepEqual(new Set(toggleOsDnitPermission([], 'view_dashboard')), new Set(['view_dashboard', 'view_orders']));
assert.deepEqual(toggleOsDnitPermission(['view_orders', 'create_orders', 'edit_orders'], 'create_orders'), ['view_orders', 'edit_orders']);

for (const write of ['create_settings', 'edit_settings', 'delete_settings']) {
    const granted = toggleOsDnitPermission([], write);
    assert.deepEqual(new Set(granted), new Set(['view_orders', 'view_settings', write]));
    assert.deepEqual(toggleOsDnitPermission(granted, 'view_settings'), ['view_orders']);
}
assert.deepEqual(new Set(toggleOsDnitPermission([], 'view_settings')), new Set(['view_orders', 'view_settings']));
