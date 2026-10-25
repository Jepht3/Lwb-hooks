<?php
/**
 * LE WEB MAX — paid add-ons for hosting accounts (extra mailboxes, extra storage).
 *
 * When a product addon is activated, suspended, unsuspended, cancelled or
 * terminated, the cPanel limits of the parent service are recomputed:
 *     limit = base limit of the account + (quantity x units of every ACTIVE addon)
 * and applied through WHM (modifyacct). The base limit of each account is
 * recorded the first time, so existing clients keep their own conditions.
 *
 * @category  Automation
 * @author    JNK
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Addon name => resource it extends. Units: mailboxes, or MB of disk.
 */
function lwm_addon_catalog()
{
    return [
        'Pack +5 boîtes e-mail' => ['resource' => 'maxpop', 'units' => 5],
        'Stockage +10 Go' => ['resource' => 'quota', 'units' => 10240],
    ];
}

function lwm_addon_table()
{
    if (!Capsule::schema()->hasTable('mod_lwm_limits')) {
        Capsule::schema()->create('mod_lwm_limits', function ($t) {
            $t->integer('service_id')->unsigned()->primary();
            $t->string('base_maxpop', 32);
            $t->string('base_quota', 32);
            $t->timestamp('updated_at')->nullable();
        });
    }
}

/**
 * @return array [addonId => ['resource' => ..., 'units' => ...]]
 */
function lwm_addon_ids()
{
    static $ids = null;
    if ($ids === null) {
        $ids = [];
        foreach (lwm_addon_catalog() as $name => $def) {
            foreach (Capsule::table('tbladdons')->where('name', $name)->pluck('id') as $id) {
                $ids[(int) $id] = $def;
            }
        }
    }
    return $ids;
}

function lwm_whm($serverId, $function, array $params = [])
{
    $server = Capsule::table('tblservers')->where('id', $serverId)->first();
    if (!$server || $server->type !== 'cpanel') {
        return null;
    }
    $url = 'https://' . ($server->hostname ?: $server->ipaddress) . ':2087/json-api/' . $function
        . '?' . http_build_query(['api.version' => 1] + $params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERPWD => $server->username . ':' . decrypt($server->password),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $body, true);
}

/**
 * Base limits of a service (recorded once, re-read after a package change).
 */
function lwm_addon_base($service, $refresh = false)
{
    lwm_addon_table();
    $row = Capsule::table('mod_lwm_limits')->where('service_id', $service->id)->first();
    if ($row && !$refresh) {
        return ['maxpop' => $row->base_maxpop, 'quota' => $row->base_quota];
    }
    $summary = lwm_whm($service->server, 'accountsummary', ['user' => $service->username]);
    $acct = $summary['data']['acct'][0] ?? null;
    if (!$acct) {
        return null;
    }
    // If add-ons are already applied (refresh after package change), WHM now holds the
    // new package values, which are the new base.
    $base = [
        'maxpop' => (string) $acct['maxpop'],
        'quota' => strtolower((string) $acct['disklimit']) === 'unlimited'
            ? 'unlimited' : (string) (int) $acct['disklimit'],
    ];
    Capsule::table('mod_lwm_limits')->updateOrInsert(
        ['service_id' => $service->id],
        ['base_maxpop' => $base['maxpop'], 'base_quota' => $base['quota'], 'updated_at' => date('Y-m-d H:i:s')]
    );
    return $base;
}

/**
 * Recompute and apply the cPanel limits of a service.
 */
function lwm_addon_recompute($serviceId, $refreshBase = false)
{
    $ids = lwm_addon_ids();
    if (!$ids) {
        return;
    }
    $service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->first();
    if (!$service || !$service->username || !in_array($service->domainstatus, ['Active', 'Suspended'], true)) {
        return;
    }
    $addons = Capsule::table('tblhostingaddons')->where('hostingid', $service->id)
        ->whereIn('addonid', array_keys($ids))->get();
    if (!count($addons) && !$refreshBase) {
        return;
    }

    $base = lwm_addon_base($service, $refreshBase);
    if (!$base) {
        logActivity("LE WEB MAX add-ons : compte cPanel introuvable pour le service #{$service->id}");
        return;
    }

    $extra = ['maxpop' => 0, 'quota' => 0];
    foreach ($addons as $addon) {
        if ($addon->status === 'Active') {
            $def = $ids[(int) $addon->addonid];
            $extra[$def['resource']] += max(1, (int) $addon->qty) * $def['units'];
        }
    }

    // Right after creation or a package change, WHM already holds the package limits.
    if ($refreshBase && !$extra['maxpop'] && !$extra['quota']) {
        return;
    }

    $log = [];
    $errors = [];
    if (strtolower($base['maxpop']) !== 'unlimited') {
        $maxpop = (int) $base['maxpop'] + $extra['maxpop'];
        $result = lwm_whm($service->server, 'modifyacct', ['user' => $service->username, 'MAXPOP' => $maxpop]);
        if (($result['metadata']['result'] ?? 0) == 1) {
            $log[] = 'boîtes e-mail ' . $maxpop;
        } else {
            $errors[] = 'boîtes e-mail : ' . ($result['metadata']['reason'] ?? 'WHM injoignable');
        }
    }
    // Disk quota: modifyacct no longer accepts QUOTA on recent WHM versions, editquota (MB) does.
    if (strtolower($base['quota']) !== 'unlimited') {
        $quota = (int) $base['quota'] + $extra['quota'];
        $result = lwm_whm($service->server, 'editquota', ['user' => $service->username, 'quota' => $quota]);
        if (($result['metadata']['result'] ?? 0) == 1) {
            $log[] = 'disque ' . round($quota / 1024, 1) . ' Go';
        } else {
            $errors[] = 'disque : ' . ($result['metadata']['reason'] ?? 'WHM injoignable');
        }
    }
    if (!$log && !$errors) {
        return;
    }

    logActivity(sprintf(
        'LE WEB MAX add-ons : service #%d (%s) %s',
        $service->id,
        $service->domain,
        $errors ? 'ÉCHEC : ' . implode(' ; ', $errors) . ($log ? ' (appliqué : ' . implode(', ', $log) . ')' : '') : 'limites appliquées : ' . implode(', ', $log)
    ), $service->userid);
}

/* ---------- Lifecycle hooks ---------- */

foreach (['AddonActivated', 'AddonActivation', 'AddonSuspended', 'AddonUnsuspended',
          'AddonTerminated', 'AddonCancelled', 'AddonEdit'] as $lwmAddonHook) {
    add_hook($lwmAddonHook, 1, function ($vars) {
        try {
            $addonId = (int) ($vars['addonid'] ?? 0);
            if ($addonId && !isset(lwm_addon_ids()[$addonId])) {
                return;
            }
            $serviceId = (int) ($vars['serviceid'] ?? ($vars['hostingid'] ?? 0));
            if (!$serviceId && !empty($vars['id'])) {
                $serviceId = (int) Capsule::table('tblhostingaddons')->where('id', (int) $vars['id'])->value('hostingid');
            }
            if ($serviceId) {
                lwm_addon_recompute($serviceId);
            }
        } catch (\Throwable $e) {
            logActivity('LE WEB MAX add-ons : ' . $e->getMessage());
        }
    });
}

// Deleted addon: its row is already gone, recompute from the remaining ones.
add_hook('AddonDeleted', 1, function ($vars) {
    try {
        if (!empty($vars['id'])) {
            foreach (Capsule::table('mod_lwm_limits')->pluck('service_id') as $sid) {
                lwm_addon_recompute($sid);
            }
        }
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX add-ons : ' . $e->getMessage());
    }
});

// New account created with add-ons ordered at the same time.
add_hook('AfterModuleCreate', 1, function ($vars) {
    try {
        lwm_addon_recompute((int) ($vars['params']['serviceid'] ?? 0), true);
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX add-ons : ' . $e->getMessage());
    }
});

// Package change resets cPanel limits: take the new package as base, re-apply add-ons.
add_hook('AfterModuleChangePackage', 1, function ($vars) {
    try {
        lwm_addon_recompute((int) ($vars['params']['serviceid'] ?? 0), true);
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX add-ons : ' . $e->getMessage());
    }
});

/* ---------- Cart guard: no paid add-on for an already unlimited resource ---------- */

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    $errors = [];
    try {
        $ids = lwm_addon_ids();
        foreach ((array) ($_SESSION['cart']['addons'] ?? []) as $item) {
            $def = $ids[(int) ($item['id'] ?? 0)] ?? null;
            $service = $def ? Capsule::table('tblhosting')->where('id', (int) ($item['productid'] ?? 0))->first() : null;
            if (!$service) {
                continue;
            }
            $base = lwm_addon_base($service);
            if ($base && strtolower($base[$def['resource']]) === 'unlimited') {
                $errors[] = $def['resource'] === 'maxpop'
                    ? "Votre hébergement {$service->domain} inclut déjà des boîtes e-mail illimitées : le pack de boîtes supplémentaires n'est pas nécessaire."
                    : "Votre hébergement {$service->domain} dispose déjà d'un espace disque illimité : le stockage supplémentaire n'est pas nécessaire.";
            }
        }

        // Add-ons ordered together with a new hosting product: check the WHM package.
        $packages = [];
        foreach ((array) ($_SESSION['cart']['products'] ?? []) as $product) {
            foreach ((array) ($product['addons'] ?? []) as $addon) {
                $addonId = is_array($addon) ? (int) ($addon['addonid'] ?? ($addon['id'] ?? 0)) : (int) $addon;
                $def = $ids[$addonId] ?? null;
                if (!$def) {
                    continue;
                }
                $p = Capsule::table('tblproducts')->where('id', (int) ($product['pid'] ?? 0))->first(['name', 'servertype', 'configoption1']);
                if (!$p || $p->servertype !== 'cpanel') {
                    continue;
                }
                if (!isset($packages[$p->configoption1])) {
                    $serverId = (int) Capsule::table('tblservers')->where('type', 'cpanel')->where('disabled', 0)->value('id');
                    $info = lwm_whm($serverId, 'getpkginfo', ['pkg' => $p->configoption1]);
                    $pkg = $info['data']['pkg'] ?? [];
                    $packages[$p->configoption1] = [
                        'maxpop' => strtolower((string) ($pkg['MAXPOP'] ?? 'unlimited')),
                        'quota' => strtolower((string) ($pkg['QUOTA'] ?? 'unlimited')),
                    ];
                }
                $limit = $packages[$p->configoption1][$def['resource']];
                if ($limit === 'unlimited' || $limit === '' || ($def['resource'] === 'quota' && $limit === '0')) {
                    $errors[] = $def['resource'] === 'maxpop'
                        ? "L'offre {$p->name} inclut déjà des boîtes e-mail illimitées : retirez le pack de boîtes supplémentaires."
                        : "L'offre {$p->name} inclut déjà un espace disque illimité : retirez le stockage supplémentaire.";
                }
            }
        }
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX add-ons (panier) : ' . $e->getMessage());
    }
    return $errors;
});
