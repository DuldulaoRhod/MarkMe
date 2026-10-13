<?php
/**
 * The pagination controls, shared by every admin table.
 *
 * Expects $pager — the array paginate() returned — and optionally $pagerLabel,
 * the word for what is being counted ("product", "order"). Rendered below the
 * table it belongs to.
 *
 * The rows-per-page selector is a plain GET form, so it works without
 * JavaScript; script.js submits it on change where scripting is available.
 * Changing the size always returns to page 1, because page 9 of a 10-row view
 * is not page 9 of a 100-row one.
 */
$label = $pagerLabel ?? 'record';
$total = (int)$pager['total'];
/* An explicit plural where adding "s" is wrong - "categorys" is the sort of
   thing nobody notices until it is on screen. */
$plural = $total === 1 ? $label : ($pagerPlural ?? $label . 's');
?>
<nav class="pager" aria-label="Table pages">
    <p class="pager-count" role="status" aria-live="polite">
        Showing <strong><?= (int)$pager['from'] ?>&ndash;<?= (int)$pager['to'] ?></strong>
        of <strong><?= $total ?></strong> <?= e($plural) ?>
    </p>

    <form method="get" class="pager-size">
        <?php /* Every other active parameter rides along as a hidden field, so
                 changing the size keeps the search, the filters and the sort.
                 page is deliberately absent: a new size starts at page 1. */ ?>
        <?php foreach ($_GET as $key => $value): ?>
            <?php if ($key === 'per_page' || $key === 'page' || is_array($value)) {
                continue;
            } ?>
            <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
        <?php endforeach; ?>
        <label for="perPage<?= e($label) ?>">Rows</label>
        <select id="perPage<?= e($label) ?>" name="per_page" class="form-select form-select-sm"
                data-pager-size>
            <?php foreach (PAGE_SIZES as $size): ?>
                <option value="<?= $size ?>" <?= (int)$pager['perPage'] === $size ? 'selected' : '' ?>>
                    <?= $size ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-secondary btn-sm">Apply</button></noscript>
    </form>

    <?php if ((int)$pager['pages'] > 1): ?>
        <div class="pager-pages">
            <a class="pager-step <?= $pager['page'] <= 1 ? 'is-off' : '' ?>"
               href="<?= e(page_url(['page' => max(1, $pager['page'] - 1)])) ?>"
               <?= $pager['page'] <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
               aria-label="Previous page">
                <?= icon('chevron-left', 14) ?> Prev
            </a>

            <?php foreach (page_numbers((int)$pager['page'], (int)$pager['pages']) as $n): ?>
                <?php if ($n === null): ?>
                    <span class="pager-gap" aria-hidden="true">&hellip;</span>
                <?php else: ?>
                    <a class="pager-num <?= $n === (int)$pager['page'] ? 'is-current' : '' ?>"
                       href="<?= e(page_url(['page' => $n])) ?>"
                       <?= $n === (int)$pager['page'] ? 'aria-current="page"' : '' ?>>
                        <?= $n ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <a class="pager-step <?= $pager['page'] >= $pager['pages'] ? 'is-off' : '' ?>"
               href="<?= e(page_url(['page' => min((int)$pager['pages'], $pager['page'] + 1)])) ?>"
               <?= $pager['page'] >= $pager['pages'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>
               aria-label="Next page">
                Next <?= icon('chevron-right', 14) ?>
            </a>
        </div>
    <?php endif; ?>
</nav>
