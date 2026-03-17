import { escHtml } from './utils.js';

export function showModal(title, bodyHtml, buttons = [], extraClass = '') {
    const overlay = document.getElementById('modal-overlay');
    const modal = document.getElementById('modal');
    const titleEl = document.getElementById('modal-title');
    const bodyEl = document.getElementById('modal-body');
    const footerEl = document.getElementById('modal-footer');

    titleEl.textContent = title;
    bodyEl.innerHTML = bodyHtml;
    footerEl.innerHTML = '';

    modal.className = 'modal' + (extraClass ? ' ' + extraClass : '');

    for (const btn of buttons) {
        const b = document.createElement('button');
        b.className = 'btn ' + (btn.cls || '');
        b.textContent = btn.label;
        b.addEventListener('click', btn.action);
        footerEl.appendChild(b);
    }

    overlay.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

export function closeModal() {
    document.getElementById('modal-overlay').classList.add('hidden');
    document.body.style.overflow = '';
}

export function promptInput(title, label, defaultValue = '') {
    return new Promise(resolve => {
        showModal(title, `
            <div class="form-group">
                <label>${escHtml(label)}</label>
                <input type="text" id="prompt-input" value="${escHtml(defaultValue)}">
            </div>
        `, [
            { label: 'Cancel', cls: '', action: () => { closeModal(); resolve(null); } },
            {
                label: 'OK', cls: 'btn-primary', action: () => {
                    const v = document.getElementById('prompt-input')?.value?.trim() || '';
                    closeModal();
                    resolve(v || null);
                }
            },
        ]);

        setTimeout(() => {
            const input = document.getElementById('prompt-input');
            if (input) {
                input.focus();
                const dot = defaultValue.lastIndexOf('.');
                input.setSelectionRange(0, dot > 0 ? dot : defaultValue.length);
            }
        }, 100);

        setTimeout(() => {
            document.getElementById('prompt-input')?.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.querySelector('.modal-footer .btn-primary')?.click();
                }
            });
        }, 50);
    });
}

export function confirm_(message, confirmLabel = 'Confirm') {
    return new Promise(resolve => {
        showModal('Confirm', `<p>${escHtml(message)}</p>`, [
            { label: 'Cancel', cls: '', action: () => { closeModal(); resolve(false); } },
            { label: confirmLabel, cls: 'btn-danger', action: () => { closeModal(); resolve(true); } },
        ]);
    });
}

export function toast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.innerHTML = `
        <span>${escHtml(message)}</span>
        <button class="toast-close" title="Dismiss">
            <svg viewBox="0 0 24 24" width="14" height="14"><path d="M18 6 6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
    `;

    el.querySelector('.toast-close').addEventListener('click', () => removeToast(el));
    container.appendChild(el);

    setTimeout(() => removeToast(el), 4000);
}

function removeToast(el) {
    if (!el.parentNode) return;
    el.classList.add('removing');
    setTimeout(() => el.remove(), 250);
}

export function hideContextMenu() {
    document.getElementById('context-menu').classList.add('hidden');
}

export function setLoading(show) {
    const el = document.getElementById('loading');
    const list = document.getElementById('file-list');
    if (show) {
        el.classList.remove('hidden');
        list.style.opacity = '0.5';
    } else {
        el.classList.add('hidden');
        list.style.opacity = '';
    }
}
