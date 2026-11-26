<?php $page = max(1, min(10000, (int) ($_GET['page'] ?? 1))); $query = '&q=' . rawurlencode(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''); ?>
<nav class="heading-actions mt-5" aria-label="Catalogue pages">
<?php if ($page > 1): ?><a class="btn" href="<?= e(app_url($paginationPath . '&page=' . ($page - 1) . $query)); ?>">Previous</a><?php endif; ?>
<?php if (count($jobs) === 30 && $page < 10000): ?><a class="btn" href="<?= e(app_url($paginationPath . '&page=' . ($page + 1) . $query)); ?>">Next</a><?php endif; ?>
</nav>
