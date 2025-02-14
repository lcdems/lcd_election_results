<?php
/**
 * Plugin Name: LCD Election Results
 * Description: Import and manage election results from CSV files
 * Version: 1.0.0
 * Author: LCD
 * License: GPL v2 or later
 */

defined('ABSPATH') or die('Direct access not allowed');

class LCD_Election_Results {
    private static $instance = null;
    private static $results_table;
    private static $candidates_table;
    private static $precincts_table;
    private static $voters_table;
    private static $voter_history_table;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        self::$results_table = $wpdb->prefix . 'election_results';
        self::$candidates_table = $wpdb->prefix . 'election_candidates';
        self::$precincts_table = $wpdb->prefix . 'election_precincts';
        self::$voters_table = $wpdb->prefix . 'election_voters';
        self::$voter_history_table = $wpdb->prefix . 'election_voter_history';
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_admin'));
        
        // Removed map initialization and AJAX handlers
    }

    public static function activate() {
        global $wpdb;
        
        self::$results_table = $wpdb->prefix . 'election_results';
        self::$candidates_table = $wpdb->prefix . 'election_candidates';
        self::$precincts_table = $wpdb->prefix . 'election_precincts';
        self::$voters_table = $wpdb->prefix . 'election_voters';
        self::$voter_history_table = $wpdb->prefix . 'election_voter_history';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create candidates table if it doesn't exist
        $sql_candidates = "CREATE TABLE IF NOT EXISTS " . self::$candidates_table . " (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            candidate_name VARCHAR(255) NOT NULL,
            race_name VARCHAR(255) NOT NULL,
            election_date DATE NOT NULL,
            party VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_candidate_race (candidate_name(80), race_name(80), election_date)
        ) $charset_collate;";

        // Create results table with candidate_id if it doesn't exist
        $sql_results = "CREATE TABLE IF NOT EXISTS " . self::$results_table . " (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            election_date DATE NOT NULL,
            candidate_id BIGINT NOT NULL,
            precinct_name VARCHAR(100) NOT NULL,
            precinct_number VARCHAR(20) NOT NULL,
            votes INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_date (election_date),
            INDEX idx_candidate_id (candidate_id),
            UNIQUE KEY unique_vote_record (candidate_id, precinct_number, election_date),
            FOREIGN KEY (candidate_id) REFERENCES " . self::$candidates_table . "(id) ON DELETE CASCADE
        ) $charset_collate;";

        // Create precincts table
        $sql_precincts = "CREATE TABLE IF NOT EXISTS " . self::$precincts_table . " (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            county_code VARCHAR(10) NOT NULL,
            county_name VARCHAR(100) NOT NULL,
            district_type VARCHAR(50) NOT NULL,
            district_code VARCHAR(20) NOT NULL,
            district_name VARCHAR(255) NOT NULL,
            precinct_code VARCHAR(20) NOT NULL,
            precinct_name VARCHAR(255) NOT NULL,
            precinct_part VARCHAR(20) NOT NULL,
            import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_precinct_location (county_code, district_code, precinct_part)
        ) $charset_collate;";

        // Create voters table
        $sql_voters = "CREATE TABLE IF NOT EXISTS " . self::$voters_table . " (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            state_voter_id BIGINT NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(255),
            last_name VARCHAR(100) NOT NULL,
            name_suffix VARCHAR(10),
            birth_year INT,
            gender CHAR(1),
            registration_date DATE,
            last_voted DATE,
            status_code VARCHAR(20),
            precinct_code VARCHAR(20) NOT NULL,
            precinct_part VARCHAR(20) NOT NULL,
            legislative_district VARCHAR(20),
            congressional_district VARCHAR(20),
            import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_voter (state_voter_id),
            INDEX idx_precinct (precinct_code, precinct_part)
        ) $charset_collate;";

        // Create voter history table
        $sql_voter_history = "CREATE TABLE IF NOT EXISTS " . self::$voter_history_table . " (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            voter_history_id BIGINT NOT NULL,
            state_voter_id BIGINT NOT NULL,
            county_code VARCHAR(10) NOT NULL,
            county_code_voting VARCHAR(10) NOT NULL,
            election_date DATE NOT NULL,
            import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_voter_history (voter_history_id),
            INDEX idx_voter_election (state_voter_id, election_date),
            FOREIGN KEY (state_voter_id) REFERENCES " . self::$voters_table . "(state_voter_id) ON DELETE CASCADE
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Execute the SQL directly instead of using dbDelta
        $wpdb->query($sql_candidates);
        if ($wpdb->last_error) {
            error_log('Error creating candidates table: ' . $wpdb->last_error);
        }
        
        $wpdb->query($sql_results);
        if ($wpdb->last_error) {
            error_log('Error creating results table: ' . $wpdb->last_error);
        }

        $wpdb->query($sql_precincts);
        if ($wpdb->last_error) {
            error_log('Error creating precincts table: ' . $wpdb->last_error);
        }

        $wpdb->query($sql_voters);
        if ($wpdb->last_error) {
            error_log('Error creating voters table: ' . $wpdb->last_error);
        }

        $wpdb->query($sql_voter_history);
        if ($wpdb->last_error) {
            error_log('Error creating voter history table: ' . $wpdb->last_error);
        }
    }

    private function get_or_create_candidate($candidate_name, $race_name, $election_date) {
        global $wpdb;
        
        // Try to find existing candidate
        $candidate_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::$candidates_table . " 
            WHERE candidate_name = %s AND race_name = %s AND election_date = %s",
            $candidate_name,
            $race_name,
            $election_date
        ));

        if ($candidate_id === null) {
            $party = null;

            // Rule 1: If candidate is "WRITE-IN", set party to "unaffiliated"
            if (strtoupper($candidate_name) === 'WRITE-IN') {
                $party = 'unaffiliated';
            } else {
                // Rule 2: Check for same candidate in same race but different date
                $previous_party = $wpdb->get_var($wpdb->prepare(
                    "SELECT party 
                    FROM " . self::$candidates_table . "
                    WHERE candidate_name = %s 
                    AND race_name = %s 
                    AND election_date < %s 
                    AND party IS NOT NULL 
                    ORDER BY election_date DESC 
                    LIMIT 1",
                    $candidate_name,
                    $race_name,
                    $election_date
                ));

                if ($previous_party !== null) {
                    $party = $previous_party;
                }
            }

            // Create new candidate with party if determined
            $insert_data = [
                'candidate_name' => $candidate_name,
                'race_name' => $race_name,
                'election_date' => $election_date
            ];
            
            if ($party !== null) {
                $insert_data['party'] = $party;
            }

            $wpdb->insert(self::$candidates_table, $insert_data);
            $candidate_id = $wpdb->insert_id;
        }

        return $candidate_id;
    }

    public function process_upload() {
        if (!isset($_FILES['election_file'])) {
            return ['errors' => 'No file uploaded'];
        }

        $file = $_FILES['election_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['errors' => 'File upload failed'];
        }

        // Extract date from filename or use current date
        $filename = basename($file['name']);
        $date_match = [];
        if (preg_match('/^(\d{8})/', $filename, $date_match)) {
            $election_date = date('Y-m-d', strtotime($date_match[1]));
        } else {
            $election_date = current_time('Y-m-d');
        }

        // Process CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            return ['errors' => 'Could not open file'];
        }

        global $wpdb;
        $results = [
            'added' => 0, 
            'updated' => 0, 
            'skipped' => 0,
            'errors' => []
        ];

        try {
            $wpdb->query('START TRANSACTION');

            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) !== 5) {
                    $results['errors'][] = 'Row skipped: Invalid number of columns';
                    continue;
                }

                // Skip total rows
                if ($data[2] === 'Total' && $data[3] === '-1') {
                    continue;
                }

                // Get or create candidate
                $candidate_id = $this->get_or_create_candidate($data[1], $data[0], $election_date);

                // Check if record exists and get current votes
                $existing_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, votes FROM " . self::$results_table . "
                    WHERE election_date = %s
                    AND candidate_id = %d
                    AND precinct_number = %s",
                    $election_date,
                    $candidate_id,
                    $data[3]
                ));

                $new_votes = intval($data[4]);

                // If record exists
                if ($existing_record) {
                    // Convert existing votes to integer for proper comparison
                    $existing_votes = intval($existing_record->votes);
                    
                    // Only update if vote count is different
                    if ($existing_votes !== $new_votes) {
                        $results['debug'][] = sprintf(
                            'Vote mismatch - Race: %s, Option: %s, Precinct: %s, Old: %d, New: %d',
                            $data[0],
                            $data[1],
                            $data[3],
                            $existing_votes,
                            $new_votes
                        );
                        
                        $updated = $wpdb->update(
                            self::$results_table,
                            [
                                'votes' => $new_votes,
                                'precinct_name' => $data[2],
                                'filename' => $filename
                            ],
                            ['id' => $existing_record->id]
                        );

                        if ($updated === false) {
                            $results['errors'][] = sprintf(
                                'Error updating record: Race=%s, Option=%s, Precinct=%s',
                                $data[0],
                                $data[1],
                                $data[3]
                            );
                        } else {
                            $results['updated']++;
                        }
                    } else {
                        $results['skipped']++;
                    }
                } else {
                    // Insert new record
                    $inserted = $wpdb->insert(
                        self::$results_table,
                        [
                            'election_date' => $election_date,
                            'candidate_id' => $candidate_id,
                            'precinct_name' => $data[2],
                            'precinct_number' => $data[3],
                            'votes' => $new_votes,
                            'filename' => $filename
                        ]
                    );

                    if ($inserted === false) {
                        $results['errors'][] = sprintf(
                            'Error inserting record: Race=%s, Option=%s, Precinct=%s',
                            $data[0],
                            $data[1],
                            $data[3]
                        );
                    } else {
                        $results['added']++;
                    }
                }
            }

            if (empty($results['errors'])) {
                $wpdb->query('COMMIT');
            } else {
                $wpdb->query('ROLLBACK');
            }
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $results['errors'][] = $e->getMessage();
        }

        fclose($handle);
        return $results;
    }

    public function add_admin_menu() {
        add_menu_page(
            'LCD Election Results',
            'LCD Election Results',
            'manage_options',
            'lcd-election-results',
            array($this, 'render_admin_page'),
            'dashicons-chart-bar'
        );

        add_submenu_page(
            'lcd-election-results',
            'Manage Precincts',
            'Manage Precincts',
            'manage_options',
            'lcd-election-precincts',
            array($this, 'render_precincts_page')
        );

        add_submenu_page(
            'lcd-election-results',
            'Manage Voter Data',
            'Manage Voter Data',
            'manage_options',
            'lcd-election-voters',
            array($this, 'render_voter_data_page')
        );
    }

    public function init_admin() {
        // Handle candidate party updates
        if (isset($_POST['update_candidate_party']) && check_admin_referer('lcd_update_candidate_party')) {
            $candidate_id = intval($_POST['candidate_id']);
            $party = sanitize_text_field($_POST['party']);
            
            global $wpdb;
            $updated = $wpdb->update(
                self::$candidates_table,
                ['party' => $party],
                ['id' => $candidate_id],
                ['%s'],
                ['%d']
            );

            if ($updated !== false) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Candidate party affiliation updated successfully.</p></div>';
                });
            }
        }

        // Handle party color updates
        if (isset($_POST['update_party_colors']) && check_admin_referer('lcd_party_colors_nonce')) {
            $party_colors = array();
            foreach ($_POST['party_colors'] as $party => $color) {
                if (!empty($color)) {
                    // Sanitize the color value to ensure it's a valid hex code
                    $color = sanitize_hex_color($color);
                    if ($color) {
                        $party_colors[sanitize_text_field($party)] = $color;
                    }
                }
            }
            update_option('lcd_party_colors', $party_colors);
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Party colors updated successfully.</p></div>';
            });
        }

        // Enqueue color picker assets
        if (isset($_GET['page']) && $_GET['page'] === 'lcd-party-colors') {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            add_action('admin_footer', array($this, 'color_picker_script'));
        }
    }

    public function color_picker_script() {
        ?>
        <script>
            jQuery(document).ready(function($) {
                $('.color-picker').wpColorPicker();
            });
        </script>
        <?php
    }

    public function render_party_colors_page() {
        global $wpdb;
        
        // Get all unique parties from the database
        $parties = $wpdb->get_col(
            "SELECT DISTINCT party FROM " . self::$candidates_table . "
            WHERE party IS NOT NULL AND party != ''
            ORDER BY party ASC"
        );

        // Get saved colors
        $party_colors = get_option('lcd_party_colors', array());
        ?>
        <div class="wrap">
            <h1>Party Colors</h1>
            
            <div class="card">
                <h2>Manage Party Colors</h2>
                <p>Set colors for each party to be used in election result visualizations.</p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('lcd_party_colors_nonce'); ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Party</th>
                                <th>Color</th>
                                <th>Preview</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parties as $party): ?>
                                <tr>
                                    <td><?php echo esc_html($party); ?></td>
                                    <td>
                                        <input type="text" 
                                               name="party_colors[<?php echo esc_attr($party); ?>]" 
                                               value="<?php echo esc_attr($party_colors[$party] ?? ''); ?>" 
                                               class="color-picker"
                                               data-default-color="<?php echo esc_attr($party_colors[$party] ?? '#FFFFFF'); ?>">
                                    </td>
                                    <td>
                                        <div style="width: 100px; height: 30px; background-color: <?php echo esc_attr($party_colors[$party] ?? '#FFFFFF'); ?>; border: 1px solid #ddd;"></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" 
                               name="update_party_colors" 
                               class="button button-primary" 
                               value="Save Colors">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_candidates_page() {
        global $wpdb;
        
        // Get filter values
        $election_date = isset($_GET['election_date']) ? sanitize_text_field($_GET['election_date']) : '';
        $race = isset($_GET['race']) ? sanitize_text_field($_GET['race']) : '';
        $party = isset($_GET['party']) ? sanitize_text_field($_GET['party']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build query
        $query = "SELECT * FROM " . self::$candidates_table;
        $where_clauses = array();
        $query_params = array();
        
        if ($election_date) {
            $where_clauses[] = "election_date = %s";
            $query_params[] = $election_date;
        }
        if ($race) {
            $where_clauses[] = "race_name = %s";
            $query_params[] = $race;
        }
        if ($party) {
            if ($party === 'none') {
                $where_clauses[] = "(party IS NULL OR party = '')";
            } else {
                $where_clauses[] = "party = %s";
                $query_params[] = $party;
            }
        }
        if ($search) {
            $where_clauses[] = "(candidate_name LIKE %s OR race_name LIKE %s)";
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        if (!empty($where_clauses)) {
            $query .= " WHERE " . implode(" AND ", $where_clauses);
        }
        
        // Add sorting
        $orderby = isset($_GET['orderby']) ? sanitize_sql_orderby($_GET['orderby']) : 'election_date';
        $order = isset($_GET['order']) ? sanitize_sql_orderby($_GET['order']) : 'DESC';
        
        if (!in_array($orderby, array('election_date', 'race_name', 'candidate_name', 'party'))) {
            $orderby = 'election_date';
        }
        if (!in_array($order, array('ASC', 'DESC'))) {
            $order = 'DESC';
        }
        
        $query .= " ORDER BY $orderby $order";
        
        // Get candidates - only use prepare if we have parameters
        $candidates = !empty($query_params) ? 
            $wpdb->get_results($wpdb->prepare($query, $query_params)) : 
            $wpdb->get_results($query);
        
        // Get unique values for filters
        $election_dates = $wpdb->get_col("SELECT DISTINCT election_date FROM " . self::$candidates_table . " ORDER BY election_date DESC");
        $races = $wpdb->get_col("SELECT DISTINCT race_name FROM " . self::$candidates_table . " ORDER BY race_name ASC");
        $parties = $wpdb->get_col("SELECT DISTINCT party FROM " . self::$candidates_table . " WHERE party IS NOT NULL AND party != '' ORDER BY party ASC");

        // Build sort URLs
        $current_url = add_query_arg(array());
        function build_sort_url($orderby_value) {
            $current_orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'election_date';
            $current_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
            
            $order = ($orderby_value === $current_orderby && $current_order === 'ASC') ? 'DESC' : 'ASC';
            return add_query_arg(array('orderby' => $orderby_value, 'order' => $order));
        }

        ?>
        <div class="wrap">
            <h1>Manage Candidates</h1>
            
            <!-- Search and Filters -->
            <div class="tablenav top">
                <form method="get" class="alignleft actions">
                    <input type="hidden" name="page" value="lcd-election-candidates">
                    
                    <select name="election_date">
                        <option value="">All Dates</option>
                        <?php foreach ($election_dates as $date): ?>
                            <option value="<?php echo esc_attr($date); ?>" <?php selected($election_date, $date); ?>>
                                <?php echo esc_html(date('F j, Y', strtotime($date))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="race">
                        <option value="">All Races</option>
                        <?php foreach ($races as $race_name): ?>
                            <option value="<?php echo esc_attr($race_name); ?>" <?php selected($race, $race_name); ?>>
                                <?php echo esc_html($race_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="party">
                        <option value="">All Parties</option>
                        <option value="none" <?php selected($party, 'none'); ?>>No Party Set</option>
                        <?php foreach ($parties as $party_name): ?>
                            <option value="<?php echo esc_attr($party_name); ?>" <?php selected($party, $party_name); ?>>
                                <?php echo esc_html($party_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search candidates...">
                    
                    <input type="submit" class="button" value="Filter">
                    <?php if ($election_date || $race || $party || $search): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lcd-election-candidates')); ?>" class="button">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="sortable <?php echo $orderby === 'election_date' ? 'sorted ' . strtolower($order) : 'desc'; ?>">
                            <a href="<?php echo esc_url(build_sort_url('election_date')); ?>">
                                <span>Election Date</span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $orderby === 'race_name' ? 'sorted ' . strtolower($order) : 'desc'; ?>">
                            <a href="<?php echo esc_url(build_sort_url('race_name')); ?>">
                                <span>Race</span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $orderby === 'candidate_name' ? 'sorted ' . strtolower($order) : 'desc'; ?>">
                            <a href="<?php echo esc_url(build_sort_url('candidate_name')); ?>">
                                <span>Candidate</span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $orderby === 'party' ? 'sorted ' . strtolower($order) : 'desc'; ?>">
                            <a href="<?php echo esc_url(build_sort_url('party')); ?>">
                                <span>Party</span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($candidates)): ?>
                        <tr>
                            <td colspan="5">No candidates found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($candidates as $candidate): ?>
                            <tr>
                                <td><?php echo esc_html(date('F j, Y', strtotime($candidate->election_date))); ?></td>
                                <td><?php echo esc_html($candidate->race_name); ?></td>
                                <td><?php echo esc_html($candidate->candidate_name); ?></td>
                                <td><?php echo esc_html($candidate->party ?: 'Not set'); ?></td>
                                <td>
                                    <button type="button" 
                                            class="button edit-party-button" 
                                            data-candidate-id="<?php echo esc_attr($candidate->id); ?>"
                                            data-candidate-name="<?php echo esc_attr($candidate->candidate_name); ?>"
                                            data-party="<?php echo esc_attr($candidate->party); ?>">
                                        Edit Party
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Modal for editing party -->
            <div id="edit-party-modal" class="lcd-modal" style="display: none;">
                <div class="lcd-modal-content">
                    <h2>Edit Party Affiliation</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('lcd_update_candidate_party'); ?>
                        <input type="hidden" name="candidate_id" id="modal-candidate-id">
                        
                        <p>
                            <strong>Candidate:</strong> 
                            <span id="modal-candidate-name"></span>
                        </p>
                        
                        <p>
                            <label for="party">Party Affiliation:</label><br>
                            <input type="text" name="party" id="modal-party" class="regular-text">
                        </p>
                        
                        <p>
                            <input type="submit" name="update_candidate_party" class="button button-primary" value="Update Party">
                            <button type="button" class="button cancel-modal">Cancel</button>
                        </p>
                    </form>
                </div>
            </div>

            <style>
                .lcd-modal {
                    display: none;
                    position: fixed;
                    z-index: 1000;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0,0,0,0.5);
                }

                .lcd-modal-content {
                    background-color: #fefefe;
                    margin: 15% auto;
                    padding: 20px;
                    border: 1px solid #888;
                    width: 50%;
                    max-width: 500px;
                    border-radius: 4px;
                }

                .tablenav {
                    margin: 1em 0;
                }
                .tablenav select,
                .tablenav input[type="search"] {
                    margin-right: 8px;
                    max-width: 200px;
                }
                .sortable {
                    cursor: pointer;
                }
                .sorted.asc .sorting-indicator:before {
                    content: "\f142";
                }
                .sorted.desc .sorting-indicator:before {
                    content: "\f140";
                }
                .sorting-indicator {
                    display: inline-block;
                    width: 10px;
                    height: 10px;
                    margin-left: 5px;
                }
            </style>

            <script>
                jQuery(document).ready(function($) {
                    $('.edit-party-button').click(function() {
                        var candidateId = $(this).data('candidate-id');
                        var candidateName = $(this).data('candidate-name');
                        var party = $(this).data('party');

                        $('#modal-candidate-id').val(candidateId);
                        $('#modal-candidate-name').text(candidateName);
                        $('#modal-party').val(party);
                        $('#edit-party-modal').show();
                    });

                    $('.cancel-modal').click(function() {
                        $('#edit-party-modal').hide();
                    });

                    // Close modal when clicking outside
                    $(window).click(function(e) {
                        if ($(e.target).hasClass('lcd-modal')) {
                            $('.lcd-modal').hide();
                        }
                    });
                });
            </script>
        </div>
        <?php
    }

    public function render_precincts_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle file upload
        $import_status = null;
        if (isset($_FILES['precinct_file']) && isset($_POST['lcd_precinct_nonce']) && 
            wp_verify_nonce($_POST['lcd_precinct_nonce'], 'lcd_precinct_upload')) {
            
            $import_status = $this->handle_precinct_file_upload();
        }

        // Get current precinct count
        global $wpdb;
        $precinct_count = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$precincts_table);
        $last_import = $wpdb->get_var("SELECT MAX(import_date) FROM " . self::$precincts_table);

        ?>
        <div class="wrap">
            <h1>Manage Precincts</h1>

            <?php if ($import_status): ?>
                <div class="notice notice-<?php echo $import_status['success'] ? 'success' : 'error'; ?>">
                    <p><?php echo nl2br(esc_html($import_status['message'])); ?></p>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2>Current Precinct Data</h2>
                <?php if ($precinct_count > 0): ?>
                    <p>Number of precinct parts: <?php echo esc_html($precinct_count); ?></p>
                    <p>Last import: <?php echo esc_html(date('F j, Y g:i a', strtotime($last_import))); ?></p>
                <?php else: ?>
                    <p>No precinct data imported yet.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Import Precinct Data</h2>
                <p>Upload the Districts-Precincts data as a CSV file to update precinct information.</p>
                <p><strong>Note:</strong> If you have an Excel file, please save it as CSV (Comma delimited) before uploading.</p>
                <p><strong>Note:</strong> The file download from the state website contains all counties, remove the counties you don't need before uploading.</p>
                <p><strong>Required Format:</strong><br>
                Column order: CountyCode, County, DistrictType, DistrictCode, DistrictName, PrecinctCode, PrecinctName, PrecinctPart</p>
                <p><strong>Warning:</strong> Uploading a new file will replace all existing precinct data.</p>

                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('lcd_precinct_upload', 'lcd_precinct_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="precinct_file">Select CSV File</label>
                            </th>
                            <td>
                                <input type="file" 
                                       name="precinct_file" 
                                       id="precinct_file" 
                                       accept=".csv"
                                       required>
                                <p class="description">Upload a CSV file exported from the Districts-Precincts Excel file.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" 
                               name="import_precincts" 
                               class="button button-primary" 
                               value="Import Precincts">
                    </p>
                </form>
            </div>

            <?php if ($precinct_count > 0): ?>
            <div class="card">
                <h2>Precinct List</h2>
                <?php
                $precincts = $wpdb->get_results(
                    "SELECT * FROM " . self::$precincts_table . " 
                    ORDER BY county_code, precinct_code, precinct_part 
                    LIMIT 100"
                );
                ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>County</th>
                            <th>Precinct Code</th>
                            <th>Precinct Name</th>
                            <th>District Type</th>
                            <th>District Name</th>
                            <th>Part</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($precincts as $precinct): ?>
                            <tr>
                                <td><?php echo esc_html($precinct->county_name); ?></td>
                                <td><?php echo esc_html($precinct->precinct_code); ?></td>
                                <td><?php echo esc_html($precinct->precinct_name); ?></td>
                                <td><?php echo esc_html($precinct->district_type); ?></td>
                                <td><?php echo esc_html($precinct->district_name); ?></td>
                                <td><?php echo esc_html($precinct->precinct_part); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($precincts) == 100): ?>
                    <p>Showing first 100 precincts.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handle_precinct_file_upload() {
        $file = $_FILES['precinct_file'];
        $import_status = array(
            'success' => false,
            'message' => '',
            'count' => 0
        );
        
        // Basic error checking
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $import_status['message'] = 'File upload failed';
            return $import_status;
        }

        // Verify file type
        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'csv') {
            $import_status['message'] = 'Please upload a CSV file';
            return $import_status;
        }

        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $temp_file = wp_handle_upload($file, array('test_form' => false));

            if (!$temp_file || isset($temp_file['error'])) {
                throw new Exception($temp_file['error']);
            }

            // Open CSV file
            $handle = fopen($temp_file['file'], 'r');
            if ($handle === false) {
                throw new Exception('Could not open CSV file');
            }

            global $wpdb;
            
            // Start transaction
            $wpdb->query('START TRANSACTION');

            // Clear existing data
            $wpdb->query("TRUNCATE TABLE " . self::$precincts_table);

            // Skip header row
            fgetcsv($handle);

            // Insert new data
            $insert_count = 0;
            $line = 2; // Start at line 2 since we skipped header
            $errors = array();
            
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 8) {
                    $errors[] = "Line $line: Invalid data format. Expected 8 columns.";
                    continue;
                }

                $inserted = $wpdb->insert(
                    self::$precincts_table,
                    array(
                        'county_code' => $row[0],
                        'county_name' => $row[1],
                        'district_type' => $row[2],
                        'district_code' => $row[3],
                        'district_name' => $row[4],
                        'precinct_code' => $row[5],
                        'precinct_name' => $row[6],
                        'precinct_part' => $row[7]
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
                );

                if ($inserted === false) {
                    if ($wpdb->last_error) {
                        $errors[] = "Line $line: " . $wpdb->last_error;
                    } else {
                        $errors[] = "Line $line: Failed to insert data";
                    }
                } else {
                    $insert_count++;
                }

                $line++;
            }

            fclose($handle);

            if (empty($errors)) {
                $wpdb->query('COMMIT');
                $import_status['success'] = true;
                $import_status['message'] = "Successfully imported $insert_count precincts.";
                $import_status['count'] = $insert_count;
            } else {
                $wpdb->query('ROLLBACK');
                $import_status['message'] = implode("\n", $errors);
            }

            // Clean up
            unlink($temp_file['file']);

        } catch (Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            $wpdb->query('ROLLBACK');
            $import_status['message'] = $e->getMessage();
        }

        return $import_status;
    }

    public function render_admin_page() {
        if (isset($_FILES['election_file'])) {
            $results = $this->process_upload();
        }
        ?>
        <div class="wrap">
            <h1>Election Results Import</h1>

            <?php if (isset($results)): ?>
                <div class="card">
                    <h2>Import Results</h2>
                    <table class="widefat">
                        <tr>
                            <th>New Records Added</th>
                            <td><?php echo esc_html($results['added'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <th>Records Updated</th>
                            <td><?php echo esc_html($results['updated'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <th>Records Skipped (Duplicates)</th>
                            <td><?php echo esc_html($results['skipped'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <th>Errors</th>
                            <td>
                                <?php 
                                if (!empty($results['errors'])) {
                                    if (is_array($results['errors'])) {
                                        echo '<ul class="ul-disc">';
                                        foreach ($results['errors'] as $error) {
                                            echo '<li>' . esc_html($error) . '</li>';
                                        }
                                        echo '</ul>';
                                    } else {
                                        echo esc_html($results['errors']);
                                    }
                                } else {
                                    echo 'None';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php if (!empty($results['debug'])): ?>
                        <tr>
                            <th>Debug Info</th>
                            <td>
                                <div style="max-height: 200px; overflow-y: auto;">
                                    <ul class="ul-disc">
                                        <?php foreach ($results['debug'] as $debug): ?>
                                            <li><?php echo esc_html($debug); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2>Upload Election Results CSV</h2>
                <p>Upload a CSV file containing election results. The file name should include the election date in YYYYMMDD format (e.g., 20241105.csv).</p>
                <p>The CSV should have the following columns in order:</p>
                <ol>
                    <li>Race Name</li>
                    <li>Candidate Name</li>
                    <li>Precinct Name</li>
                    <li>Precinct Number</li>
                    <li>Vote Count</li>
                </ol>
                
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('lcd_election_results_upload', 'lcd_election_results_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="election_file">Select CSV File</label></th>
                            <td>
                                <input type="file" 
                                       name="election_file" 
                                       id="election_file" 
                                       accept=".csv"
                                       required>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" 
                               name="lcd_upload_election_results" 
                               class="button button-primary" 
                               value="Upload and Import">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_voter_data_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $voter_import_status = null;
        $history_import_status = null;

        // Handle voter data file upload
        if (isset($_POST['upload_voter_data']) && check_admin_referer('upload_voter_data')) {
            if (!isset($_FILES['voter_file'])) {
                $voter_import_status = array(
                    'success' => false,
                    'message' => 'No file was uploaded.'
                );
            } else {
                $voter_import_status = $this->handle_voter_file_upload();
            }
        }

        // Handle voter history file upload
        if (isset($_POST['upload_voter_history']) && check_admin_referer('upload_voter_history')) {
            if (!isset($_FILES['voter_history_file'])) {
                $history_import_status = array(
                    'success' => false,
                    'message' => 'No file was uploaded.'
                );
            } else {
                $history_import_status = $this->handle_voter_history_file_upload();
            }
        }

        // Get current voter counts
        global $wpdb;
        $voter_count = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$voters_table);
        $history_count = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$voter_history_table);

        ?>
        <div class="wrap">
            <h1>Manage Voter Data</h1>

            <?php if ($voter_import_status): ?>
                <div class="notice notice-<?php echo $voter_import_status['success'] ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($voter_import_status['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($history_import_status): ?>
                <div class="notice notice-<?php echo $history_import_status['success'] ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($history_import_status['message']); ?></p>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2>Current Data Status</h2>
                <p>
                    <strong>Total Registered Voters:</strong> <?php echo number_format($voter_count); ?><br>
                    <strong>Total Voting History Records:</strong> <?php echo number_format($history_count); ?>
                </p>
            </div>

            <div class="card">
                <h2>Upload Voter Registration Data</h2>
                <p>Upload a pipe-delimited (|) text file containing voter registration data. The file should include the following columns in order:</p>
                <ol>
                    <li>StateVoterID</li>
                    <li>FirstName</li>
                    <li>MiddleName</li>
                    <li>LastName</li>
                    <li>NameSuffix</li>
                    <li>BirthYear</li>
                    <li>Gender</li>
                    <li>... (columns 8-17)</li>
                    <li>PrecinctCode (column 18)</li>
                    <li>PrecinctPart (column 19)</li>
                    <li>LegislativeDistrict (column 20)</li>
                    <li>CongressionalDistrict (column 21)</li>
                    <li>... (columns 22-28)</li>
                    <li>RegistrationDate (column 29)</li>
                    <li>LastVoted (column 30)</li>
                    <li>StatusCode (column 31)</li>
                </ol>
                <p><strong>Note:</strong> Uploading a new file will replace all existing voter registration data.</p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('upload_voter_data'); ?>
                    <input type="file" name="voter_file" accept=".txt" required>
                    <p class="submit">
                        <input type="submit" name="upload_voter_data" class="button button-primary" value="Upload Voter Data">
                    </p>
                </form>
            </div>

            <div class="card">
                <h2>Upload Voter History Data</h2>
                <p>Upload a pipe-delimited (|) text file containing voter history data. The file should include the following columns in order:</p>
                <ol>
                    <li>VoterHistoryID</li>
                    <li>CountyCode</li>
                    <li>CountyCodeVoting</li>
                    <li>StateVoterID</li>
                    <li>ElectionDate</li>
                </ol>
                <p><strong>Note:</strong> Voter history records are cumulative. Duplicate records will be skipped.</p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('upload_voter_history'); ?>
                    <input type="file" name="voter_history_file" accept=".txt" required>
                    <p class="submit">
                        <input type="submit" name="upload_voter_history" class="button button-primary" value="Upload Voter History">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    private function handle_voter_file_upload() {
        $file = $_FILES['voter_file'];
        $import_status = array(
            'success' => false,
            'message' => '',
            'count' => 0,
            'warnings' => array()
        );
        
        // Basic error checking
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $import_status['message'] = 'File upload failed';
            return $import_status;
        }

        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $temp_file = wp_handle_upload($file, array('test_form' => false));

            if (!$temp_file || isset($temp_file['error'])) {
                throw new Exception($temp_file['error']);
            }

            // Open file
            $handle = fopen($temp_file['file'], 'r');
            if ($handle === false) {
                throw new Exception('Could not open voter data file');
            }

            global $wpdb;
            
            // Start transaction
            $wpdb->query('START TRANSACTION');

            // Clear existing data
            $wpdb->query("TRUNCATE TABLE " . self::$voters_table);

            // Skip header row
            $header = fgetcsv($handle, 0, '|');
            if (!$header) {
                throw new Exception('Could not read header row');
            }

            // Insert new data
            $insert_count = 0;
            $line = 2; // Start at line 2 since we skipped header
            $errors = array();
            
            while (($row = fgetcsv($handle, 0, '|')) !== false) {
                if (count($row) < 32) { // Number of expected columns
                    $errors[] = "Line $line: Invalid data format. Expected 32 columns, got " . count($row);
                    continue;
                }

                // Data sanitization and validation with explicit column mapping
                $state_voter_id = $row[0];
                $first_name = substr(sanitize_text_field($row[1]), 0, 100);
                $middle_name = $row[2] ? substr(sanitize_text_field($row[2]), 0, 255) : null;
                $last_name = substr(sanitize_text_field($row[3]), 0, 100);
                $name_suffix = !empty($row[4]) ? substr(sanitize_text_field($row[4]), 0, 10) : null;
                $birth_year = is_numeric($row[5]) ? intval($row[5]) : null;
                $gender = strlen($row[6]) > 0 ? substr($row[6], 0, 1) : null;
                $precinct_code = substr($row[19], 0, 20);  // PrecinctCode
                $precinct_part = substr($row[20], 0, 20);  // PrecinctPart
                $legislative_district = substr($row[21], 0, 20);  // LegislativeDistrict
                $congressional_district = substr($row[22], 0, 20);  // CongressionalDistrict
                $registration_date = !empty($row[30]) ? date('Y-m-d', strtotime($row[30])) : null;  // Registrationdate
                $last_voted = !empty($row[31]) ? date('Y-m-d', strtotime($row[31])) : null;  // LastVoted
                $status_code = substr($row[32], 0, 20);  // StatusCode

                $inserted = $wpdb->insert(
                    self::$voters_table,
                    array(
                        'state_voter_id' => $state_voter_id,
                        'first_name' => $first_name,
                        'middle_name' => $middle_name,
                        'last_name' => $last_name,
                        'name_suffix' => $name_suffix,
                        'birth_year' => $birth_year,
                        'gender' => $gender,
                        'registration_date' => $registration_date,
                        'last_voted' => $last_voted,
                        'status_code' => $status_code,
                        'precinct_code' => $precinct_code,
                        'precinct_part' => $precinct_part,
                        'legislative_district' => $legislative_district,
                        'congressional_district' => $congressional_district
                    ),
                    array(
                        '%d', '%s', '%s', '%s', '%s', '%d', '%s',
                        '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                    )
                );

                if ($inserted === false) {
                    if ($wpdb->last_error) {
                        $errors[] = "Line $line: " . $wpdb->last_error;
                    } else {
                        $errors[] = "Line $line: Failed to insert voter data";
                    }
                } else {
                    $insert_count++;
                }

                $line++;
            }

            fclose($handle);

            if (empty($errors)) {
                $wpdb->query('COMMIT');
                $import_status['success'] = true;
                $import_status['message'] = "Successfully imported $insert_count voters.";
                $import_status['count'] = $insert_count;
            } else {
                $wpdb->query('ROLLBACK');
                $import_status['message'] = implode("\n", $errors);
            }

            // Clean up
            unlink($temp_file['file']);

        } catch (Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            $wpdb->query('ROLLBACK');
            $import_status['message'] = $e->getMessage();
        }

        return $import_status;
    }

    private function handle_voter_history_file_upload() {
        $file = $_FILES['voter_history_file'];
        $import_status = array(
            'success' => false,
            'message' => '',
            'count' => 0,
            'skipped' => 0,
            'errors' => array()
        );
        
        // Basic error checking
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $import_status['message'] = 'File upload failed';
            return $import_status;
        }

        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $temp_file = wp_handle_upload($file, array('test_form' => false));

            if (!$temp_file || isset($temp_file['error'])) {
                throw new Exception($temp_file['error']);
            }

            // Open file
            $handle = fopen($temp_file['file'], 'r');
            if ($handle === false) {
                throw new Exception('Could not open voter history file');
            }

            global $wpdb;
            
            // Start transaction
            $wpdb->query('START TRANSACTION');

            // Skip header row
            $header = fgetcsv($handle, 0, '|');
            if (!$header) {
                throw new Exception('Could not read header row');
            }

            // Get all existing voter IDs for validation
            $existing_voters = $wpdb->get_col("SELECT state_voter_id FROM " . self::$voters_table);
            if (!$existing_voters) {
                throw new Exception('No registered voters found in the database. Please import voter registration data first.');
            }
            $existing_voters = array_flip($existing_voters); // Convert to hash map for faster lookup

            // Insert new data
            $insert_count = 0;
            $skipped_count = 0;
            $line = 2; // Start at line 2 since we skipped header
            $errors = array();
            $batch_size = 1000;
            $values = array();
            $placeholders = array();
            
            while (($row = fgetcsv($handle, 0, '|')) !== false) {
                if (count($row) < 5) {
                    $errors[] = "Line $line: Invalid data format. Expected 5 columns.";
                    continue;
                }

                $voter_history_id = $row[0];
                $state_voter_id = $row[3];
                
                // Skip if voter doesn't exist
                if (!isset($existing_voters[$state_voter_id])) {
                    $skipped_count++;
                    continue;
                }

                // Check if history record already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM " . self::$voter_history_table . " WHERE voter_history_id = %d LIMIT 1",
                    $voter_history_id
                ));

                if ($exists) {
                    $skipped_count++;
                    continue;
                }

                $election_date = date('Y-m-d', strtotime($row[4]));

                // Add to batch
                array_push($values, 
                    $voter_history_id,
                    $state_voter_id,
                    $row[1], // county_code
                    $row[2], // county_code_voting
                    $election_date
                );
                $placeholders[] = "(%d, %d, %s, %s, %s)";

                // Insert batch if we've reached batch size
                if (count($placeholders) >= $batch_size) {
                    $result = $this->insert_history_batch($placeholders, $values);
                    if ($result === false) {
                        $errors[] = "Error inserting batch near line $line: " . $wpdb->last_error;
                    } else {
                        $insert_count += $result;
                    }
                    $placeholders = array();
                    $values = array();
                }

                $line++;
            }

            // Insert any remaining records
            if (!empty($placeholders)) {
                $result = $this->insert_history_batch($placeholders, $values);
                if ($result === false) {
                    $errors[] = "Error inserting final batch: " . $wpdb->last_error;
                } else {
                    $insert_count += $result;
                }
            }

            fclose($handle);

            if (empty($errors)) {
                $wpdb->query('COMMIT');
                $import_status['success'] = true;
                $import_status['message'] = "Successfully imported $insert_count voting history records. Skipped $skipped_count records.";
                $import_status['count'] = $insert_count;
                $import_status['skipped'] = $skipped_count;
            } else {
                $wpdb->query('ROLLBACK');
                $import_status['message'] = implode("\n", $errors);
                $import_status['errors'] = $errors;
            }

            // Clean up
            unlink($temp_file['file']);

        } catch (Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            $wpdb->query('ROLLBACK');
            $import_status['message'] = $e->getMessage();
            $import_status['errors'][] = $e->getMessage();
        }

        return $import_status;
    }

    private function insert_history_batch($placeholders, $values) {
        global $wpdb;
        
        $sql = $wpdb->prepare(
            "INSERT INTO " . self::$voter_history_table . "
            (voter_history_id, state_voter_id, county_code, county_code_voting, election_date)
            VALUES " . implode(', ', $placeholders),
            $values
        );

        return $wpdb->query($sql);
    }
}

// Initialize the plugin
function lcd_election_results_init() {
    $plugin = LCD_Election_Results::get_instance();
}
add_action('plugins_loaded', 'lcd_election_results_init');

// Activation hook
register_activation_hook(__FILE__, array('LCD_Election_Results', 'activate')); 