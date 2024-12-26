<?php defined('ABSPATH') or die('Direct access not allowed'); ?>

<div class="wrap">
    <h1>Election Results Import</h1>

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-<?php echo esc_attr($_GET['type'] ?? 'info'); ?> is-dismissible">
            <p><?php echo esc_html($_GET['message']); ?></p>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Upload Election Results CSV</h2>
        <p>Upload a CSV file containing election results. The file name should include the election date in YYYYMMDD format (e.g., 20241105.csv).</p>
        
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

    <?php if (isset($import_results)): ?>
    <div class="card">
        <h2>Import Results</h2>
        <table class="widefat">
            <tr>
                <th>New Records Added</th>
                <td><?php echo esc_html($import_results['added'] ?? 0); ?></td>
            </tr>
            <tr>
                <th>Records Updated</th>
                <td><?php echo esc_html($import_results['updated'] ?? 0); ?></td>
            </tr>
            <tr>
                <th>Records Skipped (Duplicates)</th>
                <td><?php echo esc_html($import_results['skipped'] ?? 0); ?></td>
            </tr>
            <tr>
                <th>Errors</th>
                <td>
                    <?php 
                    if (!empty($import_results['errors'])) {
                        if (is_array($import_results['errors'])) {
                            echo '<ul class="ul-disc">';
                            foreach ($import_results['errors'] as $error) {
                                echo '<li>' . esc_html($error) . '</li>';
                            }
                            echo '</ul>';
                        } else {
                            echo esc_html($import_results['errors']);
                        }
                    } else {
                        echo 'None';
                    }
                    ?>
                </td>
            </tr>
            <?php if (!empty($import_results['debug'])): ?>
            <tr>
                <th>Debug Info</th>
                <td>
                    <div style="max-height: 200px; overflow-y: auto;">
                        <ul class="ul-disc">
                            <?php foreach ($import_results['debug'] as $debug): ?>
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
</div> 