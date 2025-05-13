<?php
/**
 * Plugin Name: Excel Uploader (CSV Only)
 * Description: Upload and parse CSV files exported from Excel.
 * Version: 1.0
 * Author: Your Name
 */

// Add admin menu
add_action('admin_menu', function () {
    add_menu_page('Excel Uploader', 'Excel Uploader', 'manage_options', 'excel-uploader', 'excel_uploader_page');
});

function excel_uploader_page() {
    echo '<h1>Upload CSV File</h1>';

    if (!empty($_FILES['csv_file']['tmp_name'])) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $type = mime_content_type($tmp);

        if ($type === 'text/plain' || $type === 'text/csv') {
            echo '<h2>CSV Contents</h2>';
            echo '<table border="1" cellpadding="5">';
            if (($handle = fopen($tmp, 'r')) !== false) {
                while (($data = fgetcsv($handle)) !== false) {
                    echo '<tr>';
                    foreach ($data as $cell) {
                        echo '<td>' . esc_html($cell) . '</td>';
                    }
                    echo '</tr>';
                }
                fclose($handle);
            }
            echo '</table>';
        } else {
            echo '<p style="color:red;">Please upload a valid CSV file.</p>';
        }
    }

    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="file" name="csv_file" accept=".csv" required>';
    echo '<input type="submit" value="Upload">';
    echo '</form>';
}
