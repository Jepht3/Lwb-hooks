<?php
/**
 * LE WEB MAX — email promotionnel récurrent (paiement anticipé + parrainage).
 *
 * Envoie le modèle LWM_PROMO_TEMPLATE à chaque client actif ayant accepté les
 * emails marketing, au plus une fois tous les LWM_PROMO_EVERY_DAYS jours.
 * Les envois sont étalés (LWM_PROMO_MAX_PER_HOUR par heure, en journée) pour
 * rester sous la limite d'emails par heure du serveur.
 *
 * Pour suspendre la campagne : désactiver le modèle d'email dans WHMCS.
 *
 * @category  Automation
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

const LWM_PROMO_TEMPLATE = 'LWM Promotion Avantages Clients';
const LWM_PROMO_EVERY_DAYS = 30;
const LWM_PROMO_MAX_PER_HOUR = 8;
// Server time (GMT): 08h–17h = 09h–18h à Kinshasa, 10h–19h à Lubumbashi/Goma.
const LWM_PROMO_START_HOUR = 8;
const LWM_PROMO_END_HOUR = 17;

function lwm_promo_table()
{
    if (!Capsule::schema()->hasTable('mod_lwm_promo_mail')) {
        Capsule::schema()->create('mod_lwm_promo_mail', function ($t) {
            $t->integer('client_id')->unsigned()->primary();
            $t->dateTime('last_sent');
        });
    }
}

/**
 * Clients due for the promo email, at most $limit.
 */
function lwm_promo_due_clients($limit)
{
    lwm_promo_table();
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . LWM_PROMO_EVERY_DAYS . ' days'));

    return Capsule::table('tblclients as c')
        ->leftJoin('mod_lwm_promo_mail as p', 'p.client_id', '=', 'c.id')
        ->where('c.status', 'Active')
        ->where('c.marketing_emails_opt_in', 1)
        ->where(function ($q) use ($cutoff) {
            $q->whereNull('p.last_sent')->orWhere('p.last_sent', '<', $cutoff);
        })
        ->orderBy('c.id')
        ->limit($limit)
        ->pluck('c.id')
        ->all();
}

/**
 * @return int number of emails sent
 */
function lwm_promo_send_batch()
{
    $hour = (int) date('G');
    if ($hour < LWM_PROMO_START_HOUR || $hour >= LWM_PROMO_END_HOUR) {
        return 0;
    }

    $template = Capsule::table('tblemailtemplates')
        ->where('name', LWM_PROMO_TEMPLATE)
        ->where('language', '')
        ->first(['disabled']);
    if (!$template || $template->disabled) {
        return 0;
    }

    lwm_promo_table();
    $sentLastHour = Capsule::table('mod_lwm_promo_mail')
        ->where('last_sent', '>=', date('Y-m-d H:i:s', strtotime('-1 hour')))
        ->count();
    $budget = LWM_PROMO_MAX_PER_HOUR - $sentLastHour;
    if ($budget <= 0) {
        return 0;
    }

    $sent = 0;
    foreach (lwm_promo_due_clients($budget) as $clientId) {
        $result = localAPI('SendEmail', ['messagename' => LWM_PROMO_TEMPLATE, 'id' => $clientId]);
        // Recorded even on failure so a bad address is not retried every cron run.
        Capsule::table('mod_lwm_promo_mail')->updateOrInsert(
            ['client_id' => $clientId],
            ['last_sent' => date('Y-m-d H:i:s')]
        );
        if (($result['result'] ?? '') === 'success') {
            $sent++;
        } else {
            logActivity("LE WEB MAX promo : échec client #{$clientId} : " . ($result['message'] ?? ''));
        }
    }

    return $sent;
}

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        $count = lwm_promo_send_batch();
        if ($count) {
            logActivity("LE WEB MAX promo : {$count} email(s) promotionnel(s) envoyé(s)");
        }
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX promo : ' . $e->getMessage());
    }
});
