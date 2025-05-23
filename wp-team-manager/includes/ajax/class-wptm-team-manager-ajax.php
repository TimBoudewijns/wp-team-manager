<?php
/**
 * Team Manager AJAX Handler
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle team manager related AJAX requests
 */
class WPTM_Team_Manager_Ajax {
    
    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_wptm_get_managed_teams', array(__CLASS__, 'get_managed_teams'));
        add_action('wp_ajax_wptm_add_managed_team', array(__CLASS__, 'add_managed_team'));
        add_action('wp_ajax_wptm_get_team_trainers', array(__CLASS__, 'get_team_trainers'));
        add_action('wp_ajax_wptm_get_available_trainers', array(__CLASS__, 'get_available_trainers'));
        add_action('wp_ajax_wptm_assign_trainer_to_team', array(__CLASS__, 'assign_trainer_to_team'));
        add_action('wp_ajax_wptm_remove_trainer_from_team', array(__CLASS__, 'remove_trainer_from_team'));
        add_action('wp_ajax_wptm_verify_trainer_count', array(__CLASS__, 'verify_trainer_count'));
    }
    
    /**
     * Get managed teams
     */
    public static function get_managed_teams() {
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
        
        // Check if user is manager or owner
        if (!WPTM_Permissions::can_manage_teams($user_id)) {
            WPTM_Utils::send_json_error('You do not have access to manage teams');
        }
        
        $cache_key = WPTM_Cache::get_managed_teams_cache_key($user_id, $season);
        $teams = get_transient($cache_key);
        
        if ($teams !== false) {
            WPTM_Utils::send_json_success($teams);
        }
        
        // Get teams managed by this user
        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT id, team_name, season, coach, wc_team_id 
             FROM {$wpdb->prefix}wptm_teams 
             WHERE user_id = %d AND season = %s",
            $user_id, $season
        ));
        
        if (empty($teams)) {
            WPTM_Utils::send_json_success(array());
        }
        
        // Add trainers to each team
        foreach ($teams as $team) {
            $trainers = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID, u.display_name, u.user_email, um.meta_value as role
                 FROM {$wpdb->prefix}users u
                 JOIN {$wpdb->prefix}usermeta um ON u.ID = um.user_id
                 WHERE um.meta_key = %s
                 AND um.meta_value IN ('member', 'manager')
                 ORDER BY um.meta_value DESC, u.display_name ASC",
                "_wc_memberships_for_teams_team_{$team->wc_team_id}_role"
            ));
            
            $team->trainers = array();
            foreach ($trainers as $trainer) {
                $team->trainers[] = array(
                    'id' => $trainer->ID,
                    'name' => $trainer->display_name,
                    'email' => $trainer->user_email,
                    'role' => $trainer->role
                );
            }
            $team->trainer_count = count($team->trainers);
        }
        
        set_transient($cache_key, $teams, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($teams);
    }
    
    /**
     * Add managed team
     */
    public static function add_managed_team() {
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
        
        // Find wc_team_id where user is manager or owner
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
                    if (in_array($role, ['manager', 'owner'])) {
                        $wc_team_id = $potential_team_id;
                        break;
                    }
                }
            }
        }
        
        if (!$wc_team_id) {
            WPTM_Utils::send_json_error('No valid club found. You need to be a manager or owner of at least one club.');
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
            WPTM_Utils::log_error('Error creating managed team', array(
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
     * Get available trainers
     */
    public static function get_available_trainers() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $team_id = intval($_POST['team_id'] ?? 0);
        
        // Check if user is manager or owner
        if (!WPTM_Permissions::can_manage_teams($user_id)) {
            WPTM_Utils::send_json_error('You do not have permission to view trainers');
        }
        
        // Get WC team IDs that user can manage
        $wc_team_ids = array();
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
                    $wc_team_id = $matches[1];
                    $role = $meta->meta_value;
                    if (in_array($role, ['manager', 'owner'])) {
                        $wc_team_ids[] = $wc_team_id;
                    }
                }
            }
        }
        
        if (empty($wc_team_ids)) {
            WPTM_Utils::send_json_error('You do not have permission to view trainers');
        }
        
        // If specific team is requested, filter to that team's WC team ID
        if ($team_id) {
            $team = $wpdb->get_row($wpdb->prepare(
                "SELECT wc_team_id FROM {$wpdb->prefix}wptm_teams WHERE id = %d",
                $team_id
            ));
            
            if (!$team || !$team->wc_team_id) {
                WPTM_Utils::send_json_error('Invalid team');
            }
            
            // Check access
            if (!in_array($team->wc_team_id, $wc_team_ids)) {
                WPTM_Utils::send_json_error('Insufficient permissions for this team');
            }
            
            $wc_team_ids = array($team->wc_team_id);
        }
        
        $cache_key = WPTM_Cache::get_available_trainers_cache_key($wc_team_ids);
        $trainers = get_transient($cache_key);
        
        if ($trainers !== false) {
            WPTM_Utils::send_json_success($trainers);
        }
        
        $trainers = array();
        
        foreach ($wc_team_ids as $wc_team_id) {
            if (!function_exists('wc_memberships_for_teams_get_team')) {
                WPTM_Utils::send_json_error('Teams for WooCommerce Memberships plugin not properly installed');
            }
            
            $team = wc_memberships_for_teams_get_team($wc_team_id);
            if (!$team) {
                continue;
            }
            
            // Get all team members
            $members = $team->get_members();
            
            if (is_array($members) && !empty($members)) {
                foreach ($members as $member) {
                    if (!is_object($member) || !method_exists($member, 'get_id')) {
                        continue;
                    }
                    
                    $member_id = $member->get_id();
                    if (!$member_id) {
                        continue;
                    }
                    
                    $user = get_user_by('id', $member_id);
                    if (!$user) {
                        continue;
                    }
                    
                    // Get real role including custom roles
                    $role = WPTM_Role_Mapper::get_user_role($member_id, $wc_team_id);
                    $display_role = WPTM_Role_Mapper::get_display_name($role);
                    
                    // Prevent duplicates
                    $exists = false;
                    foreach ($trainers as $existing) {
                        if ($existing['id'] == $member_id) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $trainers[] = array(
                            'id' => $member_id,
                            'name' => $user->display_name ?: $user->user_login,
                            'email' => $user->user_email,
                            'role' => $role,
                            'display_role' => $display_role,
                            'wc_team_id' => $wc_team_id,
                            'status' => 'active'
                        );
                    }
                }
            }
            
            // Get invitations
            $invitations = $team->get_invitations();
            
            if (is_array($invitations) && !empty($invitations)) {
                foreach ($invitations as $invitation) {
                    if (!is_object($invitation) || !method_exists($invitation, 'get_email')) {
                        continue;
                    }
                    
                    $email = $invitation->get_email();
                    if (!$email) {
                        continue;
                    }
                    
                    // Check if invited person is already a user
                    $invited_user = get_user_by('email', $email);
                    $invited_id = $invited_user ? $invited_user->ID : null;
                    
                    // Get invitation role
                    $invitation_id = $invitation->get_id();
                    $role = get_post_meta($invitation_id, '_role', true);
                    if (!$role) {
                        $role = get_post_meta($invitation_id, '_invitation_role', true);
                    }
                    if (!$role) {
                        $role = 'member'; // Default
                    }
                    
                    // Check for Sports Coordinator invitations
                    $is_coordinator = get_post_meta($invitation_id, '_wptm_invitation_is_coordinator', true) === 'yes';
                    if ($is_coordinator) {
                        $role = 'sports_coordinator';
                    }
                    
                    // Get display role
                    $display_role = WPTM_Role_Mapper::get_display_name($role);
                    
                    // Prevent duplicates
                    $exists = false;
                    foreach ($trainers as $existing) {
                        if (($invited_id && $existing['id'] == $invited_id) || 
                            (!$invited_id && $existing['email'] == $email)) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        if ($invited_user) {
                            // If person is a user, use their ID and name
                            $trainers[] = array(
                                'id' => $invited_user->ID,
                                'name' => $invited_user->display_name ?: $invited_user->user_login,
                                'email' => $email,
                                'role' => $role,
                                'display_role' => $display_role,
                                'wc_team_id' => $wc_team_id,
                                'status' => 'invited'
                            );
                        } else {
                            // Create special entry for invitation
                            // Use negative ID to indicate this is an invitation
                            $trainers[] = array(
                                'id' => -1 * $invitation_id,
                                'name' => "Invited: $email",
                                'email' => $email,
                                'role' => $role,
                                'display_role' => $display_role,
                                'wc_team_id' => $wc_team_id,
                                'status' => 'invited'
                            );
                        }
                    }
                }
            }
        }
        
        set_transient($cache_key, $trainers, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($trainers);
    }
    
    /**
     * Get team trainers
     */
    public static function get_team_trainers() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $team_id = intval($_POST['team_id'] ?? 0);
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$team_id || !$season) {
            WPTM_Utils::send_json_error('Missing required parameters');
        }
        
        if (!WPTM_Permissions::is_valid_season_range($season)) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_manage_teams($user_id, $team_id)) {
            WPTM_Utils::send_json_error('You do not have permission to view trainers for this team');
        }
        
        $cache_key = WPTM_Cache::get_team_trainers_cache_key($team_id, $season);
        $trainers = get_transient($cache_key);
        
        if ($trainers !== false) {
            WPTM_Utils::send_json_success($trainers);
        }
        
        // Check if club_role column exists
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '{$wpdb->prefix}wptm_team_trainers' 
            AND COLUMN_NAME = 'club_role'
        ");
        
        if ($column_exists == 0) {
            // Add column if it doesn't exist
            $wpdb->query("ALTER TABLE {$wpdb->prefix}wptm_team_trainers ADD COLUMN club_role VARCHAR(50) DEFAULT 'trainer' AFTER role");
        }
        
        // Get trainers from wptm_team_trainers table
        $trainer_results = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.id, tt.trainer_user_id, tt.role, tt.club_role, tt.additional_info, 
                    u.display_name, u.user_email
             FROM {$wpdb->prefix}wptm_team_trainers tt
             JOIN {$wpdb->users} u ON tt.trainer_user_id = u.ID
             WHERE tt.team_id = %d AND tt.season = %s
             ORDER BY tt.role, u.display_name",
            $team_id, $season
        ));
        
        if ($wpdb->last_error) {
            WPTM_Utils::send_json_error('Database error when retrieving trainers: ' . $wpdb->last_error);
        }
        
        $trainers = array();
        
        // Process results
        foreach ($trainer_results as $trainer) {
            $trainer_id = $trainer->trainer_user_id;
            
            // Team role (position/function in team)
            $team_role = $trainer->role;
            $team_display_role = $team_role;
            
            // Club role
            $club_role = $trainer->club_role;
            
            // If club_role is empty, determine based on usermeta (backward compatibility)
            if (empty($club_role)) {
                $team_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT wc_team_id FROM {$wpdb->prefix}wptm_teams WHERE id = %d",
                    $team_id
                ));
                
                if ($team_record && $team_record->wc_team_id) {
                    $meta_key = "_wc_memberships_for_teams_team_{$team_record->wc_team_id}_role";
                    $meta_value = get_user_meta($trainer_id, $meta_key, true);
                    $is_coordinator = get_user_meta($trainer_id, "_wptm_team_{$team_record->wc_team_id}_is_coordinator", true) === 'yes';
                    
                    if ($is_coordinator) {
                        $club_role = 'sports_coordinator';
                    } elseif ($meta_value === 'owner') {
                        $club_role = 'owner';
                    } elseif ($meta_value === 'manager') {
                        $club_role = 'manager';
                    } elseif ($meta_value === 'member') {
                        $club_role = 'trainer';
                    } else {
                        $club_role = 'trainer'; // Default
                    }
                    
                    // Update database for next time
                    $wpdb->update(
                        "{$wpdb->prefix}wptm_team_trainers",
                        array('club_role' => $club_role),
                        array('id' => $trainer->id),
                        array('%s'),
                        array('%d')
                    );
                }
            }
            
            // Determine if this is a coordinator
            $is_coordinator = ($club_role === 'sports_coordinator');
            
            // Get display role for club role
            $club_display_role = WPTM_Role_Mapper::get_display_name($club_role);
            
            $trainers[] = array(
                'id' => $trainer->id,
                'trainer_user_id' => $trainer_id,
                'name' => $trainer->display_name ?: 'Unnamed Trainer',
                'email' => $trainer->user_email ?: 'No email',
                'role' => $team_role,
                'display_role' => $team_display_role,
                'additional_info' => $trainer->additional_info,
                'club_role' => $club_role,
                'club_display_role' => $club_display_role,
                'is_coordinator' => $is_coordinator
            );
        }
        
        set_transient($cache_key, $trainers, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($trainers);
    }
    
    /**
     * Assign trainer to team
     */
    public static function assign_trainer_to_team() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $team_id = intval($_POST['team_id'] ?? 0);
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        $position = sanitize_text_field($_POST['position'] ?? '');
        $additional_info = sanitize_text_field($_POST['additional_info'] ?? '');
        $club_role = sanitize_text_field($_POST['club_role'] ?? 'trainer');
        
        if (!$team_id || !$trainer_id || !$season) {
            WPTM_Utils::send_json_error('Missing required parameters');
        }
        
        if (!WPTM_Permissions::is_valid_season_range($season)) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        // Validate club_role
        if (!in_array($club_role, array('trainer', 'sports_coordinator', 'manager', 'owner'))) {
            $club_role = 'trainer'; // Default for invalid values
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_manage_teams($user_id, $team_id)) {
            WPTM_Utils::send_json_error('You do not have permission to assign trainers to this team');
        }
        
        // Get team info
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, wc_team_id FROM {$wpdb->prefix}wptm_teams WHERE id = %d",
            $team_id
        ));
        
        if (!$team) {
            WPTM_Utils::send_json_error('Team not found');
        }
        
        // Check if trainer exists
        $trainer_user = get_userdata($trainer_id);
        if (!$trainer_user) {
            WPTM_Utils::send_json_error('Trainer not found');
        }
        
        // Check for negative ID (invitation not yet accepted)
        if ($trainer_id < 0) {
            WPTM_Utils::send_json_error('This trainer has been invited but has not yet accepted the invitation. Please wait for them to accept before assigning them to a team.');
        }
        
        // Update WooCommerce metadata if this is a sports coordinator
        if ($club_role === 'sports_coordinator' && $team->wc_team_id) {
            update_user_meta($trainer_id, "_wptm_team_{$team->wc_team_id}_is_coordinator", 'yes');
        } elseif ($team->wc_team_id) {
            // Remove coordinator marking if user is no longer a coordinator
            delete_user_meta($trainer_id, "_wptm_team_{$team->wc_team_id}_is_coordinator");
        }
        
        // Check if trainer already assigned
        $existing_trainer = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wptm_team_trainers 
             WHERE trainer_user_id = %d AND team_id = %d AND season = %s",
            $trainer_id, $team_id, $season
        ));
        
        if ($existing_trainer) {
            // Update existing record
            $result = $wpdb->update(
                "{$wpdb->prefix}wptm_team_trainers",
                array(
                    'role' => $position,
                    'club_role' => $club_role,
                    'additional_info' => $additional_info
                ),
                array('id' => $existing_trainer),
                array('%s', '%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                WPTM_Utils::send_json_error('Error updating trainer information: ' . $wpdb->last_error);
            }
            
            // Clear cache
            WPTM_Cache::clear_team_cache($team_id, $season);
            
            WPTM_Utils::send_json_success(array('updated' => true), 'Trainer information updated successfully');
        } else {
            // Insert new record
            $result = $wpdb->insert(
                "{$wpdb->prefix}wptm_team_trainers",
                array(
                    'trainer_user_id' => $trainer_id,
                    'team_id' => $team_id,
                    'wc_team_id' => $team->wc_team_id,
                    'season' => $season,
                    'role' => $position,
                    'club_role' => $club_role,
                    'additional_info' => $additional_info,
                    'assigned_by' => $user_id,
                    'assigned_at' => current_time('mysql')
                ),
                array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s')
            );
            
            if ($result === false) {
                WPTM_Utils::log_error('Error assigning trainer to team', array(
                    'team_id' => $team_id,
                    'trainer_id' => $trainer_id,
                    'season' => $season,
                    'db_error' => $wpdb->last_error
                ));
                WPTM_Utils::send_json_error('Error assigning trainer to team: ' . $wpdb->last_error);
            }
            
            // Clear cache
            WPTM_Cache::clear_team_cache($team_id, $season);
            
            WPTM_Utils::send_json_success('Trainer successfully assigned to team');
        }
    }
    
    /**
     * Remove trainer from team
     */
    public static function remove_trainer_from_team() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        $team_id = intval($_POST['team_id'] ?? 0);
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$team_id || !$trainer_id || !$season) {
            WPTM_Utils::send_json_error('Missing required parameters');
        }
        
        if (!WPTM_Permissions::is_valid_season_range($season)) {
            WPTM_Utils::send_json_error('Invalid season format');
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_manage_teams($user_id, $team_id)) {
            WPTM_Utils::send_json_error('You do not have permission to remove trainers from this team');
        }
        
        // Remove trainer from team_trainers table
        $result = $wpdb->delete(
            "{$wpdb->prefix}wptm_team_trainers",
            array(
                'trainer_user_id' => $trainer_id,
                'team_id' => $team_id,
                'season' => $season
            ),
            array('%d', '%d', '%s')
        );
        
        if ($result === false) {
            WPTM_Utils::log_error('Error removing trainer from team_trainers', array(
                'team_id' => $team_id,
                'trainer_id' => $trainer_id,
                'season' => $season,
                'db_error' => $wpdb->last_error
            ));
            WPTM_Utils::send_json_error('Error removing trainer from team: ' . $wpdb->last_error);
        }
        
        // Clear cache
        WPTM_Cache::clear_team_cache($team_id, $season);
        
        WPTM_Utils::send_json_success('Trainer successfully removed from team');
    }
    
    /**
     * Verify trainer count
     */
    public static function verify_trainer_count() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $team_id = intval($_POST['team_id'] ?? 0);
        $season = WPTM_Utils::sanitize_season($_POST['season'] ?? '');
        
        if (!$team_id || !$season) {
            WPTM_Utils::send_json_error('Missing required parameters');
        }
        
        // Direct count from database
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wptm_team_trainers 
             WHERE team_id = %d AND season = %s",
            $team_id, $season
        ));
        
        WPTM_Utils::send_json_success(array('count' => (int)$count));
    }
}