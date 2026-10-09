<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Plain view (Bootstrap). Themes can override it and render their own UI from $kiosk (the same data the package's
 * script gets), on top of js/src/core.
 *
 * @var array $kiosk
 */
$this->requireAsset('community_store_quick_pos');
?>
<div data-qpos="kiosk" class="qpos-mount">
    <script type="application/json"><?= json_encode($kiosk, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <noscript><div class="alert alert-warning"><?= t('The kiosk needs JavaScript.') ?></div></noscript>
</div>
