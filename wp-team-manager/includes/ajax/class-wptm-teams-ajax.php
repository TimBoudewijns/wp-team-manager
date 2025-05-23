<?php
/**
 * Teams AJAX Handler
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle teams related AJAX requests
 */
class WPTM_Teams_Ajax {
    
    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_wptm_get_teams', array(__CLASS__, 'get_teams'));
        add_action('wp_ajax_wptm_add_team', array(__CLASS__, 'add_team'));
        add_action('wp_ajax_wptm_get_manager_teams', array(__CLASS__, 'get_manager_teams'));
    }
    
    /**
     * Get user's teams
     */
    public static function get_teams() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$season) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        if (!WPTM_Permissions::is_valid_season_range($season)) {
            WPTM_Utils::send_json_error('Invalid season');
        }
        
        $cache_key = WPTM_Cache::get_teams_cache_key($user_id, $season, 'teams');
        $teams = get_transient($cache_key);
        
        if ($teams !== false) {
            WPTM_Utils::send_json_success($teams);
        }
        
        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT id, team_name, season, coach FROM {$wpdb->prefix}wptm_teams WHERE user_id = %d AND season = %s",
            $user_id, $season
        ));
        
        foreach ($teams as $team) {
            $team->players = $wpdb->get_results($wpdb->prepare(
                "SELECT up.id, up.first_name, up.last_name, up.birth_date, tp.position, tp.player_number
                 FROM {$wpdb->prefix}wptm_unique_players up
                 JOIN {$wpdb->prefix}wptm_team_players tp ON up.id = tp.player_id
                 WHERE tp.team_id = %d AND tp.season = %s",
                $team->id, $season
            ));
            $team->player_count = count($team->players);
        }
        
        set_transient($cache_key, $teams, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($teams);
    }
    
    /**
     * Add new team
     */
    public static function add_team() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $team_name = sanitize_text_field($_POST['team_name'] ?? '');
        $coach = sanitize_text_field($_POST['coach'] ?? '');
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$season) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        if (!WPTM_Permissions::is_valid_season_range($season)) {
            WPTM_Utils::send_json_error('Invalid season');
        }
        
        if (empty($team_name)) {
            WPTM_Utils::send_json_error('Team name is required');
        }
        
        if (!$user_id) {
            WPTM_Utils::send_json_error('User not logged in');
        }
        
        // Find WC team ID
        $wc_team_id = null;
        $meta_results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
             FROM {$wpdb->prefix}usermeta 
             WHERE user_id = %d 
             AND meta_key LIKE '_wc_memberships_for_teams_team_%_role'",
            $user_id
        ));
        
        if (!empty($meta_results)) {
            foreach ($meta_results as $meta) {
                if (preg_match('/_wc_memberships_for_teams_team_(\d+)_role/', $meta->meta_key, $matches)) {
                    $potential_team_id = $matches[1];
                    $role = $meta->meta_value;
                    if (in_array($role, ['member', 'manager', 'owner'])) {
                        $wc_team_id = $potential_team_id;
                        break;
                    }
                }
            }
        }
        
        $data = array(
            'user_id' => $user_id,
            'team_name' => $team_name,
            'season' => $season,
            'coach' => $coach ?: null,
            'wc_team_id' => $wc_team_id
        );
        
        $format = array('%d', '%s', '%s', '%s', '%d');
        
        $result = $wpdb->insert(
            "{$wpdb->prefix}wptm_teams",
            $data,
            $format
        );
        
        if ($result === false) {
            WPTM_Utils::log_error('Error creating team', array(
                'user_id' => $user_id,
                'team_name' => $team_name,
                'season' => $season,
                'db_error' => $wpdb->last_error
            ));
            WPTM_Utils::send_json_error('Error creating team: ' . $wpdb->last_error);
        }
        
        if ($wpdb->insert_id == 0) {
            WPTM_Utils::send_json_error('Team not created, no ID returned');
        }
        
        // Clear cache
        WPTM_Cache::clear_user_cache($user_id);
        
        WPTM_Utils::send_json_success(array('team_id' => $wpdb->insert_id));
    }
    
    /**
     * Get manager teams
     */
    public static function get_manager_teams() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$season) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        if (!WPTM_Permissions::is_valid_season_range($season)) {
            WPTM_Utils::send_json_error('Invalid season');
        }
        
        $cache_key = WPTM_Cache::get_teams_cache_key($user_id, $season, 'manager_teams');
        $teams = get_transient($cache_key);
        
        if ($teams !== false) {
            WPTM_Utils::send_json_success($teams);
        }
        
        $meta_results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
             FROM {$wpdb->prefix}usermeta 
             WHERE user_id = %d 
             AND meta_key LIKE '_wc_memberships_for_teams_team_%_role'",
            $user_id
        ));
        
        if (empty($meta_results)) {
            WPTM_Utils::send_json_success(array());
        }
        
        $wc_team_ids = array();
        foreach ($meta_results as $meta) {
            if (preg_match('/_wc_memberships_for_teams_team_(\d+)_role/', $meta->meta_key, $matches)) {
                $team_id = $matches[1];
                $role = $meta->meta_value;
                if (in_array($role, ['manager', 'owner'])) {
                    $wc_team_ids[] = $team_id;
                }
            }
        }
        
        if (empty($wc_team_ids)) {
            WPTM_Utils::send_json_success(array());
        }
        
        $placeholders = implode(',', array_fill(0, count($wc_team_ids), '%d'));
        $query = "SELECT t.id, t.team_name, t.season, t.coach, u.display_name as member_name
                 FROM {$wpdb->prefix}wptm_teams t
                 JOIN {$wpdb->prefix}users u ON t.user_id = u.ID
                 WHERE t.wc_team_id IN ($placeholders) AND t.season = %s
                 ORDER BY t.season DESC";
        $params = array_merge($wc_team_ids, array($season));
        $teams = $wpdb->get_results($wpdb->prepare($query, $params));
        
        if (empty($teams)) {
            WPTM_Utils::send_json_success(array());
        }
        
        foreach ($teams as $team) {
            $team->players = $wpdb->get_results($wpdb->prepare(
                "SELECT up.id, up.first_name, up.last_name, up.birth_date, tp.position
                 FROM {$wpdb->prefix}wptm_unique_players up
                 JOIN {$wpdb->prefix}wptm_team_players tp ON up.id = tp.player_id
                 WHERE tp.team_id = %d AND tp.season = %s",
                $team->id, $team->season
            ));
            $team->player_count = count($team->players);
            foreach ($team->players as $player) {
                $player->birth_date = $player->birth_date ?? null;
            }
        }
        
        set_transient($cache_key, $teams, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($teams);
    }
}