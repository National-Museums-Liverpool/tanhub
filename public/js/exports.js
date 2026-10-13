(() => {
    'use strict';
    const panel = document.querySelector('[data-export]');
    if (!panel) return;
    const form = panel.querySelector('[data-process-form]');
    if (!form) return;
    const resume = panel.querySelector('[data-resume]');
    const pause = panel.querySelector('[data-pause]');
    const error = panel.querySelector('[data-export-error]');
    const bar = panel.querySelector('[data-progress-bar]');
    let running = false;
    let pending = false;
    let terminal = false;
    let timer = null;
    const controls = () => {
        const disabled = running || pending;
        const caption = running ? 'Processing...' : pending ? 'Pausing...' : 'Resume';
        if (resume.disabled !== disabled) resume.disabled = disabled;
        if (resume.textContent !== caption) resume.textContent = caption;
        resume.hidden = terminal;
        pause.hidden = terminal || (!running && !pending);
        bar.classList.toggle('progress-bar-animated', running);
    };
    const stop = () => {
        running = false;
        window.clearTimeout(timer);
        timer = null;
        controls();
    };
    const showError = (message) => {
        stop();
        error.textContent = message;
        error.hidden = false;
    };
    const render = (job) => {
        panel.dataset.phase = job.phase;
        panel.dataset.state = job.status;
        panel.querySelector('[data-progress-text]').textContent =
            `${job.status}: ${job.captured_rows} captured; ${job.written_rows} written; ${job.file_offset} CSV bytes.` +
            (job.estimated_total == null ? '' : ` Captured ${job.captured_rows} out of approximately ${job.estimated_total} rows.`);
        const writing = job.phase === 'writing';
        const total = writing ? Number(job.captured_rows) : job.estimated_total;
        const count = Number(writing ? job.written_rows : job.captured_rows);
        const percent = total == null ? 100 : total > 0
            ? Math.max(0, Math.min(writing ? 100 : 99, Math.floor(count / total * 100))) : writing ? 100 : 0;
        bar.style.width = `${percent}%`;
        bar.textContent = writing ? `${percent}% CSV written`
            : total == null ? 'Capturing rows...' : `${percent}% captured (estimated)`;
        bar.classList.toggle('progress-bar-animated', running);
        const progress = bar.parentElement;
        if (total != null) {
            progress.setAttribute('aria-valuenow', String(percent));
            progress.setAttribute('aria-valuemin', '0');
            progress.setAttribute('aria-valuemax', '100');
        } else {
            progress.removeAttribute('aria-valuenow');
        }
        panel.querySelector('[data-regenerate]').hidden = job.phase !== 'writing';
        if (['completed', 'cancelled', 'expired', 'failed'].includes(job.status)) {
            terminal = true;
            stop();
            panel.querySelector('[data-action-form]').hidden = true;
            panel.querySelector('[data-download]').hidden = job.status !== 'completed';
        }
    };
    const next = async () => {
        if (!running || pending) return;
        pending = true;
        controls();
        let delay = 50;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
                credentials: 'same-origin'
            });
            const type = response.headers.get('content-type') || '';
            if (!type.includes('application/json')) throw new Error('Session or CSRF validation failed. Reload this page to resume safely.');
            const result = await response.json();
            if (result.csrf) {
                panel.querySelectorAll('input[type="hidden"]').forEach((input) => {
                    if (input.name === result.csrf.name) input.value = result.csrf.hash;
                });
            }
            if (!response.ok && result.retryable) {
                delay = 500 + Math.random() * 500;
                panel.querySelector('[data-progress-text]').textContent = 'Another batch is running; waiting to retry safely...';
            } else {
                if (!response.ok || result.error) throw new Error(result.error || 'Export request failed.');
                render(result.data);
            }
        } catch (exception) {
            showError(`${exception.message} Reload to reconcile committed server progress before retrying.`);
        } finally {
            pending = false;
            controls();
        }
        if (running) {
            window.clearTimeout(timer);
            timer = window.setTimeout(next, delay);
        }
    };
    const start = () => {
        if (running || pending || terminal) return;
        error.hidden = true;
        pause.textContent = 'Pause';
        running = true;
        controls();
        next();
    };
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        start();
    });
    pause.addEventListener('click', () => {
        stop();
        pause.textContent = pending ? 'Pausing after this batch...' : 'Paused';
    });
    panel.querySelector('[data-action-form]').addEventListener('submit', (event) => {
        stop();
        if (pending) {
            event.preventDefault();
            showError('Pause and wait for the current batch before cancelling or regenerating.');
        }
    });
    controls();
    if (panel.dataset.autoStart === 'true') start();
})();
