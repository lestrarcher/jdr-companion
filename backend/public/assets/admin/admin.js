(() => {
    const form = document.querySelector('[data-editor]');
    if (!form) return;
    const fields = Array.from(form.querySelectorAll('input:not([type="hidden"]), textarea'));
    const initial = fields.map(field => field.value);
    let submitted = false;
    const dirty = () => form.dataset.unsaved === 'true' || fields.some((field, index) => field.value !== initial[index]);
    const status = form.querySelector('[data-draft-status]');
    const update = () => { status.textContent = dirty() ? 'Modifications non enregistrées' : ''; };
    form.addEventListener('input', update);
    form.addEventListener('submit', () => { submitted = true; });
    window.addEventListener('pageshow', () => { submitted = false; update(); });
    window.addEventListener('beforeunload', event => {
        if (!submitted && dirty()) { event.preventDefault(); event.returnValue = ''; }
    });
    update();
})();
