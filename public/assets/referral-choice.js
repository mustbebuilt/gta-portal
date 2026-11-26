for (const label of document.querySelectorAll('[data-referral-choice]')) {
    const decision = label.closest('form')?.elements.namedItem('decision');
    const reason = label.querySelector('select');
    if (!decision || !reason) continue;

    const update = () => {
        const referred = decision.value === 'Referred';
        label.hidden = !referred;
        reason.disabled = !referred;
        reason.required = referred;
    };
    decision.addEventListener('change', update);
    update();
}
