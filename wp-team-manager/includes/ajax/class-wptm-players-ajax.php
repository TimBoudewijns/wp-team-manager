<?php
/**
 * Players AJAX Handler
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle players related AJAX requests
 */
class WPTM_Players_Ajax {
    
    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_wptm_get_players', array(__CLASS__, 'get_players'));
        add_action('wp_ajax_wptm_add_player_to_team', array(__CLASS__, 'add_player_to_team'));
        add_action('wp_ajax_wptm_delete_player_from_team', array(__CLASS__, 'delete_player_from_team'));
        add_action('wp_ajax_wptm_get_player_history', array(__CLASS__, 'get_player_history'));
    }
    
    /**
     * Get players
     */
    public static function get_players() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $all_players = isset($_POST['all_players']) && $_POST['all_players'] === 'true';
        
        if ($all_players) {
            $cache_key = WPTM_Cache::get_players_cache_key($user_id, null, true);
            $players = get_transient($cache_key);
            
            if ($players !== false) {
                WPTM_Utils::send_json_success($players);
            }
            
            $players = $wpdb->get_results($wpdb->prepare(
                "SELECT id, first_name, last_name, birth_date FROM {$wpdb->prefix}wptm_unique_players WHERE user_id = %d",
                $user_id
            ));
            
            foreach ($players as $player) {
                $player->birth_date = $player->birth_date ?? null;
            }
            
            set_transient($cache_key, $players, WPTM_Cache::CACHE_TIME_MEDIUM);
            WPTM_Utils::send_json_success($players);
        } else {
            $team_id = intval($_POST['team_id'] ?? 0);
            $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
            
            if (!$season) {
                WPTM_Utils::send_json_error('Invalid season format');
            }
            
            if (!$team_id) {
                WPTM_Utils::send_json_error('Team ID is required');
            }
            
            $cache_key = WPTM_Cache::get_players_cache_key($team_id, $season);
            $players = get_transient($cache_key);
            
            if ($players !== false) {
                WPTM_Utils::send_json_success($players);
            }
            
            // Check permissions
            if (!WPTM_Permissions::can_view_player($user_id, null, $team_id)) {
                WPTM_Utils::send_json_error('You do not have permission to view players of this team');
            }
            
            $players = $wpdb->get_results($wpdb->prepare(
                "SELECT up.id, up.first_name, up.last_name, up.birth_date, tp.position, tp.player_number
                 FROM {$wpdb->prefix}wptm_unique_players up
                 JOIN {$wpdb->prefix}wptm_team_players tp ON up.id = tp.player_id
                 WHERE tp.team_id = %d AND tp.season = %s",
                $team_id, $season
            ));
            
            foreach ($players as $player) {
                $player->birth_date = $player->birth_date ?? null;
            }
            
            set_transient($cache_key, $players, WPTM_Cache::CACHE_TIME_MEDIUM);
            WPTM_Utils::send_json_success($players);
        }
    }
    
    /**
     * Add player to team
     */
    public static function add_player_to_team() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $team_id = intval($_POST['team_id'] ?? 0);
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        $player_id = isset($_POST['player_id']) && $_POST['player_id'] ? intval($_POST['player_id']) : null;
        
        $player_data = WPTM_Utils::sanitize_player_data($_POST);
        
        if (!$season) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        if (!WPTM_Permissions::is_valid_season_range($season)) {
            WPTM_Utils::send_json_error('Invalid season');
        }
        
        if (!$team_id) {
            WPTM_Utils::send_json_error('Team ID is required');
        }
        
        // Check permissions
        if (!WPTM_Permissions::is_team_owner($user_id, $team_id)) {
            WPTM_Utils::send_json_error('You do not have permission to add players to this team');
        }
        
        // If no player selected, create new player
        if (!$player_id && ($player_data['first_name'] || $player_data['last_name'])) {
            if (empty($player_data['first_name']) || empty($player_data['last_name'])) {
                WPTM_Utils::send_json_error('Both first name and last name are required');
            }
            
            $birth_date_formatted = $player_data['birth_date'] ? date('Y-m-d', strtotime($player_data['birth_date'])) : null;
            
            $result = $wpdb->insert(
                "{$wpdb->prefix}wptm_unique_players",
                array(
                    'user_id' => $user_id,
                    'first_name' => $player_data['first_name'],
                    'last_name' => $player_data['last_name'],
                    'birth_date' => $birth_date_formatted
                ),
                array('%d', '%s', '%s', $birth_date_formatted ? '%s' : '%s')
            );
            
            if ($result === false) {
                WPTM_Utils::send_json_error('Error creating player: ' . $wpdb->last_error);
            }
            
            $player_id = $wpdb->insert_id;
            
            // Clear cache
            WPTM_Cache::clear_user_cache($user_id);
        }
        
        if ($player_id) {
            // Check player ownership
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}wptm_unique_players WHERE id = %d",
                $player_id
            ));
            
            if (!$player || $player->user_id != $user_id) {
                WPTM_Utils::send_json_error('You do not have permission to add this player');
            }
            
            // Check if player already exists in team
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wptm_team_players 
                 WHERE player_id = %d AND team_id = %d AND season = %s",
                $player_id, $team_id, $season
            ));
            
            if ($exists) {
                WPTM_Utils::send_json_error('Player already added to this team for this season');
            }
            
            $result = $wpdb->insert(
                "{$wpdb->prefix}wptm_team_players",
                array(
                    'player_id' => $player_id,
                    'team_id' => $team_id,
                    'season' => $season,
                    'position' => $player_data['position'] ?: null,
                    'player_number' => $player_data['player_number'] ?: null
                ),
                array('%d', '%d', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                WPTM_Utils::send_json_error('Error adding player to team: ' . $wpdb->last_error);
            }
            
            // Clear cache
            WPTM_Cache::clear_team_cache($team_id, $season);
            WPTM_Cache::clear_player_cache($player_id);
            
            WPTM_Utils::send_json_success(array('player_id' => $player_id));
        }
        
        WPTM_Utils::send_json_error('No player selected or names provided');
    }
    
    /**
     * Delete player from team
     */
    public static function delete_player_from_team() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $player_id = intval($_POST['player_id'] ?? 0);
        $team_id = intval($_POST['team_id'] ?? 0);
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$season) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        if (!$player_id || !$team_id) {
            WPTM_Utils::send_json_error('Player ID and Team ID are required');
        }
        
        // Check permissions
        if (!WPTM_Permissions::is_team_owner($user_id, $team_id)) {
            WPTM_Utils::send_json_error('You do not have permission to remove players from this team');
        }
        
        $result = $wpdb->delete(
            "{$wpdb->prefix}wptm_team_players",
            array('player_id' => $player_id, 'team_id' => $team_id, 'season' => $season),
            array('%d', '%d', '%s')
        );
        
        if ($result === false) {
            WPTM_Utils::send_json_error('Error removing player from team: ' . $wpdb->last_error);
        }
        
        // Clear cache
        WPTM_Cache::clear_team_cache($team_id, $season);
        WPTM_Cache::clear_player_cache($player_id);
        
        WPTM_Utils::send_json_success('Player removed from team successfully');
    }
    
    /**
     * Get player history
     */
    public static function get_player_history() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $player_id = intval($_POST['player_id'] ?? 0);
        
        if (!$player_id) {
            WPTM_Utils::send_json_error('Player ID is required');
        }
        
        $cache_key = WPTM_Cache::get_player_history_cache_key($player_id);
        $history = get_transient($cache_key);
        
        if ($history !== false) {
            WPTM_Utils::send_json_success($history);
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_view_player($user_id, $player_id, null)) {
            WPTM_Utils::send_json_error('You do not have permission to view this player\'s history');
        }
        
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT t.team_name, tp.season, tp.position
             FROM {$wpdb->prefix}wptm_team_players tp
             JOIN {$wpdb->prefix}wptm_teams t ON tp.team_id = t.id
             WHERE tp.player_id = %d
             ORDER BY tp.season DESC",
            $player_id
        ));
        
        set_transient($cache_key, $history, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($history);
    }
}