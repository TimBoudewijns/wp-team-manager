<?php
class WPTM_Shortcode {
    public static function init() {
        add_shortcode('team_manager', [__CLASS__, 'render_team_manager']);
    }

    public static function render_team_manager($atts) {
        if (!is_user_logged_in() || !wc_memberships_is_user_active_member()) {
            return '<div class="alert alert-warning">Please log in and activate your membership to manage teams.</div>';
        }

        // Enqueue Font Awesome for modern icons
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1');

        // Fetch available seasons from the database
        global $wpdb;
        $user_id = get_current_user_id();
        $seasons = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT season FROM {$wpdb->prefix}wptm_teams WHERE user_id = %d ORDER BY season DESC",
            $user_id
        ));

        // If no seasons are found, provide a default range
        if (empty($seasons)) {
            $current_year = date('Y');
            $seasons = [];
            for ($y = $current_year - 5; $y <= $current_year + 1; $y++) {
                $seasons[] = $y . '-' . ($y + 1);
            }
        }

        ob_start();
        ?>
        <div id="wptm-team-manager">
            <!-- Header with Tabs and Season Selection -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <!-- Tabs Navigation -->
                <ul class="nav nav-tabs modern-tabs" id="wptm-team-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link active" id="my-teams-tab" href="#my-teams" data-bs-toggle="tab" role="tab" aria-controls="my-teams" aria-selected="true">My Teams</a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link" id="club-teams-tab" href="#club-teams" data-bs-toggle="tab" role="tab" aria-controls="club-teams" aria-selected="false" style="display: none;">Club Teams</a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link" id="trainer-management-tab" href="#trainer-management" data-bs-toggle="tab" role="tab" aria-controls="trainer-management" aria-selected="false" style="display: none;">Trainer Management</a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link" id="team-manager-tab" href="#team-manager" data-bs-toggle="tab" role="tab" aria-controls="team-manager" aria-selected="false" style="display: none;">Team Manager</a>
                    </li>
                </ul>
                <!-- Season Selection -->
                <div>
                    <label for="wptm-season-select" class="modern-label me-2 visually-hidden">Select Season</label>
                    <select id="wptm-season-select" class="form-select wptm-season-select">
                        <?php
                        $current_year = date('Y');
                        $default_season = $current_year . '-' . ($current_year + 1);
                        foreach ($seasons as $season) {
                            $season_start_year = (int) explode('-', $season)[0];
                            echo '<option value="' . $season_start_year . '" ' . selected($season, $default_season, false) . '>' . $season . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="tab-content" id="wptm-team-content">
                <!-- My Teams Tab -->
                <div class="tab-pane fade show active" id="my-teams" role="tabpanel" aria-labelledby="my-teams-tab">
                    <div id="my-teams-list"></div>
                    <div id="my-teams-players-list"></div>
                    <div id="my-teams-player-details"></div>
                    <div id="my-teams-spider-chart">
                        <canvas id="my-teams-spider-chart-canvas" style="display: none;"></canvas>
                    </div>
                    <div id="my-teams-player-history"></div>
                </div>

                <!-- Club Teams Tab -->
                <div class="tab-pane fade" id="club-teams" role="tabpanel" aria-labelledby="club-teams-tab">
                    <div id="club-teams-list"></div>
                    <div id="club-teams-players-list"></div>
                    <div id="club-teams-player-details"></div>
                    <div id="club-teams-spider-chart">
                        <canvas id="club-teams-spider-chart-canvas" style="display: none;"></canvas>
                    </div>
                    <div id="club-teams-player-history"></div>
                </div>

                <!-- Trainer Management Tab -->
                <div class="tab-pane fade" id="trainer-management" role="tabpanel" aria-labelledby="trainer-management-tab">
                    <div id="trainer-management-content"></div>
                </div>
                
                <!-- Team Manager Tab (Nieuw) -->
                <div class="tab-pane fade" id="team-manager" role="tabpanel" aria-labelledby="team-manager-tab">
                    <div id="team-manager-content"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}