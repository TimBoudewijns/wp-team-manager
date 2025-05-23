<?php
/**
 * Ratings AJAX Handler
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle ratings related AJAX requests
 */
class WPTM_Ratings_Ajax {
    
    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_wptm_save_ratings', array(__CLASS__, 'save_ratings'));
        add_action('wp_ajax_wptm_get_spider_chart', array(__CLASS__, 'get_spider_chart'));
        add_action('wp_ajax_wptm_get_player_ratings', array(__CLASS__, 'get_player_ratings'));
        
        // Register the background task for generating coach advice
        add_action('wptm_generate_coach_advice', array(__CLASS__, 'generate_coach_advice_background'), 10, 6);
    }
    
    /**
     * Save player ratings
     */
    public static function save_ratings() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $player_id = intval($_POST['player_id'] ?? 0);
        $team_id = intval($_POST['team_id'] ?? 0);
        $rating_date = sanitize_text_field($_POST['rating_date'] ?? '');
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        $ratings = $_POST['ratings'] ?? array();
        
        // Validate inputs
        if (!$season) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        if (!WPTM_Permissions::is_valid_season_range($season)) {
            WPTM_Utils::send_json_error('Invalid season');
        }
        
        if (!$team_id || !$player_id) {
            WPTM_Utils::send_json_error('Team ID and Player ID are required');
        }
        
        $rating_date_formatted = date('Y-m-d', strtotime($rating_date));
        if (!$rating_date || $rating_date_formatted === '1970-01-01') {
            WPTM_Utils::send_json_error('Invalid rating date');
        }
        
        // Validate ratings
        if (!WPTM_Utils::validate_ratings($ratings)) {
            WPTM_Utils::send_json_error('Invalid ratings data');
        }
        
        // Sanitize ratings
        $sanitized_ratings = array();
        foreach ($ratings as $skill => $value) {
            $sanitized_ratings[$skill] = intval($value);
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_rate_player($user_id, $player_id, $team_id)) {
            WPTM_Utils::send_json_error('You do not have permission to rate this player');
        }
        
        $start_year = (int) explode('-', $season)[0];
        
        // Check if player is in team for this season
        $team_player = $wpdb->get_row($wpdb->prepare(
            "SELECT tp.*, t.user_id as team_owner, t.wc_team_id 
             FROM {$wpdb->prefix}wptm_team_players tp 
             JOIN {$wpdb->prefix}wptm_teams t ON tp.team_id = t.id 
             WHERE tp.player_id = %d AND tp.season = %s AND tp.team_id = %d",
            $player_id, $season, $team_id
        ));
        
        if (!$team_player) {
            WPTM_Utils::send_json_error('Player is not in the specified team for this season');
        }
        
        // Check if ratings already exist for this date
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wptm_ratings 
             WHERE player_id = %d AND team_id = %d AND rating_date = %s",
            $player_id, $team_id, $rating_date_formatted
        ));
        
        if ($exists) {
            WPTM_Utils::send_json_error('Ratings already exist for this player in this team on this date');
        }
        
        // Insert ratings
        $result = $wpdb->insert(
            "{$wpdb->prefix}wptm_ratings",
            array(
                'player_id' => $player_id,
                'team_id' => $team_id,
                'rating_date' => $rating_date_formatted,
                'season' => $season,
                'technique' => $sanitized_ratings['technique'],
                'speed' => $sanitized_ratings['speed'],
                'endurance' => $sanitized_ratings['endurance'],
                'intelligence' => $sanitized_ratings['intelligence'],
                'passing' => $sanitized_ratings['passing'],
                'defense' => $sanitized_ratings['defense'],
                'attack' => $sanitized_ratings['attack'],
                'teamwork' => $sanitized_ratings['teamwork'],
                'agility' => $sanitized_ratings['agility'],
                'strength' => $sanitized_ratings['strength']
            ),
            array('%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d')
        );
        
        if ($result === false) {
            WPTM_Utils::log_error('Error saving ratings', array(
                'player_id' => $player_id,
                'team_id' => $team_id,
                'season' => $season,
                'db_error' => $wpdb->last_error
            ));
            WPTM_Utils::send_json_error('Error saving ratings: ' . $wpdb->last_error);
        }
        
        // Generate coach advice if needed
        self::schedule_coach_advice($player_id, $team_id, $start_year, $season);
        
        // Clear cache
        WPTM_Cache::clear_player_cache($player_id);
        
        WPTM_Utils::send_json_success();
    }
    
    /**
     * Get spider chart data
     */
    public static function get_spider_chart() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $player_id = intval($_POST['player_id'] ?? 0);
        $team_id = intval($_POST['team_id'] ?? 0);
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$season) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        if (!$team_id || !$player_id) {
            WPTM_Utils::send_json_error('Team ID and Player ID are required');
        }
        
        $start_year = (int) explode('-', $season)[0];
        
        $cache_key = WPTM_Cache::get_spider_chart_cache_key($player_id, $team_id, $season);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            WPTM_Utils::send_json_success($cached_data);
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_view_player($user_id, $player_id, $team_id)) {
            WPTM_Utils::send_json_error('You do not have permission to view this player\'s chart');
        }
        
        // Get player info
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, first_name, last_name, birth_date FROM {$wpdb->prefix}wptm_unique_players WHERE id = %d",
            $player_id
        ));
        
        if (!$player) {
            WPTM_Utils::send_json_error('Player not found');
        }
        
        // Get team player info
        $team_player = $wpdb->get_row($wpdb->prepare(
            "SELECT tp.position, tp.player_number 
             FROM {$wpdb->prefix}wptm_team_players tp 
             JOIN {$wpdb->prefix}wptm_teams t ON tp.team_id = t.id 
             WHERE tp.player_id = %d AND tp.season = %s AND tp.team_id = %d",
            $player_id, $season, $team_id
        ));
        
        if (!$team_player) {
            WPTM_Utils::send_json_error('Player is not in the specified team for this season');
        }
        
        // Get average ratings
        $ratings = $wpdb->get_results($wpdb->prepare(
            "SELECT AVG(technique) as technique, AVG(speed) as speed, AVG(endurance) as endurance,
                    AVG(intelligence) as intelligence, AVG(passing) as passing, AVG(defense) as defense,
                    AVG(attack) as attack, AVG(teamwork) as teamwork, AVG(agility) as agility, AVG(strength) as strength
             FROM {$wpdb->prefix}wptm_ratings
             WHERE player_id = %d AND team_id = %d AND season = %s",
            $player_id, $team_id, $season
        ));
        
        if (empty($ratings) || !$ratings[0]->technique) {
            $ratings = array((object) array(
                'technique' => 0,
                'speed' => 0,
                'endurance' => 0,
                'intelligence' => 0,
                'passing' => 0,
                'defense' => 0,
                'attack' => 0,
                'teamwork' => 0,
                'agility' => 0,
                'strength' => 0
            ));
        }
        
        // Get coach advice
        $coach_advice = $wpdb->get_var($wpdb->prepare(
            "SELECT advice_text FROM {$wpdb->prefix}wptm_coach_advice 
             WHERE player_id = %d AND team_id = %d AND year = %d 
             ORDER BY created_at DESC LIMIT 1",
            $player_id, $team_id, $start_year
        ));
        
        $advice_message = 'No advice available.';
        if ($ratings[0]->technique > 0) {
            $advice_message = $coach_advice ?: 'Coach advice is being generated...';
        }
        
        $response = array(
            'ratings' => $ratings[0],
            'player' => array(
                'first_name' => $player->first_name,
                'last_name' => $player->last_name,
                'birth_date' => $player->birth_date ?? null,
                'position' => $team_player->position ?? null,
                'player_number' => $team_player->player_number ?? null
            ),
            'coach_advice' => $advice_message
        );
        
        set_transient($cache_key, $response, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($response);
    }
    
    /**
     * Get player ratings
     */
    public static function get_player_ratings() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $player_id = intval($_POST['player_id'] ?? 0);
        $team_id = intval($_POST['team_id'] ?? 0);
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$season) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        if (!$team_id || !$player_id) {
            WPTM_Utils::send_json_error('Invalid team or player ID');
        }
        
        $cache_key = WPTM_Cache::get_player_ratings_cache_key($player_id, $team_id, $season);
        $ratings = get_transient($cache_key);
        
        if ($ratings !== false) {
            WPTM_Utils::send_json_success($ratings);
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_view_player($user_id, $player_id, $team_id)) {
            WPTM_Utils::send_json_error('You do not have permission to view this player\'s ratings');
        }
        
        $ratings = $wpdb->get_results($wpdb->prepare(
            "SELECT rating_date, technique, speed, endurance, intelligence, passing, defense, attack, teamwork, agility, strength
             FROM {$wpdb->prefix}wptm_ratings
             WHERE player_id = %d AND team_id = %d AND season = %s
             ORDER BY rating_date ASC",
            $player_id, $team_id, $season
        ));
        
        set_transient($cache_key, $ratings, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($ratings);
    }
    
    /**
     * Schedule coach advice generation
     */
    private static function schedule_coach_advice($player_id, $team_id, $start_year, $season) {
        global $wpdb;
        
        // Get average ratings
        $average_ratings = $wpdb->get_results($wpdb->prepare(
            "SELECT AVG(technique) as technique, AVG(speed) as speed, AVG(endurance) as endurance,
                    AVG(intelligence) as intelligence, AVG(passing) as passing, AVG(defense) as defense,
                    AVG(attack) as attack, AVG(teamwork) as teamwork, AVG(agility) as agility, AVG(strength) as strength
             FROM {$wpdb->prefix}wptm_ratings
             WHERE player_id = %d AND team_id = %d AND season = %s",
            $player_id, $team_id, $season
        ));
        
        if (empty($average_ratings) || !$average_ratings[0]->technique) {
            return; // No ratings to generate advice for
        }
        
        $ratings_array = (array) $average_ratings[0];
        $ratings_hash = md5(json_encode($ratings_array));
        
        // Check if advice already exists with same hash
        $existing_advice = $wpdb->get_row($wpdb->prepare(
            "SELECT ratings_hash FROM {$wpdb->prefix}wptm_coach_advice 
             WHERE player_id = %d AND team_id = %d AND year = %d 
             ORDER BY created_at DESC LIMIT 1",
            $player_id, $team_id, $start_year
        ));
        
        if (!$existing_advice || $existing_advice->ratings_hash !== $ratings_hash) {
            // Get player info
            $player_info = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}wptm_unique_players WHERE id = %d",
                $player_id
            ));
            
            $team_info = $wpdb->get_row($wpdb->prepare(
                "SELECT position FROM {$wpdb->prefix}wptm_team_players 
                 WHERE player_id = %d AND team_id = %d AND season = %s",
                $player_id, $team_id, $season
            ));
            
            $player_data = array(
                'first_name' => $player_info->first_name,
                'last_name' => $player_info->last_name,
                'position' => $team_info->position ?? null
            );
            
            // Schedule background task
            wp_schedule_single_event(
                time() + 10, 
                'wptm_generate_coach_advice', 
                array($player_id, $team_id, $start_year, $ratings_array, $player_data, $ratings_hash)
            );
            
            WPTM_Utils::log_error("Scheduled coach advice generation for player {$player_id}, team {$team_id}, season {$season}");
        }
    }
    
    /**
     * Generate coach advice in background
     */
    public static function generate_coach_advice_background($player_id, $team_id, $year, $ratings, $player, $ratings_hash) {
        global $wpdb;
        
        // Double-check if advice is still needed
        $existing_advice = $wpdb->get_row($wpdb->prepare(
            "SELECT ratings_hash FROM {$wpdb->prefix}wptm_coach_advice 
             WHERE player_id = %d AND team_id = %d AND year = %d 
             ORDER BY created_at DESC LIMIT 1",
            $player_id, $team_id, $year
        ));
        
        if ($existing_advice && $existing_advice->ratings_hash === $ratings_hash) {
            WPTM_Utils::log_error("Skipped coach advice generation for player {$player_id}, team {$team_id}, year {$year} - ratings unchanged in background task");
            return;
        }
        
        // Generate advice
        $coach_advice = WPTM_Utils::generate_coach_advice($ratings, $player);
        
        // Save advice
        $result = $wpdb->insert(
            "{$wpdb->prefix}wptm_coach_advice",
            array(
                'player_id' => $player_id,
                'team_id' => $team_id,
                'year' => $year,
                'advice_text' => $coach_advice,
                'ratings_hash' => $ratings_hash,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s')
        );
        
        if ($wpdb->last_error) {
            WPTM_Utils::log_error('Failed to save coach advice in background task: ' . $wpdb->last_error);
        } else {
            WPTM_Utils::log_error("Successfully generated and saved coach advice for player {$player_id}, team {$team_id}, year {$year}");
        }
        
        // Clear cache
        $season = "{$year}-" . ($year + 1);
        delete_transient(WPTM_Cache::get_spider_chart_cache_key($player_id, $team_id, $season));
    }
}