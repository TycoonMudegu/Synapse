import { toast } from './api.js';

export function initialiseNavigation() {
    const toggle = document.querySelector('#menu-toggle');
    const sidebar = document.querySelector('#sidebar');
    const overlay = document.querySelector('#sidebar-overlay');
    function closeMenu() {
        sidebar?.classList.remove('open');
        toggle?.setAttribute('aria-expanded', 'false');
        if (overlay) overlay.hidden = true;
    }
    toggle?.addEventListener('click', () => {
        const open = sidebar.classList.toggle('open');
        toggle.setAttribute('aria-expanded', String(open));
        overlay.hidden = !open;
        if (open) sidebar.querySelector('a')?.focus();
    });
    overlay?.addEventListener('click', closeMenu);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMenu();
    });
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.togglePassword);
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', String(visible));
        });
    });
    document.querySelectorAll('[data-open-dialog]').forEach((button) => {
        button.addEventListener('click', () =>
            document.getElementById(button.dataset.openDialog)?.showModal(),
        );
    });
    document.querySelectorAll('[data-close-dialog]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog').close());
    });
    document.querySelectorAll('[data-filter-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const url = new URL(form.action || window.location.href);
            url.search = new URLSearchParams(new FormData(form)).toString();
            const region = document.querySelector('[data-filter-results]');
            region.setAttribute('aria-busy', 'true');
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                if (response.redirected) {
                    window.location.assign(response.url);
                    return;
                }
                if (!response.ok) throw new Error('The filtered view could not be loaded.');
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const results = page.querySelector('[data-filter-results]');
                if (!results) throw new Error('The server returned an unexpected page.');
                region.replaceChildren(...results.childNodes);
                history.replaceState({}, '', url);
                document.dispatchEvent(new Event('jobflow:icons'));
            } catch (error) {
                toast(error.message, true);
            } finally {
                region.removeAttribute('aria-busy');
            }
        });
    });
}
