<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Form\Service\Form $form
 * @var array $eventOptions
 * @var int $eventID
 * @var float $tabLimit
 * @var int $idleSeconds
 * @var int $fullscreen
 * @var string $headline
 * @var string $intro
 */
?>
<div class="form-group mb-3">
    <?= $form->label('eventID', t('Event')) ?>
    <?= $form->select('eventID', $eventOptions, (int) $eventID) ?>
    <div class="form-text"><?= t('Kiosk sales go on the event tab of checked-in participants. The event needs the tab enabled.') ?></div>
</div>
<?php View::element('block_groups', ['groupRows' => $groupRows, 'icons' => $icons], 'community_store_quick_pos'); ?>
<div class="row">
    <div class="col-sm-6 form-group mb-3">
        <?= $form->label('tabLimit', t('Tab limit')) ?>
        <?= $form->number('tabLimit', (float) $tabLimit, ['min' => 0, 'step' => '0.05']) ?>
        <div class="form-text"><?= t('The kiosk refuses sales above this tab total. 0 = no limit.') ?></div>
    </div>
    <div class="col-sm-6 form-group mb-3">
        <?= $form->label('idleSeconds', t('Sign out after (seconds)')) ?>
        <?= $form->number('idleSeconds', (int) $idleSeconds, ['min' => 15, 'max' => 600]) ?>
        <div class="form-text"><?= t('Without a touch, the kiosk signs the customer out.') ?></div>
    </div>
</div>
<div class="form-group mb-3">
    <?= $form->label('headline', t('Headline of the start screen')) ?>
    <?= $form->text('headline', (string) $headline, ['maxlength' => 255]) ?>
    <div class="form-text"><?= t('Put *stars* around a word to highlight it.') ?></div>
</div>
<div class="form-group mb-3">
    <?= $form->label('intro', t('Text of the start screen')) ?>
    <?= $form->textarea('intro', (string) $intro, ['rows' => 2]) ?>
</div>
<div class="form-check mb-3">
    <?= $form->checkbox('fullscreen', 1, (int) $fullscreen === 1) ?>
    <?= $form->label('fullscreen', t('Cover the whole screen (hides the page around the kiosk)'), ['class' => 'form-check-label']) ?>
</div>
