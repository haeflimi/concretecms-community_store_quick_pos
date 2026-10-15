<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Product groups of a POS form (register block, kiosk page attribute): which store groups are sold besides the
 * event's shop groups. Icons are picked by the icon picker, not here.
 *
 * @var array $groupRows
 * @var string|null $prefix field name of the array, default "groups"
 * @var bool|null $label show the label (default) or only the list
 */
$prefix = $prefix ?? 'groups';
$id = 'qpos-group-' . substr(md5($prefix), 0, 6) . '-';
?>
<div class="form-group mb-3">
    <?php if ($label ?? true) { ?>
        <label class="form-label"><?= t('Product groups') ?></label>
    <?php } ?>
    <p class="form-text mt-0"><?= t("The event's shop groups are always sold. Tick further groups to sell them too.") ?></p>
    <?php if (!$groupRows) { ?>
        <p class="text-muted"><?= t('There are no product groups in the store yet.') ?></p>
    <?php } else { ?>
        <?php foreach ($groupRows as $row) { ?>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="<?= $id . $row['id'] ?>" name="<?= h($prefix) ?>[<?= $row['id'] ?>][on]" value="1" <?= $row['on'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= $id . $row['id'] ?>"><?= h($row['name']) ?></label>
            </div>
        <?php } ?>
    <?php } ?>
</div>
