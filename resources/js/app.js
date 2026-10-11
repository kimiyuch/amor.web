// ---------- scan form: photo preview, and a smaller JPEG before uploading ----------

const MAX_UPLOAD_SIDE = 4000; // plenty for the zoom tool, and a fraction of a phone photo's size

const scanForm = document.querySelector('[data-scan-form]');
if (scanForm) {
    const input = scanForm.querySelector('[data-photo-input]');
    const preview = scanForm.querySelector('[data-photo-preview]');

    input.addEventListener('change', async () => {
        const file = input.files?.[0];
        if (!file) return;
        preview.src = URL.createObjectURL(file);
        preview.hidden = false;
        scanForm.querySelector('[data-photo-empty]').hidden = true;
        scanForm.querySelector('[data-photo-change]').hidden = false;

        // Converts HEIC and huge photos to a JPEG the server can read; keeps the original if that fails.
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, MAX_UPLOAD_SIDE / Math.max(bitmap.width, bitmap.height));
            const canvas = Object.assign(document.createElement('canvas'), {
                width: Math.round(bitmap.width * scale),
                height: Math.round(bitmap.height * scale),
            });
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
            if (blob && (blob.size < file.size || file.type !== 'image/jpeg')) {
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], 'roster.jpg', { type: 'image/jpeg' }));
                input.files = transfer.files;
            }
        } catch (err) {
            console.warn('Uploading the original photo:', err);
        }
    });

    scanForm.addEventListener('submit', () => {
        const button = scanForm.querySelector('[data-scan-submit]');
        button.disabled = true;
        button.lastChild.textContent = 'Uploading…';
    });
}

// ---------- reading progress ----------

const progress = document.querySelector('[data-scan-status]');
if (progress) {
    const text = progress.querySelector('[data-status-text]');
    const poll = async () => {
        try {
            const response = await fetch(progress.dataset.scanStatus, { headers: { Accept: 'application/json' } });
            const scan = await response.json();
            if (!['queued', 'reading'].includes(scan.status)) return location.reload();
            if (scan.status_text) text.textContent = scan.status_text;
        } catch {
            // Offline for a moment; try again.
        }
        setTimeout(poll, 2000);
    };
    setTimeout(poll, 2000);
}

// ---------- review: the event preview follows the code while editing ----------
// Mirrors App\Calendar\ShiftCalendar.

const days = document.querySelector('[data-days]');
if (days) {
    const codes = JSON.parse(document.getElementById('shift-codes').textContent);
    const normalize = (code) => String(code ?? '').toUpperCase().replace(/\s+/g, '');
    const find = (code) => codes.find((c) => normalize(c.code) === normalize(code));

    const describe = (code) => {
        code = code.trim();
        if (!code) return { kind: 'free', title: 'Free', when: '' };
        const entry = find(code);
        if (entry?.skip) return { kind: 'skipped', title: 'Not added', when: '' };
        const title = entry?.title?.trim() || `Dienst ${code}`;
        if (entry?.start && entry?.end) {
            const nextDay = entry.end <= entry.start ? ' next day' : '';
            return { kind: 'timed', title, when: `${entry.start} – ${entry.end}${nextDay}` };
        }
        return { kind: 'allday', title, when: 'All day' };
    };

    const summary = document.querySelector('[data-summary]');
    const updateSummary = () => {
        const rows = [...days.querySelectorAll('[data-day]')];
        const shifts = rows.filter((row) => ['timed', 'allday'].includes(row.dataset.kind)).length;
        const unsure = rows.filter((row) => row.hasAttribute('data-uncertain')).length;
        summary.textContent = `${shifts} ${shifts === 1 ? 'shift' : 'shifts'}${unsure ? `, ${unsure} to check` : ''}`;
    };

    days.addEventListener('input', (event) => {
        const row = event.target.closest('[data-day]');
        if (!row) return;
        const { kind, title, when } = describe(event.target.value);
        row.dataset.kind = kind;
        row.removeAttribute('data-uncertain');
        row.querySelector('[data-event-title]').textContent = title;
        row.querySelector('[data-event-when]').textContent = when;
        updateSummary();
    });
}

// ---------- settings ----------

const codeList = document.querySelector('[data-codes]');
if (codeList) {
    const template = document.querySelector('[data-code-template]');

    document.querySelector('[data-add-code]').addEventListener('click', () => {
        const html = template.innerHTML.replaceAll('__INDEX__', `new${Date.now()}`);
        codeList.insertAdjacentHTML('beforeend', html);
        const row = codeList.lastElementChild;
        row.querySelector('input').focus();
        row.scrollIntoView({ block: 'nearest' });
    });

    codeList.addEventListener('click', (event) => {
        event.target.closest('[data-remove-code]')?.closest('[data-code-row]').remove();
    });
}

for (const button of document.querySelectorAll('[data-copy]')) {
    button.addEventListener('click', async () => {
        const label = button.lastChild;
        const original = label.textContent;
        try {
            await navigator.clipboard.writeText(button.dataset.copy);
            label.textContent = ' Copied';
        } catch {
            prompt('Copy this link:', button.dataset.copy);
        }
        setTimeout(() => (label.textContent = original), 2000);
    });
}

for (const form of document.querySelectorAll('form[data-confirm]')) {
    form.addEventListener('submit', (event) => confirm(form.dataset.confirm) || event.preventDefault());
}
