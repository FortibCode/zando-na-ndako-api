<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'view_users', 'manage_users_status', 'delete_users',
            'view_vendeurs', 'validate_vendeurs', 'suspendre_vendeurs', 'edit_vendeurs',
            'view_livreurs', 'validate_livreurs', 'suspendre_livreurs',
            'view_all_commandes', 'assign_commandes', 'edit_commande_status', 'refund_commandes',
            'view_livraisons', 'reassign_livraisons',
            'view_all_produits', 'edit_produits', 'delete_produits',
            'create_categories', 'edit_categories', 'delete_categories',
            'view_types_boutique', 'create_types_boutique', 'edit_types_boutique', 'delete_types_boutique',
            'view_zones', 'create_zones', 'edit_zones', 'delete_zones',
            'view_finances', 'manage_retraits', 'manage_commissions', 'generate_reports',
            'view_taux_change', 'create_taux_change', 'edit_taux_change', 'delete_taux_change',
            'view_avis', 'delete_avis',
            'view_litiges', 'traiter_litiges',
            'view_litige_motifs', 'create_litige_motifs', 'edit_litige_motifs', 'delete_litige_motifs',
            'view_tickets', 'manage_tickets',
            'view_promotions', 'manage_promotions', 'view_coupons', 'manage_coupons',
            'view_notifications', 'send_notifications',
            'view_parametres', 'edit_parametres',
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['nom_permission' => $perm],
                ['id' => Str::uuid()->toString(), 'description' => "Permission {$perm}", 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}

