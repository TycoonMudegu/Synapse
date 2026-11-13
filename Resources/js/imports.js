import { request, toast, withLoading, showFormError } from './api.js';

function populateJob(data) {
    const form = document.querySelector('#job-details-form');
    if (!form) return;
    for (const [name, value] of Object.entries(data)) {
        const field = form.elements.namedItem(name);
        if (field && field.type !== 'checkbox') field.value = value ?? '';
    }
    form.elements.namedItem('verified').checked = false;
    const notice = document.querySelector('#extraction-notice');
    notice.hidden = false;
    document.querySelector('#extraction-uncertainties').textContent = data.uncertainties?.length
        ? `Needs your review: ${data.uncertainties.join(' ')}`
        : 'Verify all information against the original advertisement before saving.';
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export function initialiseImports() {
    document.querySelectorAll('[data-selected-value]').forEach((select) => {
        if (select.dataset.selectedValue) select.value = select.dataset.selectedValue;
    });
    const tabs = [...document.querySelectorAll('[data-import-tab]')];
    function selectTab(tab, focus = false) {
        for (const button of tabs) {
            const selected = button === tab;
            button.classList.toggle('selected', selected);
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            const method = button.dataset.importTab;
            if (method !== 'manual') {
                document.querySelector(`#import-${method}`).hidden = !selected;
            }
        }
        if (focus) tab.focus();
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectTab(tab));
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next =
                event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? tabs.length - 1
                      : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            selectTab(tabs[next], true);
        });
    });
    if (tabs.length) selectTab(tabs[0]);

    document.querySelectorAll('[data-extract-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            form.querySelector('.form-error').hidden = true;
            await withLoading(event.submitter, async () => {
                try {
                    const result = await request('/api/jobs/extract', { data: new FormData(form) });
                    populateJob(result.data);
                    toast(result.message);
                } catch (error) {
                    showFormError(form, error.message);
                }
            });
        });
    });

    document.querySelector('[data-poster-form]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        form.querySelector('.form-error').hidden = true;
        await withLoading(event.submitter, async () => {
            try {
                const uploaded = await request('/api/documents', { data: new FormData(form) });
                document
                    .querySelector('#job-details-form')
                    .elements.namedItem('poster_document_id').value = uploaded.document.id;
                toast('Poster stored privately. Extracting the advertisement…');
                const extracted = await request('/api/jobs/extract', {
                    data: { type: 'poster', document_id: uploaded.document.id },
                });
                populateJob(extracted.data);
                toast(extracted.message);
            } catch (error) {
                showFormError(form, `${error.message} You can enter the details manually below.`);
            }
        });
    });
}
