(() => {
    // Print the dashboard's optional detail, then restore the reader's chosen view.
    const openedForPrint = new Set();
    window.addEventListener('beforeprint', () => {
        document.querySelectorAll('details[data-print-expand]').forEach(detail => {
            if (!detail.open) {
                openedForPrint.add(detail);
                detail.open = true;
            }
        });
    });
    window.addEventListener('afterprint', () => {
        openedForPrint.forEach(detail => { detail.open = false; });
        openedForPrint.clear();
    });
})();
