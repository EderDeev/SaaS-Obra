export function submitOsDnitAnalysis(form, { review, mode, url, onSaved, onCompleted }) {
    // useForm.transform returns void in the installed Inertia React version.
    form.transform((values) => review ? {
        version: values.version, mode, review_comment: values.review_comment,
        estimated_deadline: values.estimated_deadline, items: values.items,
    } : {
        version: values.version, decision: values.decision,
        return_to: values.return_to, comment: values.comment,
    });
    form.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            if (review && mode === 'save') {
                form.setDefaults();
                onSaved();
            } else onCompleted();
        },
    });
}
