for (const picker of document.querySelectorAll('[data-staff-template]')) {
    const form = picker.closest('form');
    const select = picker.querySelector('[data-staff-template-choice]');
    const status = picker.querySelector('[data-staff-template-status]');
    const applied = new Map();
    if (!form) continue;

    picker.hidden = false;
    select.addEventListener('change', () => {
        const selected = select.selectedOptions[0];
        if (!selected?.dataset.fill) return;

        const values = JSON.parse(selected.dataset.fill);
        let kept = false;
        for (const name of new Set([...applied.keys(), ...Object.keys(values)])) {
            const input = form.elements.namedItem(name);
            if (!input || (!applied.has(name) && values[name] === input.value)) continue;

            if (input.value !== '' && input.value !== applied.get(name)) {
                kept = true;
                applied.delete(name);
                continue;
            }

            input.value = values[name] ?? '';
            applied.set(name, input.value);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        status.textContent = kept ? 'Existing entries were kept. Check and edit them as needed.' : '';
    });
}
