<?php
/**
 * LE WEB MAX — fin de la vente de l'hébergement Diva 1 (29,99 $/an).
 *
 * Diva 1 reste en vente jusqu'au 30/11/2027. Au premier cron à partir du
 * 1er décembre 2027, le hook le retire de la boutique (les clients qui l'ont
 * déjà continuent de le renouveler), remplace le prix d'appel dans la base de
 * connaissances et crée une tâche pour relire les articles qui le citent.
 * Côté lewebmax.com, le mu-plugin lwm-diva1-end.php fait de même à la même date.
 *
 * À placer dans includes/hooks/ de WHMCS.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

const LWM_DIVA1_END = '2027-12-01';

add_hook('AfterCronJob', 1, function () {
    if (date('Y-m-d') < LWM_DIVA1_END || \WHMCS\Config\Setting::getValue('LwmDiva1Retired')) {
        return;
    }

    $pid = Capsule::table('tblproducts_slugs')
        ->where('group_slug', 'hebergement-web-partage')->where('slug', 'diva-1')->value('product_id');
    if ($pid) {
        // « retired » empêche aussi la commande par lien direct, sans toucher aux services existants.
        Capsule::table('tblproducts')->where('id', $pid)->update(['hidden' => 1, 'retired' => 1]);
    }

    // Prix d'appel : Diva 2 devient la formule d'entrée (44,99 $/an).
    $replacements = [
        'Hébergement web dès 29,99 $ par an' => 'Hébergement web dès 44,99 $ par an',
        'dès 2,49 $/mois' => 'dès 3,74 $/mois',
    ];
    $toReview = [];
    foreach (Capsule::table('tblknowledgebase')->get(['id', 'title', 'article']) as $article) {
        $text = strtr($article->article, $replacements);
        if ($text !== $article->article) {
            Capsule::table('tblknowledgebase')->where('id', $article->id)->update(['article' => $text]);
        }
        if (stripos($text, 'Diva 1') !== false) {
            $toReview[] = "#{$article->id} {$article->title}";
        }
    }

    Capsule::table('tbltodolist')->insert([
        'date' => date('Y-m-d'),
        'title' => 'Diva 1 retiré de la vente : relire les articles qui le citent',
        'description' => "Base de connaissances :\n" . implode("\n", $toReview)
            . "\n\nBlog lewebmax.com : articles avec des exemples de budget « Diva 1 ».",
        'admin' => 0,
        'status' => 'Pending',
        'duedate' => date('Y-m-d', strtotime('+7 days')),
    ]);

    \WHMCS\Config\Setting::setValue('LwmDiva1Retired', date('Y-m-d H:i:s'));
    logActivity('Diva 1 retiré de la vente (fin de l\'offre au 30/11/2027)');
});
