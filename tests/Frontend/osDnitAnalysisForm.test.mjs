import assert from 'node:assert/strict';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { useForm } from '@inertiajs/react';
import { router } from '@inertiajs/core';
import { submitOsDnitAnalysis } from '../../resources/js/Support/osDnitAnalysisForm.js';

// Exercise the actual installed hook: its transform() is not chainable and
// the defaults method is setDefaults(), not defaults(). No network or database.
function makeForm(values) {
    let form;
    function Harness() { form = useForm(values); return null; }
    renderToString(React.createElement(Harness));
    return form;
}

const originalPost = router.post;
const requests = [];
router.post = (url, data, options) => requests.push({ url, data, options });
let saved = 0;
let completed = 0;
const values = { version: 2, items: [{ product_id: 1, quantity: '2.500' }], estimated_deadline: '2026-11-05',
    review_comment: 'Revisão técnica', decision: 'approve', comment: '', return_to: '' };
try {
    for (const mode of ['save', 'forward']) {
        const form = makeForm(values);
        submitOsDnitAnalysis(form, { review: true, mode, url: '/review', onSaved: () => saved++, onCompleted: () => completed++ });
        const request = requests.at(-1);
        assert.equal(request.url, '/review');
        assert.deepEqual(request.data, { version: 2, mode, review_comment: values.review_comment,
            estimated_deadline: values.estimated_deadline, items: values.items });
        await request.options.onSuccess({ props: {} });
    }
    assert.equal(saved, 1, 'Saving refreshes the modal and uses the installed defaults API');
    assert.equal(completed, 1, 'Forwarding closes the modal');
    for (const decision of ['approve', 'return']) {
        const form = makeForm({ ...values, decision, return_to: 'concremat', comment: 'Rever prazo.' });
        submitOsDnitAnalysis(form, { review: false, mode: 'forward', url: '/decide', onSaved: () => saved++, onCompleted: () => completed++ });
        const request = requests.at(-1);
        assert.deepEqual(request.data, { version: 2, decision, return_to: 'concremat', comment: 'Rever prazo.' });
        await request.options.onSuccess({ props: {} });
    }
    assert.equal(requests.length, 4);
    assert.equal(completed, 3);
    assert.equal(saved, 1);
    console.log('PASS: save, forward, approve and return issue requests using the actual Inertia React hook.');
} finally { router.post = originalPost; }
