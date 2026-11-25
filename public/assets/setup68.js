// Progressive enhancement only; all decisions and calculations are server-side.
(()=>{const state=document.querySelector('#marker-state'),fields=document.querySelector('#marker-fields'),other=document.querySelector('#marker-other');function markers(){if(!state)return;fields.hidden=state.value!=='selected';if(other)other.hidden=!document.querySelector('input[value="IM40"]')?.checked;}state?.addEventListener('change',markers);document.querySelector('input[value="IM40"]')?.addEventListener('change',markers);markers();const employer=document.querySelector('#employer'),newForm=document.querySelector('#add-employer');function toggle(){if(newForm)newForm.hidden=!!employer?.value;}employer?.addEventListener('change',()=>{toggle();const verified=document.querySelector('[name="data[agreement_verified]"]');if(verified)verified.checked=false;});toggle();})();

(()=>{const form=document.querySelector('#step-form');if(!form)return;let dirty=false;form.addEventListener('input',()=>dirty=true);form.addEventListener('change',()=>dirty=true);document.querySelectorAll('.setup68 nav a').forEach(link=>link.addEventListener('click',event=>{if(!dirty)return;const match=link.getAttribute('href').match(/\/([1-7])$/);if(!match)return;event.preventDefault();const next=document.createElement('input');next.type='hidden';next.name='next';next.value=match[1];form.append(next);form.requestSubmit();}));})();

(() => {
    const panel = document.querySelector('#rpl-adjustments');
    if (!panel) return;
    const mode = document.querySelector('#rpl-mode');
    const disclosure = panel.querySelector('summary');
    const clearNote = document.querySelector('#rpl-clear-note');
    const tools = panel.querySelector('[data-rpl-tools]');
    const picker = panel.querySelector('#rpl-ksb');
    const table = panel.querySelector('[data-rpl-rows]');
    const summary = panel.querySelector('[data-rpl-summary]');
    const assessment = panel.querySelector('#assessment');
    const reductions = Array.from(panel.querySelectorAll('[data-rpl-reduction]'));

    function toggleAdjustments() {
        const applies = mode.value === 'applies';
        panel.hidden = !applies;
        panel.open = applies;
        disclosure.hidden = true;
        assessment.disabled = !applies;
        reductions.forEach(select => { select.disabled = !applies; });
        if (clearNote) clearNote.hidden = mode.value !== 'none';
    }

    function updateSummary() {
        const count = Number(summary.dataset.otherAdjusted) + reductions.filter(select => Number(select.value) > 0).length;
        summary.textContent = count + (count === 1 ? ' KSB with a reduction' : ' KSBs with reductions');
    }

    if (picker) {
        const byCode = new Map();
        reductions.forEach(select => {
            const row = select.closest('tr');
            row.hidden = Number(select.value) === 0 && !row.hasAttribute('data-rpl-error');
            byCode.set(row.dataset.rplCode, {row, select});
            select.addEventListener('change', updateSummary);
        });
        table.hidden = Array.from(byCode.values()).every(({row}) => row.hidden);
        tools.hidden = false;
        picker.addEventListener('change', () => {
            const entry = byCode.get(picker.value);
            if (!entry) return;
            entry.row.hidden = false;
            table.hidden = false;
            picker.value = '';
            entry.select.focus();
        });
    }

    mode.addEventListener('change', toggleAdjustments);
    toggleAdjustments();
    updateSummary();
})();

// Open the relevant disclosure when a setup issue links to a field inside it.
function revealSetupField() {
    const target = document.getElementById(window.location.hash.slice(1));
    if (!target?.closest('.setup68')) return;
    let parent = target.parentElement;
    while (parent) {
        if (parent instanceof HTMLDetailsElement) parent.open = true;
        parent = parent.parentElement;
    }
    target.scrollIntoView({ block: 'nearest' });
}
window.addEventListener('hashchange', revealSetupField);
revealSetupField();
