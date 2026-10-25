<?php
/**
 * LE WEB MAX — fin de la promo « Création de site web ».
 *
 * Jusqu'au 31/12/2026 : site vitrine 200 $ et site e-commerce 300 $ la 1re
 * année, tout compris : conception, hébergement Diva 3 (75,99 $/an) et nom de
 * domaine .com, .net ou .org. Dès le 1er janvier 2027, les frais de conception
 * augmentent de 100 $ : 300 $ et 400 $. Le hook applique ce changement une
 * seule fois, au premier cron.
 *
 * À placer dans includes/hooks/ de WHMCS.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

const LWM_DESIGN_PROMO_END = '2027-01-01';

/** slug du produit => [frais de conception promo, frais 2027, total 1re année 2027] */
const LWM_DESIGN_PRODUCTS = [
    'site-vitrine' => [124.01, 224.01, '300 $'],
    'site-e-commerce' => [224.01, 324.01, '400 $'],
];

add_hook('AfterCronJob', 1, function () {
    if (date('Y-m-d') < LWM_DESIGN_PROMO_END || \WHMCS\Config\Setting::getValue('LwmDesignPromoEnded')) {
        return;
    }

    foreach (LWM_DESIGN_PRODUCTS as $slug => [$promoFee, $regularFee, $total]) {
        $pid = Capsule::table('tblproducts_slugs')
            ->where('group_slug', 'creation-site-web')->where('slug', $slug)->value('product_id');
        if (!$pid) {
            continue;
        }
        Capsule::table('tblpricing')
            ->where('type', 'product')->where('relid', $pid)->where('asetupfee', $promoFee)
            ->update(['asetupfee' => $regularFee]);

        $description = Capsule::table('tblproducts')->where('id', $pid)->value('description');
        $description = preg_replace('/Total 1re année : .*$/u', "Total 1re année : $total", $description);
        Capsule::table('tblproducts')->where('id', $pid)->update(['description' => $description]);
    }

    \WHMCS\Config\Setting::setValue('LwmDesignPromoEnded', date('Y-m-d H:i:s'));
    logActivity('Création de site web : fin de la promo, tarifs 2027 appliqués');
});
