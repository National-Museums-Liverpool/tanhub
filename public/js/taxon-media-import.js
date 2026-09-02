(function () {
    'use strict';

    const form = document.querySelector('.bulk-media-import-form');
    if (!form) {
        return;
    }

    const root = form.closest('.bulk-media-import');
    const csvInput = form.querySelector('#csv_file');
    const photoInput = form.querySelector('#photos');
    const dropzone = form.querySelector('#photo-dropzone');
    const dropzonePrompt = form.querySelector('#photo-dropzone-prompt');
    const dropzoneSelection = form.querySelector('#photo-dropzone-selection');
    const csrfInput = form.querySelector('input[name="' + root.dataset.csrfName + '"]');
    const feedback = root.querySelector('#bulk-media-feedback');
    const progress = root.querySelector('#bulk-media-progress');
    const statusLabel = root.querySelector('#bulk-media-status');
    const countLabel = root.querySelector('#bulk-media-count');
    const progressBar = root.querySelector('#bulk-media-progress-bar');
    const fileList = root.querySelector('#bulk-media-files');
    const createButton = form.querySelector('#create-draft');
    const queueButton = form.querySelector('#queue-import');
    const cancelButton = form.querySelector('#cancel-import');
    const retryButton = form.querySelector('#retry-import');
    const state = { uuid: null, importStatus: null, files: [], serverFiles: [], csrfName: root.dataset.csrfName, csrfHash: root.dataset.csrfHash, polling: null, processing: false, uploading: null, previewUrls: [] };

    function showFeedback(message, type) {
        feedback.textContent = message;
        feedback.className = 'alert mt-4 alert-' + type;
    }

    function updateCsrf(payload) {
        if (payload && payload.csrf && payload.csrf.name && payload.csrf.hash) {
            state.csrfName = payload.csrf.name;
            state.csrfHash = payload.csrf.hash;
            if (csrfInput) {
                csrfInput.name = state.csrfName;
                csrfInput.value = state.csrfHash;
            }
        }
    }

    async function request(url, options) {
        const requestOptions = options || {};
        requestOptions.headers = Object.assign({}, requestOptions.headers || {}, { 'X-CSRF-TOKEN': state.csrfHash });
        const response = await fetch(url, requestOptions);
        const payload = await response.json().catch(function () { return {}; });
        updateCsrf(payload);
        if (!response.ok) {
            if (response.status === 403) {
                throw new Error('Your session could not be verified. Refresh the page and try again.');
            }
            throw new Error(payload.error && payload.error.message ? payload.error.message : 'The server rejected the request.');
        }
        return payload.data || {};
    }

    function basename(file) {
        return file.name.replace(/^[\\/]+/, '').split(/[\\/]/).pop();
    }

    function photoFiles(files) {
        return Array.from(files).filter(function (file) {
            return /^image\/(jpeg|png|gif|webp)$/i.test(file.type) || /\.(jpe?g|png|gif|webp)$/i.test(file.name);
        });
    }

    function setFiles(files) {
        state.files = photoFiles(files);
        renderSelectedFiles();
        renderMatching();
    }

    function allFilesStaged() {
        return state.serverFiles.length > 0 && state.serverFiles.every(function (file) { return file.staged || file.status === 'published'; });
    }

    function renderSelectedFiles() {
        state.previewUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        state.previewUrls = [];
        dropzoneSelection.replaceChildren();
        dropzoneSelection.classList.toggle('d-none', state.files.length === 0);
        dropzonePrompt.classList.toggle('d-none', state.files.length > 0);
        if (!state.files.length) {
            return;
        }
        const summary = document.createElement('strong');
        summary.className = 'bulk-media-selection-summary';
        summary.textContent = state.files.length + (state.files.length === 1 ? ' photo selected' : ' photos selected');
        dropzoneSelection.appendChild(summary);
        const previews = document.createElement('div');
        previews.className = 'bulk-media-previews';
        state.files.slice(0, 8).forEach(function (file) {
            const figure = document.createElement('figure');
            figure.className = 'bulk-media-preview';
            const image = document.createElement('img');
            const url = URL.createObjectURL(file);
            state.previewUrls.push(url);
            image.src = url;
            image.alt = '';
            const caption = document.createElement('figcaption');
            caption.textContent = basename(file);
            figure.append(image, caption);
            previews.appendChild(figure);
        });
        dropzoneSelection.appendChild(previews);
        if (state.files.length > 8) {
            const remainder = document.createElement('span');
            remainder.className = 'text-muted small';
            remainder.textContent = 'and ' + (state.files.length - 8) + ' more';
            dropzoneSelection.appendChild(remainder);
        }
    }

    async function readDirectory(entry) {
        if (entry.isFile) {
            return new Promise(function (resolve) { entry.file(resolve); });
        }
        if (!entry.isDirectory) {
            return [];
        }
        const reader = entry.createReader();
        const entries = [];
        let batch;
        do {
            batch = await new Promise(function (resolve) { reader.readEntries(resolve); });
            entries.push.apply(entries, batch);
        } while (batch.length);
        const children = await Promise.all(entries.map(readDirectory));
        return children.flat();
    }

    async function droppedFiles(event) {
        const items = Array.from(event.dataTransfer.items || []);
        if (!items.some(function (item) { return item.webkitGetAsEntry; })) {
            return Array.from(event.dataTransfer.files);
        }
        const entries = items.map(function (item) { return item.webkitGetAsEntry(); }).filter(Boolean);
        const files = await Promise.all(entries.map(readDirectory));
        return files.flat();
    }

    function renderMatching() {
        progress.classList.toggle('d-none', !state.uuid);
        fileList.replaceChildren();
        const expected = new Set(state.serverFiles.map(function (file) { return file.photo_filename; }));
        const selected = new Set(state.files.map(basename));
        const missing = state.serverFiles.filter(function (file) { return !file.staged && !selected.has(file.photo_filename); }).map(function (file) { return file.photo_filename; });
        const extra = state.serverFiles.length ? Array.from(selected).filter(function (name) { return !expected.has(name); }) : [];
        const duplicateCount = state.files.length - selected.size;
        const allStaged = allFilesStaged();
        const message = missing.length || extra.length || duplicateCount ? 'Some photos need attention: ' + missing.length + ' missing, ' + extra.length + ' not listed in the CSV, ' + duplicateCount + ' duplicate.' : state.serverFiles.length ? 'All photos listed in the CSV are ready.' : '';
        statusLabel.textContent = message;
        if (state.serverFiles.length && (missing.length || extra.length || duplicateCount)) {
            showFeedback(message, 'warning');
        }
        state.serverFiles.forEach(function (serverFile) {
            const item = document.createElement('li');
            item.className = 'list-group-item d-flex justify-content-between align-items-center';
            item.textContent = serverFile.photo_filename;
            const selectedFile = state.files.find(function (file) { return basename(file) === serverFile.photo_filename; });
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-' + (serverFile.status === 'published' || serverFile.staged || selectedFile ? 'success' : 'secondary');
            badge.textContent = serverFile.status === 'published' ? 'published' : serverFile.staged ? 'uploaded' : selectedFile ? 'selected' : 'missing';
            item.appendChild(badge);
            fileList.appendChild(item);
        });
        countLabel.textContent = state.serverFiles.filter(function (file) { return file.staged || file.status === 'published'; }).length + '/' + state.serverFiles.length;
        queueButton.disabled = !state.uuid || !['draft', 'uploading'].includes(state.importStatus) || !allStaged || missing.length > 0 || extra.length > 0 || duplicateCount > 0;
        cancelButton.disabled = !state.uuid;
        retryButton.disabled = !state.uuid || state.importStatus !== 'failed';
    }

    async function createDraft(event) {
        event.preventDefault();
        if (!csvInput.files.length) {
            showFeedback('Choose a CSV file first.', 'danger');
            return;
        }
        if (!state.files.length) {
            showFeedback('Add the photos listed in the CSV before uploading.', 'danger');
            return;
        }
        createButton.disabled = true;
        const body = new FormData();
        body.append('csv_file', csvInput.files[0]);
        body.append(state.csrfName, state.csrfHash);
        try {
            const data = await request(root.dataset.createUrl, { method: 'POST', body: body });
            state.uuid = data.uuid;
            await refreshStatus();
            showFeedback('Uploading selected photos...', 'info');
            await uploadPending();
            if (allFilesStaged()) {
                showFeedback('All files are uploaded. Select Start import to continue.', 'success');
            }
        } catch (error) {
            showFeedback(error.message, 'danger');
        } finally {
            createButton.disabled = false;
        }
    }

    async function refreshStatus() {
        if (!state.uuid) {
            return;
        }
        const data = await request(root.dataset.importBase + '/' + encodeURIComponent(state.uuid) + '/status', { method: 'GET' });
        updateImportStatus(data);
    }

    function updateImportStatus(data) {
        state.importStatus = data.status;
        state.serverFiles = data.files || [];
        renderMatching();
        statusLabel.textContent = data.status + ' (' + data.processed_rows + '/' + data.total_rows + ')';
        progressBar.style.width = Math.round((data.total_rows ? data.processed_rows / data.total_rows : 0) * 100) + '%';
        if (['queued', 'processing'].includes(data.status)) {
            beginPolling();
        } else if (state.polling) {
            window.clearInterval(state.polling);
            state.polling = null;
        }
        if (data.status === 'failed') {
            showFeedback(data.error_message || 'The worker reported a failure.', 'danger');
        }
    }

    async function processNextPhoto() {
        if (!state.uuid || state.processing || !['queued', 'processing'].includes(state.importStatus)) {
            return;
        }
        state.processing = true;
        try {
            const data = await request(root.dataset.importBase + '/' + encodeURIComponent(state.uuid) + '/process', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: state.csrfName + '=' + encodeURIComponent(state.csrfHash) });
            updateImportStatus(data);
            if (data.busy) {
                showFeedback('Waiting for another import to finish...', 'info');
            } else if (data.status === 'published') {
                showFeedback('Import complete. The photos are now available.', 'success');
            }
        } finally {
            state.processing = false;
        }
    }

    async function uploadOne(file) {
        const body = new FormData();
        body.append('photo', file, basename(file));
        body.append(state.csrfName, state.csrfHash);
        return request(root.dataset.importBase + '/' + encodeURIComponent(state.uuid) + '/files', { method: 'POST', body: body });
    }

    async function uploadPending() {
        if (!state.uuid) {
            return;
        }
        if (state.uploading) {
            await state.uploading;
            return uploadPending();
        }
        state.uploading = uploadPendingFiles();
        try {
            await state.uploading;
        } finally {
            state.uploading = null;
        }
    }

    async function uploadPendingFiles() {
        const staged = new Set(state.serverFiles.filter(function (file) { return file.staged; }).map(function (file) { return file.photo_filename; }));
        const pending = state.files.filter(function (file) { return !staged.has(basename(file)); });
        for (const file of pending) {
            try {
                await uploadOne(file);
            } catch (error) {
                showFeedback('Could not upload ' + basename(file) + ': ' + error.message, 'danger');
            }
        }
        await refreshStatus();
    }

    async function uploadSelectedFiles() {
        showFeedback('Uploading selected photos...', 'info');
        await uploadPending();
        if (allFilesStaged()) {
            showFeedback('All files are uploaded. Select Start import to continue.', 'success');
        }
    }

    async function resumeImport(button) {
        state.uuid = button.dataset.uuid;
        state.importStatus = null;
        state.files = [];
        photoInput.value = '';
        try {
            await refreshStatus();
            if (state.importStatus !== 'failed') {
                showFeedback('Import loaded. Select any files that are still missing.', 'success');
            }
        } catch (error) {
            showFeedback(error.message, 'danger');
        }
    }

    async function queueImport() {
        try {
            const data = await request(root.dataset.importBase + '/' + encodeURIComponent(state.uuid) + '/finalize', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: state.csrfName + '=' + encodeURIComponent(state.csrfHash) });
            showFeedback('Import started. This page will update as the photos are processed.', 'success');
            state.importStatus = data.status;
            statusLabel.textContent = data.status;
            beginPolling();
        } catch (error) {
            showFeedback(error.message, 'danger');
            await refreshStatus();
        }
    }

    async function cancelImport() {
        if (!state.uuid || !window.confirm('Cancel this import and remove its staged files?')) {
            return;
        }
        try {
            await request(root.dataset.importBase + '/' + encodeURIComponent(state.uuid) + '/cancel', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: state.csrfName + '=' + encodeURIComponent(state.csrfHash) });
            showFeedback('Import cancelled.', 'secondary');
            await refreshStatus();
        } catch (error) {
            showFeedback(error.message, 'danger');
        }
    }

    async function retryImport() {
        try {
            await uploadPending();
            const data = await request(root.dataset.importBase + '/' + encodeURIComponent(state.uuid) + '/retry', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: state.csrfName + '=' + encodeURIComponent(state.csrfHash) });
            showFeedback('Import restarted. This page will update as the photos are processed.', 'success');
            state.importStatus = data.status;
            beginPolling();
            return data;
        } catch (error) {
            showFeedback(error.message, 'danger');
        }
    }

    function beginPolling() {
        if (state.polling) {
            return;
        }
        const process = function () {
            processNextPhoto().catch(function (error) { showFeedback(error.message, 'danger'); });
        };
        state.polling = window.setInterval(function () {
            process();
        }, 2500);
        process();
    }

    form.addEventListener('submit', createDraft);
    photoInput.addEventListener('change', function () { setFiles(photoInput.files); if (state.uuid) { uploadSelectedFiles(); } });
    dropzone.addEventListener('click', function () { photoInput.click(); });
    dropzone.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); photoInput.click(); } });
    dropzone.addEventListener('dragover', function (event) { event.preventDefault(); dropzone.classList.add('is-dragging'); });
    dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('is-dragging'); });
    dropzone.addEventListener('drop', async function (event) { event.preventDefault(); dropzone.classList.remove('is-dragging'); setFiles(await droppedFiles(event)); if (state.uuid) { await uploadSelectedFiles(); } });
    queueButton.addEventListener('click', queueImport);
    cancelButton.addEventListener('click', cancelImport);
    retryButton.addEventListener('click', retryImport);
    root.querySelectorAll('.resume-import').forEach(function (button) {
        button.addEventListener('click', function () { resumeImport(button); });
    });
    root.querySelectorAll('.csv-template').forEach(function (button) {
        button.addEventListener('click', function () {
            const identifier = button.dataset.identifier;
            const blob = new Blob([identifier + ',photo_filename,alt_text,caption,attribution,license,sort_order,is_primary\n'], { type: 'text/csv' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'taxon-media-' + identifier + '.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        });
    });
}());
