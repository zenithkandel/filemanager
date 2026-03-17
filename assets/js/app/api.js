import { API, state } from './state.js';
import { closeModal, showModal } from './ui.js';

async function promptReauth() {
    return new Promise(resolve => {
        showModal('Re-authenticate', `
            <p style="margin-bottom:16px;color:var(--text-secondary)">This action requires password confirmation.</p>
            <div class="form-group">
                <label for="reauth-pass">Password</label>
                <input type="password" id="reauth-pass" autocomplete="current-password">
            </div>
        `, [
            { label: 'Cancel', cls: '', action: () => { closeModal(); resolve(null); } },
            {
                label: 'Confirm', cls: 'btn-primary', action: () => {
                    const pw = document.getElementById('reauth-pass')?.value || '';
                    closeModal();
                    resolve(pw || null);
                }
            },
        ]);
        setTimeout(() => document.getElementById('reauth-pass')?.focus(), 100);
    });
}

export async function api(action, opts = {}) {
    const { method = 'GET', body = null, params = {} } = opts;
    const url = new URL(API, window.location.href);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, v);
    }

    const headers = {};
    let fetchBody = null;

    if (method === 'POST') {
        headers['X-CSRF-Token'] = state.csrf;
        if (body instanceof FormData) {
            fetchBody = body;
        } else if (body !== null) {
            headers['Content-Type'] = 'application/json';
            fetchBody = JSON.stringify(body);
        }
    }

    const resp = await fetch(url.toString(), { method, headers, body: fetchBody, credentials: 'same-origin' });
    const contentType = resp.headers.get('content-type') || '';

    if (contentType.includes('application/json')) {
        const data = await resp.json();
        if (!data.ok) {
            const errMsg = data.error || 'Unknown error';
            if (resp.status === 449) {
                const pw = await promptReauth();
                if (pw !== null) {
                    const reauthResp = await api('reauth', { method: 'POST', body: { password: pw } });
                    if (reauthResp.ok) {
                        return api(action, opts);
                    }
                }
                throw new Error('Re-authentication cancelled.');
            }
            throw new Error(errMsg);
        }
        return data;
    }
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    return resp;
}
