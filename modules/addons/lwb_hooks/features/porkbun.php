<?php
/**
 * LE WEB MAX — domains bought manually at Porkbun.
 *
 * No automatic purchase: when a domain order is paid, the domain is attached to the
 * Porkbun registrar and the administrator receives an e-mail with a direct Porkbun link.
 * Once the domain shows up in the Porkbun account (checked every 10 minutes), it is
 * activated in WHMCS with the real dates and the client receives the confirmation e-mail.
 *
 * @category  Domains
 * @author    JNK
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

const LWM_PORKBUN_CHECK_MINUTES = 10;

function lwm_porkbun_tlds()
{
    return ['com', 'net', 'org', 'biz', 'info', 'blog', 'ca', 'club', 'co.uk', 'de', 'eu', 'icu', 'io', 'me',
        'mobi', 'nl', 'online', 'site', 'top', 'uk', 'us', 'vip', 'xyz', 'store', 'app'];
}

function lwm_porkbun_supported($domain)
{
    $parts = explode('.', strtolower($domain), 2);
    return isset($parts[1]) && in_array($parts[1], lwm_porkbun_tlds(), true);
}

/**
 * @return array|null [domain => ['createDate' => ..., 'expireDate' => ..., 'status' => ...]]
 */
function lwm_porkbun_account_domains()
{
    $cfg = [];
    foreach (Capsule::table('tblregistrars')->where('registrar', 'porkbun')->get() as $row) {
        $cfg[$row->setting] = decrypt($row->value);
    }
    if (empty($cfg['APIKey']) || empty($cfg['SecretAPIKey'])) {
        return null;
    }
    $list = [];
    for ($start = 0; $start < 10000; $start += 1000) {
        $ch = curl_init('https://api.porkbun.com/api/json/v3/domain/listAll');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['apikey' => $cfg['APIKey'], 'secretapikey' => $cfg['SecretAPIKey'], 'start' => $start]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 40,
        ]);
        $body = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (($body['status'] ?? '') !== 'SUCCESS') {
            return null;
        }
        foreach ((array) ($body['domains'] ?? []) as $d) {
            $list[strtolower($d['domain'])] = $d;
        }
        if (count((array) ($body['domains'] ?? [])) < 1000) {
            break;
        }
    }
    return $list;
}

/* ---------- 1. Paid order: prepare the domain and tell the administrator ---------- */

add_hook('InvoicePaid', 20, function ($vars) {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)
            ->whereIn('type', ['DomainRegister', 'DomainTransfer'])->get(['type', 'relid']);
        foreach ($items as $item) {
            $domain = Capsule::table('tbldomains')->where('id', (int) $item->relid)->first();
            if (!$domain || !lwm_porkbun_supported($domain->domain)
                || !in_array($domain->status, ['Pending', 'Pending Registration', 'Pending Transfer'], true)) {
                continue;
            }
            if ($domain->registrar === '') {
                Capsule::table('tbldomains')->where('id', $domain->id)->update(['registrar' => 'porkbun']);
            }
            $client = Capsule::table('tblclients')->where('id', $domain->userid)->first(['firstname', 'lastname', 'companyname', 'email']);
            $who = trim(($client->companyname ?: $client->firstname . ' ' . $client->lastname));
            $transfer = $item->type === 'DomainTransfer';
            $link = $transfer
                ? 'https://porkbun.com/transfer'
                : 'https://porkbun.com/checkout/search?q=' . rawurlencode($domain->domain);
            $years = (int) $domain->registrationperiod ?: 1;

            $message = '<p>Le client <strong>' . htmlspecialchars($who) . '</strong> (' . htmlspecialchars($client->email) . ') a payé '
                . ($transfer ? 'le transfert' : "l'enregistrement") . ' du domaine <strong>' . htmlspecialchars($domain->domain) . '</strong>'
                . ' pour ' . $years . ' an(s) (facture n° ' . $invoiceId . ').</p>'
                . '<p><a href="' . $link . '" style="display:inline-block;padding:10px 18px;background:#030673;color:#fff;border-radius:8px;text-decoration:none;font-weight:bold">'
                . ($transfer ? 'Ouvrir le transfert Porkbun' : 'Ajouter ' . htmlspecialchars($domain->domain) . ' au panier Porkbun') . '</a></p>'
                . ($transfer ? '<p>Code EPP fourni par le client : voir la fiche du domaine dans WHMCS.</p>' : '')
                . '<p>Dès que le domaine apparaît dans votre compte Porkbun, WHMCS l\'active automatiquement '
                . '(dates réelles + e-mail de confirmation au client), en moins de ' . LWM_PORKBUN_CHECK_MINUTES . ' minutes.</p>';

            localAPI('SendAdminEmail', [
                'customsubject' => ($transfer ? 'Transfert à lancer chez Porkbun : ' : 'Domaine à acheter chez Porkbun : ') . $domain->domain,
                'custommessage' => $message,
                'type' => 'system',
            ]);
            logActivity('Porkbun : ' . $domain->domain . ' payé, à ' . ($transfer ? 'transférer' : 'acheter') . ' manuellement (lien envoyé à l’administrateur)', $domain->userid);
        }
    } catch (\Throwable $e) {
        logActivity('Porkbun (préparation) : ' . $e->getMessage());
    }
});

/* ---------- 2. Domain bought at Porkbun: activate it in WHMCS ---------- */

add_hook('AfterCronJob', 20, function ($vars) {
    try {
        $last = (int) \WHMCS\Config\Setting::getValue('LwmPorkbunLastCheck');
        if (time() - $last < LWM_PORKBUN_CHECK_MINUTES * 60) {
            return;
        }
        \WHMCS\Config\Setting::setValue('LwmPorkbunLastCheck', time());

        $waiting = Capsule::table('tbldomains')->where('registrar', 'porkbun')
            ->where(function ($q) {
                $q->whereIn('status', ['Pending', 'Pending Registration', 'Pending Transfer'])
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'Active')->where('expirydate', '0000-00-00');
                    });
            })->get();
        if (!count($waiting)) {
            return;
        }
        $account = lwm_porkbun_account_domains();
        if ($account === null) {
            logActivity('Porkbun : API injoignable ou clés invalides, vérification reportée');
            return;
        }
        foreach ($waiting as $domain) {
            $found = $account[strtolower($domain->domain)] ?? null;
            if (!$found || empty($found['expireDate'])) {
                continue;
            }
            $expiry = substr($found['expireDate'], 0, 10);
            $wasPending = $domain->status !== 'Active';
            Capsule::table('tbldomains')->where('id', $domain->id)->update([
                'status' => 'Active',
                'registrationdate' => substr($found['createDate'] ?: $found['expireDate'], 0, 10),
                'expirydate' => $expiry,
                'nextduedate' => $expiry,
                'nextinvoicedate' => $expiry,
            ]);
            if ($wasPending) {
                localAPI('SendEmail', ['messagename' => 'Domain Registration Confirmation', 'id' => $domain->id]);
            }
            logActivity('Porkbun : ' . $domain->domain . ' trouvé dans le compte Porkbun, activé (expire le ' . $expiry . ')', $domain->userid);
        }
    } catch (\Throwable $e) {
        logActivity('Porkbun (vérification) : ' . $e->getMessage());
    }
});
