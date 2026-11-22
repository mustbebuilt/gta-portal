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
