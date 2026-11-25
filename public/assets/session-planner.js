const planner = document.querySelector('[data-session-planner]');
if (planner) {
  const group = planner.elements.tutor_group_id;
  const date = planner.elements.session_date;
  const timing = planner.querySelector('[data-plan-timing]');
  const custom = planner.querySelector('[data-plan-custom]');
  const change = planner.querySelector('[data-plan-change]');
  const schedule = planner.querySelector('[data-plan-schedule]');
  const message = planner.querySelector('[data-plan-message]');
  const picker = planner.querySelector('[data-plan-picker]');
  const subject = planner.querySelector('[data-plan-subject]');
  const list = planner.querySelector('[data-plan-selected]');
  const save = planner.querySelector('[data-plan-save]');
  const other = planner.elements.other_subject;
  const updateSave = () => {
    save.disabled = Boolean(pending) || !group.value || !date.value || (selected.length === 0 && !other.value.trim());
  };
  const addBreak = planner.querySelector('[data-plan-add-break]');
  let options = JSON.parse(planner.querySelector('[data-plan-options]').textContent);
  let selected = JSON.parse(planner.querySelector('[data-plan-initial]').textContent);
  let pending = null;

  function renderSubjects() {
    const available = new Map(options.subjects.map(item => [item.subjectId, item.label]));
    selected = selected.filter(id => available.has(id));
    subject.replaceChildren(new Option('Choose a subject', ''));
    for (const [id, title] of available) {
      if (!selected.includes(id)) subject.add(new Option(title, id));
    }
    list.replaceChildren();
    for (const id of selected) {
      const row = document.createElement('li');
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'subjects[]';
      input.value = id;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', 'Remove ' + available.get(id));
      remove.addEventListener('click', () => {
        selected = selected.filter(value => value !== id);
        renderSubjects();
        subject.focus();
      });
      row.append(available.get(id) + ' ', remove, input);
      list.append(row);
    }
    picker.hidden = available.size === 0;
    updateSave();
  }

  function showTiming(mode) {
    timing.value = mode;
    custom.hidden = !group.value || !date.value || mode === 'weekly';
    custom.disabled = custom.hidden;
    change.hidden = !options.timing;
    change.textContent = mode === 'weekly' ? 'Use different times' : 'Use weekly times';
    schedule.textContent = mode === 'weekly' ?
      'Using the weekly schedule, ' + options.timing.start + '–' + options.timing.end + '.' :
      (date.value && group.value && !options.timing ? 'There is no weekly schedule for this date. Enter times for this session.' : '');
  }

  function addBreakRow() {
    const index = custom.querySelectorAll('input[name$="[start]"]').length;
    const row = document.createElement('div');
    row.className = 'form-grid';
    for (const part of ['start', 'end']) {
      const label = document.createElement('label');
      const input = document.createElement('input');
      label.textContent = 'Break ' + part;
      input.type = 'time';
      input.name = 'breaks[' + index + '][' + part + ']';
      label.append(input);
      row.append(label);
    }
    addBreak.before(row);
    addBreak.hidden = index >= 9;
    return row;
  }

  async function refresh(clearSelection) {
    pending?.abort();
    pending = null;
    const url = group.selectedOptions[0]?.dataset.options;
    if (clearSelection) selected = [];
    if (!url) options = {subjects: [], timing: null};
    if (!url) {
      message.textContent = '';
      renderSubjects();
      showTiming('custom');
      return;
    }
    const request = new AbortController();
    pending = request;
    message.textContent = 'Loading subjects and schedule…';
    save.disabled = true;
    picker.hidden = true;
    try {
      const response = await fetch(url + '?' + new URLSearchParams({date: date.value}), {
        signal: request.signal, headers: {Accept: 'application/json'},
      });
      if (!response.ok) throw new Error('Subject options unavailable');
      const fresh = await response.json();
      if (pending !== request) return;
      options = fresh;
      message.textContent = options.subjects.length ? '' : 'No scheme-of-work subjects are available for this group. Use Other subject.';
    } catch (error) {
      if (pending !== request) return;
      options = {subjects: [], timing: null};
      message.textContent = 'Subjects could not load. Choose the group again or refresh the page.';
    } finally {
      if (pending === request) {
        pending = null;
        planner.elements.breaks_checked.checked = false;
        renderSubjects();
        showTiming(options.timing ? 'weekly' : 'custom');
      }
    }
  }

  subject.addEventListener('change', () => {
    if (subject.value && selected.length < 50) selected.push(subject.value);
    renderSubjects();
  });
  change.addEventListener('click', () => {
    if (timing.value === 'weekly') {
      planner.elements.start_time.value = options.timing.start;
      planner.elements.end_time.value = options.timing.end;
      for (const input of custom.querySelectorAll('input[type=time][name^="breaks["]')) input.value = '';
      for (const [index, excluded] of options.timing.breaks.entries()) {
        if (!planner.elements['breaks[' + index + '][start]']) addBreakRow();
        planner.elements['breaks[' + index + '][start]'].value = excluded.start;
        planner.elements['breaks[' + index + '][end]'].value = excluded.end;
      }
      showTiming('custom');
    } else {
      showTiming('weekly');
    }
  });
  addBreak.hidden = custom.querySelectorAll('input[name$="[start]"]').length >= 10;
  addBreak.addEventListener('click', () => addBreakRow().querySelector('input').focus());
  other.addEventListener('input', updateSave);
  group.addEventListener('change', () => refresh(true));
  date.addEventListener('change', () => refresh(false));
  renderSubjects();
  showTiming(timing.value);
}
