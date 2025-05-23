<?php
/**
 * Cache Helper Class
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle caching for WP Team Manager
 */
class WPTM_Cache {
    
    /**
     * Cache expiration times
     */
    const CACHE_TIME_SHORT = 5 * MINUTE_IN_SECONDS;    // 5 minutes
    const CACHE_TIME_MEDIUM = HOUR_IN_SECONDS;         // 1 hour
    const CACHE_TIME_LONG = 12 * HOUR_IN_SECONDS;      // 12 hours
    
    /**
     * Get cache key for teams
     *
     * @param int $user_id User ID
     * @param string $season Season
     * @param string $type Type (my_teams, manager_teams, etc.)
     * @return string
     */
    public static function get_teams_cache_key($user_id, $season, $type = 'teams') {
        return "wptm_{$type}_{$user_id}_{$season}";
    }
    
    /**
     * Get cache key for players
     *
     * @param int $team_id Team ID
     * @param string $season Season
     * @param bool $all_players Whether this is for all players
     * @return string
     */
    public static function get_players_cache_key($team_id, $season, $all_players = false) {
        if ($all_players) {
            return "wptm_all_players_{$team_id}";
        }
        return "wptm_players_{$team_id}_{$season}";
    }
    
    /**
     * Get cache key for player ratings
     *
     * @param int $player_id Player ID
     * @param int $team_id Team ID
     * @param string $season Season
     * @return string
     */
    public static function get_player_ratings_cache_key($player_id, $team_id, $season) {
        return "wptm_player_ratings_{$player_id}_{$team_id}_{$season}";
    }
    
    /**
     * Get cache key for spider chart
     *
     * @param int $player_id Player ID
     * @param int $team_id Team ID
     * @param string $season Season
     * @return string
     */
    public static function get_spider_chart_cache_key($player_id, $team_id, $season) {
        return "wptm_spider_chart_{$player_id}_{$team_id}_{$season}";
    }
    
    /**
     * Get cache key for player history
     *
     * @param int $player_id Player ID
     * @return string
     */
    public static function get_player_history_cache_key($player_id) {
        return "wptm_player_history_{$player_id}";
    }
    
    /**
     * Get cache key for clubs
     *
     * @param int $user_id User ID
     * @return string
     */
    public static function get_clubs_cache_key($user_id) {
        return "wptm_clubs_{$user_id}";
    }
    
    /**
     * Get cache key for club details
     *
     * @param int $club_id Club ID
     * @return string
     */
    public static function get_club_details_cache_key($club_id) {
        return "wptm_club_details_{$club_id}";
    }
    
    /**
     * Get cache key for managed teams
     *
     * @param int $user_id User ID
     * @param string $season Season
     * @return string
     */
    public static function get_managed_teams_cache_key($user_id, $season) {
        return "wptm_managed_teams_{$user_id}_{$season}";
    }
    
    /**
     * Get cache key for team trainers
     *
     * @param int $team_id Team ID
     * @param string $season Season
     * @return string
     */
    public static function get_team_trainers_cache_key($team_id, $season) {
        return "wptm_team_trainers_{$team_id}_{$season}";
    }
    
    /**
     * Get cache key for available trainers
     *
     * @param array $wc_team_ids Array of WC team IDs
     * @return string
     */
    public static function get_available_trainers_cache_key($wc_team_ids) {
        if (is_array($wc_team_ids)) {
            $wc_team_ids = implode('_', $wc_team_ids);
        }
        return "wptm_available_trainers_{$wc_team_ids}";
    }
    
    /**
     * Clear all cache for a user
     *
     * @param int $user_id User ID
     */
    public static function clear_user_cache($user_id) {
        // Clear teams cache
        global $wpdb;
        $seasons = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT season FROM {$wpdb->prefix}wptm_teams WHERE user_id = %d",
            $user_id
        ));
        
        foreach ($seasons as $season) {
            delete_transient(self::get_teams_cache_key($user_id, $season, 'teams'));
            delete_transient(self::get_teams_cache_key($user_id, $season, 'manager_teams'));
            delete_transient(self::get_managed_teams_cache_key($user_id, $season));
        }
        
        // Clear clubs cache
        delete_transient(self::get_clubs_cache_key($user_id));
    }
    
    /**
     * Clear all cache for a team
     *
     * @param int $team_id Team ID
     * @param string $season Season
     */
    public static function clear_team_cache($team_id, $season) {
        // Clear players cache
        delete_transient(self::get_players_cache_key($team_id, $season));
        
        // Clear team trainers cache
        delete_transient(self::get_team_trainers_cache_key($team_id, $season));
        
        // Get team owner to clear their cache
        global $wpdb;
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}wptm_teams WHERE id = %d",
            $team_id
        ));
        
        if ($team) {
            delete_transient(self::get_teams_cache_key($team->user_id, $season, 'teams'));
            delete_transient(self::get_teams_cache_key($team->user_id, $season, 'manager_teams'));
            delete_transient(self::get_managed_teams_cache_key($team->user_id, $season));
        }
    }
    
    /**
     * Clear all cache for a player
     *
     * @param int $player_id Player ID
     */
    public static function clear_player_cache($player_id) {
        global $wpdb;
        
        // Get all teams this player is in
        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT team_id, season FROM {$wpdb->prefix}wptm_team_players WHERE player_id = %d",
            $player_id
        ));
        
        foreach ($teams as $team) {
            delete_transient(self::get_player_ratings_cache_key($player_id, $team->team_id, $team->season));
            delete_transient(self::get_spider_chart_cache_key($player_id, $team->team_id, $team->season));
        }
        
        // Clear player history cache
        delete_transient(self::get_player_history_cache_key($player_id));
    }
    
    /**
     * Clear all cache for a club
     *
     * @param int $club_id Club ID
     */
    public static function clear_club_cache($club_id) {
        delete_transient(self::get_club_details_cache_key($club_id));
        
        // Clear available trainers cache (this is tricky as we don't know all combinations)
        // For now, we'll clear when needed in the AJAX handlers
    }
    
    /**
     * Clear all plugin cache
     */
    public static function clear_all_cache() {
        global $wpdb;
        
        // Get all transients that start with wptm_
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wptm_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wptm_%'");
    }
    
    /**
     * Get cache with fallback
     *
     * @param string $cache_key Cache key
     * @param callable $callback Callback to get data if cache miss
     * @param int $expiration Cache expiration time
     * @return mixed
     */
    public static function get_or_set($cache_key, $callback, $expiration = self::CACHE_TIME_MEDIUM) {
        $data = get_transient($cache_key);
        
        if ($data !== false) {
            return $data;
        }
        
        $data = call_user_func($callback);
        
        if ($data !== null) {
            set_transient($cache_key, $data, $expiration);
        }
        
        return $data;
    }
}