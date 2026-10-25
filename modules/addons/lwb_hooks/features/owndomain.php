<?php
/**
 * LE WEB MAX — block "use my own domain" when the domain is not registered.
 *
 * A client could order hosting with "I already own this domain" for a name that
 * does not exist, so the domain was never billed nor registered. At checkout,
 * every domain attached to a product without a matching domain order must have
 * name servers (i.e. exist at the registry).
 *
 * @category  Ordering
 * @author    JNK
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function lwm_domain_exists($domain)
{
    $domain = strtolower(trim($domain, " .\t\n\r"));
    if ($domain === '' || strpos($domain, '.') === false) {
        return true;
    }
    if (@checkdnsrr($domain . '.', 'NS') || @checkdnsrr($domain . '.', 'SOA')) {
        return true;
    }
    // DNS can fail temporarily: only block when the name clearly has no record at all.
    return @checkdnsrr($domain . '.', 'A') || @checkdnsrr($domain . '.', 'MX');
}

add_hook('ShoppingCartValidateCheckout', 5, function ($vars) {
    $errors = [];
    try {
        $cart = $_SESSION['cart'] ?? [];
        $ordered = [];
        foreach ((array) ($cart['domains'] ?? []) as $d) {
            if (!empty($d['domain'])) {
                $ordered[strtolower($d['domain'])] = true;
            }
        }
        foreach ((array) ($cart['products'] ?? []) as $p) {
            $domain = strtolower(trim((string) ($p['domain'] ?? '')));
            if ($domain === '' || isset($ordered[$domain])) {
                continue;
            }
            if (!lwm_domain_exists($domain)) {
                $errors[] = "Le nom de domaine {$domain} n'est pas encore enregistré. "
                    . "Retirez l'offre du panier puis ajoutez-la de nouveau en choisissant « Enregistrer un nouveau domaine ».";
            }
        }
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX domaine existant : ' . $e->getMessage());
    }
    return $errors;
});
