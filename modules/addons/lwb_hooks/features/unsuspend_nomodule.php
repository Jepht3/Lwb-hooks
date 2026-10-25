<?php
/**
 * LE WEB MAX — réactivation automatique des services sans module serveur.
 *
 * Pour un produit sans module serveur (ex. health.polycliniquelejourdain.com),
 * WHMCS n'a aucune commande à envoyer : un paiement ne remet donc jamais le
 * service en « Actif ». Ce hook le fait quand une facture est payée (bouton
 * « Ajouter un paiement », passerelle de paiement) et que le service
 * rattaché est « Suspendu ». Les produits avec un module serveur ne sont pas
 * touchés : WHMCS les réactive lui-même.
 *
 * À placer dans includes/hooks/ de WHMCS.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('InvoicePaid', 1, function ($vars) {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if (!$invoiceId) {
            return;
        }
        $services = Capsule::table('tblinvoiceitems')
            ->join('tblhosting', 'tblhosting.id', '=', 'tblinvoiceitems.relid')
            ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblinvoiceitems.invoiceid', $invoiceId)
            ->where('tblinvoiceitems.type', 'Hosting')
            ->where('tblhosting.domainstatus', 'Suspended')
            ->where(function ($q) {
                $q->whereNull('tblproducts.servertype')->orWhere('tblproducts.servertype', '');
            })
            ->distinct()
            ->get(['tblhosting.id', 'tblhosting.domain']);

        foreach ($services as $service) {
            Capsule::table('tblhosting')->where('id', $service->id)->update([
                'domainstatus' => 'Active',
                'suspendreason' => '',
            ]);
            logActivity("Service #{$service->id} ({$service->domain}) remis en Actif après paiement de la facture #{$invoiceId} (produit sans module serveur)");
        }
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX réactivation sans module : ' . $e->getMessage());
    }
});