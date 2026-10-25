<?php
/**
 * LE WEB MAX — rappels de paiement à dates fixes pour les factures impayées.
 *
 * Remplace les rappels natifs de WHMCS (désactivés dans les réglages).
 * Un seul e-mail par étape, calé sur la suspension automatique (J+5) :
 *   - avant l'échéance : J-14, J-7, J-3, J-1  → « Invoice Payment Reminder »
 *   - J+1 → « First Invoice Overdue Notice »
 *   - J+3 → « Second Invoice Overdue Notice »
 *   - J+4 → « Third Invoice Overdue Notice » (dernier rappel avant suspension)
 *   - J+10, J+20, J+30 → « Second Invoice Overdue Notice », puis plus rien.
 * Une étape manquée (cron arrêté) est rattrapée une seule fois : seule la plus
 * récente est envoyée.
 *
 * @category  Automation
 * @author    JNK
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Jours par rapport à l'échéance (négatif = avant) => modèle d'e-mail. */
const LWM_REMINDER_STAGES = [
    -14 => 'Invoice Payment Reminder',
    -7 => 'Invoice Payment Reminder',
    -3 => 'Invoice Payment Reminder',
    -1 => 'Invoice Payment Reminder',
    1 => 'First Invoice Overdue Notice',
    3 => 'Second Invoice Overdue Notice',
    4 => 'Third Invoice Overdue Notice',
    10 => 'Second Invoice Overdue Notice',
    20 => 'Second Invoice Overdue Notice',
    30 => 'Second Invoice Overdue Notice',
];
const LWM_REMINDER_MAX_OVERDUE = 30;

function lwm_reminder_table()
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_lwm_reminders')) {
        $schema->create('mod_lwm_reminders', function ($t) {
            $t->integer('invoice_id')->unsigned()->primary();
            $t->date('last_sent');
            $t->integer('count')->unsigned()->default(0);
            $t->integer('stage')->nullable();
        });
    } elseif (!$schema->hasColumn('mod_lwm_reminders', 'stage')) {
        // Passage de l'ancien rythme (tous les 3 jours) aux étapes : les factures déjà
        // relancées repartent de leur étape actuelle, sans renvoi immédiat.
        $schema->table('mod_lwm_reminders', function ($t) {
            $t->integer('stage')->nullable();
        });
        foreach (Capsule::table('mod_lwm_reminders as r')->join('tblinvoices as i', 'i.id', '=', 'r.invoice_id')
            ->get(['r.invoice_id', 'i.duedate']) as $row) {
            Capsule::table('mod_lwm_reminders')->where('invoice_id', $row->invoice_id)
                ->update(['stage' => lwm_reminder_stage(lwm_reminder_overdue($row->duedate))]);
        }
    }
}

/** Nombre de jours de retard (négatif avant l'échéance). */
function lwm_reminder_overdue($duedate)
{
    return (int) floor((strtotime(date('Y-m-d')) - strtotime(substr((string) $duedate, 0, 10))) / 86400);
}

/** Dernière étape atteinte pour ce nombre de jours, ou null avant la première. */
function lwm_reminder_stage($overdue)
{
    $stage = null;
    foreach (array_keys(LWM_REMINDER_STAGES) as $day) {
        if ($day <= $overdue) {
            $stage = $day;
        }
    }
    return $stage;
}

/**
 * Factures à relancer aujourd'hui.
 *
 * @return array [['id' => .., 'template' => .., 'overdue' => .., 'stage' => ..], ...]
 */
function lwm_reminder_due_list()
{
    lwm_reminder_table();
    $today = date('Y-m-d');
    $sent = [];
    foreach (Capsule::table('mod_lwm_reminders')->get(['invoice_id', 'last_sent', 'stage']) as $row) {
        $sent[$row->invoice_id] = $row;
    }

    $rows = Capsule::table('tblinvoices as i')
        ->join('tblclients as c', 'c.id', '=', 'i.userid')
        ->where('i.status', 'Unpaid')
        ->where('c.status', 'Active')
        ->where('i.total', '>', 0)
        ->where('i.date', '<', $today)
        ->where('i.duedate', '>=', date('Y-m-d', strtotime('-' . LWM_REMINDER_MAX_OVERDUE . ' days')))
        ->get(['i.id', 'i.duedate']);

    $list = [];
    foreach ($rows as $inv) {
        $overdue = lwm_reminder_overdue($inv->duedate);
        $stage = lwm_reminder_stage($overdue);
        if ($stage === null) {
            continue;
        }
        $done = $sent[$inv->id] ?? null;
        if ($done && ($done->last_sent === $today || ($done->stage !== null && (int) $done->stage >= $stage))) {
            continue;
        }
        $list[] = ['id' => (int) $inv->id, 'template' => LWM_REMINDER_STAGES[$stage], 'overdue' => $overdue, 'stage' => $stage];
    }
    return $list;
}

add_hook('DailyCronJob', 50, function ($vars) {
    try {
        $count = 0;
        foreach (lwm_reminder_due_list() as $item) {
            $result = localAPI('SendEmail', ['messagename' => $item['template'], 'id' => $item['id']]);
            if (($result['result'] ?? '') !== 'success') {
                logActivity("LE WEB MAX rappels : échec facture #{$item['id']} : " . ($result['message'] ?? ''));
                continue;
            }
            $row = Capsule::table('mod_lwm_reminders')->where('invoice_id', $item['id'])->first();
            Capsule::table('mod_lwm_reminders')->updateOrInsert(
                ['invoice_id' => $item['id']],
                ['last_sent' => date('Y-m-d'), 'count' => ($row->count ?? 0) + 1, 'stage' => $item['stage']]
            );
            $count++;
        }
        // Forget paid / cancelled invoices.
        Capsule::table('mod_lwm_reminders')
            ->whereIn('invoice_id', Capsule::table('tblinvoices')->where('status', '!=', 'Unpaid')->select('id'))
            ->delete();
        if ($count) {
            logActivity("LE WEB MAX rappels : {$count} rappel(s) de paiement envoyé(s)");
        }
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX rappels : ' . $e->getMessage());
    }
});
