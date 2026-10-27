<?php
/**
 * LWB Hooks — Produits facturés uniquement par option.
 *
 * Pour un produit dont le prix de base est 0 et qui se paie par une option
 * configurable (ex. Google Workspace : nombre d'adresses à 10 USD par mois),
 * l'étape « Configurer » afficherait « 0.00 USD Annuel » dans la liste des
 * durées et une ligne à 0.00 USD dans le résumé. Le prix est retiré de ces
 * deux endroits : le client ne voit que la durée et le vrai total.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Le produit n'a que des durées à 0 (prix porté par les options configurables). */
function lwb_hooks_zero_base_product($pid)
{
    $pid = (int) $pid;
    if (!$pid || !Capsule::table('tblproductconfiglinks')->where('pid', $pid)->exists()) {
        return false;
    }
    $cycles = ['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'];
    $rows = Capsule::table('tblpricing')->where('type', 'product')->where('relid', $pid)->get($cycles);
    if (!count($rows)) {
        return false;
    }
    $enabled = 0;
    foreach ($rows as $row) {
        foreach ($cycles as $c) {
            $price = (float) $row->$c;
            if ($price > 0) {
                return false;
            }
            if ($price == 0) {
                $enabled++;
            }
        }
    }
    return $enabled > 0;
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (($vars['templatefile'] ?? '') !== 'configureproduct') {
        return '';
    }
    try {
        $i = isset($_GET['i']) ? (int) $_GET['i'] : (isset($_POST['i']) ? (int) $_POST['i'] : null);
        $pid = $i !== null ? (int) ($_SESSION['cart']['products'][$i]['pid'] ?? 0) : 0;
        if (!lwb_hooks_zero_base_product($pid)) {
            return '';
        }
    } catch (\Throwable $e) {
        return '';
    }
    return <<<'HTML'
<script>
(function(){
  function strip(t){ return t.replace(/^.*?0[.,]00(\s*[A-Z]{3})?\s*/, '').trim() || t; }
  function cycles(){
    var sel=document.getElementById('inputBillingcycle'); if(!sel) return;
    for(var i=0;i<sel.options.length;i++){ var o=sel.options[i]; if(/0[.,]00/.test(o.text)){ o.text=strip(o.text); } }
  }
  function summary(){
    var box=document.getElementById('producttotal'); if(!box) return;
    var first=box.querySelector('.clearfix .pull-right, .clearfix .float-right');
    if(first && /^\D*0[.,]00\b/.test(first.textContent.trim()) && !first.getAttribute('data-lwb-hidden')){ first.setAttribute('data-lwb-hidden','1'); first.style.visibility='hidden'; }
  }
  function init(){ cycles(); summary(); var box=document.getElementById('producttotal'); if(box){ new MutationObserver(summary).observe(box,{childList:true,subtree:true}); } }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
</script>
HTML;
});
