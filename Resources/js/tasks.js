import { appUrl, request, toast } from './api.js';

let polling = false;

export async function trackTask(id) {
    sessionStorage.setItem('jobflow-task', String(id));
    if (polling) return;
    polling = true;
    const region = document.querySelector('#task-region');
    const panel = document.createElement('div');
    panel.className = 'task-progress';
    const spinner = document.createElement('span');
    spinner.className = 'spinner';
    spinner.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = 'Task queued. Waiting for the background worker…';
    panel.append(spinner, text);
    region?.replaceChildren(panel);
    let count = 0;
    const check = async () => {
        try {
            const result = await request(appUrl(`/api/tasks/${id}`), { method: 'GET' });
            const task = result.data;
            if (task.status === 'completed') {
                sessionStorage.removeItem('jobflow-task');
                text.textContent = 'Task completed. Updating your workspace…';
                toast('Background task completed successfully.');
                window.setTimeout(() => window.location.reload(), 900);
                return;
            }
            if (task.status === 'failed') {
                sessionStorage.removeItem('jobflow-task');
                throw new Error(
                    task.last_error ?? 'The task failed. See System settings for details.',
                );
            }
            const descriptions = {
                discovery: 'Checking permitted sources and processing opportunities…',
                analyse_job: 'Analysing requirements and comparing your verified profile…',
                prepare_application: 'Preparing your tailored CV and cover letter…',
                submit_application: 'Submitting through Gmail. Waiting for confirmation…',
                gmail_sync: 'Synchronising relevant application correspondence…',
            };
            text.textContent =
                task.status === 'running'
                    ? (descriptions[task.type] ?? 'Processing your task…')
                    : task.last_error
                      ? `Waiting to retry: ${task.last_error}`
                      : 'Queued. The CLI worker must be running to process this task.';
            count += 1;
            if (count < 100) {
                window.setTimeout(check, 3000);
            } else {
                polling = false;
                spinner.remove();
                text.textContent =
                    'This task is still queued or running. Review its status in Settings → System.';
            }
        } catch (error) {
            polling = false;
            spinner.remove();
            text.textContent = error.message;
            toast(error.message, true);
        }
    };
    await check();
}

export function resumeTask() {
    const id = sessionStorage.getItem('jobflow-task');
    if (id && /^\d+$/.test(id)) trackTask(id);
}
