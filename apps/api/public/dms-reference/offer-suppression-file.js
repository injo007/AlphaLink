(() => {
    'use strict';

    const root = document.getElementById('offer-suppression-file-manager');
    if (!root) return;

    const refreshButton = document.getElementById('offer-suppression-refresh');
    const refreshStatus = document.getElementById('offer-suppression-refresh-status');
    const progressWrap = document.getElementById('offer-suppression-refresh-progress-wrap');
    const progress = document.getElementById('offer-suppression-refresh-progress');
    const progressSize = document.getElementById('offer-suppression-refresh-size');
    const uploadForm = document.getElementById('offer-suppression-upload-form');
    const uploadInput = document.getElementById('offer-suppression-upload-input');
    const uploadButton = document.getElementById('offer-suppression-upload-button');
    const uploadStatus = document.getElementById('offer-suppression-upload-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let busy = false;

    const responseError = (payload) => {
        const firstValidation = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
        return firstValidation || payload?.data || payload?.message || 'The suppression file action failed.';
    };

    const requestJson = async (url, options = {}) => {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) };
        if (options.body && !(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
        }
        const response = await fetch(url, { credentials: 'same-origin', ...options, headers });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(responseError(payload));
        return payload;
    };

    const formatBytes = (value) => {
        const bytes = Number(value || 0);
        if (bytes < 1024) return `${bytes} B`;
        const units = ['KB', 'MB', 'GB', 'TB'];
        let size = bytes / 1024;
        let unit = 0;
        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit += 1;
        }
        return `${size.toFixed(2)} ${units[unit]}`;
    };

    const setRefreshMessage = (text, tone = 'info') => {
        refreshStatus.textContent = text;
        refreshStatus.className = `alert alert-${tone} py-2 mb-3`;
        refreshStatus.hidden = false;
    };

    const renderProgress = (download) => {
        const percent = download.percent;
        const determinate = percent !== null && percent !== undefined;
        progressWrap.hidden = false;
        progress.style.width = determinate ? `${percent}%` : '100%';
        progress.setAttribute('aria-valuenow', determinate ? String(percent) : '0');
        progress.classList.toggle('progress-bar-animated', !['completed', 'failed'].includes(download.status));
        const labels = {
            queued: 'Waiting for the suppression worker…',
            resolving: 'Requesting the file from the sponsor…',
            downloading: 'Downloading the suppression file…',
            extracting: 'Unpacking the downloaded file…',
            validating: 'Checking suppression entries…',
            saving: 'Saving the verified file to this offer…',
            completed: 'The suppression file is saved to this offer.',
            failed: 'The suppression file could not be saved.',
        };
        setRefreshMessage(labels[download.status] || 'Processing the suppression file…', download.status === 'failed' ? 'danger' : 'info');
        progressSize.textContent = download.status === 'saving'
            ? `${Number(download.processed_entries || 0).toLocaleString()} of ${Number(download.entry_count || 0).toLocaleString()} entries saved`
            : download.total_bytes
                ? `${formatBytes(download.downloaded_bytes)} of ${formatBytes(download.total_bytes)}${download.status === 'validating' ? ' checked' : ''}`
                : (download.downloaded_bytes ? `${formatBytes(download.downloaded_bytes)} downloaded` : '');
        if (download.status === 'failed') {
            setRefreshMessage(download.failure_message || labels.failed, 'danger');
        }
    };

    const pollDownload = async (download, startedAt) => {
        renderProgress(download);
        if (download.status === 'completed') {
            setTimeout(() => window.location.reload(), 700);
            return;
        }
        if (download.status === 'failed') {
            busy = false;
            refreshButton.disabled = false;
            return;
        }
        if (Date.now() - startedAt > 2 * 60 * 60 * 1000) {
            setRefreshMessage('Progress checks timed out. Reload this page before starting another refresh.', 'warning');
            busy = false;
            refreshButton.disabled = false;
            return;
        }

        const statusUrl = root.dataset.refreshStatusUrlTemplate.replace('__DOWNLOAD_ID__', encodeURIComponent(download.id));
        window.setTimeout(async () => {
            try {
                const state = await requestJson(statusUrl);
                await pollDownload(state.download, startedAt);
            } catch (error) {
                setRefreshMessage(error.message || 'Could not check suppression download progress. Reload the page and try again.', 'danger');
                busy = false;
                refreshButton.disabled = false;
            }
        }, 1200);
    };

    refreshButton?.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        refreshButton.disabled = true;
        progressWrap.hidden = true;
        setRefreshMessage('Starting sponsor refresh…');
        try {
            const result = await requestJson(root.dataset.refreshUrl, { method: 'POST', body: '{}' });
            await pollDownload(result.download, Date.now());
        } catch (error) {
            setRefreshMessage(error.message || 'Could not start the sponsor refresh.', 'danger');
            busy = false;
            refreshButton.disabled = false;
        }
    });

    uploadInput?.addEventListener('change', () => {
        uploadButton.disabled = busy || !uploadInput.files?.length;
    });

    uploadForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const file = uploadInput.files?.[0];
        if (!file || busy) {
            uploadStatus.textContent = 'Choose a suppression file before uploading.';
            uploadStatus.className = 'alert alert-warning py-2';
            uploadStatus.hidden = false;
            return;
        }

        const body = new FormData();
        body.append('suppression_file', file);
        busy = true;
        uploadButton.disabled = true;
        uploadInput.disabled = true;
        uploadStatus.hidden = true;
        try {
            const result = await requestJson(root.dataset.uploadUrl, { method: 'POST', body });
            uploadStatus.textContent = `${result.data.message} ${Number(result.data.entry_count).toLocaleString()} ${String(result.data.format).toUpperCase()} entries.`;
            uploadStatus.className = 'alert alert-success py-2';
            uploadStatus.hidden = false;
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            uploadStatus.textContent = error.message || 'The suppression file could not be uploaded.';
            uploadStatus.className = 'alert alert-danger py-2';
            uploadStatus.hidden = false;
            busy = false;
            uploadInput.disabled = false;
            uploadButton.disabled = !uploadInput.files?.length;
        }
    });
})();
