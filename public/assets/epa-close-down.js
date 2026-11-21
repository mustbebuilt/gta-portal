const attemptForm = document.querySelector('[data-epa-attempt]');
if (attemptForm) {
    const result = attemptForm.elements.namedItem('result');
    const saveAttempt = attemptForm.querySelector('button[value="record"]');

    function showFields(selector, visible) {
        attemptForm.querySelectorAll(selector).forEach((element) => {
            element.hidden = !visible;
            element.querySelectorAll('input, select').forEach((input) => {
                input.disabled = !visible;
                input.required = visible;
            });
            if (element instanceof HTMLButtonElement) {
                element.disabled = !visible;
            }
        });
    }

    function updateAttemptForm() {
        const received = result.value !== 'Awaiting result';
        const achieved = ['Pass', 'Merit', 'Distinction'].includes(result.value);
        showFields('[data-epa-received]', received);
        showFields('[data-epa-achievement]', achieved);
        saveAttempt.hidden = achieved;
        saveAttempt.disabled = achieved;
    }

    result.addEventListener('change', updateAttemptForm);
    updateAttemptForm();
}
