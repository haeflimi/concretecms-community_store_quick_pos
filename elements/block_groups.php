<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Product groups of a POS block form: which store groups are sold (besides the event's shop groups) and their icon.
 *
 * @var array $groupRows
 * @var array $icons
 */
?>
<div class="form-group mb-3">
    <label class="form-label"><?= t('Product groups') ?></label>
    <p class="form-text mt-0"><?= t("The event's shop groups are always sold. Tick further groups to sell them too, and choose an icon per group.") ?></p>
    <?php if (!$groupRows) { ?>
        <p class="text-muted"><?= t('There are no product groups in the store yet.') ?></p>
    <?php } else { ?>
        <table class="table table-sm align-middle">
            <tbody>
                <?php foreach ($groupRows as $row) { ?>
                    <tr>
                        <td style="width: 2rem">
                            <input type="checkbox" class="form-check-input" id="qpos-group-<?= $row['id'] ?>" name="groups[<?= $row['id'] ?>][on]" value="1" <?= $row['on'] ? 'checked' : '' ?>>
                        </td>
                        <td><label for="qpos-group-<?= $row['id'] ?>"><?= h($row['name']) ?></label></td>
                        <td style="width: 12rem">
                            <select class="form-select form-select-sm" name="groups[<?= $row['id'] ?>][icon]" aria-label="<?= h(t('Icon of %s', $row['name'])) ?>">
                                <?php foreach ($icons as $icon => $label) { ?>
                                    <option value="<?= h($icon) ?>" <?= $row['icon'] === $icon ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>
