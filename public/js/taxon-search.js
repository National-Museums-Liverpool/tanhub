'use strict';

/**
 * Progressively enhance the staff taxon filter with accessible name suggestions.
 *
 * The text input is a search label; the server-rendered select submits the canonical
 * taxon ID used for descendant filtering. Keep that select as a fallback if lookup
 * fails, and never let an older response overwrite a newer query or selection.
 *
 * @typedef {Object} TaxonSuggestion
 * @property {number} id Canonical taxon ID, not the ID of a matched synonym.
 * @property {string} scientific_name Accepted scientific name.
 * @property {string} vernacular_name Preferred vernacular name, or an empty string.
 * @property {string} rank Actual rank of the suggested taxon.
 * @property {string} matched_name Matching name, which may be a synonym.
 */
document.querySelectorAll('[data-taxon-search]').forEach((form) => {
    const input = form.querySelector('#taxon_name');
    const selection = form.querySelector('#taxon_id');
    const list = form.querySelector('#taxon-suggestions');
    const status = form.querySelector('#taxon-status');
    let timer;
    let request;
    // Aborting alone cannot invalidate a response that has already reached JSON parsing.
    let version = 0;
    // -1 means the list is visible but no keyboard option has been highlighted.
    let active = -1;
    /** @type {TaxonSuggestion[]} */
    let matches = [];

    form.querySelector('[data-taxon-fallback]').hidden = true;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');
    list.setAttribute('role', 'listbox');

    /**
     * Dismiss suggestions without clearing an already selected taxon.
     *
     * @returns {void}
     */
    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };

    /**
     * Commit a canonical taxon ID and invalidate any outstanding suggestions.
     *
     * @param {TaxonSuggestion} match Selected accepted taxon and its display labels.
     * @returns {void}
     */
    const choose = (match) => {
        version += 1;
        request?.abort();
        clearTimeout(timer);
        const label = match.scientific_name + (match.vernacular_name ? ' - ' + match.vernacular_name : '');
        selection.replaceChildren(new Option('No selection', ''), new Option(label, String(match.id), true, true));
        input.value = label;
        status.textContent = 'Selected ' + label + ', including descendants.';
        close();
    };

    /**
     * Fetch context-sensitive suggestions and restore fallback controls on failure.
     *
     * @returns {Promise<void>}
     */
    const load = async () => {
        const current = ++version;
        request?.abort();
        request = new AbortController();
        close();
        if (input.value.trim().length < 2) {
            status.textContent = 'Enter at least two characters for suggestions.';
            return;
        }
        const url = new URL(form.dataset.taxonSearch, window.location.href);
        url.searchParams.set('taxon_name', input.value.trim());
        // Classification/group/status narrow suggestions. Checklist rank and occurrence
        // filters must not prevent selecting a parent taxon to search its descendants.
        for (const element of form.querySelectorAll('select')) {
            if (element.name !== 'taxon_id' && element.name !== 'rank_id'
                && element.name !== 'geographic_region_id' && element.name !== 'data_source_id'
                && element.name !== 'blocked' && element.value !== '') {
                url.searchParams.set(element.name, element.value);
            }
        }
        status.textContent = 'Loading suggestions...';
        try {
            const response = await fetch(url, { signal: request.signal, headers: { Accept: 'application/json' } });
            // An expired staff session may redirect to a successful HTML login response.
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Taxon lookup failed.');
            }
            const body = await response.json();
            if (!Array.isArray(body.data)) {
                throw new Error('Invalid taxon lookup response.');
            }
            if (current !== version) {
                return;
            }
            matches = body.data;
            list.replaceChildren();
            matches.forEach((match, index) => {
                const item = document.createElement('li');
                item.id = 'taxon-option-' + index;
                item.className = 'list-group-item list-group-item-action';
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                // Imported names are untrusted text, never HTML markup.
                item.textContent = match.scientific_name
                    + (match.vernacular_name ? ' - ' + match.vernacular_name : '')
                    + (match.rank ? ' (' + match.rank + ')' : '')
                    + (match.matched_name && match.matched_name !== match.scientific_name
                        && match.matched_name !== match.vernacular_name ? ' - matched: ' + match.matched_name : '');
                // Keep focus until click commits the selection; blur otherwise closes the list first.
                item.addEventListener('pointerdown', (event) => event.preventDefault());
                item.addEventListener('click', () => choose(match));
                list.append(item);
            });
            list.hidden = matches.length === 0;
            input.setAttribute('aria-expanded', String(matches.length > 0));
            status.textContent = matches.length ? matches.length + ' suggestions. Use arrow keys and Enter to select.'
                : 'No matching taxa.';
        } catch (error) {
            if (error.name !== 'AbortError' && current === version) {
                close();
                status.textContent = 'Could not load suggestions. Try again, or use the taxon choices below.';
                form.querySelector('[data-taxon-fallback]').hidden = false;
            }
        }
    };

    input.addEventListener('input', () => {
        version += 1;
        request?.abort();
        // A changed label must not keep submitting the previous taxon's ID.
        selection.value = '';
        close();
        clearTimeout(timer);
        timer = setTimeout(load, 250);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            version += 1;
            request?.abort();
            clearTimeout(timer);
            close();
        } else if (!list.hidden && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            // Keep focus in the combobox while aria-activedescendant tracks the highlighted option.
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
    input.addEventListener('blur', () => {
        version += 1;
        request?.abort();
        clearTimeout(timer);
        close();
    });
    form.querySelectorAll('select').forEach((element) => {
        if (!['taxon_id', 'rank_id', 'geographic_region_id', 'data_source_id', 'blocked'].includes(element.name)) {
            element.addEventListener('change', () => {
                // Discard stale suggestions, but retain deliberate selections: contradictory
                // filters should produce no matches rather than silently changing the chosen taxon.
                version += 1;
                request?.abort();
                close();
                if (document.activeElement === input) {
                    load();
                }
            });
        }
    });
});
