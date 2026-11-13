<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">YOUR EXPERIENCE IS THE STARTING POINT</div>
        <h1>My CV & career profile</h1>
        <p>A truthful picture of what you bring. The foundation of every application.</p>
    </div>
    <div class="heading-actions">
        <button class="btn" data-open-dialog="upload-dialog"><?= icon('upload'); ?>Upload CV</button>
        <form action="/api/profile/export" data-async>
            <input type="hidden" name="format" value="pdf" />
            <button class="btn btn-soft"><?= icon('download'); ?>Export PDF</button>
        </form>
        <form action="/api/profile/export" data-async>
            <input type="hidden" name="format" value="docx" />
            <button class="btn btn-soft"><?= icon('download'); ?>DOCX</button>
        </form>
    </div>
</div>
<div class="notice">
    <?= icon('shield-check'); ?>
    <div>
        <strong>Your experience, accurately represented.</strong>
        <p>
            Only verified facts should go into your profile. AI imports are drafts for your review.
            Save changes before exporting.
        </p>
    </div>
</div>
<div class="content-grid">
    <form action="/api/profile" method="post" data-async id="profile-form" class="stack">
        <div class="form-error" role="alert" hidden></div>
        <section class="panel">
            <div class="panel-header">
                <h2>The introduction</h2>
                <span class="badge <?= $profile[ 'verified_at' ] ? 'badge-green' : 'badge-warm'; ?>"><?= $profile['verified_at'] ? 'Verified' : 'Needs your review'; ?></span>
            </div>
            <div class="panel-body form-grid">
                <?php foreach (
                [
                    'full_name' => 'Full name',
                    'email' => 'Email address',
                    'phone' => 'Telephone',
                    'location' => 'Location',
                ]
                as $field => $name
            ): ?>
                <div class="field">
                    <label for="profile-<?= e($field); ?>"><?= e($name); ?></label>
                    <input
                        name="<?= e($field); ?>"
                        id="profile-<?= e($field); ?>"
                        type="<?= $field === 'email' ? 'email' : 'text'; ?>"
                        value="<?= e($profile[$field]); ?>"
                        <?= in_array($field, ['full_name', 'email'], true) ? 'required' : ''; ?>
                    />
                </div>
                <?php endforeach; ?>
                <div class="field full-width">
                    <label for="profile-summary">Professional summary</label>
                    <textarea
                        name="summary"
                        id="profile-summary"
                        rows="5"
                        placeholder="What do you do, and what experience do you bring?"
                    >
<?= e($profile['summary']); ?></textarea>
                </div>
                <div class="field full-width">
                    <label for="profile-skills">Skills</label>
                    <textarea
                        name="skills"
                        id="profile-skills"
                        rows="3"
                        placeholder="Separate verified skills with commas"
                    >
<?= e(implode(', ', $profile['skills'])); ?></textarea>
                </div>
            </div>
        </section>
        <?php foreach (
            ['employment' => 'Employment history', 'education' => 'Education']
            as $kind => $name
        ): ?>
        <section class="panel">
            <div class="panel-header">
                <h2><?= e($name); ?></h2>
                <button class="btn btn-sm" type="button" data-add-record="<?= e($kind); ?>">
                    <?= icon('plus'); ?>Add entry
                </button>
            </div>
            <div class="panel-body" data-record-list="<?= e($kind); ?>"><?php foreach ($profile[$kind] ?: [[]] as $index => $record):
    require BASE_PATH . '/Resources/Views/components/career-record.php';
endforeach; ?></div>
        </section>
        <template id="template-<?= e($kind); ?>"><?php
$index = '__INDEX__';
$record = [];
require BASE_PATH . '/Resources/Views/components/career-record.php';
?></template>
        <?php endforeach; ?>
        <section class="panel">
            <div class="panel-header"><h2>The rest of your story</h2></div>
            <div class="panel-body form-grid">
                <?php foreach (
            [
                'certifications' => 'Certifications',
                'languages' => 'Languages',
                'achievements' => 'Other achievements',
                'portfolio_links' => 'Portfolio and professional links',
                'references' => 'Professional references (optional)',
            ]
            as $field => $name
        ): ?>
                <div class="field <?= $field === 'references' ? 'full-width' : ''; ?>">
                    <label for="detail-<?= e($field); ?>"><?= e($name); ?></label>
                    <textarea
                        name="details[<?= e($field); ?>]"
                        id="detail-<?= e($field); ?>"
                        rows="3"
                    >
<?= e($profile['details'][$field] ?? ''); ?></textarea>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="panel">
            <div class="panel-header"><h2>Your next move</h2></div>
            <div class="panel-body form-grid">
                <?php foreach (
            [
                'titles' => 'Preferred job titles',
                'industries' => 'Preferred industries',
                'locations' => 'Preferred locations',
                'employment_types' => 'Employment preferences',
            ]
            as $field => $name
        ): ?>
                <div class="field">
                    <label for="pref-<?= e($field); ?>"><?= e($name); ?></label>
                    <input
                        id="pref-<?= e($field); ?>"
                        name="preferences[<?= e($field); ?>]"
                        value="<?= e(implode(', ', $profile['preferences'][$field] ?? [])); ?>"
                        placeholder="Separate with commas"
                    />
                </div>
                <?php endforeach; ?>
                <div class="field">
                    <label for="pref-salary">Minimum expected monthly salary (KES)</label>
                    <input
                        type="number"
                        min="0"
                        id="pref-salary"
                        name="preferences[minimum_salary]"
                        value="<?= e($profile['preferences']['minimum_salary'] ?? 0); ?>"
                    />
                </div>
                <div class="field">
                    <label for="pref-workplace">Work arrangement</label>
                    <select name="preferences[workplace]" id="pref-workplace">
                        <?php foreach (
    ['any', 'remote', 'hybrid', 'office']
    as $type
): ?>
                        <option value="<?= e($type); ?>" <?= ($profile['preferences']['workplace'] ?? 'any') === $type ? 'selected' : ''; ?>>
                            <?= e(label($type)); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>
        <section class="panel panel-body">
            <label class="check-label">
                <input type="checkbox" name="verified" value="1" <?= $profile[ 'verified_at' ] ? 'checked' : ''; ?> />
                I have reviewed this profile and confirm that its qualifications, experience, dates,
                skills and achievements are accurate.
            </label>
            <div class="form-actions">
                <button
                    class="btn btn-primary"
                    type="submit"
                    data-loading="Saving your career profile…"
                >
                    <?= icon('check'); ?>Save career profile
                </button>
            </div>
        </section>
    </form>
    <aside class="stack">
        <section class="panel">
            <div class="panel-header">
                <h2>Your CV versions</h2>
                <span class="badge"><?= count($documents); ?></span>
            </div>
            <?php if (!$documents):
    empty_state(
        'file-user',
        'Your story belongs here',
        'Upload your existing CV or export your career profile to get started.',
    );
else:
    foreach (
        $documents
        as $document
    ): ?>
            <div class="job-row flex-wrap">
                <div>
                    <a class="text-link break-all" href="/documents/<?= e($document['id']); ?>">
                        <?= e($document['name']); ?>
                    </a>
                    <p><?= e(short_date($document['created_at'])); ?> <?= $document[ 'is_master' ] ? '· Master CV' : ''; ?></p>
                </div>
                <?php if (
    $document['extracted_text']
): ?>
                <button
                    class="btn btn-sm"
                    type="button"
                    data-profile-import="<?= e($document['id']); ?>"
                    data-loading="Extracting your profile…"
                >
                    Import into profile
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach;
endif; ?>
        </section>
        <div class="soft-note">
            Importing a CV creates an editable draft. Your current profile is only replaced when you
            review and save it.
        </div>
    </aside>
</div>
