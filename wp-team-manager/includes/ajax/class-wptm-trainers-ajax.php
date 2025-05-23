<?php
/**
 * Trainers AJAX Handler
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle trainers management related AJAX requests
 */
class WPTM_Trainers_Ajax {
    
    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_wptm_get_clubs', array(__CLASS__, 'get_clubs'));
        add_action('wp_ajax_wptm_get_club_details', array(__CLASS__, 'get_club_details'));
        add_action('wp_ajax_wptm_update_club_name', array(__CLASS__, 'update_club_name'));
        add_action('wp_ajax_wptm_invite_trainer', array(__CLASS__, 'invite_trainer'));
        add_action('wp_ajax_wptm_remove_trainer', array(__CLASS__, 'remove_trainer'));
        add_action('wp_ajax_wptm_change_trainer_role', array(__CLASS__, 'change_trainer_role'));
        add_action('wp_ajax_wptm_cancel_invitation', array(__CLASS__, 'cancel_invitation'));
    }
    
    /**
     * Get user's clubs
     */
    public static function get_clubs() {
        WPTM_Utils::verify_ajax_nonce();
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        $cache_key = WPTM_Cache::get_clubs_cache_key($user_id);
        $clubs = get_transient($cache_key);
        
        if ($clubs !== false) {
            WPTM_Utils::send_json_success($clubs);
        }
        
        $meta_results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
             FROM {$wpdb->prefix}usermeta 
             WHERE user_id = %d 
             AND meta_key LIKE '_wc_memberships_for_teams_team_%_role'",
            $user_id
        ));
        
        $clubs = array();
        if (!empty($meta_results)) {
            foreach ($meta_results as $meta) {
                if (preg_match('/_wc_memberships_for_teams_team_(\d+)_role/', $meta->meta_key, $matches)) {
                    $wc_team_id = $matches[1];
                    $role = $meta->meta_value;
                    $team = wc_memberships_for_teams_get_team($wc_team_id);
                    if ($team) {
                        $clubs[] = array(
                            'id' => $wc_team_id,
                            'name' => $team->get_name(),
                            'role' => $role
                        );
                    }
                }
            }
        }
        
        set_transient($cache_key, $clubs, WPTM_Cache::CACHE_TIME_MEDIUM);
        WPTM_Utils::send_json_success($clubs);
    }
    
    /**
     * Get club details
     */
    public static function get_club_details() {
        WPTM_Utils::verify_ajax_nonce();
        
        $user_id = get_current_user_id();
        $club_id = intval($_POST['club_id'] ?? 0);
        
        if (!$club_id) {
            WPTM_Utils::send_json_error('Invalid club ID');
        }
        
        // Check if required functions exist
        if (!function_exists('wc_memberships_for_teams') || !function_exists('wc_memberships_for_teams_get_team')) {
            WPTM_Utils::send_json_error('Teams for WooCommerce Memberships plugin not properly installed');
        }
        
        $team = wc_memberships_for_teams_get_team($club_id);
        if (!$team || !is_object($team)) {
            WPTM_Utils::send_json_error('Club not found');
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_manage_trainers($user_id, $club_id)) {
            WPTM_Utils::send_json_error('You are not a member of this club');
        }
        
        $cache_key = WPTM_Cache::get_club_details_cache_key($club_id);
        $club_details = get_transient($cache_key);
        
        if ($club_details !== false) {
            WPTM_Utils::send_json_success($club_details);
        }
        
        // Get user role and display role
        $user_role = WPTM_Role_Mapper::get_user_role($user_id, $club_id);
        $user_display_role = WPTM_Role_Mapper::get_display_name($user_role);
        
        // Get trainers/members
        $trainers = array();
        $members = $team->get_members();
        
        if (is_array($members) && !empty($members)) {
            foreach ($members as $member) {
                if (!is_object($member) || !method_exists($member, 'get_id')) {
                    continue;
                }
                
                $member_id = $member->get_id();
                if (!$member_id || $member_id <= 0) {
                    continue;
                }
                
                $user = get_user_by('id', $member_id);
                if (!$user) {
                    continue;
                }
                
                // Get the actual role, including custom roles
                $member_role = WPTM_Role_Mapper::get_user_role($member_id, $club_id);
                $member_display_role = WPTM_Role_Mapper::get_display_name($member_role);
                
                $trainers[] = array(
                    'id' => $member_id,
                    'name' => $user->display_name ?: $user->user_login,
                    'email' => $user->user_email,
                    'role' => $member_role,
                    'display_role' => $member_display_role
                );
            }
        }
        
        // Get invitations
        $invitations = array();
        $team_invitations = $team->get_invitations();
        
        if (is_array($team_invitations) && !empty($team_invitations)) {
            foreach ($team_invitations as $invitation) {
                if (!is_object($invitation) || !method_exists($invitation, 'get_id')) {
                    continue;
                }
                
                $invitation_id = $invitation->get_id();
                $role = get_post_meta($invitation_id, '_role', true);
                
                if (!$role) {
                    $role = get_post_meta($invitation_id, '_invitation_role', true);
                }
                
                if (!$role) {
                    $role = 'member'; // Default
                }
                
                // Check for Sports Coordinator invitations
                $is_coordinator = get_post_meta($invitation_id, '_wptm_invitation_is_coordinator', true);
                if ($is_coordinator === 'yes') {
                    $role = 'sports_coordinator';
                }
                
                // Get display role
                $display_role = WPTM_Role_Mapper::get_display_name($role);
                
                $invitations[] = array(
                    'id' => $invitation_id,
                    'email' => $invitation->get_email(),
                    'role' => $role,
                    'display_role' => $display_role,
                    'status' => $invitation->get_status(),
                    'date' => $invitation->get_date('date_created')
                );
            }
        }
        
        // Get additional club details
        $plan_id = method_exists($team, 'get_plan_id') ? $team->get_plan_id() : get_post_meta($team->get_id(), '_membership_plan_id', true);
        $plan_name = $plan_id && function_exists('wc_memberships_get_membership_plan') ? 
            wc_memberships_get_membership_plan($plan_id)->get_name() : 'Unknown Plan';
        $seat_count = $team->get_seat_count();
        $member_count = $team->get_member_count();
        $free_seats = $seat_count === null ? 'Unlimited' : ($seat_count - $member_count);
        $created_on = $team->get_date('date_created') ? get_post($team->get_id())->post_date : 'Unknown';
        $member_since = get_user_meta($user_id, "_wc_memberships_for_teams_team_{$club_id}_joined_date", true) ?: $created_on;
        
        $club_details = array(
            'id' => $team->get_id(),
            'name' => $team->get_name(),
            'role' => $user_role,
            'display_role' => $user_display_role,
            'trainers' => $trainers,
            'invitations' => $invitations,
            'plan' => $plan_name,
            'seat_count' => $seat_count === null ? 'Unlimited' : $seat_count,
            'member_count' => $member_count,
            'free_seats' => $free_seats,
            'created_on' => $created_on,
            'member_since' => $member_since
        );
        
        set_transient($cache_key, $club_details, WPTM_Cache::CACHE_TIME_SHORT);
        WPTM_Utils::send_json_success($club_details);
    }
    
    /**
     * Update club name
     */
    public static function update_club_name() {
        WPTM_Utils::verify_ajax_nonce();
        
        $user_id = get_current_user_id();
        $club_id = intval($_POST['club_id'] ?? 0);
        $new_name = sanitize_text_field($_POST['name'] ?? '');
        
        if (!$club_id) {
            WPTM_Utils::send_json_error('Invalid club ID');
        }
        
        if (empty($new_name)) {
            WPTM_Utils::send_json_error('Club name cannot be empty');
        }
        
        if (!function_exists('wc_memberships_for_teams_get_team')) {
            WPTM_Utils::send_json_error('Teams for WooCommerce Memberships plugin not properly installed');
        }
        
        $team = wc_memberships_for_teams_get_team($club_id);
        if (!$team) {
            WPTM_Utils::send_json_error('Club not found');
        }
        
        // Check if user is owner
        $user_role = get_user_meta($user_id, "_wc_memberships_for_teams_team_{$club_id}_role", true);
        if ($user_role !== 'owner') {
            WPTM_Utils::send_json_error('Only the owner can update the club name');
        }
        
        $result = $team->set_name($new_name);
        if (is_wp_error($result)) {
            WPTM_Utils::send_json_error($result->get_error_message());
        }
        
        // Clear cache
        WPTM_Cache::clear_user_cache($user_id);
        WPTM_Cache::clear_club_cache($club_id);
        
        WPTM_Utils::send_json_success('Club name updated successfully');
    }
    
    /**
     * Invite trainer
     */
    public static function invite_trainer() {
        WPTM_Utils::verify_ajax_nonce();
        
        $user_id = get_current_user_id();
        $club_id = intval($_POST['club_id'] ?? 0);
        $email = sanitize_email($_POST['email'] ?? '');
        $custom_role = sanitize_text_field($_POST['role'] ?? 'member');
        
        if (!$club_id || !$email) {
            WPTM_Utils::send_json_error('Invalid club ID or email');
        }
        
        if (!WPTM_Utils::is_valid_email($email)) {
            WPTM_Utils::send_json_error('Invalid email address');
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_manage_trainers($user_id, $club_id)) {
            WPTM_Utils::send_json_error('You do not have permission to invite trainers');
        }
        
        // Convert custom role to internal role for WooCommerce Teams
        $is_coordinator = ($custom_role === 'sports_coordinator');
        $role = $is_coordinator ? 'member' : $custom_role;
        
        // Validate role
        if (!in_array($role, ['member', 'manager', 'owner'])) {
            WPTM_Utils::send_json_error('Invalid role');
        }
        
        if (!function_exists('wc_memberships_for_teams_get_team')) {
            WPTM_Utils::send_json_error('Teams for WooCommerce Memberships plugin not properly installed');
        }
        
        $team = wc_memberships_for_teams_get_team($club_id);
        if (!$team) {
            WPTM_Utils::send_json_error('Club not found');
        }
        
        // Check available seats
        $seat_count = $team->get_seat_count();
        $member_count = $team->get_member_count();
        $invitation_count = count($team->get_invitations());
        
        if ($seat_count !== null && ($member_count + $invitation_count) >= $seat_count) {
            WPTM_Utils::send_json_error('No available seats in the club');
        }
        
        // Check if user already exists
        $existing_user = get_user_by('email', $email);
        if ($existing_user) {
            $existing_role = get_user_meta($existing_user->ID, "_wc_memberships_for_teams_team_{$club_id}_role", true);
            if ($existing_role) {
                WPTM_Utils::send_json_error('This user is already a member of the club');
            }
        }
        
        // Create invitation
        $invitation_args = array(
            'team_id' => $club_id,
            'email' => $email,
            'role' => $role,
            'inviter_id' => $user_id
        );
        
        $invitation = wc_memberships_for_teams_create_invitation($invitation_args);
        if (is_wp_error($invitation)) {
            WPTM_Utils::send_json_error($invitation->get_error_message());
        }
        
        $invitation_id = $invitation->get_id();
        
        // Ensure role is stored in postmeta
        $stored_role = get_post_meta($invitation_id, '_role', true);
        if ($stored_role !== $role) {
            update_post_meta($invitation_id, '_role', $role);
        }
        
        // Mark as Sports Coordinator if needed
        if ($is_coordinator) {
            update_post_meta($invitation_id, '_wptm_invitation_is_coordinator', 'yes');
        }
        
        // Send email
        $invitation_url = $invitation->get_accept_url();
        $inviter = get_user_by('id', $user_id);
        $inviter_name = $inviter->display_name ?: $inviter->user_login;
        $team_name = $team->get_name();
        $site_name = get_bloginfo('name');
        
        // Use custom role name for email
        $display_role = WPTM_Role_Mapper::get_display_name($custom_role);
        
        $subject = sprintf('Invitation to join %s on %s', $team_name, $site_name);
        $message = sprintf(
            "Hello,\n\nYou have been invited by %s to join the team '%s' as a %s on %s.\n\nPlease click the following link to accept the invitation:\n%s\n\nBest regards,\n%s",
            $inviter_name,
            $team_name,
            $display_role,
            $site_name,
            esc_url($invitation_url),
            $site_name
        );
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $sent = wp_mail($email, $subject, $message, $headers);
        
        if (!$sent) {
            WPTM_Utils::send_json_error('Invitation created, but failed to send email');
        }
        
        // Clear cache
        WPTM_Cache::clear_club_cache($club_id);
        
        WPTM_Utils::send_json_success('Invitation sent successfully');
    }
    
    /**
     * Remove trainer
     */
    public static function remove_trainer() {
        WPTM_Utils::verify_ajax_nonce();
        
        $user_id = get_current_user_id();
        $club_id = intval($_POST['club_id'] ?? 0);
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        
        if (!$club_id || !$trainer_id) {
            WPTM_Utils::send_json_error('Invalid club or trainer ID');
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_remove_trainer($user_id, $trainer_id, $club_id)) {
            WPTM_Utils::send_json_error('You do not have permission to remove this trainer');
        }
        
        if (!function_exists('wc_memberships_for_teams_get_team')) {
            WPTM_Utils::send_json_error('Teams for WooCommerce Memberships plugin not properly installed');
        }
        
        $team = wc_memberships_for_teams_get_team($club_id);
        if (!$team) {
            WPTM_Utils::send_json_error('Club not found');
        }
        
        $result = $team->remove_member($trainer_id);
        if (is_wp_error($result)) {
            WPTM_Utils::send_json_error($result->get_error_message());
        }
        
        // Clear cache
        WPTM_Cache::clear_club_cache($club_id);
        
        WPTM_Utils::send_json_success('Trainer removed successfully');
    }
    
    /**
     * Change trainer role
     */
    public static function change_trainer_role() {
        WPTM_Utils::verify_ajax_nonce();
        
        $user_id = get_current_user_id();
        $club_id = intval($_POST['club_id'] ?? 0);
        $member_id = intval($_POST['member_id'] ?? 0);
        $new_role = sanitize_text_field($_POST['role'] ?? '');
        
        if (!$club_id || !$member_id) {
            WPTM_Utils::send_json_error('Invalid club or member ID');
        }
        
        if (!in_array($new_role, ['member', 'manager'])) {
            WPTM_Utils::send_json_error('Invalid role');
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_change_trainer_role($user_id, $member_id, $club_id)) {
            WPTM_Utils::send_json_error('You do not have permission to change trainer roles');
        }
        
        if (!function_exists('wc_memberships_for_teams_get_team')) {
            WPTM_Utils::send_json_error('Teams for WooCommerce Memberships plugin not properly installed');
        }
        
        $team = wc_memberships_for_teams_get_team($club_id);
        if (!$team) {
            WPTM_Utils::send_json_error('Club not found');
        }
        
        // Check if member exists and is not owner
        $member_role = get_user_meta($member_id, "_wc_memberships_for_teams_team_{$club_id}_role", true);
        if (!$member_role) {
            WPTM_Utils::send_json_error('Member not found in this club');
        }
        
        if ($member_role === 'owner') {
            WPTM_Utils::send_json_error('Cannot change the role of an owner');
        }
        
        if ($member_role === $new_role) {
            WPTM_Utils::send_json_error('Member already has this role');
        }
        
        // Update role
        $updated = update_user_meta($member_id, "_wc_memberships_for_teams_team_{$club_id}_role", $new_role);
        if (false === $updated) {
            WPTM_Utils::send_json_error('Failed to update trainer role');
        }
        
        // Trigger hook
        do_action('wc_memberships_for_teams_team_member_role_updated', $member_id, $new_role, $club_id);
        
        // Clear cache
        WPTM_Cache::clear_club_cache($club_id);
        
        WPTM_Utils::send_json_success('Trainer role updated successfully');
    }
    
    /**
     * Cancel invitation
     */
    public static function cancel_invitation() {
        WPTM_Utils::verify_ajax_nonce();
        
        $user_id = get_current_user_id();
        $club_id = intval($_POST['club_id'] ?? 0);
        $invitation_id = intval($_POST['invitation_id'] ?? 0);
        
        if (!$club_id || !$invitation_id) {
            WPTM_Utils::send_json_error('Invalid club or invitation ID');
        }
        
        // Check permissions
        if (!WPTM_Permissions::can_manage_trainers($user_id, $club_id)) {
            WPTM_Utils::send_json_error('You do not have permission to cancel invitations');
        }
        
        if (!function_exists('wc_memberships_for_teams_get_team')) {
            WPTM_Utils::send_json_error('Teams for WooCommerce Memberships plugin not properly installed');
        }
        
        $team = wc_memberships_for_teams_get_team($club_id);
        if (!$team) {
            WPTM_Utils::send_json_error('Club not found');
        }
        
        // Validate invitation
        $invitation_post = get_post($invitation_id);
        if (!$invitation_post || $invitation_post->post_type !== 'wc_team_invitation' || $invitation_post->post_parent != $club_id) {
            WPTM_Utils::send_json_error('Invitation not found or invalid');
        }
        
        // Delete invitation
        $result = wp_delete_post($invitation_id, true);
        if (false === $result) {
            WPTM_Utils::send_json_error('Failed to cancel invitation');
        }
        
        // Trigger hook
        do_action('wc_memberships_for_teams_invitation_deleted', $invitation_id, $club_id);
        
        // Clear cache
        WPTM_Cache::clear_club_cache($club_id);
        
        WPTM_Utils::send_json_success('Invitation cancelled successfully');
    }
}