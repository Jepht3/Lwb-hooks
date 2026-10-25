<?php
/**
 * LWB Hooks — chargement des fonctionnalités activées.
 *
 * WHMCS charge ce fichier uniquement quand le module est actif. Chaque
 * fonctionnalité est un fichier de hooks du dossier features/, chargé si elle
 * n'est pas désactivée dans Addons > LWB Hooks.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/features.php';

$lwbHooksOff = lwb_hooks_disabled();
foreach (lwb_hooks_features() as $lwbHooksKey => $lwbHooksFeature) {
    $lwbHooksFile = __DIR__ . '/features/' . $lwbHooksFeature['file'];
    if (!in_array($lwbHooksKey, $lwbHooksOff, true) && is_file($lwbHooksFile)) {
        require_once $lwbHooksFile;
    }
}
unset($lwbHooksOff, $lwbHooksKey, $lwbHooksFeature, $lwbHooksFile);
