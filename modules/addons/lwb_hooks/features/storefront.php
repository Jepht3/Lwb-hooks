<?php
/**
 * LE WEB MAX — boutique : domaine inclus dans l'offre.
 *
 * À l'étape « nom de domaine » d'une offre qui inclut le domaine (Site vitrine,
 * Site e-commerce…), WHMCS affiche « Continuez pour enregistrer ce domaine pour
 * 12.99 USD » alors que le domaine est offert. Ce hook remplace ce prix par
 * « Inclus dans votre offre » pour les extensions offertes (.com, .net, .org),
 * dans le résultat principal comme dans les suggestions.
 *
 * Masque aussi, dans tous les e-mails (confirmation de commande, notification
 * admin…), le mot de passe administrateur demandé à la commande de la
 * maintenance de site web : il reste visible dans l'administration WHMCS.
 *
 * À placer dans includes/hooks/ de WHMCS.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (($vars['templatefile'] ?? '') !== 'configureproductdomain') {
        return '';
    }
    try {
        $pid = (int) ($vars['pid'] ?? ($vars['productinfo']['pid'] ?? ($_REQUEST['pid'] ?? 0)));
        if (!$pid) {
            return '';
        }
        $product = Capsule::table('tblproducts')->where('id', $pid)->first(['freedomain', 'freedomaintlds']);
        if (!$product || !$product->freedomain) {
            return '';
        }
        $tlds = array_values(array_filter(array_map(function ($t) {
            return '.' . ltrim(strtolower(trim($t)), '.');
        }, explode(',', (string) $product->freedomaintlds)), function ($t) {
            return $t !== '.';
        }));
        if (!$tlds) {
            return '';
        }
        $en = strtolower((string) ($vars['language'] ?? '')) === 'english';
        $text = [
            'label' => $en ? 'This domain is included in your plan:' : 'Ce domaine est inclus dans votre offre :',
            'price' => $en ? 'Free the 1st year' : 'Offert la 1re année',
            'short' => $en ? 'Included' : 'Inclus',
        ];
        $json = json_encode(['tlds' => $tlds, 'text' => $text], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        return <<<HTML
<script>
(function () {
    var cfg = {$json};
    function tldOf(domain) {
        domain = (domain || '').trim().toLowerCase();
        for (var i = 0; i < cfg.tlds.length; i++) {
            var t = cfg.tlds[i];
            if (domain.length > t.length && domain.slice(-t.length) === t) { return t; }
        }
        return '';
    }
    function apply() {
        var primary = document.getElementById('primaryLookupResult');
        if (primary) {
            var input = document.getElementById('resultDomain');
            var label = primary.querySelector('.register-price-label');
            var price = primary.querySelector('.domain-price .price');
            var option = document.getElementById('resultDomainOption');
            if (label && price && input && (!option || option.value === 'register')) {
                if (!label.hasAttribute('data-lwb-default')) { label.setAttribute('data-lwb-default', label.textContent); }
                if (tldOf(input.value)) {
                    if (price.textContent !== cfg.text.price) {
                        label.textContent = cfg.text.label;
                        price.textContent = cfg.text.price;
                        price.classList.add('lwb-free-domain');
                    }
                } else if (price.classList.contains('lwb-free-domain')) {
                    label.textContent = label.getAttribute('data-lwb-default');
                    price.classList.remove('lwb-free-domain');
                }
            }
        }
        var rows = document.querySelectorAll('#domainSuggestions .domain-suggestion.clone');
        for (var i = 0; i < rows.length; i++) {
            var ext = rows[i].querySelector('.extension');
            var p = rows[i].querySelector('.price');
            if (ext && p && cfg.tlds.indexOf(ext.textContent.trim().toLowerCase()) !== -1 && p.textContent !== cfg.text.short) {
                p.textContent = cfg.text.short;
                p.classList.add('lwb-free-domain');
            }
        }
    }
    var target = document.getElementById('DomainSearchResults') || document.body;
    var timer = null;
    new MutationObserver(function () {
        clearTimeout(timer);
        timer = setTimeout(apply, 30);
    }).observe(target, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['style', 'value'] });
})();
</script>
HTML;
    } catch (\Throwable $e) {
        logActivity('LE WEB MAX boutique (domaine inclus) : ' . $e->getMessage());
        return '';
    }
});

/** Libellés des champs personnalisés dont la valeur ne doit jamais partir par e-mail. */
const LWB_SECRET_FIELDS = ['Mot de passe administrateur du site'];

add_hook('EmailPreSend', 1, function ($vars) {
    $out = [];
    foreach ((array) ($vars['mergefields'] ?? []) as $key => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        $masked = $value;
        foreach (LWB_SECRET_FIELDS as $label) {
            $masked = preg_replace('/(' . preg_quote($label, '/') . '\s*:?\s*(?:<[^>]*>\s*)*)([^<\r\n]+)/u', '$1••••••••', $masked);
        }
        if ($masked !== null && $masked !== $value) {
            $out[$key] = $masked;
        }
    }
    return $out;
});
