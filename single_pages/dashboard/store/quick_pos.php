<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var string $tab
 * @var string $keywords
 * @var array $badges
 * @var array $devices
 * @var array $settings
 * @var int $attributeBadges
 */
$date = app('date');
$tabs = ['badges' => t('Badges'), 'devices' => t('Kiosk devices'), 'settings' => t('Settings')];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($tabs as $key => $label) { ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= h($view->action('') . '?tab=' . $key) ?>"><?= h($label) ?></a></li>
    <?php } ?>
</ul>

<?php if ($tab === 'devices') { ?>
    <p><?= t('A device becomes a kiosk when a staff member opens a kiosk page on it and registers it. Revoke lost or retired devices here.') ?></p>
    <table class="table">
        <thead><tr><th><?= t('Name') ?></th><th><?= t('Registered') ?></th><th><?= t('Last seen') ?></th><th></th></tr></thead>
        <tbody>
            <?php foreach ($devices as $device) { ?>
                <tr class="<?= $device['revokedAt'] ? 'text-muted' : '' ?>">
                    <td>
                        <?php if ($device['revokedAt']) { ?>
                            <?= h($device['name']) ?> <span class="badge bg-secondary"><?= t('Revoked') ?></span>
                        <?php } else { ?>
                            <form method="post" action="<?= h($view->action('rename_device', $device['id'])) ?>" class="d-flex gap-2">
                                <?php $token->output('rename_device') ?>
                                <input class="form-control form-control-sm" name="name" value="<?= h($device['name']) ?>" maxlength="100" aria-label="<?= t('Name') ?>">
                                <button class="btn btn-sm btn-secondary"><?= t('Save') ?></button>
                            </form>
                        <?php } ?>
                    </td>
                    <td><?= h($date->formatDateTime($device['createdAt'])) ?><?= $device['createdByName'] ? ' · ' . h($device['createdByName']) : '' ?></td>
                    <td><?= $device['lastSeenAt'] ? h($date->formatDateTime($device['lastSeenAt'])) : '—' ?></td>
                    <td class="text-end">
                        <?php if (!$device['revokedAt']) { ?>
                            <form method="post" action="<?= h($view->action('revoke_device', $device['id'])) ?>" onsubmit="return confirm(<?= h(json_encode(t('Revoke this device?'))) ?>)">
                                <?php $token->output('revoke_device') ?>
                                <button class="btn btn-sm btn-outline-danger"><?= t('Revoke') ?></button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            <?php if (!$devices) { ?><tr><td colspan="4" class="text-muted"><?= t('No kiosk devices yet.') ?></td></tr><?php } ?>
        </tbody>
    </table>

<?php } elseif ($tab === 'settings') { ?>
    <form method="post" action="<?= h($view->action('save_settings')) ?>" style="max-width: 640px">
        <?php $token->output('save_settings') ?>
        <fieldset class="mb-4">
            <legend><?= t('Badge reading') ?></legend>
            <p class="text-muted"><?= t('Readers often cut characters off the start or end of a badge id. Shorter reads still identify a badge if no other badge matches as well. Changes apply to badges assigned (or imported again) afterwards.') ?></p>
            <div class="row">
                <div class="col-sm-6 mb-3">
                    <label class="form-label" for="min_length"><?= t('Minimum characters of a read') ?></label>
                    <input class="form-control" type="number" id="min_length" name="min_length" min="4" max="32" value="<?= (int) $settings['min_length'] ?>">
                </div>
                <div class="col-sm-6 mb-3">
                    <label class="form-label" for="max_missing"><?= t('Characters that may be missing') ?></label>
                    <input class="form-control" type="number" id="max_missing" name="max_missing" min="0" max="8" value="<?= (int) $settings['max_missing'] ?>">
                </div>
            </div>
        </fieldset>
        <fieldset class="mb-4">
            <legend><?= t('Kiosk') ?></legend>
            <div class="row">
                <div class="col-sm-4 mb-3">
                    <label class="form-label" for="session_max"><?= t('Session length (s)') ?></label>
                    <input class="form-control" type="number" id="session_max" name="session_max" min="60" max="3600" value="<?= (int) $settings['session_max'] ?>">
                </div>
                <div class="col-sm-4 mb-3">
                    <label class="form-label" for="max_failures"><?= t('Unknown badges per minute') ?></label>
                    <input class="form-control" type="number" id="max_failures" name="max_failures" min="1" max="100" value="<?= (int) $settings['max_failures'] ?>">
                </div>
                <div class="col-sm-4 mb-3">
                    <label class="form-label" for="lock_seconds"><?= t('Then locked for (s)') ?></label>
                    <input class="form-control" type="number" id="lock_seconds" name="lock_seconds" min="5" max="3600" value="<?= (int) $settings['lock_seconds'] ?>">
                </div>
            </div>
        </fieldset>
        <button class="btn btn-primary"><?= t('Save') ?></button>
    </form>

<?php } else { ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <form method="post" action="<?= h($view->action('assign_badge')) ?>" class="card card-body">
                <?php $token->output('assign_badge') ?>
                <h5><?= t('Assign a badge') ?></h5>
                <div class="mb-2">
                    <label class="form-label" for="qpos-user"><?= t('User name or e-mail') ?></label>
                    <input class="form-control" id="qpos-user" name="user" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="qpos-tag"><?= t('Badge id (scan it into this field)') ?></label>
                    <input class="form-control" id="qpos-tag" name="tag" required autocomplete="off">
                </div>
                <div><button class="btn btn-primary"><?= t('Assign') ?></button></div>
            </form>
        </div>
        <div class="col-lg-6">
            <div class="card card-body h-100">
                <h5><?= t('Import from the credit manager') ?></h5>
                <p class="text-muted"><?= t('%s users have a "badge_id" attribute (credit manager). Import them as badges; running it again is safe.', $attributeBadges) ?></p>
                <form method="post" action="<?= h($view->action('import_badges')) ?>">
                    <?php $token->output('import_badges') ?>
                    <button class="btn btn-secondary" <?= $attributeBadges ? '' : 'disabled' ?>><?= t('Import badges') ?></button>
                </form>
            </div>
        </div>
    </div>

    <form method="get" action="<?= h($view->action('')) ?>" class="d-flex gap-2 mb-3" style="max-width: 480px">
        <input type="hidden" name="tab" value="badges">
        <input class="form-control" type="search" name="keywords" value="<?= h($keywords) ?>" placeholder="<?= t('User, e-mail or last characters') ?>" aria-label="<?= t('Search') ?>">
        <button class="btn btn-secondary"><?= t('Search') ?></button>
    </form>
    <table class="table">
        <thead><tr><th><?= t('User') ?></th><th><?= t('Badge') ?></th><th><?= t('Assigned') ?></th><th><?= t('Last used') ?></th><th></th></tr></thead>
        <tbody>
            <?php foreach ($badges as $badge) { ?>
                <tr>
                    <td><?= h($badge['userName'] ?? '#' . $badge['uID']) ?> <small class="text-muted"><?= h($badge['userEmail'] ?? '') ?></small></td>
                    <td><code>…<?= h($badge['hint']) ?></code></td>
                    <td><?= h($date->formatDateTime($badge['createdAt'])) ?></td>
                    <td><?= $badge['lastUsedAt'] ? h($date->formatDateTime($badge['lastUsedAt'])) : '—' ?></td>
                    <td class="text-end">
                        <form method="post" action="<?= h($view->action('revoke_badge', $badge['id'])) ?>" onsubmit="return confirm(<?= h(json_encode(t('Revoke this badge?'))) ?>)">
                            <?php $token->output('revoke_badge') ?>
                            <button class="btn btn-sm btn-outline-danger"><?= t('Revoke') ?></button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            <?php if (!$badges) { ?><tr><td colspan="5" class="text-muted"><?= t('No badges found.') ?></td></tr><?php } ?>
        </tbody>
    </table>
<?php } ?>
