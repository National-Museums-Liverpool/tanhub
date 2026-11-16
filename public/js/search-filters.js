'use strict';

/**
 * Enhance server-rendered AND rows without changing their bookmarkable GET contract.
 *
 * Field changes deliberately clear incompatible values. Server-rendered edit actions
 * remain available when JavaScript is unavailable.
 */
document.querySelectorAll('[data-filter-builder]').forEach((form) => {
    const schema = JSON.parse(form.querySelector('[data-filter-schema]').textContent);
    const container = form.querySelector('[data-filter-rows]');
    const template = form.querySelector('[data-filter-template]');
    const status = form.querySelector('[data-filter-status]');

    /**
     * Renumber submitted rows after removal, including labels and taxon ARIA references.
     *
     * @returns {void}
     */
    const renumber = () => {
        container.querySelectorAll('[data-condition-row]').forEach((row, index) => {
            row.id = 'condition_' + index;
            row.querySelectorAll('[name]').forEach((element) => {
                element.name = element.name.replace(/^conditions\[[^\]]+\]/, 'conditions[' + index + ']');
            });
            row.querySelectorAll('[id^="field_"], [id^="operator_"], [id^="value_"], [id^="upper_"]').forEach((element) => {
                const old = element.id;
                element.id = old.split('_')[0] + '_' + index;
                row.querySelectorAll('label').forEach((label) => {
                    if (label.htmlFor === old) label.htmlFor = element.id;
                });
            });
            row.querySelector('[data-remove-filter]').value = 'remove:' + index;
            row.querySelector('[data-remove-filter]').setAttribute('aria-label', 'Remove condition ' + (index + 1));
        });
    };

    /**
     * Build trusted typed controls using DOM text APIs, never imported HTML.
     *
     * @param {HTMLElement} row Row whose field/operator changed.
     * @returns {void}
     */
    const renderValue = (row) => {
        const key = row.querySelector('[data-condition-field]').value;
        const field = schema.fields[key];
        const operator = row.querySelector('[data-condition-operator]').value;
        const target = row.querySelector('[data-condition-value]');
        const help = row.querySelector('[data-condition-help]');
        help.textContent = field?.help || '';
        help.hidden = !field?.help;
        target.dispatchEvent(new Event('taxon-dispose', { bubbles: true }));
        target.replaceChildren();
        if (!field || ['empty', 'not_empty'].includes(operator)) return;
        const index = [...container.children].indexOf(row);
        const prefix = 'conditions[' + index + ']';
        const label = document.createElement('label');
        label.className = 'form-label';
        label.htmlFor = 'value_' + index;
        label.textContent = 'Value';
        target.append(label);
        let parent = target;
        if (field.type === 'taxon') {
            parent = document.createElement('div');
            parent.dataset.taxonRow = '';
            const input = document.createElement('input');
            input.className = 'form-control mb-2';
            input.type = 'search';
            input.maxLength = 200;
            input.autocomplete = 'off';
            input.name = prefix + '[text]';
            input.setAttribute('aria-label', 'Taxon name');
            input.placeholder = 'Type a name to find taxa...';
            input.dataset.taxonInput = '';
            const list = document.createElement('ul');
            list.className = 'list-group overflow-auto';
            list.style.maxHeight = '20rem';
            list.hidden = true;
            list.dataset.taxonSuggestions = '';
            const message = document.createElement('div');
            message.className = 'form-text';
            message.dataset.taxonStatus = '';
            message.setAttribute('role', 'status');
            message.setAttribute('aria-live', 'polite');
            parent.append(input, list, message);
            target.append(parent);
        }
        let input;
        if (['choice', 'tokens', 'reference', 'flag', 'taxon'].includes(field.type)) {
            input = document.createElement('select');
            input.className = 'form-select';
            input.multiple = operator === 'in';
            if (!input.multiple) input.append(new Option('Choose a value', ''));
            const choices = field.type === 'flag' ? { 1: 'Yes', 0: 'No' } : schema.choices[key] || {};
            for (const [value, caption] of Object.entries(choices)) {
                input.append(new Option(caption, value));
            }
            if (field.type === 'taxon') input.dataset.taxonSelection = '';
        } else {
            input = document.createElement('input');
            input.className = 'form-control';
            input.type = ['integer', 'decimal'].includes(field.type) ? 'number'
                : field.type === 'date' ? 'date' : 'text';
            input.maxLength = 200;
            if (input.type === 'number') input.step = field.type === 'decimal' ? '0.0001' : '1';
            if (field.min !== undefined) input.min = field.min;
            if (field.max !== undefined) input.max = field.max;
        }
        input.id = label.htmlFor;
        input.name = prefix + '[value]' + (input.tagName === 'SELECT' && operator === 'in' ? '[]' : '');
        parent.append(input);
        if (operator === 'between') {
            const upperLabel = document.createElement('label');
            upperLabel.className = 'form-label mt-2';
            upperLabel.htmlFor = 'upper_' + index;
            upperLabel.textContent = 'Maximum';
            const upper = input.cloneNode();
            upper.id = upperLabel.htmlFor;
            upper.name = prefix + '[upper]';
            parent.append(upperLabel, upper);
        }
        if (operator === 'in') {
            const hint = document.createElement('div');
            hint.className = 'form-text';
            hint.textContent = field.type === 'tokens' ? 'Match any whole abbreviation.' : 'Match any selected value.';
            parent.append(hint);
        }
        form.dispatchEvent(new Event('taxon-rows-changed'));
    };

    form.addEventListener('change', (event) => {
        const row = event.target.closest('[data-condition-row]');
        if (!row) return;
        if (event.target.matches('[data-condition-field]')) {
            const field = schema.fields[event.target.value];
            const operators = row.querySelector('[data-condition-operator]');
            operators.replaceChildren();
            for (const [value, label] of Object.entries(field?.operators || { '': 'Choose a field' })) {
                operators.append(new Option(label, value));
            }
            renderValue(row);
        } else if (event.target.matches('[data-condition-operator]')) {
            renderValue(row);
        }
    });
    form.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-filter]');
        if (remove) {
            event.preventDefault();
            remove.closest('[data-condition-row]').dispatchEvent(new Event('taxon-dispose', { bubbles: true }));
            remove.closest('[data-condition-row]').remove();
            renumber();
            form.querySelector('[data-add-filter]').focus();
            status.textContent = 'Condition removed. Select Search to apply changes.';
        }
    });
    form.querySelector('[data-add-filter]').addEventListener('click', (event) => {
        event.preventDefault();
        if (container.children.length >= 50) {
            status.textContent = 'Use no more than 50 conditions.';
            return;
        }
        const fragment = template.content.cloneNode(true);
        container.append(fragment);
        renumber();
        container.lastElementChild.querySelector('[data-condition-field]').focus();
        status.textContent = 'Condition added.';
    });
});
