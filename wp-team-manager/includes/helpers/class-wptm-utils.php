<?php
/**
 * Utility Helper Class
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Utility functions for WP Team Manager
 */
class WPTM_Utils {
    
    /**
     * Sanitize season input
     *
     * @param string $season Season string
     * @return string|false
     */
    public static function sanitize_season($season) {
        if (!$season || !is_string($season)) {
            return false;
        }
        
        $season = sanitize_text_field($season);
        
        if (!preg_match('/^\d{4}-\d{4}$/', $season)) {
            return false;
        }
        
        return $season;
    }
    
    /**
     * Get current season
     *
     * @return string
     */
    public static function get_current_season() {
        $current_year = date('Y');
        return $current_year . '-' . ($current_year + 1);
    }
    
    /**
     * Generate available seasons
     *
     * @param int $years_back Years to go back
     * @param int $years_forward Years to go forward
     * @return array
     */
    public static function get_available_seasons($years_back = 10, $years_forward = 5) {
        $current_year = date('Y');
        $seasons = array();
        
        for ($year = ($current_year - $years_back); $year <= ($current_year + $years_forward); $year++) {
            $seasons[] = $year . '-' . ($year + 1);
        }
        
        return $seasons;
    }
    
    /**
     * Send JSON response and exit
     *
     * @param mixed $data Response data
     * @param bool $success Success status
     * @param string $message Message
     */
    public static function send_json_response($data = null, $success = true, $message = '') {
        $response = array(
            'success' => $success
        );
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        if ($message) {
            if ($success) {
                $response['data']['message'] = $message;
            } else {
                $response['data'] = array('message' => $message);
            }
        }
        
        wp_send_json($response);
    }
    
    /**
     * Send JSON error response and exit
     *
     * @param string $message Error message
     * @param mixed $data Additional data
     */
    public static function send_json_error($message, $data = null) {
        $response_data = array('message' => $message);
        
        if ($data !== null) {
            $response_data = array_merge($response_data, (array) $data);
        }
        
        self::send_json_response($response_data, false);
    }
    
    /**
     * Send JSON success response and exit
     *
     * @param mixed $data Response data
     * @param string $message Success message
     */
    public static function send_json_success($data = null, $message = '') {
        self::send_json_response($data, true, $message);
    }
    
    /**
     * Verify AJAX nonce
     *
     * @param string $nonce_name Nonce name
     * @param bool $die Whether to die on failure
     * @return bool
     */
    public static function verify_ajax_nonce($nonce_name = 'nonce', $die = true) {
        if (!check_ajax_referer('wptm_nonce', $nonce_name, false)) {
            if ($die) {
                self::send_json_error('Invalid nonce');
            }
            return false;
        }
        return true;
    }
    
    /**
     * Log error with context
     *
     * @param string $message Error message
     * @param array $context Additional context
     */
    public static function log_error($message, $context = array()) {
        $log_message = "WP Team Manager: {$message}";
        
        if (!empty($context)) {
            $log_message .= ' Context: ' . print_r($context, true);
        }
        
        error_log($log_message);
    }
    
    /**
     * Generate coach advice using OpenAI
     *
     * @param array $ratings Player ratings
     * @param array $player Player data
     * @return string
     */
    public static function generate_coach_advice($ratings, $player) {
        if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
            self::log_error('OpenAI API key is not defined in wp-config.php');
            return 'Coach advice unavailable: API key not configured.';
        }
        
        $formatted_ratings = array();
        foreach ($ratings as $key => $value) {
            $formatted_ratings[$key] = number_format((float)$value, 1);
        }
        
        $ratings_text = "Technique: {$formatted_ratings['technique']}, Speed: {$formatted_ratings['speed']}, Endurance: {$formatted_ratings['endurance']}, Intelligence: {$formatted_ratings['intelligence']}, Passing: {$formatted_ratings['passing']}, Defense: {$formatted_ratings['defense']}, Attack: {$formatted_ratings['attack']}, Teamwork: {$formatted_ratings['teamwork']}, Agility: {$formatted_ratings['agility']}, Strength: {$formatted_ratings['strength']}";
        $position = $player['position'] ?? 'unknown position';
        $prompt = "You are a soccer coach providing advice to another coach. Provide a short, actionable piece of advice (2-3 sentences) for the coach of a player named {$player['first_name']} {$player['last_name']}, who plays as a {$position}. The player's average ratings (out of 10) for the year are: {$ratings_text}. Focus on how the coach can help improve the player's weakest area while considering their role on the team.";
        
        $body = array(
            'model' => 'gpt-4o',
            'messages' => array(
                array('role' => 'system', 'content' => 'You are a knowledgeable soccer coach providing advice to another coach about their player based on performance metrics.'),
                array('role' => 'user', 'content' => $prompt)
            ),
            'max_tokens' => 150,
            'temperature' => 0.7
        );
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . OPENAI_API_KEY,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            self::log_error('OpenAI API request failed: ' . $response->get_error_message());
            return 'Coach advice unavailable: API request failed.';
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (isset($data['error'])) {
            self::log_error('OpenAI API error: ' . $data['error']['message']);
            return 'Coach advice unavailable: API error.';
        }
        
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
        
        self::log_error('OpenAI API response did not contain expected content.');
        return 'Coach advice unavailable: Invalid API response.';
    }
    
    /**
     * Format date for display
     *
     * @param string $date Date string
     * @param string $format Date format
     * @return string
     */
    public static function format_date($date, $format = 'd-m-Y') {
        if (!$date) {
            return 'Not set';
        }
        
        $datetime = date_create($date);
        if (!$datetime) {
            return 'Invalid date';
        }
        
        return date_format($datetime, $format);
    }
    
    /**
     * Validate email
     *
     * @param string $email Email address
     * @return bool
     */
    public static function is_valid_email($email) {
        return !empty($email) && is_email($email);
    }
    
    /**
     * Sanitize player data
     *
     * @param array $data Player data
     * @return array
     */
    public static function sanitize_player_data($data) {
        return array(
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'birth_date' => sanitize_text_field($data['birth_date'] ?? ''),
            'position' => sanitize_text_field($data['position'] ?? ''),
            'player_number' => sanitize_text_field($data['player_number'] ?? ''),
        );
    }
    
    /**
     * Validate ratings data
     *
     * @param array $ratings Ratings array
     * @return bool
     */
    public static function validate_ratings($ratings) {
        $required_skills = array('technique', 'speed', 'endurance', 'intelligence', 'passing', 'defense', 'attack', 'teamwork', 'agility', 'strength');
        
        foreach ($required_skills as $skill) {
            if (!isset($ratings[$skill])) {
                return false;
            }
            
            $value = intval($ratings[$skill]);
            if ($value < 0 || $value > 10) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get user display name
     *
     * @param int $user_id User ID
     * @return string
     */
    public static function get_user_display_name($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return 'Unknown User';
        }
        
        return $user->display_name ?: $user->user_login;
    }
}