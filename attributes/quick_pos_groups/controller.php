<?php

namespace Concrete\Package\CommunityStoreQuickPos\Attribute\QuickPosGroups;

use CommunityStoreQuickPos\PosBlock;
use Concrete\Core\Attribute\DefaultController;
use Concrete\Core\Attribute\FontAwesomeIconFormatter;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Product groups of a kiosk page: which store groups it sells besides the event's shop groups, stored as the JSON
 * PosBlock describes (text value).
 */
class Controller extends DefaultController
{
    public function getIconFormatter()
    {
        return new FontAwesomeIconFormatter('shopping-basket');
    }

    public function form()
    {
        $this->set('groupRows', PosBlock::groupRows($this->value()));
        $this->set('prefix', $this->field('groups'));
    }

    public function createAttributeValueFromRequest()
    {
        $groups = $this->post('groups');

        return $this->createAttributeValue(PosBlock::groupsFromPost(is_array($groups) ? $groups : []));
    }

    public function createAttributeValue($value)
    {
        return parent::createAttributeValue(is_array($value) ? json_encode($value) : (string) $value);
    }

    public function getDisplayValue()
    {
        $names = [];
        foreach (PosBlock::groupRows($this->value()) as $row) {
            if ($row['on']) {
                $names[] = $row['name'];
            }
        }

        return $names ? h(implode(', ', $names)) : t('Only the event\'s shop groups');
    }

    // an empty choice is fine: the event's shop groups are always sold
    public function validateForm($data)
    {
        return true;
    }

    public function validateValue()
    {
        return true;
    }

    public function search()
    {
    }

    protected function value(): string
    {
        return is_object($this->attributeValue) ? (string) $this->getAttributeValue()->getValue() : '';
    }
}
