<?php
/**
 * LWB Hooks — les hooks LE WEB MAX réunis dans un module complémentaire WHMCS.
 *
 * Plutôt que des fichiers isolés dans includes/hooks, chaque fonctionnalité est
 * un fichier du dossier features/, activable ou désactivable depuis
 * Addons > LWB Hooks. Le module se met à jour d'un bloc, comme les autres.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/features.php';

define('LWB_HOOKS_VERSION', '1.0.0');

function lwb_hooks_config()
{
    return [
        'name' => 'LWB Hooks',
        'description' => 'Les automatisations LE WEB MAX (commande, facturation, domaines, espace client, maintenance) réunies dans un module, activables une par une.',
        'version' => LWB_HOOKS_VERSION,
        'author' => 'LE WEB MAX',
        'language' => 'french',
        'fields' => [],
    ];
}

function lwb_hooks_activate()
{
    return ['status' => 'success', 'description' => 'LWB Hooks est activé : toutes les fonctionnalités sont actives (Addons > LWB Hooks pour les gérer).'];
}

function lwb_hooks_deactivate()
{
    return ['status' => 'success', 'description' => 'LWB Hooks est désactivé : aucune de ses fonctionnalités ne s\'exécute plus.'];
}

function lwb_hooks_output($vars)
{
    $h = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    $features = lwb_hooks_features();
    $off = lwb_hooks_disabled();
    $flash = '';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['lwb_feature'])) {
        $key = (string) $_POST['lwb_feature'];
        if (isset($features[$key])) {
            $enable = ($_POST['lwb_state'] ?? '') === 'on';
            $off = $enable ? array_values(array_diff($off, [$key])) : array_values(array_unique(array_merge($off, [$key])));
            lwb_hooks_set_disabled($off);
            logActivity('LWB Hooks : « ' . $features[$key]['label'] . ' » ' . ($enable ? 'activé' : 'désactivé'));
            $flash = '<div class="successbox">« ' . $h($features[$key]['label']) . ' » ' . ($enable ? 'activé' : 'désactivé')
                . '. Le changement s\'applique dès la prochaine page ou le prochain cron.</div>';
        }
    }

    $token = generate_token('plain');
    $action = $h($vars['modulelink']);
    echo $flash . '<style>.lwbh-card{border:1px solid #e3e6ea;border-radius:6px;padding:4px 16px 8px;margin-bottom:14px;background:#fff}'
        . '.lwbh-row{display:flex;gap:14px;align-items:flex-start;padding:10px 0;border-top:1px solid #f0f1f3}.lwbh-row:first-of-type{border-top:0}'
        . '.lwbh-row .lwbh-txt{flex:1}.lwbh-row small{color:#6b7280}.lwbh-off{opacity:.65}</style>'
        . '<p>Les automatisations LE WEB MAX, réunies ici au lieu de fichiers isolés dans <code>includes/hooks</code>. '
        . 'Désactiver une fonctionnalité arrête tout de suite ses actions ; la réactiver la remet en route, sans perte de réglages.</p>';

    $count = count($features) - count(array_intersect($off, array_keys($features)));
    echo '<p><strong>' . $count . ' / ' . count($features) . '</strong> fonctionnalités actives.</p>';

    $group = null;
    foreach ($features as $key => $f) {
        if ($f['group'] !== $group) {
            echo ($group === null ? '' : '</div>') . '<h3 style="margin-top:22px">' . $h($f['group']) . '</h3><div class="lwbh-card">';
            $group = $f['group'];
        }
        $active = !in_array($key, $off, true);
        $present = is_file(__DIR__ . '/features/' . $f['file']);
        $confirm = $active
            ? 'Désactiver « ' . $f['label'] . ' » ?' . (!empty($f['warn']) ? "\n\n" . $f['warn'] : '')
            : 'Réactiver « ' . $f['label'] . ' » ?';
        echo '<div class="lwbh-row' . ($active ? '' : ' lwbh-off') . '"><div class="lwbh-txt"><strong>' . $h($f['label']) . '</strong> '
            . ($active ? '<span class="label label-success">Actif</span>' : '<span class="label label-default">Désactivé</span>')
            . (!$present ? ' <span class="label label-danger">fichier manquant</span>' : '')
            . '<br><small>' . $h($f['desc']) . '</small><br><small><code>features/' . $h($f['file']) . '</code></small></div>'
            . '<form method="post" action="' . $action . '" onsubmit="return confirm(' . $h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')">'
            . '<input type="hidden" name="token" value="' . $h($token) . '">'
            . '<input type="hidden" name="lwb_feature" value="' . $h($key) . '">'
            . '<input type="hidden" name="lwb_state" value="' . ($active ? 'off' : 'on') . '">'
            . '<button class="btn btn-sm ' . ($active ? 'btn-default' : 'btn-success') . '">' . ($active ? 'Désactiver' : 'Activer') . '</button></form></div>';
    }
    echo '</div><p class="text-muted"><small>Version ' . LWB_HOOKS_VERSION . ' · chaque changement est noté dans le journal d\'activité WHMCS.</small></p>';
}
