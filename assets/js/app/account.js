export function createAccountModule(deps) {
    const {
        state,
        api,
        showModal,
        closeModal,
        confirm_,
        toast,
        escHtml,
        navigate,
        initApp,
    } = deps;

    async function handleLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('login-btn');
        const errEl = document.getElementById('login-error');
        const username = document.getElementById('login-user').value.trim();
        const password = document.getElementById('login-pass').value;

        if (!username || !password) return;

        btn.disabled = true;
        btn.querySelector('.btn-text').textContent = 'Signing in...';
        btn.querySelector('.btn-spinner').classList.remove('hidden');
        errEl.classList.add('hidden');

        try {
            const data = await api('login', { method: 'POST', body: { username, password } });
            state.user = data.user;
            state.role = data.role;
            state.csrf = data.csrf;
            state.settings = data.settings || {};
            state.view = state.settings.default_view || 'list';

            document.getElementById('login-screen').classList.add('hidden');
            document.getElementById('app').classList.remove('hidden');
            document.getElementById('app').dataset.user = state.user;
            document.getElementById('app').dataset.role = state.role;
            initApp();
        } catch (err) {
            errEl.textContent = err.message;
            errEl.classList.remove('hidden');
        } finally {
            btn.disabled = false;
            btn.querySelector('.btn-text').textContent = 'Sign In';
            btn.querySelector('.btn-spinner').classList.add('hidden');
        }
    }

    async function handleLogout() {
        try {
            await api('logout', { method: 'POST', body: {} });
        } catch {
            // ignore
        }
        window.location.reload();
    }

    async function showTrash() {
        try {
            const data = await api('trash_list');
            const items = data.items || [];

            let html = '';
            if (items.length === 0) {
                html = '<p style="text-align:center;color:var(--text-muted);padding:40px">Trash is empty.</p>';
            } else {
                html = items.map(item => `
                    <div class="trash-item" data-trash-name="${escHtml(item.trash_name)}">
                        <svg viewBox="0 0 24 24" width="16" height="16" style="flex-shrink:0;color:var(--text-muted)">
                            ${item.is_dir ? '<path d="M2 9a2 2 0 012-2h5l2 2h7a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2Z" fill="none" stroke="currentColor" stroke-width="2"/>' : '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" fill="none" stroke="currentColor" stroke-width="2"/>'}
                        </svg>
                        <span class="trash-item-name" title="${escHtml(item.original)}">${escHtml(item.original)}</span>
                        <span class="trash-item-date">${escHtml(item.deleted)}</span>
                        <button class="btn btn-sm trash-restore" data-name="${escHtml(item.trash_name)}">Restore</button>
                    </div>
                `).join('');
            }

            const footerBtns = [{ label: 'Close', cls: '', action: closeModal }];
            if (items.length > 0 && state.role === 'admin') {
                footerBtns.push({ label: 'Empty Trash', cls: 'btn-danger', action: emptyTrash });
            }

            showModal('Trash', html, footerBtns, 'modal-lg');

            document.querySelectorAll('.trash-restore').forEach(btn => {
                btn.addEventListener('click', async () => {
                    try {
                        await api('trash_restore', { method: 'POST', body: { trash_name: btn.dataset.name } });
                        toast('Item restored.', 'success');
                        closeModal();
                        navigate(state.path);
                    } catch (err) { toast(err.message, 'error'); }
                });
            });
        } catch (err) { toast(err.message, 'error'); }
    }

    async function emptyTrash() {
        if (!await confirm_('Empty all items in trash? This cannot be undone.', 'Empty Trash')) return;
        try {
            await api('trash_empty', { method: 'POST', body: {} });
            toast('Trash emptied.', 'success');
            closeModal();
        } catch (err) { toast(err.message, 'error'); }
    }

    async function showUsers() {
        try {
            const data = await api('users');
            const users = data.users || [];

            let html = users.map(u => `
                <div class="user-item">
                    <div class="avatar" style="width:32px;height:32px;font-size:.8rem">${u.username[0].toUpperCase()}</div>
                    <span class="user-item-name">${escHtml(u.username)}</span>
                    <span class="user-item-role">${escHtml(u.role)}</span>
                    ${u.username !== state.user ? `<button class="btn btn-sm btn-danger user-delete" data-username="${escHtml(u.username)}">Delete</button>` : ''}
                </div>
            `).join('');

            html += `<div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
                <h3 style="font-size:.95rem;margin-bottom:12px">Add User</h3>
                <div class="form-group"><label>Username</label><input type="text" id="new-username"></div>
                <div class="form-group"><label>Password</label><input type="password" id="new-password"></div>
                <div class="form-group"><label>Role</label>
                    <select id="new-role" style="width:100%;padding:8px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text)">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <button class="btn btn-primary btn-sm" id="btn-add-user">Add User</button>
            </div>`;

            showModal('Manage Users', html, [
                { label: 'Close', cls: 'btn-primary', action: closeModal },
            ]);

            document.querySelectorAll('.user-delete').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!await confirm_(`Delete user "${btn.dataset.username}"?`, 'Delete')) return;
                    try {
                        await api('delete_user', { method: 'POST', body: { username: btn.dataset.username } });
                        toast('User deleted.', 'success');
                        showUsers();
                    } catch (err) { toast(err.message, 'error'); }
                });
            });

            document.getElementById('btn-add-user')?.addEventListener('click', async () => {
                const username = document.getElementById('new-username').value.trim();
                const password = document.getElementById('new-password').value;
                const role = document.getElementById('new-role').value;
                if (!username || !password) { toast('Username and password required.', 'error'); return; }
                try {
                    await api('add_user', { method: 'POST', body: { username, password, role } });
                    toast('User added.', 'success');
                    showUsers();
                } catch (err) { toast(err.message, 'error'); }
            });
        } catch (err) { toast(err.message, 'error'); }
    }

    async function showChangePassword() {
        showModal('Change Password', `
            <div class="form-group"><label>Current Password</label><input type="password" id="cp-old"></div>
            <div class="form-group"><label>New Password</label><input type="password" id="cp-new"></div>
            <div class="form-group"><label>Confirm Password</label><input type="password" id="cp-confirm"></div>
        `, [
            { label: 'Cancel', cls: '', action: closeModal },
            {
                label: 'Change', cls: 'btn-primary', action: async () => {
                    const old = document.getElementById('cp-old').value;
                    const _new = document.getElementById('cp-new').value;
                    const confirm = document.getElementById('cp-confirm').value;
                    if (_new !== confirm) { toast('Passwords do not match.', 'error'); return; }
                    try {
                        await api('change_password', { method: 'POST', body: { old_password: old, new_password: _new } });
                        toast('Password changed.', 'success');
                        closeModal();
                    } catch (err) { toast(err.message, 'error'); }
                }
            },
        ]);
        setTimeout(() => document.getElementById('cp-old')?.focus(), 100);
    }

    async function showSettings() {
        const s = state.settings;
        const html = `
            <div class="setting-row">
                <div><div class="setting-label">Show Hidden Files</div><div class="setting-desc">Display files starting with a dot</div></div>
                <label class="toggle"><input type="checkbox" id="s-hidden" ${s.show_hidden ? 'checked' : ''}><span class="toggle-slider"></span></label>
            </div>
            <div class="setting-row">
                <div><div class="setting-label">Default View</div><div class="setting-desc">Choose list or grid view</div></div>
                <select id="s-view" style="padding:6px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text)">
                    <option value="list" ${s.default_view === 'list' ? 'selected' : ''}>List</option>
                    <option value="grid" ${s.default_view === 'grid' ? 'selected' : ''}>Grid</option>
                </select>
            </div>
            <div class="setting-row">
                <div><div class="setting-label">Enable Trash</div><div class="setting-desc">Move deleted items to trash instead of permanent delete</div></div>
                <label class="toggle"><input type="checkbox" id="s-trash" ${s.enable_trash ? 'checked' : ''}><span class="toggle-slider"></span></label>
            </div>
            <div class="setting-row">
                <div><div class="setting-label">Theme</div><div class="setting-desc">Application color scheme</div></div>
                <select id="s-theme" style="padding:6px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text)">
                    <option value="auto" ${s.theme === 'auto' ? 'selected' : ''}>Auto</option>
                    <option value="light" ${s.theme === 'light' ? 'selected' : ''}>Light</option>
                    <option value="dark" ${s.theme === 'dark' ? 'selected' : ''}>Dark</option>
                </select>
            </div>
            <div class="setting-row">
                <div><div class="setting-label">Date Format</div><div class="setting-desc">Format for file dates</div></div>
                <select id="s-datefmt" style="padding:6px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text)">
                    <option value="Y-m-d H:i" ${s.date_format === 'Y-m-d H:i' ? 'selected' : ''}>2024-01-15 14:30</option>
                    <option value="d/m/Y H:i" ${s.date_format === 'd/m/Y H:i' ? 'selected' : ''}>15/01/2024 14:30</option>
                    <option value="m/d/Y h:i A" ${s.date_format === 'm/d/Y h:i A' ? 'selected' : ''}>01/15/2024 02:30 PM</option>
                    <option value="relative" ${s.date_format === 'relative' ? 'selected' : ''}>Relative (2 hours ago)</option>
                </select>
            </div>
        `;

        showModal('Settings', html, [
            { label: 'Cancel', cls: '', action: closeModal },
            {
                label: 'Save', cls: 'btn-primary', action: async () => {
                    const newSettings = {
                        show_hidden: document.getElementById('s-hidden').checked,
                        default_view: document.getElementById('s-view').value,
                        enable_trash: document.getElementById('s-trash').checked,
                        theme: document.getElementById('s-theme').value,
                        date_format: document.getElementById('s-datefmt').value,
                    };
                    try {
                        const resp = await api('settings', { method: 'POST', body: newSettings });
                        state.settings = resp.settings;
                        document.documentElement.dataset.theme = state.settings.theme;
                        resolveTheme();
                        toast('Settings saved.', 'success');
                        closeModal();
                        navigate(state.path);
                    } catch (err) { toast(err.message, 'error'); }
                }
            },
        ]);
    }

    async function showStorageInfo() {
        try {
            const data = await api('storage');
            const usedPct = data.total > 0 ? Math.round((data.used / data.total) * 100) : 0;
            const html = `
                <div style="margin-bottom:20px">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.9rem">
                        <span>Used: ${escHtml(data.used_human)}</span>
                        <span>${usedPct}%</span>
                    </div>
                    <div style="height:12px;background:var(--bg);border-radius:6px;overflow:hidden">
                        <div style="height:100%;width:${usedPct}%;background:${usedPct > 90 ? 'var(--danger)' : 'var(--primary)'};border-radius:6px;transition:width .3s"></div>
                    </div>
                </div>
                <div class="info-grid">
                    <div class="info-label">Total</div><div class="info-value">${escHtml(data.total_human)}</div>
                    <div class="info-label">Used</div><div class="info-value">${escHtml(data.used_human)}</div>
                    <div class="info-label">Free</div><div class="info-value">${escHtml(data.free_human)}</div>
                </div>
            `;
            showModal('Storage Info', html, [
                { label: 'Close', cls: 'btn-primary', action: closeModal },
            ]);
        } catch (err) { toast(err.message, 'error'); }
    }

    function resolveTheme() {
        const html = document.documentElement;
        const theme = html.dataset.theme || 'auto';
        if (theme === 'auto') {
            const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            html.dataset.resolvedTheme = isDark ? 'dark' : 'light';
        } else {
            html.dataset.resolvedTheme = theme;
        }
    }

    function toggleTheme() {
        const html = document.documentElement;
        const current = html.dataset.resolvedTheme;
        const newTheme = current === 'dark' ? 'light' : 'dark';
        html.dataset.theme = newTheme;
        html.dataset.resolvedTheme = newTheme;
        localStorage.setItem('fm_theme', newTheme);
    }

    return {
        handleLogin,
        handleLogout,
        showTrash,
        emptyTrash,
        showUsers,
        showChangePassword,
        showSettings,
        showStorageInfo,
        resolveTheme,
        toggleTheme,
    };
}
