const plannedTraining = document.querySelector('[data-use-planned-training]');
const vocationalTraining = document.querySelector('[name="training[vocational]"]');
if (plannedTraining && vocationalTraining) {
    plannedTraining.hidden = false;
    plannedTraining.addEventListener('click', () => {
        if (vocationalTraining.value.trim() === '') {
            vocationalTraining.value = document.querySelector('#planned-training-copy').content.textContent.trim();
        }
        vocationalTraining.focus();
    });
}

const topicControls = [...document.querySelectorAll('[data-register-topic]')];
const deliveryDraft = document.querySelector('[data-delivery-draft]');
if (deliveryDraft) {
    let edited = false;
    deliveryDraft.addEventListener('input', () => { edited = true; });
    topicControls.forEach(input => input.addEventListener('change', () => {
        if (!edited) {
            deliveryDraft.value = topicControls.filter(topic => topic.checked && topic.dataset.topicArea === 'vocational')
                .map(topic => topic.dataset.topicLabel).join('\n');
        }
    }));
}

const topicExceptions = [...document.querySelectorAll('[data-topic-exceptions]')];
if (topicControls.length && topicExceptions.length) {
    const marker = document.createElement('input');
    marker.type = 'hidden';
    marker.name = 'training[topic_exceptions_present]';
    marker.value = '1';
    topicControls[0].form.append(marker);

    const states = new Map(topicExceptions.map(container => [
        container, JSON.parse(container.dataset.savedExceptions),
    ]));
    const renderExceptions = () => {
        const selected = topicControls.filter(input => input.checked);
        for (const container of topicExceptions) {
            const values = states.get(container);
            container.replaceChildren();
            for (const topic of selected) {
                const label = document.createElement('label');
                label.textContent = topic.dataset.topicLabel;
                const select = document.createElement('select');
                select.name = `training[topic_exceptions][${container.dataset.topicExceptions}][${topic.value}]`;
                for (const [value, text] of [['', 'Use group or area mark'], ['missed', 'Missed'], ['partial', 'Partly received']]) {
                    select.add(new Option(text, value, false, (values[topic.value] ?? '') === value));
                }
                select.addEventListener('change', () => { values[topic.value] = select.value; });
                label.append(select);
                container.append(label);
            }
        }
    };
    topicControls.forEach(input => input.addEventListener('change', renderExceptions));
    renderExceptions();
}

document.querySelectorAll('[data-training-area]').forEach(area => {
    const picker = area.querySelector('[data-topic-picker]');
    const select = picker.querySelector('select');
    const list = area.querySelector('[data-topic-list]');
    const fallback = area.querySelector('[data-topic-fallback]');
    const custom = area.querySelector('[data-training-custom]');
    const controls = [...area.querySelectorAll('[data-register-topic]')];

    function render() {
        select.replaceChildren(new Option('Choose a subject', ''));
        list.replaceChildren();
        for (const control of controls) {
            const label = control.dataset.topicLabel;
            if (!control.checked) {
                select.add(new Option(label, control.value));
                continue;
            }
            const item = document.createElement('li');
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = 'Remove';
            remove.setAttribute('aria-label', 'Remove ' + label);
            remove.addEventListener('click', () => {
                control.checked = false;
                control.dispatchEvent(new Event('change', {bubbles: true}));
                select.focus();
            });
            item.append(label + ' ', remove);
            list.append(item);
        }
        select.add(new Option('Custom…', 'custom'));
        list.hidden = list.childElementCount === 0;
    }

    select.addEventListener('change', () => {
        if (select.value === 'custom') {
            custom.open = true;
            custom.querySelector('textarea').focus();
            select.value = '';
            return;
        }
        const chosen = controls.find(control => control.value === select.value);
        if (chosen) {
            chosen.checked = true;
            chosen.dispatchEvent(new Event('change', {bubbles: true}));
        }
    });
    controls.forEach(control => control.addEventListener('change', render));
    if (fallback) fallback.hidden = true;
    picker.hidden = false;
    render();
});
