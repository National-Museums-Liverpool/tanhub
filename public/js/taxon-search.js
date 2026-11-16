'use strict';

/**
 * Accessible, independently cancellable taxon suggestions for dynamic filter rows.
 *
 * Canonical IDs, not editable labels, select subtrees. Suggestions intentionally omit
 * occurrence/statistics scope and rank so a parent remains selectable.
 */
document.querySelectorAll('[data-filter-builder]').forEach((form) => {
    let sequence = 0;
    const initialized = new WeakSet();
    const schema = JSON.parse(form.querySelector('[data-filter-schema]').textContent);

    /**
     * Initialize only new taxon controls; each owns its timer, request and version.
     *
     * @returns {void}
     */
    const initialize = () => form.querySelectorAll('[data-taxon-row]').forEach((row) => {
        if (initialized.has(row)) return;
        initialized.add(row);
        const input = row.querySelector('[data-taxon-input]');
        const selection = row.querySelector('[data-taxon-selection]');
        const list = row.querySelector('[data-taxon-suggestions]');
        const status = row.querySelector('[data-taxon-status]');
        let timer;
        let request;
        let version = 0;
        let active = -1;
        let matches = [];
        const lifecycle = new AbortController();
        list.id = 'taxon-suggestions-' + (++sequence);
        list.setAttribute('role', 'listbox');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', list.id);
        input.setAttribute('aria-expanded', 'false');
        selection.setAttribute('aria-label', 'Selected taxon (fallback choices)');

        /**
         * Invalidate outstanding work and dismiss suggestions without clearing selection.
         *
         * @returns {void}
         */
        const close = () => {
            version += 1;
            request?.abort();
            clearTimeout(timer);
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        };

        /**
         * Commit a canonical selection, preserving imported names as text only.
         *
         * @param {Object} match Accepted taxon and display labels from the lookup endpoint.
         * @returns {void}
         */
        const choose = (match) => {
            close();
            const label = match.scientific_name + (match.vernacular_name ? ' - ' + match.vernacular_name : '');
            selection.replaceChildren(new Option('No selection', ''), new Option(label, String(match.id), true, true));
            input.value = label;
            status.textContent = 'Selected ' + label + ', including descendants.';
        };

        /**
         * Fetch contextual choices; stale responses cannot replace a newer query.
         *
         * @returns {Promise<void>}
         */
        const load = async () => {
            close();
            if (input.value.trim().length < 2) {
                status.textContent = 'Enter at least two characters for suggestions.';
                return;
            }
            const current = version;
            request = new AbortController();
            const url = new URL(form.dataset.taxonSearch, window.location.href);
            url.searchParams.set('taxon_name', input.value.trim());
            const data = new FormData(form);
            let contextIndex = 0;
            for (const candidate of form.querySelectorAll('[data-condition-row]')) {
                const control = candidate.querySelector('[data-condition-field]');
                const field = schema.fields[control.value];
                if (!field || !['taxon', 'classification', 'attribute'].includes(field.domain)
                    || ['taxon_id', 'taxon_name', 'rank_id'].includes(control.value)) continue;
                const prefix = control.name.replace(/\[field\]$/, '');
                const values = [...data.entries()].filter(([name]) => name.startsWith(prefix + '['));
                if (!values.some(([name, value]) => name.includes('[value]') && value !== '')) continue;
                for (const [name, value] of values) {
                    url.searchParams.append(name.replace(prefix, 'conditions[' + contextIndex + ']'), value);
                }
                contextIndex += 1;
            }
            status.textContent = 'Loading suggestions...';
            try {
                const response = await fetch(url, { signal: request.signal, headers: { Accept: 'application/json' } });
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
                    throw new Error('Taxon lookup failed.');
                }
                const body = await response.json();
                if (!Array.isArray(body.data)) throw new Error('Invalid taxon lookup response.');
                if (current !== version || !row.isConnected) return;
                matches = body.data;
                list.replaceChildren();
                matches.forEach((match, index) => {
                    const item = document.createElement('li');
                    item.id = list.id + '-option-' + index;
                    item.className = 'list-group-item list-group-item-action';
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', 'false');
                    item.textContent = match.scientific_name
                        + (match.vernacular_name ? ' - ' + match.vernacular_name : '')
                        + (match.rank ? ' (' + match.rank + ')' : '')
                        + (match.matched_name && match.matched_name !== match.scientific_name
                            ? ' - matched: ' + match.matched_name : '');
                    item.addEventListener('pointerdown', (event) => event.preventDefault());
                    item.addEventListener('click', () => choose(match));
                    list.append(item);
                });
                list.hidden = matches.length === 0;
                input.setAttribute('aria-expanded', String(matches.length > 0));
                status.textContent = matches.length ? matches.length + ' suggestions. Use arrow keys and Enter.'
                    : 'No matching taxa.';
            } catch (error) {
                if (error.name !== 'AbortError' && current === version && row.isConnected) {
                    status.textContent = 'Could not load suggestions. Try typing again, or select Search to match the entered name.';
                }
            }
        };
        input.addEventListener('input', () => {
            close();
            selection.value = '';
            timer = setTimeout(load, 250);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') close();
            else if (!list.hidden && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                active = active < 0 ? (event.key === 'ArrowDown' ? 0 : matches.length - 1)
                    : (active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
                [...list.children].forEach((item, index) => {
                    item.classList.toggle('active', index === active);
                    item.setAttribute('aria-selected', String(index === active));
                });
                input.setAttribute('aria-activedescendant', list.children[active].id);
                list.children[active].scrollIntoView({ block: 'nearest' });
            } else if (!list.hidden && event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                choose(matches[active]);
            }
        });
        input.addEventListener('blur', close);
        selection.addEventListener('change', () => {
            close();
            input.value = selection.value === '' ? '' : selection.selectedOptions[0].textContent;
            status.textContent = selection.value === '' ? 'Selection cleared.'
                : 'Selected ' + input.value + ', including descendants.';
        });
        // A changed context must invalidate in-flight results even before a new keystroke.
        form.addEventListener('change', close, { signal: lifecycle.signal });
        form.addEventListener('input', (event) => { if (event.target !== input) close(); }, { signal: lifecycle.signal });
        form.addEventListener('taxon-dispose', (event) => {
            if (event.target.contains(row) || event.target === row) {
                close();
                lifecycle.abort();
            }
        }, { signal: lifecycle.signal });
    });
    initialize();
    form.addEventListener('taxon-rows-changed', initialize);
});
