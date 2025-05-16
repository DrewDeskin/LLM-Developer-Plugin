<?php
/**
 * Plugin Name: Excel Uploader (Flush Output Per Block)
 * Description: Upload CSV, split by "Renny Wong", process and display each block's result immediately with output flush.
 * Version: 2.1
 * Author: Your Name
 */

add_action('admin_menu', function () {
    add_menu_page('Excel Uploader', 'Excel Uploader', 'manage_options', 'excel-uploader', 'excel_uploader_page');
});

function excel_uploader_page() {
    $error_message = '';
    $upload_success = false;

    if (!empty($_FILES['csv_file']['tmp_name'])) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $type = mime_content_type($tmp);

        if ($type === 'text/plain' || $type === 'text/csv') {
            $upload_success = true;
            $raw_text = file_get_contents($tmp);
            $blocks = explode("Renny Wong", $raw_text);
            $blocks = array_filter(array_map('trim', $blocks), function ($b) {
                return strlen($b) > 0;
            });

            echo '<h2>Processing Results</h2>';
            ob_flush(); flush();

            $index = 0;
            foreach ($blocks as $block) {
                echo '<div style="margin-bottom:20px;">';
                echo '<strong>Block ' . esc_html($index) . ':</strong><br>';
                echo '<em>Input:</em><pre>' . esc_html($block) . '</pre>';

                // Stage 1
                $stage1_prompt = "Given the following task description, return a JSON object with the key 'url' identifying the full URL of the relevant WordPress page:\n\n" . $block . "\n\nReturn only the JSON object. No extra text.";
                $stage1_response = send_to_gpt($stage1_prompt);
                echo '<em>Stage 1 Output:</em><pre>' . esc_html($stage1_response) . '</pre>';
                ob_flush(); flush();

                $post_info = json_decode($stage1_response, true);

                if (!is_array($post_info) || !isset($post_info['url'])) {
                    echo '<em>Execution Log:</em><pre>URL identification failed.</pre></div>';
                    ob_flush(); flush();
                    $index++;
                    sleep(2);
                    continue;
                }

                $slug = trim(parse_url($post_info['url'], PHP_URL_PATH), '/');
                $post = get_page_by_path($slug, OBJECT, ['post', 'page']);

                if (!$post) {
                    echo '<em>Execution Log:</em><pre>Post not found for URL: ' . esc_html($post_info['url']) . '</pre></div>';
                    ob_flush(); flush();
                    $index++;
                    sleep(2);
                    continue;
                }

                $post_content = $post->post_content;

                // Stage 2
                $stage2_prompt = "You are given a task and the current content of a WordPress page. Based on this, return JSON update_post commands.\n" .
                    "Each command must use the following format and field names exactly:\n" .
                    "{\"action\": \"update_post\", \"slug\": \"...\", \"field\": \"post_title|post_content|post_excerpt\", \"value\": \"...\"}\n" .
                    "Only return a JSON array. Do not include explanation or any other text.\n\n" .
                    "Task:\n" . $block . "\n\nPage URL:\n" . $post_info['url'] . "\n\nPage Content:\n" . $post_content;

                $stage2_response = send_to_gpt($stage2_prompt);
                echo '<em>Stage 2 Output:</em><pre>' . esc_html($stage2_response) . '</pre>';
                ob_flush(); flush();

                $execution_log = execute_gpt_commands_by_slug($stage2_response);
                echo '<em>Execution Log:</em><pre>' . esc_html($execution_log) . '</pre>';
                echo '</div>';
                ob_flush(); flush();

                $index++;
                sleep(2);
            }
        } else {
            $error_message = 'Invalid file type. Please upload a CSV.';
        }
    }

    echo '<h1>Upload CSV File</h1>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="file" name="csv_file" accept=".csv" required>'; 
    echo '<input type="submit" value="Upload">';
    echo '</form>';

    if ($error_message) {
        echo '<p style="color:red;">' . esc_html($error_message) . '</p>';
    }

    if ($upload_success) {
        echo '<p style="color:green;">File upload successful. Processing blocks split by "Renny Wong"...</p>';
    }
}

function send_to_gpt($prompt) {
    $api_key = 'OPEN_API_KEY';
    $data = [
        "model" => "gpt-3.5-turbo",
        "messages" => [["role" => "user", "content" => $prompt]]
    ];

    $response = wp_remote_post("https://api.openai.com/v1/chat/completions", [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode($data)
    ]);

    if (is_wp_error($response)) {
        return "Error: " . $response->get_error_message();
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['choices'][0]['message']['content'] ?? 'Error: No response from API.';
}

function execute_gpt_commands_by_slug($json_text) {
    $log = '';
    $commands = json_decode($json_text, true);

    if (!is_array($commands)) {
        return "Invalid JSON or structure.";
    }

    foreach ($commands as $cmd) {
        if (!isset($cmd['action'], $cmd['slug'], $cmd['field'], $cmd['value'])) {
            $log .= "Skipped command: missing required keys.\n";
            continue;
        }

        if ($cmd['action'] !== 'update_post') {
            $log .= "Unsupported action: {$cmd['action']}.\n";
            continue;
        }

        $post = get_page_by_path(ltrim($cmd['slug'], '/'), OBJECT, ['post', 'page']);
        if (!$post) {
            $log .= "Post not found with slug: {$cmd['slug']}.\n";
            continue;
        }

        $post_id = $post->ID;
        $field = $cmd['field'];
        $value = $cmd['value'];

        if (in_array($field, ['post_title', 'post_content', 'post_excerpt'], true)) {
            $update = [
                'ID' => $post_id,
                $field => $value
            ];
            wp_update_post($update);
            $log .= "Updated {$field} for slug '{$cmd['slug']}'.\n";
        } else {
            $log .= "Unsupported field: {$field}.\n";
        }
    }

    return $log;
}




