<?php
/*
Plugin Name: WP Team Manager
Description: A plugin for WooCommerce Memberships and Teams to manage teams, add players, and create weekly skill ratings with spider charts.
Version: 1.0
Author: Tim Boudewijns
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('WPTM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPTM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPTM_VERSION', '1.0');

/**
 * Main plugin class
 */
class WP_Team_Manager {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce Memberships is active
        if (!class_exists('WC_Memberships')) {
            add_action('admin_notices', array($this, 'missing_wc_memberships_notice'));
            return;
        }
        
        // Load required files
        $this->load_dependencies();
        
        // Initialize components
        $this->init_components();
        
        // Setup hooks
        $this->setup_hooks();
        
        // Temporary debug: Force installation
        if (isset($_GET['wptm_force_install']) && current_user_can('manage_options')) {
            WPTM_Database::force_install();
            add_action('admin_notices', function() {
                echo '<div class="updated"><p>WP Team Manager: Database installation forcibly executed. Check the debug log.</p></div>';
            });
        }
    }
    
    /**
     * Load all required files
     */
    private function load_dependencies() {
        $required_files = array(
            // Core classes
            WPTM_PLUGIN_DIR . 'includes/class-wptm-database.php',
            WPTM_PLUGIN_DIR . 'includes/class-wptm-shortcode.php',
            WPTM_PLUGIN_DIR . 'includes/class-wptm-frontend.php',
            WPTM_PLUGIN_DIR . 'includes/class-wptm-role-mapper.php',
            
            // Helper classes
            WPTM_PLUGIN_DIR . 'includes/helpers/class-wptm-permissions.php',
            WPTM_PLUGIN_DIR . 'includes/helpers/class-wptm-cache.php',
            WPTM_PLUGIN_DIR . 'includes/helpers/class-wptm-utils.php',
            
            // AJAX handlers
            WPTM_PLUGIN_DIR . 'includes/ajax/class-wptm-teams-ajax.php',
            WPTM_PLUGIN_DIR . 'includes/ajax/class-wptm-players-ajax.php',
            WPTM_PLUGIN_DIR . 'includes/ajax/class-wptm-ratings-ajax.php',
            WPTM_PLUGIN_DIR . 'includes/ajax/class-wptm-trainers-ajax.php',
            WPTM_PLUGIN_DIR . 'includes/ajax/class-wptm-team-manager-ajax.php',
        );
        
        foreach ($required_files as $file) {
            if (file_exists($file)) {
                require_once $file;
            } else {
                add_action('admin_notices', function() use ($file) {
                    echo '<div class="error"><p>WP Team Manager: Could not find file: ' . esc_html($file) . '</p></div>';
                });
            }
        }
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize core components
        WPTM_Database::init();
        WPTM_Shortcode::init();
        WPTM_Frontend::init();
        
        // Initialize AJAX handlers
        WPTM_Teams_Ajax::init();
        WPTM_Players_Ajax::init();
        WPTM_Ratings_Ajax::init();
        WPTM_Trainers_Ajax::init();
        WPTM_Team_Manager_Ajax::init();
    }
    
    /**
     * Setup plugin hooks
     */
    private function setup_hooks() {
        // Hook for when an invitation is accepted
        add_action('wc_memberships_for_teams_invitation_accepted', array($this, 'handle_invitation_accepted'), 10, 2);
    }
    
    /**
     * Handle invitation accepted
     */
    public function handle_invitation_accepted($invitation, $team) {
        global $wpdb;
        
        // Log for debugging
        error_log("WPTM: Invitation accepted - ID: {$invitation->get_id()}, Team ID: {$team->get_id()}, User ID: {$invitation->get_user_id()}");
        
        // Check if this invitation has a sports_coordinator marking
        $invitation_id = $invitation->get_id();
        $is_coordinator = get_post_meta($invitation_id, '_wptm_invitation_is_coordinator', true) === 'yes';
        
        error_log("WPTM: Invitation is_coordinator check - Result: " . ($is_coordinator ? 'yes' : 'no'));
        
        if ($is_coordinator) {
            // Find the corresponding record in wptm_team_trainers
            $table_name = $wpdb->prefix . 'wptm_team_trainers';
            $user_id = $invitation->get_user_id();
            $wc_team_id = $team->get_id();
            
            // Find all teams linked to this WooCommerce team
            $team_records = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wptm_teams WHERE wc_team_id = %d",
                $wc_team_id
            ));
            
            if (!empty($team_records)) {
                foreach ($team_records as $team_record) {
                    $team_id = $team_record->id;
                    
                    // Update or insert the trainer record with the correct club_role
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_name WHERE trainer_user_id = %d AND team_id = %d",
                        $user_id, $team_id
                    ));
                    
                    if ($exists) {
                        // Update existing record
                        $wpdb->update(
                            $table_name,
                            ['club_role' => 'sports_coordinator'],
                            [
                                'trainer_user_id' => $user_id,
                                'team_id' => $team_id
                            ],
                            ['%s'],
                            ['%d', '%d']
                        );
                        
                        error_log("WPTM: Updated trainer record to sports_coordinator - User ID: $user_id, Team ID: $team_id");
                    } else {
                        // Check if the trainer_user_id and wc_team_id combination exists, but with a different team_id
                        $alternate_record = $wpdb->get_row($wpdb->prepare(
                            "SELECT id, team_id FROM $table_name WHERE trainer_user_id = %d AND wc_team_id = %d",
                            $user_id, $wc_team_id
                        ));
                        
                        if ($alternate_record) {
                            // Update this record
                            $wpdb->update(
                                $table_name,
                                [
                                    'team_id' => $team_id,
                                    'club_role' => 'sports_coordinator'
                                ],
                                ['id' => $alternate_record->id],
                                ['%d', '%s'],
                                ['%d']
                            );
                            
                            error_log("WPTM: Updated alternate trainer record to sports_coordinator - User ID: $user_id, Team ID from {$alternate_record->team_id} to $team_id");
                        } else {
                            error_log("WPTM: No existing trainer record found to update for User ID: $user_id, Team ID: $team_id");
                        }
                    }
                    
                    // Update also the usermeta for backward compatibility
                    update_user_meta($user_id, "_wptm_team_{$wc_team_id}_is_coordinator", 'yes');
                    error_log("WPTM: Updated usermeta marker for coordinator - User ID: $user_id, WC Team ID: $wc_team_id");
                }
            } else {
                error_log("WPTM: No matching teams found for WC Team ID: $wc_team_id");
            }
        } else {
            error_log("WPTM: Invitation is not for a coordinator, no special handling needed");
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        if (class_exists('WPTM_Database')) {
            WPTM_Database::install();
            WPTM_Database::update_database();
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled events
        wp_clear_scheduled_hook('wptm_generate_coach_advice');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Missing WooCommerce Memberships notice
     */
    public function missing_wc_memberships_notice() {
        echo '<div class="error"><p>WooCommerce Memberships is required for WP Team Manager.</p></div>';
    }
}

// Initialize the plugin
WP_Team_Manager::get_instance();