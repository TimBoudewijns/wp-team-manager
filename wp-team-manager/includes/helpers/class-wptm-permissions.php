<?php
/**
 * Permissions Helper Class
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle permission checks for WP Team Manager
 */
class WPTM_Permissions {
    
    /**
     * Check if user can manage teams
     *
     * @param int $user_id User ID
     * @param int $team_id Team ID (optional)
     * @return bool
     */
    public static function can_manage_teams($user_id, $team_id = null) {
        if (!$user_id) {
            return false;
        }
        
        if ($team_id) {
            return self::is_team_owner($user_id, $team_id) || self::is_team_manager($user_id, $team_id);
        }
        
        // Check if user has any manager/owner roles
        global $wpdb;
        $meta_results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value 
             FROM {$wpdb->prefix}usermeta 
             WHERE user_id = %d 
             AND meta_key LIKE '_wc_memberships_for_teams_team_%_role'",
            $user_id
        ));
        
        if (!empty($meta_results)) {
            foreach ($meta_results as $meta) {
                if (in_array($meta->meta_value, ['manager', 'owner'])) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if user is team owner
     *
     * @param int $user_id User ID
     * @param int $team_id Team ID
     * @return bool
     */
    public static function is_team_owner($user_id, $team_id) {
        global $wpdb;
        
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}wptm_teams WHERE id = %d",
            $team_id
        ));
        
        return $team && $team->user_id == $user_id;
    }
    
    /**
     * Check if user is team manager
     *
     * @param int $user_id User ID
     * @param int $team_id Team ID
     * @return bool
     */
    public static function is_team_manager($user_id, $team_id) {
        global $wpdb;
        
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT wc_team_id FROM {$wpdb->prefix}wptm_teams WHERE id = %d",
            $team_id
        ));
        
        if (!$team || !$team->wc_team_id) {
            return false;
        }
        
        $role = get_user_meta($user_id, "_wc_memberships_for_teams_team_{$team->wc_team_id}_role", true);
        return in_array($role, ['manager', 'owner']);
    }
    
    /**
     * Check if user can view player data
     *
     * @param int $user_id User ID
     * @param int $player_id Player ID
     * @param int $team_id Team ID
     * @return bool
     */
    public static function can_view_player($user_id, $player_id, $team_id) {
        // Check if user owns the player
        global $wpdb;
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}wptm_unique_players WHERE id = %d",
            $player_id
        ));
        
        if ($player && $player->user_id == $user_id) {
            return true;
        }
        
        // Check if user can manage the team
        return self::can_manage_teams($user_id, $team_id);
    }
    
    /**
     * Check if user can rate players
     *
     * @param int $user_id User ID
     * @param int $player_id Player ID
     * @param int $team_id Team ID
     * @return bool
     */
    public static function can_rate_player($user_id, $player_id, $team_id) {
        return self::can_view_player($user_id, $player_id, $team_id);
    }
    
    /**
     * Check if user can manage trainers
     *
     * @param int $user_id User ID
     * @param int $club_id Club ID
     * @return bool
     */
    public static function can_manage_trainers($user_id, $club_id) {
        if (!$user_id || !$club_id) {
            return false;
        }
        
        $role = get_user_meta($user_id, "_wc_memberships_for_teams_team_{$club_id}_role", true);
        return in_array($role, ['manager', 'owner']);
    }
    
    /**
     * Check if user can remove trainer
     *
     * @param int $user_id Current user ID
     * @param int $trainer_id Trainer to remove ID
     * @param int $club_id Club ID
     * @return bool
     */
    public static function can_remove_trainer($user_id, $trainer_id, $club_id) {
        if ($trainer_id == $user_id) {
            return false; // Cannot remove yourself
        }
        
        $user_role = get_user_meta($user_id, "_wc_memberships_for_teams_team_{$club_id}_role", true);
        $trainer_role = get_user_meta($trainer_id, "_wc_memberships_for_teams_team_{$club_id}_role", true);
        
        // Owner cannot be removed
        if ($trainer_role === 'owner') {
            return false;
        }
        
        // Managers cannot remove other managers
        if ($user_role === 'manager' && $trainer_role === 'manager') {
            return false;
        }
        
        return in_array($user_role, ['manager', 'owner']);
    }
    
    /**
     * Check if user can change trainer roles
     *
     * @param int $user_id Current user ID
     * @param int $trainer_id Trainer ID
     * @param int $club_id Club ID
     * @return bool
     */
    public static function can_change_trainer_role($user_id, $trainer_id, $club_id) {
        if ($trainer_id == $user_id) {
            return false; // Cannot change your own role
        }
        
        $user_role = get_user_meta($user_id, "_wc_memberships_for_teams_team_{$club_id}_role", true);
        $trainer_role = get_user_meta($trainer_id, "_wc_memberships_for_teams_team_{$club_id}_role", true);
        
        // Only owners can change roles
        if ($user_role !== 'owner') {
            return false;
        }
        
        // Cannot change owner role
        if ($trainer_role === 'owner') {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate season format
     *
     * @param string $season Season string
     * @return bool
     */
    public static function is_valid_season($season) {
        if (!$season || !is_string($season)) {
            return false;
        }
        
        return preg_match('/^\d{4}-\d{4}$/', $season);
    }
    
    /**
     * Validate season year range
     *
     * @param string $season Season string
     * @return bool
     */
    public static function is_valid_season_range($season) {
        if (!self::is_valid_season($season)) {
            return false;
        }
        
        $start_year = (int) explode('-', $season)[0];
        return $start_year >= 1900 && $start_year <= (date('Y') + 5);
    }
}