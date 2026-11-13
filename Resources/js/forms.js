import { request, toast, withLoading, showFormError } from './api.js';
import { trackTask } from './tasks.js';

export function handleResult(result) {
    if (result.message) toast(result.message);
    if (result.task_id) trackTask(result.task_id);
    if (result.redirect) window.location.assign(result.redirect);
    else if (result.reload) window.location.reload();
}

export function initialiseForms() {
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-async]');
        if (!form) return;
        event.preventDefault();
        const error = form.querySelector('.form-error');
        if (error) error.hidden = true;
        const button =
            event.submitter ?? form.querySelector('button[type="submit"], button:not([type])');
        await withLoading(button, async () => {
            try {
                handleResult(await request(form.action, { data: new FormData(form) }));
            } catch (error) {
                showFormError(form, error.message);
            }
        });
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-action]');
        if (!button || button.disabled) return;
        event.preventDefault();
        if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
        await withLoading(button, async () => {
            try {
                handleResult(
                    await request(button.dataset.action, {
                        data: button.dataset.payload ? JSON.parse(button.dataset.payload) : {},
                    }),
                );
            } catch (error) {
                toast(error.message, true);
            }
        });
    });

    document.querySelectorAll('[data-ai-assist]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            await withLoading(event.submitter, async () => {
                try {
                    const result = await request(form.action, { data: new FormData(form) });
                    const output = form.querySelector('[data-ai-result]');
                    const data = result.data;
                    output.textContent = data.summary ?? data.answers?.join('\n\n') ?? '';
                    if (data.uncertainties?.length) {
                        output.textContent += `\n\nNeeds your review:\n${data.uncertainties.join('\n')}`;
                    }
                    output.hidden = false;
                    toast(result.message);
                } catch (error) {
                    showFormError(form, error.message);
                }
            });
        });
    });
}
