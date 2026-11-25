(() => {
    document.querySelectorAll('select[data-review-detail]').forEach(select => {
        select.addEventListener('change', () => {
            if (select.value === select.dataset.reviewValue) {
                const detail = document.getElementById(select.dataset.reviewDetail);
                if (detail && detail.tagName === 'DETAILS') detail.open = true;
            }
        });
    });
})();
