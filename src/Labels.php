<?php

namespace CommunityStoreQuickPos;

use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * UI texts of the POS views (package views and theme templates get the same list), and the money format for the
 * browser.
 */
class Labels
{
    public static function common(): array
    {
        return [
            'cart' => t('Your order'),
            'cartEmpty' => t('Nothing selected yet.'),
            'total' => t('Total'),
            'each' => t('each'),
            'items' => t('items'),
            'add' => t('Add'),
            'addFor' => t('Add · %s'),
            'back' => t('Back'),
            'remove' => t('Remove'),
            'soldOut' => t('Sold out'),
            'onlyLeft' => t('Only %s left'),
            'unitPrice' => t('Unit price'),
            'quantity' => t('Quantity'),
            'decrease' => t('Fewer'),
            'increase' => t('More'),
            'favourites' => t('Favourites'),
            'all' => t('All'),
            'receipt' => t('Receipt'),
            'networkError' => t('No connection to the server. Please try again.'),
            'scanHint' => t('Scan a badge'),
            'noProducts' => t('Nothing for sale right now.'),
        ];
    }

    public static function kiosk(): array
    {
        return self::common() + [
            'selfService' => t('Self-service'),
            'idleTitle' => t('Hold your badge to the reader'),
            'idleHint' => t('The reader is next to the screen.'),
            'reader' => t('Reader'),
            'badgeRecognised' => t('Badge recognised'),
            'hello' => t('Hi, %s'),
            'seat' => t('Seat %s'),
            'yourTab' => t('Your LAN tab'),
            'notYou' => t('Not you?'),
            'shop' => t('Start shopping'),
            'signOut' => t('Sign out'),
            // not t('Check out'): the event manager translates that as leaving the event, and translations are shared
            'toCart' => t('Proceed to checkout'),
            'reset' => t('Reset'),
            'cartFootnote' => t('Everything goes on your LAN tab, you pay when you leave.'),
            'reviewCart' => t('Review your cart'),
            'continueShopping' => t('Keep shopping'),
            'zeroRemoves' => t('Setting a quantity to 0 removes the item.'),
            'payNow' => t('Put it on my tab'),
            'payFootnote' => t('You confirm with your badge in the next step.'),
            'pay' => t('Confirm'),
            'confirmTitle' => t('Confirm with your badge'),
            'confirmText' => t('Hold your badge to the reader once more. Only then is it put on your tab.'),
            'amount' => t('Amount'),
            'tabAfter' => t('Tab after'),
            'backToCart' => t('Back to the cart'),
            'waiting' => t('Waiting for badge …'),
            'thanks' => t('Thanks, %s!'),
            'doneText' => t('It is on your tab. Take your things from the shelf.'),
            'newTab' => t('New tab total'),
            'done' => t('Done'),
            'signOutIn' => t('Signing out in %s'),
            'errorTitle' => t('That did not work'),
            'editCart' => t('Edit cart'),
            'cancel' => t('Cancel'),
            'tryAgain' => t('Try again'),
            'notEnrolled' => t('This device is not registered as a kiosk.'),
            'notEnrolledStaff' => t('Register this device as a kiosk. Afterwards you can sign out: the kiosk keeps working on this device only.'),
            'notEnrolledGuest' => t('Ask the staff to register this device.'),
            'deviceName' => t('Device name'),
            'enroll' => t('Register this device as a kiosk'),
            'locked' => t('Too many unknown badges. Please wait a moment.'),
            'tabLimit' => t('Tab limit'),
            // page editors, no badge needed
            'selfSignIn' => t('Use the kiosk as %s'),
            'selfHint' => t('No badge reader? You may edit this page, so you can sign in without a badge.'),
            'selfRecognised' => t('Signed in without a badge'),
            'selfPayFootnote' => t('You confirm in the next step.'),
            'selfConfirmTitle' => t('Confirm your order'),
            'selfConfirmText' => t('You are signed in without a badge, so there is no second scan. Confirm to put it on your tab.'),
        ];
    }

    public static function register(): array
    {
        return self::common() + [
            'register' => t('Register'),
            'customer' => t('Customer'),
            'anonymous' => t('Walk-in customer'),
            'searchPlaceholder' => t('Name or e-mail'),
            'searchCustomer' => t('Find customer'),
            'scanOrSearch' => t('Scan a badge or search by name.'),
            'clearCustomer' => t('Clear customer'),
            'credit' => t('Store credit'),
            'tab' => t('Event tab'),
            'badges' => t('Badges'),
            'noBadge' => t('No badge'),
            'assignBadge' => t('Assign badge'),
            'assignHint' => t('Scan the new badge now.'),
            'badgeAssigned' => t('Badge assigned.'),
            'topUp' => t('Top up credit'),
            'topUpAmount' => t('Amount'),
            'topUpDone' => t('%s loaded. New balance: %s'),
            'payWith' => t('Payment'),
            'paid' => t('Booked: #%s · %s'),
            'recent' => t('Last sales'),
            'noSales' => t('No sales yet.'),
            'newSale' => t('New sale'),
            'methodNeedsCustomer' => t('Choose a customer for this payment method.'),
            'creditTooLow' => t('Not enough credit'),
            'seat' => t('Seat %s'),
        ];
    }

    /**
     * How the store formats money, taken apart for the browser: prefix, thousands and decimal separator, suffix.
     *
     * @return array{prefix: string, suffix: string, thousand: string, decimal: string, decimals: int}
     */
    public static function money(): array
    {
        $sample = html_entity_decode(strip_tags((string) Price::format(1234.56)), ENT_QUOTES, 'UTF-8');
        if (preg_match('/^(.*?)1(\D*)234(\D+)56(.*)$/u', $sample, $m)) {
            return ['prefix' => $m[1], 'thousand' => $m[2], 'decimal' => $m[3], 'suffix' => $m[4], 'decimals' => 2];
        }

        return ['prefix' => '', 'thousand' => '', 'decimal' => '.', 'suffix' => '', 'decimals' => 2];
    }
}
