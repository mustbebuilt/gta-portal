document.querySelectorAll('[data-entry-form]').forEach(form => {
 const update = () => {
  const related = ['family_owner_manager','family_supervisor'].some(k => form.elements[k]?.value === 'Yes');
  const unknown = ['family_owner_manager','family_supervisor'].some(k => !['Yes','No','Prefer not to say'].includes(form.elements[k]?.value));
  form.querySelectorAll('[data-relationship]').forEach(el => { el.hidden = !related && !unknown; });
 };
 form.addEventListener('change', update); update();
});
document.querySelectorAll('[data-milestone-form]').forEach(form => {
 const update = () => {
  const reached = form.elements.result.value === 'Reached';
  form.querySelectorAll('[data-reached]').forEach(el => {el.hidden = !reached; el.querySelectorAll('input,select').forEach(input => {input.disabled = !reached;});});
  const continuous = form.elements.continuity?.value?.startsWith('Continuous ');
  form.querySelectorAll('[data-continuous]').forEach(el => {el.hidden = !reached || !continuous; el.querySelectorAll('input').forEach(input => {input.disabled = !reached || !continuous;});});
 };
 form.addEventListener('change',update);update();
});
