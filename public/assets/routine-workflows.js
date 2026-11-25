// Progressive enhancement only. All attendance inputs remain available without JavaScript.
document.querySelectorAll('[data-compact-register]').forEach(form => {
  form.querySelectorAll('[data-register-person]').forEach(row => {
    const button = row.querySelector('[data-full-session]');
    if (!button) return;
    button.hidden = false;
    button.addEventListener('click', () => {
      row.querySelector('select[name$="[code]"]').value = 'O';
      row.querySelector('input[name$="[arrival]"]').value = form.dataset.start;
      row.querySelector('input[name$="[departure]"]').value = form.dataset.end;
      row.querySelector('input[name$="[otj_confirmed]"]').checked = false;
      row.querySelector('[data-full-session-status]').textContent = 'Full-session attendance selected. Check the actual times and separately confirm any eligible OTJ. Save the register to record this change.';
      const details = row.querySelector('details:has(input[name$="[arrival]"])');
      if (details) details.open = true;
    });
  });
});
// Workplace photos use the same answer-saving form, rather than an independent upload form.
document.querySelectorAll('[data-evidence-kind]').forEach(select => {
  const form = select.form;
  const file = form.querySelector('[data-evidence-file]');
  const description = form.querySelector('[data-photo-description]');
  const update = () => {
    const photo = select.value === 'workplace-photo';
    file.accept = photo ? 'image/jpeg,image/png' : '.pdf,.jpg,.jpeg,.png,.docx';
    // The server validates the explanation when a photo is actually uploaded. Draft text remains saveable.
    description.placeholder = photo ? 'Explain how this photo supports the task.' : 'Optional evidence note';
  };
  select.addEventListener('change', update);
  update();
});

// Show descriptions for the evidence source selected. Leave all fields available without JS.
document.querySelectorAll('[data-evidence-file]').forEach(input => {
  const form = input.form;
  const fileDetails = form.querySelector('[data-file-details]');
  const camera = form.querySelector('input[name="camera_photo"]');
  const cameraDetails = form.querySelector('[data-camera-details]');
  const update = (file, details) => {
    if (!file || !details) return;
    details.hidden = !file.files.length && !details.hasAttribute('data-keep-open') &&
      !details.querySelector('textarea')?.value.trim();
  };
  input.addEventListener('change', () => update(input, fileDetails));
  camera?.addEventListener('change', () => update(camera, cameraDetails));
  update(input, fileDetails);
  update(camera, cameraDetails);
});
