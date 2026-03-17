import { state } from './state.js';

export function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

export function humanSize(bytes) {
    if (bytes < 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < 4) {
        size /= 1024;
        i++;
    }
    return (i === 0 ? size : size.toFixed(1)) + ' ' + units[i];
}

export function formatDate(ts) {
    if (!ts) return '--';

    const fmt = state.settings?.date_format || 'Y-m-d H:i';

    if (fmt === 'relative') {
        const now = Date.now() / 1000;
        const diff = now - ts;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
    }

    const d = new Date(ts * 1000);
    const Y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const H = String(d.getHours()).padStart(2, '0');
    const i = String(d.getMinutes()).padStart(2, '0');

    if (fmt === 'd/m/Y H:i') return `${dd}/${m}/${Y} ${H}:${i}`;
    if (fmt === 'm/d/Y h:i A') {
        const h12 = d.getHours() % 12 || 12;
        const ampm = d.getHours() >= 12 ? 'PM' : 'AM';
        return `${m}/${dd}/${Y} ${h12}:${i} ${ampm}`;
    }
    return `${Y}-${m}-${dd} ${H}:${i}`;
}
