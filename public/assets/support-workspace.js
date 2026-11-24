(() => {
    function revealLinkedSection() {
        const target = document.getElementById(window.location.hash.slice(1));
        if (!target) return;

        let revealed = false;
        for (let parent = target; parent; parent = parent.parentElement) {
            if (parent instanceof HTMLDetailsElement && !parent.open) {
                parent.open = true;
                revealed = true;
            }
        }
        if (revealed) target.scrollIntoView();
    }

    window.addEventListener('hashchange', revealLinkedSection);
    revealLinkedSection();
})();
