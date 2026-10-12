<?php

namespace CommunityStoreQuickPos\Figure;

use CommunityEventManager\Figure\CallbackFigure;
use CommunityEventManager\Figure\FigureContext;
use CommunityEventManager\Figure\FigureOptions as O;
use CommunityEventManager\Figure\FigureRegistry;
use CommunityEventManager\Figure\FigureValue;

/**
 * Figures for the block "Event Figure" (community_event_manager), registered on on_event_figures_register.
 */
class ShopFigures
{
    public static function register(FigureRegistry $registry): void
    {
        $group = t('Shop & kiosk');
        $registry->register(CallbackFigure::make('shop.items_sold', t('Items sold of a product (search text)'), $group, 'fas fa-shopping-basket', [O::TEXT], static function (FigureContext $c, array $o) {
            $product = trim((string) ($o[O::TEXT] ?? ''));
            if ($product === '') {
                return null;
            }

            return FigureValue::number($c->db->fetchOne('SELECT SUM(oi.oiQty) FROM CommunityStoreOrderItems oi JOIN CommunityStoreOrders o ON o.oID = oi.oID WHERE o.oPaid IS NOT NULL AND o.oCancelled IS NULL AND o.oRefunded IS NULL AND oi.oiProductName LIKE ?', ['%' . $product . '%']) ?: 0);
        }));

        $registry->register(CallbackFigure::make('shop.orders', t('Paid orders'), $group, 'fas fa-receipt', [], static function (FigureContext $c, array $o) {
            return FigureValue::number($c->db->fetchOne('SELECT COUNT(*) FROM CommunityStoreOrders WHERE oPaid IS NOT NULL AND oCancelled IS NULL AND oRefunded IS NULL'));
        }));

        $registry->register(CallbackFigure::make('pos.badges', t('Kiosk badges handed out'), $group, 'fas fa-id-badge', [], static function (FigureContext $c, array $o) {
            return FigureValue::number($c->db->fetchOne('SELECT COUNT(*) FROM QuickPosBadges'));
        }));
    }
}
