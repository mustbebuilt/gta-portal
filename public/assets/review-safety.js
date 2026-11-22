for (const section of document.querySelectorAll('[data-review-safety]')) {
    const followup = section.querySelector('[data-safety-followup]');
    const answers = section.querySelectorAll('input[type=radio]');
    const update = () => {
        followup.hidden = section.querySelector('input:checked')?.value !== 'yes';
    };
    answers.forEach(answer => answer.addEventListener('change', update));
    update();
}
