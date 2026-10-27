# LWB Hooks

Module complémentaire WHMCS de LE WEB MAX : toutes les automatisations maison (hooks) réunies dans un
seul module, au lieu de fichiers isolés dans `includes/hooks`. Chaque fonctionnalité s'active ou se
désactive depuis **Addons > LWB Hooks**.

## Fonctionnalités

| Rubrique | Fonctionnalité | Fichier |
|---|---|---|
| Commande | Domaine choisi et son prix dans le résumé de l'étape « Configurer » | `features/cart_domain_summary.php` |
| Commande | Produits facturés par option (pas de « 0.00 USD » dans les durées ni le résumé) | `features/price_per_option.php` |
| Commande | Domaine inclus dans l'offre (+ mot de passe masqué dans les e-mails) | `features/storefront.php` |
| Commande | Contrôle « J'ai déjà ce domaine » | `features/owndomain.php` |
| Facturation | Remise pour paiement anticipé | `features/early_payment.php` |
| Facturation | Rappels de paiement à dates fixes (remplacent les rappels natifs) | `features/reminders.php` |
| Facturation | Réactivation des services sans module serveur | `features/unsuspend_nomodule.php` |
| Facturation | E-mail promotionnel récurrent | `features/promo_mail.php` |
| Services et domaines | Extensions payantes d'hébergement (boîtes e-mail, stockage) | `features/addons.php` |
| Services et domaines | Domaines achetés chez Porkbun | `features/porkbun.php` |
| Espace client | Données du thème lwb-theme | `features/theme.php` |
| Espace client | Menu de l'espace client | `features/menu.php` |
| Espace client | Panneaux de l'accueil client | `features/home_panels.php` |
| Maintenance | Sauvegarde quotidienne de la base WHMCS | `features/backup.php` |
| Maintenance | Fin de la vente de Diva 1 (1er décembre 2027) | `features/diva1_end.php` |
| Maintenance | Fin de la promo « Création de site web » (1er janvier 2027) | `features/web_design_prices.php` |

## Installation

1. Copier `modules/addons/lwb_hooks/` dans `modules/addons/` de WHMCS.
2. Retirer de `includes/hooks/` les anciens fichiers correspondants (sinon les hooks tournent deux fois).
3. Configuration > Modules complémentaires > **LWB Hooks** > Activer.

## Ajouter une fonctionnalité

1. Créer `features/ma_fonction.php` (fichier de hooks WHMCS ordinaire, avec `add_hook`).
2. La déclarer dans `lwb_hooks_features()` (`lib/features.php`) : fichier, rubrique, libellé, description,
   et `warn` si la désactiver a une conséquence importante.

Les fonctionnalités désactivées sont enregistrées dans `tbladdonmodules` (module `lwb_hooks`, réglage
`disabled`). Aucun identifiant n'est stocké dans le code : les clés (WHM, Porkbun, base de données) sont lues
dans WHMCS et `configuration.php`.
