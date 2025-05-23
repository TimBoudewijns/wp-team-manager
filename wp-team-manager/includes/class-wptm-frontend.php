<?php
/**
 * Frontend Handler Class
 *
 * @package WP Team Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle frontend functionality for WP Team Manager
 */
class WPTM_Frontend {
    
    /**
     * Initialize frontend
     */
    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }
    
    /**
     * Enqueue scripts and styles
     */
    public static function enqueue_scripts() {
        $css_path = WPTM_PLUGIN_DIR . 'assets/css/frontend.css';
        $js_path = WPTM_PLUGIN_DIR . 'assets/js/frontend.js';
        
        // External dependencies
        wp_enqueue_script('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/js/all.min.js', array(), '6.6.0', true);
        wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css', array(), '5.3.0');
        
        // Plugin CSS
        if (file_exists($css_path)) {
            wp_enqueue_style('wptm-frontend', plugins_url('/assets/css/frontend.css', WPTM_PLUGIN_DIR . 'wp-team-manager.php'), array('bootstrap'), WPTM_VERSION);
        }
        
        // Plugin JS
        if (file_exists($js_path)) {
            wp_enqueue_script('wptm-frontend', plugins_url('/assets/js/frontend.js', WPTM_PLUGIN_DIR . 'wp-team-manager.php'), array('jquery', 'font-awesome'), WPTM_VERSION, true);
            wp_localize_script('wptm-frontend', 'wptm_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wptm_nonce'),
                'wp_current_user_id' => get_current_user_id()
            ));
        }
        
        // Chart.js and Bootstrap JS
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);
        wp_enqueue_script('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', array(), '5.3.0', true);
    }
}