<?php

namespace App\Helpers;

class PermissionHelper
{
    public static function getPermissionsByRole($role)
    {
        $permissions = [
            'super_admin' => ['*'],
            
            'admin' => [
                'view_users', 'create_users', 'edit_users', 'delete_users', 'manage_users_status',
                'view_vendeurs', 'validate_vendeurs', 'suspendre_vendeurs',
                'view_livreurs', 'validate_livreurs', 'suspendre_livreurs',
                'view_all_commandes', 'assign_commandes', 'edit_commande_status', 'cancel_commandes', 'refund_commandes',
                'view_livraisons', 'reassign_livraisons',
                'view_all_produits', 'edit_produits', 'delete_produits',
                'view_categories', 'create_categories', 'edit_categories', 'delete_categories',
                'view_zones', 'create_zones', 'edit_zones', 'delete_zones',
                'view_finances', 'manage_retraits', 'manage_commissions', 'generate_reports',
                'view_litiges', 'traiter_litiges',
                'view_tickets', 'manage_tickets',
                'view_promotions', 'manage_promotions', 'view_coupons', 'manage_coupons',
                'view_notifications', 'send_notifications',
                'view_parametres', 'edit_parametres',
            ],

            'support' => [
                'view_users',
                'view_all_commandes',
                'view_livraisons',
                'view_litiges', 'traiter_litiges',
                'view_tickets', 'manage_tickets',
                'view_vendeurs',
                'view_livreurs',
            ],

            'finance' => [
                'view_finances', 'manage_retraits', 'manage_commissions', 'generate_reports',
                'view_all_commandes', 'refund_commandes',
                'view_vendeurs',
                'view_livreurs',
            ],
            
            'moderation' => [
                'view_vendeurs', 'validate_vendeurs',
                'view_livreurs', 'validate_livreurs',
                'view_all_produits', 'edit_produits', 'delete_produits',
                'view_categories', 'create_categories', 'edit_categories', 'delete_categories',
            ],

            'marketing' => [
                'view_promotions', 'manage_promotions',
                'view_coupons', 'manage_coupons',
                'view_notifications', 'send_notifications',
                'view_all_produits', 'view_categories',
                'generate_reports',
            ],

            'vendeur' => [
                // Permissions spécifiques aux vendeurs
                // Gérées via le rôle vendeur
            ],
            
            'livreur' => [
                // Permissions spécifiques aux livreurs
                // Gérées via le rôle livreur
            ],
            
            'client' => [
                // Permissions spécifiques aux clients
                // Gérées via le rôle client
            ],
        ];

        return $permissions[$role] ?? [];
    }
}
