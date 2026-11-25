// Progressive enhancement only. All attendance inputs remain available without JavaScript.
document.querySelectorAll('[data-compact-register]').forEach(form => {
  const candidates = [];
  const setFullSession = (row, openDetails) => {
    row.querySelector('select[name$="[code]"]').value = 'O';
    row.querySelector('input[name$="[arrival]"]').value = form.dataset.start;
    row.querySelector('input[name$="[departure]"]').value = form.dataset.end;
    row.querySelector('input[name$="[otj_confirmed]"]').checked = false;
    row.querySelector('[data-full-session-status]').textContent = 'Full-session attendance entered. Check the times and confirm any eligible OTJ separately.';
    const details = row.querySelector('details:has(input[name$="[arrival]"])');
    if (details && openDetails) details.open = true;
  };

  const bulk = form.querySelector('[data-register-bulk]');
  const apply = bulk.querySelector('[data-apply-full-session]');
  const selectUnmarked = bulk.querySelector('[data-select-unmarked]');
  const status = bulk.querySelector('[data-bulk-attendance-status]');
  const update = () => {
    candidates.forEach(({checkbox, label, row}) => {
      checkbox.disabled = Boolean(row.querySelector('select').value ||
        row.querySelector('input[name$="[arrival]"]').value ||
        row.querySelector('input[name$="[departure]"]').value ||
        row.querySelector('input[name$="[otj_confirmed]"]').checked ||
        row.querySelector('input[name$="[minutes]"]').value ||
        row.querySelector('textarea[name$="[adjustment]"]').value ||
        row.querySelector('textarea[name$="[comment]"]').value);
      if (checkbox.disabled) checkbox.checked = false;
      label.hidden = checkbox.disabled;
    });
    const selected = candidates.filter(({checkbox}) => checkbox.checked).length;
    selectUnmarked.disabled = candidates.every(({checkbox}) => checkbox.disabled);
    apply.disabled = selected === 0;
    status.textContent = selected + ' selected';
  };

  form.querySelectorAll('[data-register-person]').forEach(row => {
    const button = row.querySelector('[data-full-session]');
    button.hidden = false;
    button.addEventListener('click', () => {
      setFullSession(row, true);
      update();
    });
    const checkbox = row.querySelector('[data-bulk-select]');
    if (checkbox) {
      candidates.push({checkbox, row, label: row.querySelector('[data-bulk-row]')});
      row.addEventListener('input', update);
      row.addEventListener('change', update);
    }
  });

  selectUnmarked.addEventListener('click', () => {
    candidates.forEach(({checkbox}) => { checkbox.checked = !checkbox.disabled; });
    update();
  });
  apply.addEventListener('click', () => {
    update();
    const selected = candidates.filter(({checkbox}) => checkbox.checked);
    selected.forEach(({row}) => setFullSession(row, false));
    update();
    if (selected.length) status.textContent = 'Attendance entered for ' + selected.length + '. Confirm eligible OTJ separately, then save.';
  });
  bulk.hidden = candidates.length === 0;
  update();
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
