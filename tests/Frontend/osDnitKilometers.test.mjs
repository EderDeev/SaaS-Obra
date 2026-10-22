import assert from 'node:assert/strict';
import { formatKilometers, kilometersFromPaste, kilometersFromTyping, normalizeKilometers } from '../../resources/js/Support/osDnitKilometers.js';

// Exercise successive keystrokes using the displayed value, as a controlled
// input does, and verify the exact value sent to Laravel (not the masked text).
let stored = '';
const displayed = [];
for (const digit of '453') {
    stored = kilometersFromTyping(formatKilometers(stored) + digit);
    displayed.push(formatKilometers(stored));
}
assert.deepEqual(displayed, ['0,04', '0,45', '4,53']);
assert.equal(stored, '4.53');
assert.equal(kilometersFromTyping('4,5'), '0.45', 'Backspace moves the digits back through the decimal places');
assert.equal(kilometersFromTyping(''), '');
assert.equal(formatKilometers(normalizeKilometers('453.000')), '453,00', 'Opening a saved draft must not shift its magnitude');
assert.equal(formatKilometers('4.89'), '4,89');
assert.equal(formatKilometers('4.765'), '4,77', 'Round existing three-place values to two decimal places');
assert.equal(formatKilometers('4.999'), '5,00');
assert.equal(formatKilometers(0), '0,00');
assert.equal(kilometersFromPaste('4,89'), '4.89');
assert.equal(kilometersFromPaste('4.89'), '4.89');
assert.equal(kilometersFromPaste('1.234,56'), '1234.56');
assert.equal(kilometersFromPaste('453'), '453.00', 'A pasted whole kilometer value remains a whole kilometer value');
assert.equal(kilometersFromPaste('9999999,99'), '9999999.99');
for (const invalid of ['-1', 'abc', '1e3', '10000000', '4,890', '4.890', '1,2,3']) {
    assert.equal(kilometersFromPaste(invalid), null, `Reject invalid paste: ${invalid}`);
}
assert.equal(kilometersFromTyping('9999999,999'), null, 'Do not truncate out-of-range values into different distances');
console.log('PASS: automatic kilometer mask, decimal submission, draft values, paste, clearing and limits.');
