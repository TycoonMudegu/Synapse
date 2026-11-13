<div class="page-heading">
    <div>
        <a href="/applications" class="text-link mb-2"><?= icon('arrow-left'); ?>All applications</a>
        <h1>Put your best self forward.</h1>
        <p><?= e($application['title']); ?> · <?= e($application['employer']); ?></p>
    </div>
    <span class="badge badge-blue"><?= e(label($application['status'])); ?></span>
</div>
<?php if ($application['last_error']): ?>
<div class="notice warm">
    <?= icon('circle-alert'); ?>
    <div>
        <strong>This application needs your attention.</strong>
        <p><?= e($application['last_error']); ?></p>
    </div>
</div>
<?php endif; ?> <?php if ($application['confirmation']): ?>
<div class="notice">
    <?= icon('circle-check'); ?>
    <div>
        <strong>Submission confirmation</strong>
        <p><?= e($application['confirmation']); ?></p>
    </div>
</div>
<?php endif; ?> <?php $editable = in_array(
    $application['status'],
    ['draft', 'ready_for_review', 'failed'],
    true,
); ?>
<div class="content-grid">
    <div class="stack">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Your application package</h2>
                    <p>Review the actual attachments before approving.</p>
                </div>
                <?php if (
            in_array($application['status'], ['draft', 'ready_for_review'], true)
        ): ?>
                <button
                    class="btn btn-soft btn-sm"
                    data-action="/api/applications/<?= e($application['id']); ?>/prepare"
                    data-loading="Queuing document preparation…"
                >
                    <?= icon('sparkles'); ?><?= $application['cv_document_id'] ? 'Regenerate with AI' : 'Prepare with AI'; ?>
                </button>
                <?php endif; ?>
            </div>
            <form
                action="/api/applications/<?= e($application['id']); ?>"
                data-async
                class="panel-body form-stack"
            >
                <div class="form-error" hidden role="alert"></div>
                <div class="form-grid">
                    <?php foreach (
                    [
                        'cv_document_id' => 'CV attachment',
                        'letter_document_id' => 'Cover letter attachment',
                    ]
                    as $field => $name
                ): ?>
                    <div class="field">
                        <label for="<?= e($field); ?>"><?= e($name); ?></label>
                        <select name="<?= e($field); ?>" id="<?= e($field); ?>" <?= $editable ? '' : 'disabled'; ?>>
                            <option value="">Choose a document</option>
                            <?php foreach (
    $documents
    as $document
):
    if (
        !in_array(
            $document['mime_type'],
            [
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            true,
        )
    ) {
        continue;
    } ?>
                            <option value="<?= e($document['id']); ?>" <?= (int) $application[$field] === (int) $document['id'] ? 'selected' : ''; ?>>
                                <?= e($document['name']); ?> · v<?= e($document['version']); ?>
                            </option>
                            <?php
endforeach; ?>
                        </select>
                        <?php if (
    $application[$field]
): ?>
                        <a
                            class="text-link"
                            href="/documents/<?= e($application[$field]); ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            Review attachment <?= icon('external-link'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="field">
                    <label for="subject">Email subject</label>
                    <input
                        id="subject"
                        name="subject"
                        value="<?= e($application['subject']); ?>"
                        <?= $editable ? '' : 'disabled'; ?>
                        required
                        maxlength="250"
                    />
                </div>
                <div class="field">
                    <label for="email-body">Application email</label>
                    <textarea
                        id="email-body"
                        name="email_body"
                        rows="9"
                        <?= $editable ? '' : 'disabled'; ?>
                        placeholder="Write the message to accompany your attachments…"
                    >
<?= e($application['email_body']); ?></textarea>
                </div>
                <div class="field">
                    <label for="application-notes">Your notes</label>
                    <textarea name="notes" id="application-notes" rows="3" <?= $editable ? '' : 'disabled'; ?>>
<?= e($application['notes']); ?></textarea>
                </div>
                <?php if (
                    $editable
                ): ?>
                <label class="check-label">
                    <input type="checkbox" name="verified" value="1" <?= $application[ 'documents_verified_at' ] ? 'checked' : ''; ?> />
                    I have opened both attachments, verified every factual statement and checked
                    that this package meets the employer's application instructions.
                </label>
                <div class="form-actions">
                    <button class="btn" type="button" data-open-dialog="upload-dialog">
                        <?= icon('upload'); ?>Upload attachment
                    </button>
                    <button class="btn btn-primary" data-loading="Saving application…">
                        <?= icon('check'); ?>Save & verify package
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </section>
        <?php if (
            in_array($application['status'], ['draft', 'ready_for_review'], true)
        ): ?>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Cover letter editor</h2>
                    <p>Make it sound like you. Saving creates a new document version.</p>
                </div>
                <?php if (
    $letter
): ?>
                <span class="badge">Version <?= e($letter['version']); ?></span>
                <?php endif; ?>
            </div>
            <form
                action="/api/applications/<?= e($application['id']); ?>/letter"
                data-async
                class="panel-body form-stack"
            >
                <div class="form-error" hidden role="alert"></div>
                <div class="field">
                    <label for="letter-content">Your letter</label>
                    <textarea name="content" id="letter-content" rows="14" required>
<?= e($letter['content'] ?? ''); ?></textarea>
                </div>
                <div class="field">
                    <label for="letter-format">Document format</label>
                    <select name="format" id="letter-format">
                        <option value="pdf">PDF</option>
                        <option value="docx">DOCX</option>
                    </select>
                </div>
                <button class="btn btn-soft self-start" data-loading="Saving cover letter…">
                    <?= icon('file-check'); ?>Save letter version
                </button>
            </form>
        </section>
        <?php endif; ?>
    </div>
    <aside class="stack">
        <section class="panel">
            <div class="panel-header"><h2>The next step</h2></div>
            <div class="panel-body pt-0 form-stack">
                <a class="text-link" href="/jobs/<?= e($application['job_id']); ?>">
                    Review the opportunity<?= icon('arrow-up-right'); ?>
                </a>
                <div class="definition-row m-0">
                    <span>Deadline</span>
                    <strong><?= e(short_date($application['deadline'])); ?></strong>
                </div>
                <div class="definition-row m-0">
                    <span>Submission</span>
                    <strong><?= e(label($application['method'])); ?></strong>
                </div>
                <?php if (
    $application['application_email']
): ?>
                <p class="text-xs text-brand break-all">To: <?= e($application['application_email']); ?></p>
                <?php endif; ?> <?php if (
                $application['instructions']
            ): ?>
                <div class="soft-note">
                    <strong>Employer instructions</strong>
                    <p class="mt-2 whitespace-pre-wrap"><?= e($application['instructions']); ?></p>
                </div>
                <?php endif; ?> <?php if ($application['original_url']): ?>
                <a href="<?= e($application['original_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn">
                    <?= icon('external-link'); ?>Open employer page
                </a>
                <?php endif; ?> <?php if ($transitions): ?>
                <form
                    action="/api/applications/<?= e($application['id']); ?>/status"
                    data-async
                    class="form-stack"
                >
                    <div class="form-error" hidden role="alert"></div>
                    <div class="field">
                        <label for="next-status">Move application to</label>
                        <select name="status" id="next-status">
                            <?php foreach (
    $transitions
    as $next
): ?>
                            <option value="<?= e($next); ?>"><?= e(label($next)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="confirmation">External submission confirmation</label>
                        <textarea
                            name="confirmation"
                            id="confirmation"
                            rows="3"
                            placeholder="Required when recording a manual submission"
                        ></textarea>
                    </div>
                    <button class="btn btn-primary" data-loading="Updating application…">
                        Update status<?= icon('arrow-right'); ?>
                    </button>
                </form>
                <?php endif; ?> <?php if (
                $application['status'] === 'approved' &&
                $application['method'] === 'email'
            ): ?>
                <button
                    class="btn btn-primary"
                    data-action="/api/applications/<?= e($application['id']); ?>/send"
                    data-confirm="Send this approved application to the verified employer email address?"
                    data-loading="Queuing submission…"
                >
                    <?= icon('send'); ?>Send approved application
                </button>
                <?php endif; ?>
                <div class="soft-note">
                    Approval confirms your reviewed package. Email delivery requires a connected
                    Gmail account and Approval or Automatic mode. External forms, declarations,
                    CAPTCHAs and OTPs need your completion.
                </div>
            </div>
        </section>
    </aside>
</div>
<?php if (in_array($application['status'], ['submitted', 'awaiting_external_action', 'interview'], true)): ?>
    <section class="panel mt-6">
        <div class="panel-header"><h2>Interview details</h2></div>
        <form action="/api/applications/<?= e($application['id']); ?>/interview" data-async class="panel-body form-stack">
            <div class="form-error" role="alert" hidden></div>
            <div class="field">
                <label for="interview-time">Interview date & time (Africa/Nairobi)</label>
                <input id="interview-time" type="datetime-local" name="interview_at" value="<?= $application['interview_at'] ? e(date('Y-m-d\TH:i', strtotime($application['interview_at']))) : ''; ?>" required>
            </div>
            <button class="btn btn-primary self-start"><?= icon('calendar-days'); ?>Save interview</button>
        </form>
    </section>
<?php endif; ?>
