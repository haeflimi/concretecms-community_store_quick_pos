<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var array $groupRows
 * @var string $prefix the field name of the groups array
 */
View::element('pos_groups', ['groupRows' => $groupRows, 'prefix' => $prefix, 'label' => false], 'community_store_quick_pos');
