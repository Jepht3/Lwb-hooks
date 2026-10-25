<?php
 
// This hook will adjust the button in the home page panels. You have two
// different options of setExtra() or setExtras(). Use of setExtras() requires
// passing all 'extra' related vars through the array.
 
use WHMCS\View\Menu\Item;
 
add_hook('ClientAreaHomepagePanels', 1, function (Item $homePagePanels) {
    $overdueInvoicesPanel = $homePagePanels->getChild('Overdue Invoices');
    if (!is_null($overdueInvoicesPanel)) {
        $overdueInvoicesPanel->setExtras(
            [
                'color' => 'amethyst',
                'btn-text' => 'Payer maintenant',
                'btn-link' => 'clientarea.php?action=invoices',
                'btn-icon' => 'fas fa-wallet',
            ]
        );
    }
});


