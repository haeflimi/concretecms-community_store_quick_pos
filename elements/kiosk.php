<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Plain kiosk view (Bootstrap), rendered by js/dist/quick-pos.js from the page type controller's $kiosk. Themes can
 * render their own UI from the same data on top of js/src/core (the Blackout theme does).
 *
 * @var array $kiosk
 */
?>
<div data-qpos="kiosk" class="qpos-mount">
    <script type="application/json"><?= json_encode($kiosk, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <noscript><div class="alert alert-warning"><?= t('The kiosk needs JavaScript.') ?></div></noscript>
</div>
