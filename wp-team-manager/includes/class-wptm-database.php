<?php
class WPTM_Database {
    public static function init() {
        register_activation_hook(WPTM_PLUGIN_DIR . 'wp-team-manager.php', [__CLASS__, 'install']);
        register_activation_hook(WPTM_PLUGIN_DIR . 'wp-team-manager.php', [__CLASS__, 'update_database']);
    }

    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Table for teams
        $table_teams = $wpdb->prefix . 'wptm_teams';
        $sql_teams = "CREATE TABLE $table_teams (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            team_name varchar(255) NOT NULL,
            season varchar(9) NOT NULL,
            coach varchar(255) DEFAULT NULL,
            wc_team_id bigint(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Table for unique players
        $table_unique_players = $wpdb->prefix . 'wptm_unique_players';
        $sql_unique_players = "CREATE TABLE $table_unique_players (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            birth_date date DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Table for team-player associations
        $table_team_players = $wpdb->prefix . 'wptm_team_players';
        $sql_team_players = "CREATE TABLE $table_team_players (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id bigint(20) UNSIGNED NOT NULL,
            team_id bigint(20) UNSIGNED NOT NULL,
            season varchar(9) NOT NULL,
            position varchar(100) DEFAULT NULL,
            player_number varchar(10) DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Table for ratings
        $table_ratings = $wpdb->prefix . 'wptm_ratings';
        $sql_ratings = "CREATE TABLE $table_ratings (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id bigint(20) UNSIGNED NOT NULL,
            team_id bigint(20) UNSIGNED NOT NULL,
            rating_date date NOT NULL,
            season varchar(9) NOT NULL,
            technique int(2) DEFAULT 0,
            speed int(2) DEFAULT 0,
            endurance int(2) DEFAULT 0,
            intelligence int(2) DEFAULT 0,
            passing int(2) DEFAULT 0,
            defense int(2) DEFAULT 0,
            attack int(2) DEFAULT 0,
            teamwork int(2) DEFAULT 0,
            agility int(2) DEFAULT 0,
            strength int(2) DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Table for coach advice (updated to include team_id)
        $table_coach_advice = $wpdb->prefix . 'wptm_coach_advice';
        $sql_coach_advice = "CREATE TABLE $table_coach_advice (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id bigint(20) UNSIGNED NOT NULL,
            team_id bigint(20) UNSIGNED NOT NULL,
            year int(4) NOT NULL,
            advice_text text NOT NULL,
            ratings_hash varchar(64) NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Table for team-trainer associations
        $table_team_trainers = $wpdb->prefix . 'wptm_team_trainers';
        $sql_team_trainers = "CREATE TABLE $table_team_trainers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_user_id bigint(20) UNSIGNED NOT NULL,
            team_id bigint(20) UNSIGNED NOT NULL,
            wc_team_id bigint(20) UNSIGNED NOT NULL,
            season varchar(9) NOT NULL,
            role varchar(100) DEFAULT NULL,
            additional_info text DEFAULT NULL,
            assigned_by bigint(20) UNSIGNED NOT NULL,
            assigned_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_teams);
        dbDelta($sql_unique_players);
        dbDelta($sql_team_players);
        dbDelta($sql_ratings);
        dbDelta($sql_coach_advice);
        dbDelta($sql_team_trainers);

        // Add indexes to improve query performance
        $wpdb->query("CREATE INDEX idx_user_season ON {$table_teams} (user_id, season);");
        $wpdb->query("CREATE INDEX idx_team_player_season ON {$table_team_players} (team_id, player_id, season);");
        $wpdb->query("CREATE INDEX idx_ratings_player_team ON {$table_ratings} (player_id, team_id);");
        $wpdb->query("CREATE INDEX idx_ratings_player_date ON {$table_ratings} (player_id, rating_date);");
        $wpdb->query("CREATE INDEX idx_ratings_player_season ON {$table_ratings} (player_id, season);");
        $wpdb->query("CREATE INDEX idx_coach_advice_player_team_year ON {$table_coach_advice} (player_id, team_id, year);");
        $wpdb->query("CREATE INDEX idx_team_trainers_team_season ON {$table_team_trainers} (team_id, season);");
        $wpdb->query("CREATE INDEX idx_team_trainers_trainer ON {$table_team_trainers} (trainer_user_id);");
        $wpdb->query("CREATE INDEX idx_team_trainers_wc_team ON {$table_team_trainers} (wc_team_id);");

        error_log('WP Team Manager: Database installation executed. Tables: ' . implode(', ', [$table_teams, $table_unique_players, $table_team_players, $table_ratings, $table_coach_advice, $table_team_trainers]));
    }

    public static function update_database() {
        global $wpdb;
        $table_teams = $wpdb->prefix . 'wptm_teams';
        $table_unique_players = $wpdb->prefix . 'wptm_unique_players';
        $table_team_players = $wpdb->prefix . 'wptm_team_players';
        $table_coach_advice = $wpdb->prefix . 'wptm_coach_advice';
        $table_ratings = $wpdb->prefix . 'wptm_ratings';
        $table_team_trainers = $wpdb->prefix . 'wptm_team_trainers';

        // Controleer of de team_trainers tabel bestaat, zo niet, maak deze aan
        $team_trainers_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_team_trainers}'");
        if (!$team_trainers_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql_team_trainers = "CREATE TABLE $table_team_trainers (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_user_id bigint(20) UNSIGNED NOT NULL,
                team_id bigint(20) UNSIGNED NOT NULL,
                wc_team_id bigint(20) UNSIGNED NOT NULL,
                season varchar(9) NOT NULL,
                role varchar(100) DEFAULT NULL,
                club_role varchar(50) DEFAULT 'trainer',
                additional_info text DEFAULT NULL,
                assigned_by bigint(20) UNSIGNED NOT NULL,
                assigned_at timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql_team_trainers);

            // Voeg indices toe
            $wpdb->query("CREATE INDEX idx_team_trainers_team_season ON {$table_team_trainers} (team_id, season);");
            $wpdb->query("CREATE INDEX idx_team_trainers_trainer ON {$table_team_trainers} (trainer_user_id);");
            $wpdb->query("CREATE INDEX idx_team_trainers_wc_team ON {$table_team_trainers} (wc_team_id);");

            error_log('WP Team Manager: Created new team_trainers table during update_database');
        } else {
            // Controleer of de club_role kolom bestaat, zo niet, voeg deze toe
            $column_exists = $wpdb->get_var("
                SELECT COUNT(*) 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = '$table_team_trainers' 
                AND COLUMN_NAME = 'club_role'
            ");
            
            if ($column_exists == 0) {
                // Kolom bestaat nog niet, voeg deze toe
                $wpdb->query("ALTER TABLE $table_team_trainers ADD COLUMN club_role VARCHAR(50) DEFAULT 'trainer' AFTER role");
                error_log("WP Team Manager: Added club_role column to $table_team_trainers table");
                
                // Update bestaande trainers met de juiste club_role
                $trainers = $wpdb->get_results("
                    SELECT tt.id, tt.trainer_user_id, tt.wc_team_id
                    FROM $table_team_trainers tt
                    WHERE tt.club_role IS NULL OR tt.club_role = ''
                ");
                
                foreach ($trainers as $trainer) {
                    $trainer_id = $trainer->trainer_user_id;
                    $wc_team_id = $trainer->wc_team_id;
                    
                    if ($wc_team_id) {
                        // Haal de rol op uit usermeta
                        $meta_key = "_wc_memberships_for_teams_team_{$wc_team_id}_role";
                        $meta_value = get_user_meta($trainer_id, $meta_key, true);
                        
                        // Check voor sports_coordinator status
                        $is_coordinator = get_user_meta($trainer_id, "_wptm_team_{$wc_team_id}_is_coordinator", true) === 'yes';
                        
                        // Bepaal de club rol
                        $club_role = 'trainer'; // Default
                        
                        if ($is_coordinator) {
                            $club_role = 'sports_coordinator';
                        } elseif ($meta_value === 'owner') {
                            $club_role = 'owner';
                        } elseif ($meta_value === 'manager') {
                            $club_role = 'manager';
                        } elseif ($meta_value === 'member') {
                            $club_role = 'trainer'; // We gebruiken 'trainer' i.p.v. 'member'
                        }
                        
                        // Update de club_role in de database
                        $wpdb->update(
                            $table_team_trainers,
                            ['club_role' => $club_role],
                            ['id' => $trainer->id],
                            ['%s'],
                            ['%d']
                        );
                        
                        error_log("WP Team Manager: Updated club_role for trainer $trainer_id to $club_role (wc_team_id: $wc_team_id)");
                    }
                }
            }
        }

        // Reste van de bestaande update_database code...
        // (hieronder de bestaande code voor het bijwerken van wc_team_id voor teams)
    }

    // Functie om de database update eenmalig uit te voeren
    public static function force_install_trainer_roles() {
        global $wpdb;
        $table_team_trainers = $wpdb->prefix . 'wptm_team_trainers';
        
        // Controleer of de club_role kolom bestaat, zo niet, voeg deze toe
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_team_trainers' 
            AND COLUMN_NAME = 'club_role'
        ");
        
        if ($column_exists == 0) {
            // Kolom bestaat nog niet, voeg deze toe
            $wpdb->query("ALTER TABLE $table_team_trainers ADD COLUMN club_role VARCHAR(50) DEFAULT 'trainer' AFTER role");
            error_log("WP Team Manager: Added club_role column to $table_team_trainers table");
        }
        
        // Update bestaande trainers met de juiste club_role
        $trainers = $wpdb->get_results("
            SELECT tt.id, tt.trainer_user_id, tt.wc_team_id
            FROM $table_team_trainers tt
        ");
        
        $updated_count = 0;
        
        foreach ($trainers as $trainer) {
            $trainer_id = $trainer->trainer_user_id;
            $wc_team_id = $trainer->wc_team_id;
            
            if ($wc_team_id) {
                // Haal de rol op uit usermeta
                $meta_key = "_wc_memberships_for_teams_team_{$wc_team_id}_role";
                $meta_value = get_user_meta($trainer_id, $meta_key, true);
                
                // Check voor sports_coordinator status
                $is_coordinator = get_user_meta($trainer_id, "_wptm_team_{$wc_team_id}_is_coordinator", true) === 'yes';
                
                // Bepaal de club rol
                $club_role = 'trainer'; // Default
                
                if ($is_coordinator) {
                    $club_role = 'sports_coordinator';
                } elseif ($meta_value === 'owner') {
                    $club_role = 'owner';
                } elseif ($meta_value === 'manager') {
                    $club_role = 'manager';
                } elseif ($meta_value === 'member') {
                    $club_role = 'trainer'; // We gebruiken 'trainer' i.p.v. 'member'
                }
                
                // Update de club_role in de database
                $wpdb->update(
                    $table_team_trainers,
                    ['club_role' => $club_role],
                    ['id' => $trainer->id],
                    ['%s'],
                    ['%d']
                );
                
                $updated_count++;
                error_log("WP Team Manager: Updated club_role for trainer $trainer_id to $club_role (wc_team_id: $wc_team_id)");
            }
        }
        
        error_log("WP Team Manager: Total trainers updated: $updated_count");
        
        return $updated_count;
    }

    /**
     * Repareert bestaande invites en trainer records voor coordinators
     * 
     * @return int Aantal gerepareerde records
     */
    public static function repair_coordinator_roles() {
        global $wpdb;
        
        error_log("WPTM_Database: Starting repair_coordinator_roles");
        
        // Eerst controleren we op uitnodigingen die als coordinator zijn gemarkeerd
        $invitations = $wpdb->get_results("
            SELECT p.ID, p.post_author, p.post_parent 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'wc_team_invitation'
            AND pm.meta_key = '_wptm_invitation_is_coordinator'
            AND pm.meta_value = 'yes'
        ");
        
        $fixed_count = 0;
        
        error_log("WPTM_Database: Found " . count($invitations) . " coordinator invitations");
        
        foreach ($invitations as $invitation) {
            $invitation_id = $invitation->ID;
            $team_id = $invitation->post_parent; // Dit is het WC team ID
            
            // Laten we controleren of de uitnodiging is geaccepteerd
            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_user_id'",
                $invitation_id
            ));
            
            if ($user_id) {
                error_log("WPTM_Database: Invitation ID $invitation_id has been accepted by user ID $user_id");
                
                // Uitnodiging is geaccepteerd, dus de gebruiker moet bestaan
                // Zoek alle WPTM teams gekoppeld aan dit WC team
                $wptm_teams = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wptm_teams WHERE wc_team_id = %d",
                    $team_id
                ));
                
                foreach ($wptm_teams as $wptm_team) {
                    $wptm_team_id = $wptm_team->id;
                    
                    // Update de club_role in de team_trainers tabel
                    $trainer_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}wptm_team_trainers 
                         WHERE trainer_user_id = %d AND team_id = %d",
                        $user_id, $wptm_team_id
                    ));
                    
                    if ($trainer_id) {
                        $wpdb->update(
                            "{$wpdb->prefix}wptm_team_trainers",
                            ['club_role' => 'sports_coordinator'],
                            ['id' => $trainer_id],
                            ['%s'],
                            ['%d']
                        );
                        
                        $fixed_count++;
                        error_log("WPTM_Database: Fixed club_role for trainer ID $trainer_id (User ID: $user_id, Team ID: $wptm_team_id) to sports_coordinator");
                    } else {
                        error_log("WPTM_Database: No trainer record found for User ID: $user_id, Team ID: $wptm_team_id");
                    }
                    
                    // Marker in usermeta zetten voor backward compatibility
                    update_user_meta($user_id, "_wptm_team_{$team_id}_is_coordinator", 'yes');
                    error_log("WPTM_Database: Updated usermeta _wptm_team_{$team_id}_is_coordinator for user $user_id");
                }
            } else {
                error_log("WPTM_Database: Invitation ID $invitation_id has not been accepted yet");
            }
        }
        
        // Controleer ook de omgekeerde situatie: usermeta markers zonder corresponderende club_role
        $users_with_coordinator_meta = $wpdb->get_results("
            SELECT user_id, meta_key 
            FROM {$wpdb->usermeta} 
            WHERE meta_key LIKE '_wptm_team_%_is_coordinator' 
            AND meta_value = 'yes'
        ");
        
        error_log("WPTM_Database: Found " . count($users_with_coordinator_meta) . " users with coordinator metadata");
        
        foreach ($users_with_coordinator_meta as $user_meta) {
            $user_id = $user_meta->user_id;
            if (preg_match('/_wptm_team_(\d+)_is_coordinator/', $user_meta->meta_key, $matches)) {
                $wc_team_id = $matches[1];
                
                $wptm_teams = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wptm_teams WHERE wc_team_id = %d",
                    $wc_team_id
                ));
                
                foreach ($wptm_teams as $wptm_team) {
                    $wptm_team_id = $wptm_team->id;
                    
                    // Controleer of er een trainer record bestaat
                    $trainer_record = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, club_role FROM {$wpdb->prefix}wptm_team_trainers 
                         WHERE trainer_user_id = %d AND team_id = %d",
                        $user_id, $wptm_team_id
                    ));
                    
                    if ($trainer_record && $trainer_record->club_role !== 'sports_coordinator') {
                        $wpdb->update(
                            "{$wpdb->prefix}wptm_team_trainers",
                            ['club_role' => 'sports_coordinator'],
                            ['id' => $trainer_record->id],
                            ['%s'],
                            ['%d']
                        );
                        
                        $fixed_count++;
                        error_log("WPTM_Database: Updated existing trainer record ID {$trainer_record->id} from '{$trainer_record->club_role}' to 'sports_coordinator'");
                    }
                    elseif (!$trainer_record) {
                        error_log("WPTM_Database: No trainer record exists for User ID: $user_id, Team ID: $wptm_team_id with WC Team ID: $wc_team_id");
                    }
                }
            }
        }
        
        error_log("WPTM_Database: Repaired $fixed_count coordinator records");
        return $fixed_count;
    }

    public static function force_install() {
        self::install();
        self::update_database();
        error_log('WP Team Manager: Force install and update executed');
    }
}