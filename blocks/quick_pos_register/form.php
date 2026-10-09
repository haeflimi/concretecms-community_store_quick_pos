<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Form\Service\Form $form
 * @var array $eventOptions
 * @var array $methodOptions
 * @var int $eventID
 * @var string $methods
 * @var int $topUp
 */
$selected = explode(',', (string) $methods);
?>
<div class="form-group mb-3">
    <?= $form->label('eventID', t('Event')) ?>
    <?= $form->select('eventID', $eventOptions, (int) $eventID) ?>
    <div class="form-text"><?= t("Sales \"on the tab\" go to this event's tab; its shop groups are sold here.") ?></div>
</div>
<?php View::element('block_groups', ['groupRows' => $groupRows, 'icons' => $icons], 'community_store_quick_pos'); ?>
<div class="form-group mb-3">
    <label class="form-label"><?= t('Payment methods') ?></label>
    <?php foreach ($methodOptions as $id => $name) { ?>
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="qpos-method-<?= h($id) ?>" name="methods[]" value="<?= h($id) ?>" <?= in_array($id, $selected, true) ? 'checked' : '' ?>>
            <label class="form-check-label" for="qpos-method-<?= h($id) ?>"><?= h($name) ?></label>
        </div>
    <?php } ?>
    <div class="form-text"><?= t('Store credit needs the Community Store Credit package, the event tab the Community Event Manager.') ?></div>
</div>
<div class="form-check mb-3">
    <?= $form->checkbox('topUp', 1, (int) $topUp === 1) ?>
    <?= $form->label('topUp', t('Staff can top up store credit here'), ['class' => 'form-check-label']) ?>
</div>
