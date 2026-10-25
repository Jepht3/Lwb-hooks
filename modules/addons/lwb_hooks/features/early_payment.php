<?php
/**
 * LE WEB MAX — remise pour paiement anticipé des renouvellements.
 *
 * À la création d'une facture de renouvellement, ajoute une ligne de remise
 * de LWM_EARLY_PERCENT %, valable si la facture est réglée au plus tard
 * LWM_EARLY_DAYS jours avant l'échéance. Le cron quotidien retire la remise
 * des factures encore impayées une fois cette date dépassée.
 *
 * Seules les lignes de services déjà actifs comptent (hébergement, addon,
 * renouvellement de domaine) : les nouvelles commandes, dont les services sont
 * encore « Pending », ne reçoivent pas de remise.
 *
 * @category  Automation
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

const LWM_EARLY_PERCENT = 5;
const LWM_EARLY_DAYS = 20;

function lwm_early_table()
{
    if (!Capsule::schema()->hasTable('mod_lwm_early_discounts')) {
        Capsule::schema()->create('mod_lwm_early_discounts', function ($t) {
            $t->integer('invoice_id')->unsigned()->primary();
            $t->integer('item_id')->unsigned();
            $t->date('deadline');
            $t->decimal('amount', 16, 2);
            $t->dateTime('removed_at')->nullable();
        });
    }
}

function lwm_early_load_invoice_functions()
{
    if (!function_exists('updateInvoiceTotal')) {
        require_once ROOTDIR . '/includes/invoicefunctions.php';
    }
}

/**
 * Sum of the invoice lines that renew a service which is already live.
 */
function lwm_early_eligible_base($invoiceId)
{
    $sources = [
        'Hosting' => ['tblhosting', 'domainstatus'],
        'Addon' => ['tblhostingaddons', 'status'],
        'Domain' => ['tbldomains', 'status'],
    ];

    $items = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->whereIn('type', array_keys($sources))
        ->get(['type', 'relid', 'amount']);

    $base = 0.0;
    foreach ($items as $item) {
        [$table, $column] = $sources[$item->type];
        $status = Capsule::table($table)->where('id', $item->relid)->value($column);
        if ($status && $status !== 'Pending') {
            $base += (float) $item->amount;
        }
    }

    return $base;
}

function lwm_early_apply($invoiceId)
{
    lwm_early_table();

    if (Capsule::table('mod_lwm_early_discounts')->where('invoice_id', $invoiceId)->exists()) {
        return;
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice || $invoice->status !== 'Unpaid') {
        return;
    }

    $deadline = date('Y-m-d', strtotime($invoice->duedate . ' -' . LWM_EARLY_DAYS . ' days'));
    if ($deadline < date('Y-m-d')) {
        return;
    }

    $discount = round(lwm_early_eligible_base($invoiceId) * LWM_EARLY_PERCENT / 100, 2);
    if ($discount <= 0) {
        return;
    }

    $itemId = Capsule::table('tblinvoiceitems')->insertGetId([
        'invoiceid' => $invoiceId,
        'userid' => $invoice->userid,
        'type' => '',
        'relid' => 0,
        'description' => sprintf(
            'Remise paiement anticipé (%d %%) — valable si la facture est réglée au plus tard le %s',
            LWM_EARLY_PERCENT,
            date('d/m/Y', strtotime($deadline))
        ),
        'amount' => -$discount,
        'taxed' => 0,
        'duedate' => $invoice->duedate,
        'paymentmethod' => $invoice->paymentmethod,
        'notes' => '',
    ]);

    Capsule::table('mod_lwm_early_discounts')->insert([
        'invoice_id' => $invoiceId,
        'item_id' => $itemId,
        'deadline' => $deadline,
        'amount' => $discount,
    ]);

    lwm_early_load_invoice_functions();
    updateInvoiceTotal($invoiceId);
}

/**
 * Removes the discount from invoices still unpaid after their deadline.
 *
 * @return int number of invoices updated
 */
function lwm_early_expire()
{
    lwm_early_table();
    lwm_early_load_invoice_functions();

    $rows = Capsule::table('mod_lwm_early_discounts as d')
        ->join('tblinvoices as i', 'i.id', '=', 'd.invoice_id')
        ->whereNull('d.removed_at')
        ->where('d.deadline', '<', date('Y-m-d'))
        ->where('i.status', 'Unpaid')
        ->get(['d.invoice_id', 'd.item_id']);

    foreach ($rows as $row) {
        Capsule::table('tblinvoiceitems')
            ->where('id', $row->item_id)
            ->where('invoiceid', $row->invoice_id)
            ->delete();
        updateInvoiceTotal($row->invoice_id);
        Capsule::table('mod_lwm_early_discounts')
            ->where('invoice_id', $row->invoice_id)
            ->update(['removed_at' => date('Y-m-d H:i:s')]);
    }

    return count($rows);
}

function lwm_early_apply_safely($vars)
{
    try {
        lwm_early_apply((int) $vars['invoiceid']);
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX remise anticipée : ' . $e->getMessage());
    }
}

// Both hooks: PreEmail so the discount shows in the invoice email, Created as a
// fallback for invoices created without an email. lwm_early_apply is idempotent.
add_hook('InvoiceCreationPreEmail', 1, 'lwm_early_apply_safely');
add_hook('InvoiceCreated', 1, 'lwm_early_apply_safely');

// Runs before the reminders hook (priority 50) so reminders show the right total.
add_hook('DailyCronJob', 40, function ($vars) {
    try {
        $count = lwm_early_expire();
        if ($count) {
            logActivity("LE WEB MAX remise anticipée : remise retirée de {$count} facture(s) échue(s)");
        }
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX remise anticipée : ' . $e->getMessage());
    }
});
