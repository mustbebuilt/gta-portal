const requestForm = document.querySelector('[data-employer-request]');
if (requestForm) {
    const {source_choice, grant_id, title, detail, due_date, confirmed} = requestForm.elements;

    source_choice.addEventListener('change', () => {
        const suggestion = source_choice.selectedOptions[0].dataset;
        title.value = suggestion.title ?? '';
        detail.value = suggestion.detail ?? '';
        due_date.value = suggestion.due ?? '';
        confirmed.checked = false;
    });

    [title, detail, due_date, grant_id].forEach(field => {
        field.addEventListener('input', () => {
            confirmed.checked = false;
        });
    });
}
