<?php $result = json_decode($review['result_json'], true, 64, JSON_THROW_ON_ERROR); ?>
<div class="cv-score-card">
    <div class="cv-score-number"><?= $result['score'] === null ? '—' : e($result['score']); ?><small><?= $result['score'] === null ? 'Not scored' : 'out of 100'; ?></small></div>
    <div><span class="eyebrow"><?= e($result['method']); ?></span><h2><?= e($result['label']); ?></h2><p>Checked for <?= e($result['field']); ?>.</p></div>
</div>
<?php if ($result['checks']): ?><div class="cv-checks"><?php foreach ($result['checks'] as $check): ?><div class="cv-check <?= $check['passed'] ? 'passed' : ''; ?>"><?= icon($check['passed'] ? 'circle-check' : 'circle-alert'); ?><span><?= e($check['name']); ?></span><strong><?= $check['passed'] ? 'Found' : 'Needs a look'; ?></strong></div><?php endforeach; ?></div><?php endif; ?>
<p class="setup-note"><?= e($result['message']); ?></p>
