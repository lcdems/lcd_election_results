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
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_admin'));
        
        // Removed map initialization and AJAX handlers
    }

    public static function activate() {
        global $wpdb;
        
        self::$results_table = $wpdb->prefix . 'election_results';
        self::$candidates_table = $wpdb->prefix . 'election_candidates';
        
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
            'Election Results',
            'Election Results',
            'manage_options',
            'lcd-election-results',
            array($this, 'render_admin_page'),
            'dashicons-chart-bar',
            30
        );

        add_submenu_page(
            'lcd-election-results',
            'Manage Candidates',
            'Manage Candidates',
            'manage_options',
            'lcd-election-candidates',
            array($this, 'render_candidates_page')
        );

        add_submenu_page(
            'lcd-election-results',
            'Party Colors',
            'Party Colors',
            'manage_options',
            'lcd-party-colors',
            array($this, 'render_party_colors_page')
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
}

// Initialize the plugin
function lcd_election_results_init() {
    $plugin = LCD_Election_Results::get_instance();
}
add_action('plugins_loaded', 'lcd_election_results_init');

// Activation hook
register_activation_hook(__FILE__, array('LCD_Election_Results', 'activate')); 