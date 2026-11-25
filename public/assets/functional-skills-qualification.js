const qualificationForm = document.querySelector('[data-qualification-form]');

if (qualificationForm) {
    const { subject, type, status, title, result, achievement_date: achievementDate } = qualificationForm.elements;
    let suggestedTitle = defaultTitle();

    function defaultTitle() {
        if (type.value !== 'Functional Skills' || !subject.value) return '';

        return 'Functional Skills ' + subject.selectedOptions[0].text;
    }

    function updateFields() {
        const recordsAchievement = ['Achieved', 'Already held'].includes(status.value);
        const showResult = type.value !== 'Functional Skills';

        result.closest('[data-qualification-result]').hidden = !showResult;
        result.disabled = !showResult;
        achievementDate.closest('[data-qualification-award]').hidden = !recordsAchievement;
        achievementDate.disabled = !recordsAchievement;
        achievementDate.required = recordsAchievement;
    }

    function updateTitle() {
        const nextTitle = defaultTitle();
        if (qualificationForm.hasAttribute('data-new-qualification') && (!title.value || title.value === suggestedTitle)) {
            title.value = nextTitle;
        }
        suggestedTitle = nextTitle;
    }

    type.addEventListener('change', updateTitle);
    type.addEventListener('change', updateFields);
    subject.addEventListener('change', updateTitle);
    status.addEventListener('change', updateFields);
    updateFields();
}
