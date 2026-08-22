<?php
/**
 * Pagination control driven by the meta block the API returns.
 *
 * The existing query string is preserved so filters survive paging. Every
 * value is escaped before it is written back into a URL.
 *
 * @var array{page?: int, per_page?: int, total?: int, total_pages?: int} $meta
 */

$page  = (int) ($meta['page'] ?? 1);
$pages = (int) ($meta['total_pages'] ?? 1);
$total = (int) ($meta['total'] ?? 0);

if ($pages <= 1) {
    return;
}

$query = $_GET;
unset($query['page']);

$link = static function (int $target) use ($query): string {
    $query['page'] = $target;

    return '?' . http_build_query($query);
};

$start = max(1, $page - 2);
$end   = min($pages, $page + 2);
?>
<nav aria-label="Pagination" class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
    <span class="small text-muted">
        Page <?= e((string) $page) ?> of <?= e((string) $pages) ?> &middot; <?= e((string) $total) ?> records
    </span>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e($link(max(1, $page - 1))) ?>" aria-label="Previous">
                <i class="fa fa-chevron-left"></i>
            </a>
        </li>
        <?php if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= e($link(1)) ?>">1</a></li>
            <?php if ($start > 2): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= e($link($i)) ?>"><?= e((string) $i) ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($end < $pages): ?>
            <?php if ($end < $pages - 1): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= e($link($pages)) ?>"><?= e((string) $pages) ?></a></li>
        <?php endif; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e($link(min($pages, $page + 1))) ?>" aria-label="Next">
                <i class="fa fa-chevron-right"></i>
            </a>
        </li>
    </ul>
</nav>
