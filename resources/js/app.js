// ---------- scan form: photo preview, rotation, and a smaller JPEG before uploading ----------

const MAX_UPLOAD_SIDE = 4000; // plenty for the zoom tool, and a fraction of a phone photo's size

const scanForm = document.querySelector('[data-scan-form]');
if (scanForm) {
    const input = scanForm.querySelector('[data-photo-input]');
    const preview = scanForm.querySelector('[data-photo-preview]');
    const rotateButton = scanForm.querySelector('[data-photo-rotate]');
    let photo = null; // { bitmap, original file }
    let rotation = 0; // degrees clockwise
    let rendering = Promise.resolve();

    // Draws the photo upright, at most MAX_UPLOAD_SIDE, as the JPEG that gets uploaded.
    // HEIC and huge photos become a JPEG the server can read.
    const render = async () => {
        const { bitmap } = photo;
        const scale = Math.min(1, MAX_UPLOAD_SIDE / Math.max(bitmap.width, bitmap.height));
        const w = Math.round(bitmap.width * scale);
        const h = Math.round(bitmap.height * scale);
        const sideways = rotation % 180 !== 0;
        const canvas = Object.assign(document.createElement('canvas'), { width: sideways ? h : w, height: sideways ? w : h });
        const ctx = canvas.getContext('2d');
        ctx.translate(canvas.width / 2, canvas.height / 2);
        ctx.rotate((rotation * Math.PI) / 180);
        ctx.drawImage(bitmap, -w / 2, -h / 2, w, h);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        const transfer = new DataTransfer();
        transfer.items.add(new File([blob], 'roster.jpg', { type: 'image/jpeg' }));
        input.files = transfer.files;
        URL.revokeObjectURL(preview.src);
        preview.src = URL.createObjectURL(blob);
    };

    input.addEventListener('change', async () => {
        const file = input.files?.[0];
        if (!file || file === photo?.converted) return;
        rotation = 0;
        preview.hidden = false;
        scanForm.querySelector('[data-photo-empty]').hidden = true;
        scanForm.querySelector('[data-photo-change]').hidden = false;
        try {
            photo = { bitmap: await createImageBitmap(file, { imageOrientation: 'from-image' }) };
            rendering = render();
            await rendering;
            photo.converted = input.files[0];
            rotateButton.hidden = false;
        } catch (err) {
            // The browser can't decode it; upload the original and let the server try.
            console.warn('Uploading the original photo:', err);
            photo = null;
            rotateButton.hidden = true;
            preview.src = URL.createObjectURL(file);
        }
    });

    rotateButton.addEventListener('click', async () => {
        if (!photo) return;
        rotation = (rotation + 90) % 360;
        rotateButton.disabled = true;
        rendering = render();
        await rendering;
        photo.converted = input.files[0];
        rotateButton.disabled = false;
    });

    scanForm.addEventListener('submit', async (event) => {
        const button = scanForm.querySelector('[data-scan-submit]');
        button.disabled = true;
        button.lastChild.textContent = 'Wird hochgeladen…';
        // Upload the photo the way it is shown, also when a rotation is still being drawn.
        event.preventDefault();
        await rendering.catch(() => {});
        scanForm.submit();
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
        if (!code) return { kind: 'free', title: 'Frei', when: '' };
        const entry = find(code);
        if (entry?.skip) return { kind: 'skipped', title: 'Nicht im Kalender', when: '' };
        // Lead with the time; the title only when the code has one of its own.
        const title = entry?.title?.trim();
        const timed = Boolean(entry?.start && entry?.end);
        const when = timed ? `${entry.start} – ${entry.end}${entry.end <= entry.start ? ' (Folgetag)' : ''}` : 'Ganztägig';
        const kind = timed ? 'timed' : 'allday';
        return title ? { kind, title, when } : { kind, title: when, when: '' };
    };

    const summary = document.querySelector('[data-summary]');
    const updateSummary = () => {
        const rows = [...days.querySelectorAll('[data-day]')];
        const shifts = rows.filter((row) => ['timed', 'allday'].includes(row.dataset.kind)).length;
        const unsure = rows.filter((row) => row.hasAttribute('data-uncertain')).length;
        summary.textContent = `${shifts} ${shifts === 1 ? 'Dienst' : 'Dienste'}${unsure ? `, ${unsure} zu prüfen` : ''}`;
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
            label.textContent = ' Kopiert';
        } catch {
            prompt('Diesen Link kopieren:', button.dataset.copy);
        }
        setTimeout(() => (label.textContent = original), 2000);
    });
}

// ---------- busy buttons: saving can take a few seconds while Amor writes to Google ----------

const SPINNER = `<svg viewBox="0 0 24 24" fill="none" class="size-5 shrink-0 animate-spin motion-reduce:animate-[spin_3s_linear_infinite]" aria-hidden="true">
    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3" />
    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
</svg>`;

const busyButtons = (form) => [
    ...(form.id ? document.querySelectorAll(`button[type=submit][form="${form.id}"]`) : []),
    ...form.querySelectorAll('button[type=submit]:not([form])'),
];

for (const form of document.querySelectorAll('form[data-busy]')) {
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        for (const button of busyButtons(form)) {
            button.dataset.idle = button.innerHTML;
            button.innerHTML = `${SPINNER}<span>${form.dataset.busy}</span>`;
            button.setAttribute('aria-busy', 'true');
            // Disabled after the submit event has run, so the form still sends.
            setTimeout(() => (button.disabled = true));
        }
    });
}

// Coming back with the Back button shows the page as it was left: make the buttons usable again.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    for (const button of document.querySelectorAll('button[aria-busy=true]')) {
        button.innerHTML = button.dataset.idle;
        button.removeAttribute('aria-busy');
        button.disabled = false;
    }
});

for (const form of document.querySelectorAll('form[data-confirm]')) {
    form.addEventListener('submit', (event) => confirm(form.dataset.confirm) || event.preventDefault());
}
