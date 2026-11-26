const activityForm = document.querySelector('[data-timetable-activity]');

if (activityForm) {
    const kind = activityForm.elements.namedItem('kind');
    const details = activityForm.querySelector('[data-booked-activity]');
    const teaching = activityForm.querySelector('[data-teaching-activity]');
    const hint = activityForm.querySelector('[data-flexible-help]');
    const button = activityForm.querySelector('[data-timetable-submit]');

    function updateActivity() {
        const flexible = kind.value === activityForm.dataset.flexibleKind;
        details.hidden = flexible;
        details.disabled = flexible;
        hint.hidden = !flexible;
        teaching.hidden = kind.value !== 'Teaching';
        teaching.disabled = teaching.hidden;
        activityForm.elements.namedItem('title').required = !flexible;
        activityForm.elements.namedItem('confirmed').required = !flexible;
        button.textContent = flexible ? 'Continue to Flexible day' : 'Save draft for checking';
    }

    kind.addEventListener('change', updateActivity);
    updateActivity();
}
