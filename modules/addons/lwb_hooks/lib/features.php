<?php
/**
 * LWB Hooks — registre des fonctionnalités et état activé / désactivé.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Fonctionnalités, par rubrique. « warn » : conséquence d'une désactivation,
 * affichée avant de confirmer.
 */
function lwb_hooks_features()
{
    return [
        'cart_domain_summary' => [
            'file' => 'cart_domain_summary.php', 'group' => 'Commande',
            'label' => 'Domaine dans le résumé de commande',
            'desc' => 'À l\'étape « Configurer », le résumé affiche le domaine choisi et son prix, et le total à payer aujourd\'hui les inclut (comme au panier).',
        ],
        'storefront' => [
            'file' => 'storefront.php', 'group' => 'Commande',
            'label' => 'Domaine inclus dans l\'offre',
            'desc' => 'Offres avec domaine offert (site vitrine, e-commerce…) : « Inclus dans votre offre » au lieu du prix du domaine. Masque aussi, dans les e-mails, le mot de passe administrateur demandé à la commande de la maintenance de site.',
        ],
        'owndomain' => [
            'file' => 'owndomain.php', 'group' => 'Commande',
            'label' => 'Contrôle « J\'ai déjà ce domaine »',
            'desc' => 'Refuse la commande si le domaine indiqué comme « déjà à moi » n\'existe pas (aucun serveur de noms), pour qu\'il soit facturé et enregistré.',
        ],
        'early_payment' => [
            'file' => 'early_payment.php', 'group' => 'Facturation',
            'label' => 'Remise pour paiement anticipé',
            'desc' => 'Ajoute une remise aux factures de renouvellement payées tôt, retirée par le cron passé la date limite. Utilisée aussi par les messages WhatsApp et les e-mails.',
            'warn' => 'Les nouvelles factures n\'auront plus de remise anticipée.',
        ],
        'reminders' => [
            'file' => 'reminders.php', 'group' => 'Facturation',
            'label' => 'Rappels de paiement à dates fixes',
            'desc' => 'J-14, J-7, J-3, J-1, puis J+1, J+3, J+4, J+10, J+20, J+30. Remplace les rappels natifs de WHMCS, qui sont désactivés.',
            'warn' => 'Plus aucun rappel de paiement ne partira par e-mail : les rappels natifs de WHMCS sont désactivés.',
        ],
        'unsuspend_nomodule' => [
            'file' => 'unsuspend_nomodule.php', 'group' => 'Facturation',
            'label' => 'Réactivation des services sans module',
            'desc' => 'Repasse en « Actif » un service suspendu sans module serveur quand sa facture est payée (WHMCS ne le fait pas seul).',
        ],
        'promo_mail' => [
            'file' => 'promo_mail.php', 'group' => 'Facturation',
            'label' => 'E-mail promotionnel récurrent',
            'desc' => 'Paiement anticipé et parrainage, envoyé aux clients ayant accepté le marketing, étalé dans la journée.',
        ],
        'addons' => [
            'file' => 'addons.php', 'group' => 'Services et domaines',
            'label' => 'Extensions payantes d\'hébergement',
            'desc' => 'Boîtes e-mail et stockage supplémentaires : recalcule et applique les limites cPanel du compte quand une extension change d\'état.',
            'warn' => 'Les limites cPanel ne suivront plus les extensions achetées, suspendues ou résiliées.',
        ],
        'porkbun' => [
            'file' => 'porkbun.php', 'group' => 'Services et domaines',
            'label' => 'Domaines achetés chez Porkbun',
            'desc' => 'Domaine payé : rattaché à Porkbun et e-mail à l\'administrateur. Dès qu\'il apparaît dans le compte Porkbun, il est activé dans WHMCS avec les vraies dates.',
        ],
        'theme' => [
            'file' => 'theme.php', 'group' => 'Espace client',
            'label' => 'Thème client lwb-theme',
            'desc' => 'Données du tableau de bord du thème lwb-theme (services, échéances, contacts, accès à la zone DNS…).',
            'warn' => 'Le tableau de bord de l\'espace client perdra ses données (thème lwb-theme).',
        ],
        'menu' => [
            'file' => 'menu.php', 'group' => 'Espace client',
            'label' => 'Menu de l\'espace client',
            'desc' => 'Personnalisation du menu principal (retrait de « Network Status »…).',
        ],
        'home_panels' => [
            'file' => 'home_panels.php', 'group' => 'Espace client',
            'label' => 'Panneaux de l\'accueil client',
            'desc' => 'Personnalisation des boutons des panneaux de l\'accueil (factures en retard…).',
        ],
        'backup' => [
            'file' => 'backup.php', 'group' => 'Maintenance',
            'label' => 'Sauvegarde quotidienne de la base WHMCS',
            'desc' => 'Copie compressée de la base chaque jour (cron quotidien), hors du dossier web, 14 jours conservés.',
            'warn' => 'La base WHMCS ne sera plus sauvegardée chaque jour.',
        ],
        'diva1_end' => [
            'file' => 'diva1_end.php', 'group' => 'Maintenance',
            'label' => 'Fin de la vente de Diva 1 (1er décembre 2027)',
            'desc' => 'Tâche datée : retire Diva 1 de la boutique au premier cron à partir du 1er décembre 2027.',
        ],
        'web_design_prices' => [
            'file' => 'web_design_prices.php', 'group' => 'Maintenance',
            'label' => 'Fin de la promo « Création de site web » (1er janvier 2027)',
            'desc' => 'Tâche datée : applique les nouveaux prix (300 $ et 400 $) au premier cron de 2027.',
        ],
    ];
}

/** Clés des fonctionnalités désactivées. */
function lwb_hooks_disabled()
{
    try {
        $raw = (string) Capsule::table('tbladdonmodules')->where('module', 'lwb_hooks')->where('setting', 'disabled')->value('value');
    } catch (\Throwable $e) {
        return [];
    }
    return array_values(array_filter(explode(',', $raw)));
}

function lwb_hooks_set_disabled(array $keys)
{
    $keys = array_values(array_intersect(array_keys(lwb_hooks_features()), $keys));
    Capsule::table('tbladdonmodules')->updateOrInsert(['module' => 'lwb_hooks', 'setting' => 'disabled'], ['value' => implode(',', $keys)]);
}
