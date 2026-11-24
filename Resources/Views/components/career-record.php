<div class="record" data-career-record="<?= e($kind); ?>">
    <div class="record-top">
        <strong><?= $kind === 'employment' ? 'Experience' : 'Education'; ?></strong>
        <button class="icon-button" type="button" x-on:click="removeRecord($el)" aria-label="Remove this entry">
            <?= icon('x'); ?>
        </button>
    </div>
    <div class="form-grid">
        <?php
        $recordFields = $kind === 'employment'
            ? [
                'position' => 'Position',
                'employer' => 'Employer',
                'start_date' => 'Start date (as recorded)',
                'end_date' => 'End date / Present',
                'achievements' => 'Responsibilities and achievements',
            ]
            : [
                'qualification' => 'Qualification',
                'institution' => 'Institution',
                'completion_year' => 'Completion year',
            ];
        ?>
        <?php foreach ($recordFields as $field => $name): ?>
            <div class="field <?= $field === 'achievements' ? 'full-width' : ''; ?>">
                <label for="<?= e($kind . '-' . $index . '-' . $field); ?>"><?= e($name); ?></label>
                <?php if ($field === 'achievements'): ?>
                    <textarea
                        id="<?= e($kind . '-' . $index . '-' . $field); ?>"
                        name="<?= e($kind); ?>[<?= e($index); ?>][<?= e($field); ?>]"
                        data-record-field="<?= e($field); ?>"
                    ><?= e($record[$field] ?? ''); ?></textarea>
                <?php else: ?>
                    <input
                        id="<?= e($kind . '-' . $index . '-' . $field); ?>"
                        name="<?= e($kind); ?>[<?= e($index); ?>][<?= e($field); ?>]"
                        data-record-field="<?= e($field); ?>"
                        value="<?= e($record[$field] ?? ''); ?>"
                    >
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
