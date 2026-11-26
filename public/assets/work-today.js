const workFilters = document.querySelector('[data-work-filters]');

if (workFilters) {
    const group = workFilters.elements.namedItem('group');
    const apprentice = workFilters.elements.namedItem('apprentice');

    if (group && apprentice) {
        function filterPeople() {
            for (const option of apprentice.options) {
                const outsideGroup = Boolean(option.value && group.value && option.dataset.group !== group.value);
                if (outsideGroup && option.selected) {
                    apprentice.value = '';
                }
                option.hidden = outsideGroup;
                option.disabled = outsideGroup;
            }
        }

        group.addEventListener('change', filterPeople);
        filterPeople();
    }
}
