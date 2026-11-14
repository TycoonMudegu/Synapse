import { appUrl, request, toast, withLoading } from './api.js';

let recordSequence = Date.now();

function addRecord(kind, data = {}) {
    const template = document.querySelector(`#template-${kind}`);
    const list = document.querySelector(`[data-record-list="${kind}"]`);
    if (!template || !list) return;
    const holder = document.createElement('template');
    holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(recordSequence++));
    const fragment = holder.content;
    for (const field of fragment.querySelectorAll('[data-record-field]')) {
        field.value = data[field.dataset.recordField] ?? '';
    }
    list.append(fragment);
    document.dispatchEvent(new Event('jobflow:icons'));
}

export function initialiseProfile() {
    document.addEventListener('click', (event) => {
        const add = event.target.closest('[data-add-record]');
        if (add) addRecord(add.dataset.addRecord);
        event.target.closest('[data-remove-record]')?.closest('[data-career-record]')?.remove();
    });

    document.querySelectorAll('[data-profile-import]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (
                !window.confirm(
                    'Extract a draft from this CV and replace the fields currently on screen? Your saved profile changes only when you save the form.',
                )
            )
                return;
            await withLoading(button, async () => {
                try {
                    const result = await request(
                        appUrl(`/api/documents/${button.dataset.profileImport}/profile`),
                        { data: {} },
                    );
                    const form = document.querySelector('#profile-form');
                    for (const name of ['full_name', 'email', 'phone', 'location', 'summary']) {
                        form.elements.namedItem(name).value = result.data[name] ?? '';
                    }
                    form.elements.namedItem('skills').value = result.data.skills.join(', ');
                    form.elements.namedItem('verified').checked = false;
                    for (const kind of ['employment', 'education']) {
                        document.querySelector(`[data-record-list="${kind}"]`).replaceChildren();
                        for (const record of result.data[kind]) addRecord(kind, record);
                    }
                    toast(
                        `Draft imported. ${result.data.uncertainties.join(' ')} Review every field before saving.`,
                    );
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (error) {
                    toast(error.message, true);
                }
            });
        });
    });
}
