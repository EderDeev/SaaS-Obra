import assert from 'node:assert/strict';
import { activityTourSteps, resolveActivityTourIndex } from '../../resources/js/Support/activityTour.js';

// Links diretos inválidos ou de outra tela não podem pular para um alvo inexistente.
for (const requested of [null, '', '-1', '9999', 'NaN', '1.5', '10']) {
    assert.equal(resolveActivityTourIndex('create', requested), 0);
}
assert.equal(resolveActivityTourIndex('detail', '7'), 7);
assert.equal(resolveActivityTourIndex('detail', '0'), 5);
assert.equal(resolveActivityTourIndex('metrics', null), 10);
assert.equal(resolveActivityTourIndex('metrics', '11'), 11);

// O mesmo contador deve ser preservado ao sair e voltar entre componentes Inertia.
assert.equal(activityTourSteps[4].section, 'board');
assert.equal(resolveActivityTourIndex('create', '3'), 3);
assert.equal(resolveActivityTourIndex('board', '4'), 4);
assert.equal(resolveActivityTourIndex('detail', '5'), 5);
assert.equal(resolveActivityTourIndex('flow', '8'), 8);
assert.equal(activityTourSteps[8].status, 'in_progress');
assert.equal(activityTourSteps[9].status, 'done');
assert.equal(activityTourSteps.at(-1).section, 'metrics');
console.log('Tour Atividades: links diretos, retorno entre telas e situações de demonstração OK.');
