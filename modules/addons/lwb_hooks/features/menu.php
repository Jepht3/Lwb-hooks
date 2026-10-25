<?php
 
// This hook will adjust the button in the home page panels. You have two
// different options of setExtra() or setExtras(). Use of setExtras() requires
// passing all 'extra' related vars through the array.
 
use WHMCS\View\Menu\Item as MenuItem;

add_hook('ClientAreaPrimaryNavbar', 1, function (MenuItem $primaryNavbar) {
    // remove Network Status
    if (!is_null($primaryNavbar->getChild('Network Status'))) {
       $primaryNavbar->removeChild('Network Status');
    }
    
    // remove Affiliates
    if (!is_null($primaryNavbar->getChild('Affiliates'))) {
    //   $primaryNavbar->removeChild('Affiliates');
    }
    
    // Rename Announcements
    if (!is_null($primaryNavbar->getChild('Announcements'))) {
      $primaryNavbar->getChild("Announcements")->setLabel('Actualités');
    }
    
    // after logged in topmenu: Rename Announcements
    if (!is_null($primaryNavbar->getChild('Support'))) {
      $primaryNavbar->getChild('Support')->getChild("Announcements")->setLabel('Actualités');
    }
    
    // after logged in topmenu: Rename Announcements
    if (!is_null($primaryNavbar->getChild('Website Security'))) {
      $primaryNavbar->getChild('Website Security')->setLabel('Sécurité web');
    }
    
    // sidebar menu
    $secondarySidebar = Menu::secondarySidebar();
    if (!is_null($secondarySidebar->getChild('Support'))) {
      $secondarySidebar->getChild('Support')->getChild("Announcements")->setLabel('Actualités');
    }
    
}); 


