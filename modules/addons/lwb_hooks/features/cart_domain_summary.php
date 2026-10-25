<?php
/**
 * LWB Hooks — Domaine dans le résumé de commande.
 *
 * À l'étape « Configurer » du bon de commande, le résumé affiche le domaine choisi
 * (enregistrement ou transfert) et son prix, et le « Total à payer aujourd'hui »
 * les inclut, comme au panier. Rien n'est ajouté pour un domaine du client.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Domaine du produit en cours de configuration, s'il est commandé avec lui
 * (enregistrement ou transfert), avec son prix. Null sinon (domaine du client,
 * sous-domaine, prix introuvable).
 *
 * @return array|null ['label', 'price', 'priceText']
 */
function lwb_hooks_cart_domain($i)
{
    $cart = $_SESSION['cart'] ?? [];
    if ($i === null || empty($cart['products'][$i]['domain'])) {
        return null;
    }
    $dom = strtolower(trim($cart['products'][$i]['domain']));
    $item = null;
    foreach (($cart['domains'] ?? []) as $d) {
        if (strtolower($d['domain'] ?? '') === $dom) {
            $item = $d;
            break;
        }
    }
    if (!$item || !in_array($item['type'] ?? '', ['register', 'transfer'], true)) {
        return null;
    }
    $years = max(1, (int) ($item['regperiod'] ?? 1));
    $tld = '.' . substr($dom, strpos($dom, '.') + 1);
    $cur = function_exists('getCurrency') ? getCurrency($_SESSION['uid'] ?? 0, $_SESSION['currency'] ?? 0) : ['id' => 1];
    $tid = Capsule::table('tbldomainpricing')->where('extension', $tld)->value('id');
    // Colonnes de prix des domaines WHMCS : 1 à 10 ans.
    $cols = [1 => 'msetupfee', 2 => 'qsetupfee', 3 => 'ssetupfee', 4 => 'asetupfee', 5 => 'bsetupfee',
        6 => 'monthly', 7 => 'quarterly', 8 => 'semiannually', 9 => 'annually', 10 => 'biennially'];
    if (!$tid || !isset($cols[$years])) {
        return null;
    }
    $type = $item['type'] === 'transfer' ? 'domaintransfer' : 'domainregister';
    $price = Capsule::table('tblpricing')->where(['type' => $type, 'relid' => $tid, 'currency' => $cur['id']])->value($cols[$years]);
    if ($price === null || (float) $price < 0) {
        return null;
    }
    return [
        'label' => ($item['type'] === 'transfer' ? 'Transfert du domaine' : 'Nom de domaine') . ' : ' . $dom . ' (' . $years . ' an' . ($years > 1 ? 's' : '') . ')',
        'price' => (float) $price,
        'priceText' => formatCurrency((float) $price, $cur['id'])->toFull(),
    ];
}

// Résumé de l'étape « Configurer » : ajoute le domaine choisi et l'inclut dans le total.
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (($vars['templatefile'] ?? '') !== 'configureproduct') {
        return '';
    }
    try {
        $i = isset($_GET['i']) ? (int) $_GET['i'] : (isset($_POST['i']) ? (int) $_POST['i'] : null);
        $d = lwb_hooks_cart_domain($i);
        if (!$d) {
            return '';
        }
        $data = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (\Throwable $e) {
        return '';
    }
    // Le résumé (#producttotal) est rechargé en AJAX à chaque changement d'option :
    // la ligne est rajoutée à chaque fois.
    return <<<HTML
<script>
(function(){
  var D = $data;
  function num(t){ var s=(t||'').replace(/[^0-9.,]/g,''); if(s.indexOf(',')>-1&&s.indexOf('.')>-1){s=s.replace(/,/g,'');} else {s=s.replace(',', '.');} return parseFloat(s); }
  function apply(){
    var box=document.getElementById('producttotal'); if(!box||box.querySelector('.lwm-dom-line')) return;
    var due=box.querySelector('.total-due-today'); var amt=due&&due.querySelector('.amt'); if(!amt) return;
    var row=document.createElement('div'); row.className='clearfix lwm-dom-line'; row.style.marginTop='6px';
    var l=document.createElement('span'); l.className='pull-left float-left'; l.textContent=D.label;
    var r=document.createElement('span'); r.className='pull-right float-right'; r.textContent=D.priceText;
    row.appendChild(l); row.appendChild(r);
    var tot=box.querySelector('.summary-totals'); (tot||due).parentNode.insertBefore(row, tot||due);
    var raw=amt.textContent, n=num(raw); if(isNaN(n)) return;
    var m=raw.match(/[0-9][0-9.,]*/); if(!m) return;
    var dec=(m[0].match(/[.,](\d+)$/)||[0,'00'])[1].length; var sum=(n+D.price).toFixed(dec);
    var sep=/,\d+$/.test(m[0])&&m[0].indexOf('.')<0?',':'.'; if(sep===',') sum=sum.replace('.',',');
    amt.textContent=raw.replace(m[0], sum);
  }
  function init(){ var box=document.getElementById('producttotal'); if(!box) return; apply(); new MutationObserver(function(){ apply(); }).observe(box,{childList:true,subtree:true}); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
</script>
HTML;
});
