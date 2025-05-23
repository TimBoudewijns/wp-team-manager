<?php
/**
 * Role Mapper Class
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom Role Mapping for WP Team Manager
 * 
 * Deze klasse beheert het vertalen van interne rollen naar aangepaste benamingen
 * en vice versa.
 */
class WPTM_Role_Mapper {
    // Interne rollen die worden gebruikt door Teams for WooCommerce Memberships
    private static $internal_roles = [
        'owner',
        'manager',
        'member'
    ];
    
    // Aangepaste rol benaming voor de UI (sleutel = interne rol, waarde = aangepaste benaming)
    private static $role_mapping = [
        'owner' => 'Owner',
        'manager' => 'Manager',
        'member' => 'Trainer'
        // We kunnen 'sports_coordinator' niet direct toevoegen als een interne rol,
        // dit zal apart worden afgehandeld
    ];
    
    // Rol volgorde voor sortering en hiërarchie (hogere waarde = hogere toegang)
    private static $role_hierarchy = [
        'owner' => 100,
        'manager' => 80,
        'sports_coordinator' => 60, // Extra aangepaste rol
        'member' => 40
    ];
    
    /**
     * Geeft de aangepaste benaming terug voor een interne rol
     */
    public static function get_display_name($internal_role) {
        // Voor sports_coordinator gebruiken we een speciale afhandeling
        if ($internal_role === 'sports_coordinator') {
            return 'Sports Coordinator';
        }
        
        // Voor standaard interne rollen gebruiken we de mapping
        return isset(self::$role_mapping[$internal_role]) ? 
               self::$role_mapping[$internal_role] : 
               ucfirst($internal_role); // Fallback naar opgemaakte versie van de interne rol
    }
    
    /**
     * Converteert een aangepaste benaming terug naar de interne rol
     */
    public static function get_internal_role($display_name) {
        $display_name = strtolower(trim($display_name));
        
        // Speciale afhandeling voor Sports Coordinator
        if ($display_name === 'sports coordinator') {
            return 'sports_coordinator';
        }
        
        // Zoek interne rol op basis van aangepaste benaming
        foreach (self::$role_mapping as $internal => $display) {
            if (strtolower($display) === $display_name) {
                return $internal;
            }
        }
        
        // Fallback: retourneer de input als we geen match vinden
        return $display_name;
    }
    
    /**
     * Controleert of een rol een standaard interne rol is
     */
    public static function is_standard_role($role) {
        return in_array($role, self::$internal_roles);
    }
    
    /**
     * Geeft alle beschikbare rollen terug met hun aangepaste benamingen
     */
    public static function get_all_roles() {
        $roles = [];
        
        // Standaard interne rollen toevoegen
        foreach (self::$internal_roles as $role) {
            $roles[$role] = self::get_display_name($role);
        }
        
        // Extra rol toevoegen
        $roles['sports_coordinator'] = 'Sports Coordinator';
        
        return $roles;
    }
    
    /**
     * Geeft alleen de rollen terug die een gebruiker mag toewijzen
     * gebaseerd op zijn eigen rol
     */
    public static function get_assignable_roles($user_role) {
        $user_level = isset(self::$role_hierarchy[$user_role]) ? 
                     self::$role_hierarchy[$user_role] : 0;
        
        $assignable_roles = [];
        
        foreach (self::$role_hierarchy as $role => $level) {
            // Gebruikers kunnen alleen rollen toewijzen die lager zijn dan hun eigen niveau
            if ($level < $user_level) {
                $assignable_roles[$role] = self::get_display_name($role);
            }
        }
        
        return $assignable_roles;
    }
    
    /**
     * Slaat de rol op in het juiste formaat
     * - Standaardrollen ('owner', 'manager', 'member') worden direct opgeslagen
     * - Voor 'sports_coordinator' gebruiken we 'member' als interne rol,
     *   maar slaan we een extra meta veld op
     * - Nu ook aangepast om de club_role in wp_dfdwptm_team_trainers tabel bij te werken
     */
    public static function save_role($user_id, $team_id, $role) {
        global $wpdb;
        
        // Controleer of dit een Sports Coordinator is
        $is_coordinator = ($role === 'sports_coordinator');
        
        // Bepaal de interne rol die we moeten opslaan
        $internal_role = $is_coordinator ? 'member' : $role;
        
        // Voor logging doeleinden
        error_log("WPTM_Role_Mapper::save_role - Saving role for user $user_id in team $team_id: $role (internal: $internal_role, is_coordinator: " . ($is_coordinator ? 'yes' : 'no') . ')');
        
        // Als het een standaardrol is, kunnen we deze direct opslaan
        if (self::is_standard_role($internal_role)) {
            // Update WooCommerce metadata
            update_user_meta($user_id, "_wc_memberships_for_teams_team_{$team_id}_role", $internal_role);
            
            // Als het een sports coordinator is, sla dan extra meta data op
            if ($is_coordinator) {
                update_user_meta($user_id, "_wptm_team_{$team_id}_is_coordinator", 'yes');
            } else {
                // Zorg ervoor dat we de coordinator markering verwijderen als de rol verandert
                delete_user_meta($user_id, "_wptm_team_{$team_id}_is_coordinator");
            }
            
            // Update de club_role in de wp_dfdwptm_team_trainers tabel
            $table_name = $wpdb->prefix . 'wptm_team_trainers';
            
            // Controleer of de tabel bestaat
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
            if ($table_exists) {
                // Controleer of er een record bestaat voor deze gebruiker en team
                $record_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_name} WHERE trainer_user_id = %d AND team_id = %d",
                    $user_id, $team_id
                ));
                
                // De club_role die we willen opslaan
                $club_role = $is_coordinator ? 'sports_coordinator' : $internal_role;
                
                if ($record_exists) {
                    // Update bestaand record
                    $result = $wpdb->update(
                        $table_name,
                        ['club_role' => $club_role],
                        [
                            'trainer_user_id' => $user_id,
                            'team_id' => $team_id
                        ],
                        ['%s'],
                        ['%d', '%d']
                    );
                    
                    error_log("WPTM_Role_Mapper::save_role - Updated club_role in $table_name for user $user_id in team $team_id to $club_role (Result: " . ($result !== false ? 'success' : 'failure') . ")");
                } else {
                    error_log("WPTM_Role_Mapper::save_role - No record found in $table_name for user $user_id in team $team_id");
                }
            } else {
                error_log("WPTM_Role_Mapper::save_role - Table $table_name does not exist");
            }
            
            return true;
        }
        
        error_log("WPTM_Role_Mapper::save_role - Invalid role: $internal_role");
        return false; // Ongeldige rol
    }
    
    /**
     * Haalt de werkelijke rol op voor een gebruiker, inclusief onze aangepaste rollen
     * Nu met ondersteuning voor club_role in wp_dfdwptm_team_trainers tabel
     */
    public static function get_user_role($user_id, $team_id) {
        global $wpdb;
        
        // Controleer eerst in de wp_dfdwptm_team_trainers tabel
        $table_name = $wpdb->prefix . 'wptm_team_trainers';
        
        // Controleer of de tabel bestaat
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        if ($table_exists) {
            // Zoek naar een club_role in de team_trainers tabel
            $club_role = $wpdb->get_var($wpdb->prepare(
                "SELECT club_role FROM {$table_name} WHERE trainer_user_id = %d AND team_id = %d",
                $user_id, $team_id
            ));
            
            if ($club_role) {
                error_log("WPTM_Role_Mapper::get_user_role - Found club_role in $table_name for user $user_id in team $team_id: $club_role");
                return $club_role;
            }
        }
        
        // Als we hier komen, hebben we geen club_role gevonden in de database
        // of de tabel bestaat niet, dus vallen we terug op de oudere methode
        
        // Haal de basis rol op uit WooCommerce memberships
        $role = get_user_meta($user_id, "_wc_memberships_for_teams_team_{$team_id}_role", true);
        
        // Als de basis rol 'member' is, controleer dan of dit een coordinator is
        if ($role === 'member') {
            // Controleer beide mogelijke metakeys voor backward compatibility
            $is_coordinator_wc = get_user_meta($user_id, "_wptm_team_{$team_id}_is_coordinator", true);
            $is_coordinator = $is_coordinator_wc === 'yes';
            
            // Als dit een coordinator is, geef dan sports_coordinator terug als rol
            if ($is_coordinator) {
                return 'sports_coordinator';
            }
        }
        
        return $role;
    }
    
    /**
     * Synchroniseer de club_role in wptm_team_trainers tabel op basis van WooCommerce metadata
     * Deze functie kan in bulk worden uitgevoerd tijdens plugin updates of database migraties
     */
    public static function synchronize_club_roles() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wptm_team_trainers';
        
        // Controleer of de tabel bestaat
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        if (!$table_exists) {
            error_log("WPTM_Role_Mapper::synchronize_club_roles - Table $table_name does not exist");
            return false;
        }
        
        // Controleer of de club_role kolom bestaat
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND COLUMN_NAME = 'club_role'
        ");
        
        if ($column_exists == 0) {
            error_log("WPTM_Role_Mapper::synchronize_club_roles - club_role column does not exist in $table_name");
            return false;
        }
        
        // Haal alle trainers op uit de tabel
        $trainers = $wpdb->get_results("
            SELECT tt.id, tt.trainer_user_id, tt.team_id, tt.wc_team_id, tt.club_role
            FROM $table_name tt
        ");
        
        $updated_count = 0;
        
        foreach ($trainers as $trainer) {
            $trainer_id = $trainer->trainer_user_id;
            $team_id = $trainer->team_id;
            $wc_team_id = $trainer->wc_team_id;
            
            if ($wc_team_id) {
                // Haal de rol op uit usermeta
                $meta_key = "_wc_memberships_for_teams_team_{$wc_team_id}_role";
                $meta_value = get_user_meta($trainer_id, $meta_key, true);
                
                // Check voor sports_coordinator status
                $is_coordinator = get_user_meta($trainer_id, "_wptm_team_{$wc_team_id}_is_coordinator", true) === 'yes';
                
                // Bepaal de club rol
                $club_role = 'trainer'; // Default
                
                if ($is_coordinator) {
                    $club_role = 'sports_coordinator';
                } elseif ($meta_value === 'owner') {
                    $club_role = 'owner';
                } elseif ($meta_value === 'manager') {
                    $club_role = 'manager';
                } elseif ($meta_value === 'member') {
                    $club_role = 'trainer'; // We gebruiken 'trainer' i.p.v. 'member'
                }
                
                // Update de club_role in de database als deze verschilt van wat we hebben berekend
                if ($club_role !== $trainer->club_role) {
                    $wpdb->update(
                        $table_name,
                        ['club_role' => $club_role],
                        ['id' => $trainer->id],
                        ['%s'],
                        ['%d']
                    );
                    
                    $updated_count++;
                    error_log("WPTM_Role_Mapper::synchronize_club_roles - Updated club_role for trainer $trainer_id to $club_role (team_id: $team_id, wc_team_id: $wc_team_id)");
                }
            }
        }
        
        error_log("WPTM_Role_Mapper::synchronize_club_roles - Synchronized $updated_count club_roles");
        return $updated_count;
    }
}