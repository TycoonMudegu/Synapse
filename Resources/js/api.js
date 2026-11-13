export async function request(url, { method = 'POST', data = null } = {}) {
    const headers = {
        Accept: 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    };
    let body = data;
    if (data !== null && !(data instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(data);
    }
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 150000);
    let response;
    try {
        response = await fetch(url, {
            method,
            headers,
            body: method === 'GET' ? null : body,
            credentials: 'same-origin',
            signal: controller.signal,
        });
    } catch (error) {
        throw new Error(
            error.name === 'AbortError'
                ? 'The request timed out. Check its result before retrying.'
                : 'The connection was interrupted. Check your network before trying again.',
        );
    } finally {
        window.clearTimeout(timeout);
    }
    let result;
    try {
        result = await response.json();
    } catch {
        throw new Error('The server returned an unexpected response. Check the system log.');
    }
    if (!response.ok || !result.ok) {
        if (response.status === 401) {
            window.location.assign('/login');
        }
        throw new Error(result.message ?? 'This operation could not be completed.');
    }
    return result;
}

export function toast(message, error = false) {
    const region = document.querySelector('#toasts');
    if (!region) return;
    const item = document.createElement('div');
    item.className = `toast${error ? ' error' : ''}`;
    item.setAttribute('role', error ? 'alert' : 'status');
    const text = document.createElement('span');
    text.textContent = message;
    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '×';
    close.addEventListener('click', () => item.remove());
    item.append(text, close);
    region.append(item);
    window.setTimeout(() => item.remove(), error ? 18000 : 10000);
}

export async function withLoading(button, callback) {
    if (!button || button.disabled) return;
    const original = [...button.childNodes].map((node) => node.cloneNode(true));
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = button.dataset.loading ?? 'Working…';
    try {
        return await callback();
    } finally {
        button.replaceChildren(...original);
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
}

export function showFormError(form, message) {
    const error = form.querySelector('.form-error');
    if (error) {
        error.textContent = message;
        error.hidden = false;
        error.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
        toast(message, true);
    }
}
