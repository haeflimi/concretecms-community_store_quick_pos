<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Plain view (Bootstrap). Themes can override it and render their own UI from $register, on top of js/src/core.
 *
 * @var bool $staff
 * @var array|null $register
 */
if (!$staff) { ?>
    <div class="alert alert-warning"><?= t('The register is for the staff only. Please sign in.') ?></div>
<?php
    return;
}
$this->requireAsset('community_store_quick_pos');
?>
<div data-qpos="register" class="qpos-mount">
    <script type="application/json"><?= json_encode($register, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <noscript><div class="alert alert-warning"><?= t('The register needs JavaScript.') ?></div></noscript>
</div>
