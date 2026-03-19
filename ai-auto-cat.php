<?php
/**
 * Plugin Name: BoardingAI Auto Cat [Release Candidate]
 * Description: This plugin automatically assigns categories to posts based on their content. It analyzes the written blog content and categorizes it according to a preset list of categories. AI examines the main themes and areas of focus in the content and assigns up to three relevant categories. The plugin provides an admin interface to process all posts in batches and assign categories in an automated manner.
 * Version: 1.0.0
 * Author: BoardingAI
 * Author URI: https://boardingai.wpengine.com
 */

// AI API URL
define('AI_API_URL', 'https://api.openai.com/v1/chat/completions');

function send_to_ai_api($post_content, $available_slugs) {
    error_log('Sending post content to AI API...');
    
    $api_key = get_option('ai_auto_cat_api_key');
    if (empty($api_key)) {
        error_log('Error: AI API Key is not set.');
        return false;
    }
    
    // Create a dynamic prompt with the available slugs
    $system_prompt = sprintf(
        'You are an AI content categorization assistant. Analyze the provided blog post content to identify its main topic(s), theme(s), and area(s) of focus. Use the preset categorization list to assign up to three relevant categories per post, prioritizing the most appropriate category first.\n\n# Steps\n\n1. **Read and Analyze**: Carefully examine the content to grasp the main ideas, themes, and specific topics discussed.\n2. **Category Identification**:\n - Compare the contents topics with the categories in the preset list.\n - Select the three most relevant categories that accurately represent the focus of the post.\n - Ensure the first category in your list is the most relevant to the posts content.\n - If the content fits multiple categories, use a comma-separated list in descending order of relevance.\n3. **Hierarchy Considerations**: Remember that reviews is a parent category to airline-reviews, hotel-reviews, and lounge-reviews. Use the subcategory when a specific type of review is discussed.\n\n# Output Format\n\nProvide a single line output consisting of a comma-separated list of a maximum of three categories, starting with the most relevant category slug. \n\n# Examples\n\n- Input: A blog post about airline services and passenger experiences.\n- Output: airlines, airline-reviews, travel\n\n- Input: A detailed review of a new hotel in Paris.\n- Output: hotel-reviews, hotels, travel\n\n- Input: An article covering a recent industry conference.\n- Output: industry-news, news, travel\n\n# Notes\n\n- Ensure to accurately reflect the contents emphasis using the most specific categories available.\n- Maintain a consistent order of relevance with the primary category listed first.\n\n`Preset Categorization List`:\n```\n%s\n```',
        implode(', ', $available_slugs)
    );

    $headers = array(
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json'
    );

    $body = array(
        'model' => 'gpt-4o',
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $system_prompt
            ),
            array(
                'role' => 'user',
                'content' => $post_content
            )
        ),
        'temperature' => 1,
        'max_tokens' => 4095
    );

    $response = wp_remote_post(AI_API_URL, array(
        'headers' => $headers,
        'body' => json_encode($body)
    ));

    if (is_wp_error($response)) {
        error_log('Error sending post content to AI API: ' . $response->get_error_message());
        return false;
    }

    $response_body = wp_remote_retrieve_body($response);
    $response_data = json_decode($response_body);
    error_log('Received response from AI API');
    return $response_data;
}

function process_posts_batch() {
    $logs = array(); // Array to store logs

    $logs[] = 'Processing posts batch...';
    $nonce = $_POST['nonce'];
    $category_slug = $_POST['category_slug'];
    $batch_size = $_POST['batch_size'];

    if (!wp_verify_nonce($nonce, 'process_posts_batch')) {
        $logs[] = 'Nonce verification failed';
        wp_send_json_error(array('logs' => $logs));
        die();
    }
    $logs[] = 'Nonce verification passed';

    // Fetch all existing category slugs
    $site_slugs = get_terms(array(
        'taxonomy' => 'category',
        'hide_empty' => false,
        'fields' => 'slugs'
    ));

    $logs[] = 'Available category slugs: ' . implode(', ', $site_slugs);

    // Process a batch of posts
    $args = array(
        'post_type' => 'post',
        'posts_per_page' => $batch_size,
        'category_name' => $category_slug,
        'meta_query' => array(
            array(
                'key' => 'ai_auto_cat_processed',
                'compare' => 'NOT EXISTS'
            )
        )
    );

    $query = new WP_Query($args);
    $post_count = $query->post_count;
    $logs[] = 'Fetched ' . $post_count . ' posts for processing';
    $processed_count = 0;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $post_content = get_the_content();
            $logs[] = 'Processing post ID: ' . $post_id;

            $response_data = send_to_ai_api($post_content, $site_slugs);

            if ($response_data && isset($response_data->choices[0]->message->content)) {
                $categories_before = wp_get_post_categories($post_id, array('fields' => 'slugs'));
                $categories = explode(',', $response_data->choices[0]->message->content);

                $logs[] = 'AI response for post ID ' . $post_id . ': ' . $response_data->choices[0]->message->content;

                $category_ids = array();
                foreach ($categories as $category) {
                    $term = get_term_by('slug', trim($category), 'category');
                    if ($term) {
                        $category_ids[] = $term->term_id;
                    }
                }

                if (!empty($category_ids)) {
                    wp_set_post_categories($post_id, $category_ids, false);
                    $logs[] = 'Set categories for post ID: ' . $post_id;
                    update_post_meta($post_id, 'ai_auto_cat_processed', true);
                    $categories_after = wp_get_post_categories($post_id, array('fields' => 'slugs'));
                    $logs[] = 'Categories before: ' . implode(', ', $categories_before);
                    $logs[] = 'Categories after: ' . implode(', ', $categories_after);
                    $processed_count++;
                }
            } else {
                $logs[] = 'No response data or no categories in response for post ID: ' . $post_id;
            }
        }
    } else {
        $logs[] = 'No posts found for processing';
    }

    wp_reset_postdata();
    $logs[] = 'Finished batch processing of posts. Processed: ' . $processed_count . ', Successfully categorized: ' . $processed_count . ', Errors: ' . ($post_count - $processed_count);

    // Send logs to the client
    wp_send_json_success(array('logs' => $logs));
}
add_action('wp_ajax_process_posts_batch', 'process_posts_batch');
add_action('wp_ajax_nopriv_process_posts_batch', 'process_posts_batch');

function move_posts_between_categories() {
    error_log('Moving posts between categories...');
    $nonce = $_POST['nonce'];
    $old_slug = $_POST['old_slug'];
    $new_slug = $_POST['new_slug'];
    if (!wp_verify_nonce($nonce, 'move_posts_between_categories')) {
        error_log('Nonce verification failed');
        die('Nonce verification failed');
    }
    error_log('Nonce verification passed');

    // Get all posts in the old category
    $args = array(
        'category_name' => $old_slug,
        'posts_per_page' => -1
    );
    $query = new WP_Query($args);

    // Get the term ID of the new category
    $new_term = get_term_by('slug', $new_slug, 'category');
    if (!$new_term) {
        error_log('New category not found');
        return;
    }

    // Loop through the posts and update their categories
    $post_count = $query->post_count;
    error_log('Moving ' . $post_count . ' posts from category ' . $old_slug . ' to ' . $new_slug);
    $moved_count = 0;
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();

            // Remove the old category
            wp_remove_object_terms(get_the_ID(), $old_slug, 'category');

            // Add the new category
            wp_set_object_terms(get_the_ID(), $new_term->term_id, 'category', true);

            $moved_count++;
        }
    }

    wp_reset_postdata();

    error_log('Finished moving posts between categories. Moved: ' . $moved_count);
}
add_action('wp_ajax_move_posts_between_categories', 'move_posts_between_categories');
add_action('wp_ajax_nopriv_move_posts_between_categories', 'move_posts_between_categories');

// Register settings
function ai_auto_cat_register_settings() {
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_api_key');
}
add_action('admin_init', 'ai_auto_cat_register_settings');

// Admin page content
function ai_auto_cat_admin_page() {
    $process_nonce = wp_create_nonce('process_posts_batch');
    $move_nonce = wp_create_nonce('move_posts_between_categories');
    $export_nonce = wp_create_nonce('ai_auto_cat_export_data');
    $import_nonce = wp_create_nonce('ai_auto_cat_import_data');
    $reset_posts_nonce = wp_create_nonce('reset_posts'); // Add this line
    error_log('Nonces created');

    // Fetch all existing category slugs
    $categories = get_categories(array(
        'hide_empty' => false,
        'fields' => 'slugs'
    ));
    ?>
    <div class="wrap">
        <h1>AI Auto Cat</h1>
        <p>This plugin automatically assigns categories to posts based on their content. It analyzes the written blog content and categorizes it according to a preset list of categories. AI examines the main themes and areas of focus in the content and assigns up to three relevant categories.</p>
        
        <h2>Plugin Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields('ai_auto_cat_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                <th scope="row">OpenAI API Key</th>
                <td><input type="password" name="ai_auto_cat_api_key" value="<?php echo esc_attr(get_option('ai_auto_cat_api_key')); ?>" style="width: 300px;" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2>Process Posts</h2>
        <p>Select a category slug to process only posts in that category. Leave blank to process all posts.</p>
        <select id="category-slug">
            <option value="">Select Category Slug</option>
            <?php foreach ($categories as $category) : ?>
                <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
            <?php endforeach; ?>
        </select>
        <p>Enter the number of posts to process at a time. Minimum 1, maximum 500.</p>
        <input type="number" id="batch-size" min="1" max="500" value="10">
        <button id="process-posts" class="button button-primary">Process Posts</button>
        
        <h2>Move Posts Between Categories</h2>
        <p>Select the old and new category slugs to move posts from one category to another.</p>
        <select id="old-category-slug">
            <option value="">Select Old Category Slug</option>
            <?php foreach ($categories as $category) : ?>
                <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
            <?php endforeach; ?>
        </select>
        <select id="new-category-slug">
            <option value="">Select New Category Slug</option>
            <?php foreach ($categories as $category) : ?>
                <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
            <?php endforeach; ?>
        </select>
        <button id="move-posts" class="button button-primary">Move Posts</button>
        
        <h2>Export/Import Data</h2>
        <p>Select the category slugs to export/import data for. Hold Ctrl (Windows) or Command (Mac) and click to select multiple categories.</p>
        <select id="export-import-category-slugs" multiple size="10">
            <?php foreach ($categories as $category) : ?>
                <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
            <?php endforeach; ?>
        </select>
        <button id="export-data" class="button button-primary">Export Data</button>
        <button id="import-data" class="button button-primary">Import Data</button>
        
        <h2>Reset Posts</h2> <p>Click the button below to reset the processed status of all posts. This will allow them to be processed again.</p>
        <button id="reset-posts" class="button button-primary">Reset Posts</button>
        
        <div id="message-container"></div>
    </div>
    <style>
        .wrap {
            max-width: 800px;
            margin: 0 auto;
        }

        .wrap h1, .wrap h2 {
            margin-top: 20px;
        }

        .wrap p {
            margin-bottom: 20px;
        }

        .button-primary {
            margin-bottom: 20px;
        }
    </style>
    <script type="text/javascript">
        document.getElementById('process-posts').addEventListener('click', function() {
            console.log('Processing posts...');
            var categorySlug = document.getElementById('category-slug').value;
            var batchSize = document.getElementById('batch-size').value;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    var response = JSON.parse(this.responseText);
                    if (response.success) {
                        response.data.logs.forEach(function(log) {
                            console.log(log);
                        });
                    } else {
                        console.error('Error:', response.data.logs.join('\n'));
                    }
                } else {
                    console.error('Server error');
                }
            };
            xhr.onerror = function() {
                console.error('Connection error');
            };
            xhr.send('action=process_posts_batch&nonce=<?php echo $process_nonce; ?>&category_slug=' + categorySlug + '&batch_size=' + batchSize);
        });

        document.getElementById('move-posts').addEventListener('click', function() {
            console.log('Moving posts...');
            var oldSlug = document.getElementById('old-category-slug').value;
            var newSlug = document.getElementById('new-category-slug').value;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    console.log('Posts moved');
                } else {
                    console.error('Server error');
                }
            };
            xhr.onerror = function() {
                console.error('Connection error');
            };
            xhr.send('action=move_posts_between_categories&nonce=<?php echo $move_nonce; ?>&old_slug=' + oldSlug + '&new_slug=' + newSlug);
        });

        document.getElementById('export-data').addEventListener('click', function() {
            console.log('Exporting data...');
            var slugs = Array.from(document.getElementById('export-import-category-slugs').selectedOptions).map(option => option.value);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    console.log('Export completed');
                } else {
                    console.error('Server error');
                }
            };
            xhr.onerror = function() {
                console.error('Connection error');
            };
            xhr.send('action=ai_auto_cat_export_data&nonce=<?php echo $export_nonce; ?>&slugs=' + JSON.stringify(slugs));
        });

        document.getElementById('import-data').addEventListener('click', function() {
            console.log('Importing data...');
            var slugs = Array.from(document.getElementById('export-import-category-slugs').selectedOptions).map(option => option.value);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    console.log('Import completed');
                } else {
                    console.error('Server error');
                }
            };
            xhr.onerror = function() {
                console.error('Connection error');
            };
            xhr.send('action=ai_auto_cat_import_data&nonce=<?php echo $import_nonce; ?>&slugs=' + JSON.stringify(slugs));
        });

        document.getElementById('reset-posts').addEventListener('click', function() {
            console.log('Resetting posts...');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    console.log('Posts reset');
                } else {
                    console.error('Server error');
                }
            };
            xhr.onerror = function() {
                console.error('Connection error');
            };
            xhr.send('action=reset_posts&nonce=<?php echo $reset_posts_nonce; ?>');
        });

        // Display success message
        function showSuccessMessage(message) {
            var messageContainer = document.getElementById('message-container');
            var successMessage = document.createElement('p');
            successMessage.classList.add('success-message');
            successMessage.textContent = message;
            messageContainer.appendChild(successMessage);
        }

        // Display error message
        function showErrorMessage(message) {
            var messageContainer = document.getElementById('message-container');
            var errorMessage = document.createElement('p');
            errorMessage.classList.add('error-message');
            errorMessage.textContent = message;
            messageContainer.appendChild(errorMessage);
        }
    </script>
    <?php
}

// Create an admin page
function ai_auto_cat_admin_menu() {
    add_submenu_page(
        'boardingpack',                // This is the BoardingPack plugin's main menu slug
        'BoardingAI Auto Cat',         // The text to be displayed in the title bar of the browser
        'BoardingAI Auto Cat',         // The text to be used for the menu in the admin sidebar
        'manage_options',              // The capability required for this menu to be displayed to the user
        'ai-auto-cat',                 // The slug name to refer to this menu by
        'ai_auto_cat_admin_page'       // The function to be called to output the content for this page
    );
    error_log('BoardingAI Auto Cat admin page created');
}
add_action('admin_menu', 'ai_auto_cat_admin_menu', 999); // Set a high priority to add it at the end

function ai_auto_cat_export_data() {
    $slugs = json_decode(stripslashes($_POST['slugs'])); // Get slugs from request
    error_log('Slugs: ' . print_r($slugs, true)); // Log the slugs
    $category_name = implode(',', $slugs); // Create a comma-separated string of slugs
    error_log('Category name: ' . $category_name); // Log the category name
    $batch_size = 500; // Number of posts to process at a time
    $offset = 0; // Start from the first post
    $data = array();

    while (true) {
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
        );

        if (in_array('uncategorized', $slugs)) {
            $args['cat'] = get_cat_ID('uncategorized'); // Use category ID for 'uncategorized'
        } else {
            $args['category_name'] = implode(',', $slugs); // Only get posts from these categories
        }

        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            error_log('No posts found'); // Add this line
            break; // No more posts to process
        }

        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $categories = wp_get_post_categories($post_id, array('fields' => 'slugs'));
            $data[] = array(
                'id' => $post_id,
                'slugs' => $categories
            );
        }

        wp_reset_postdata();

        $offset += $batch_size; // Move to the next batch of posts
    }

    // Export data to JSON file
    $file = plugin_dir_path(__FILE__) . 'export.json';

    error_log('Export file path: ' . $file); // Debugging statement

    if (!file_exists($file)) {
        if (touch($file)) {
            error_log('Export file created');
        } else {
            error_log('Error: Failed to create the file ' . $file);
        }
    } else {
        error_log('Export file exists');
    }

    if (!is_writable($file)) {
        if (chmod($file, 0666)) { // Sets the permissions to read and write for all
            error_log('Export file is now writable');
        } else {
            error_log('Error: Failed to set the permissions for the file ' . $file);
        }
    } else {
        error_log('Export file is writable');
    }

    $result = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

    if ($result === false) {
        error_log('Error: Failed to write to the file ' . $file);
    } else {
        error_log('Successfully wrote ' . $result . ' bytes to the file ' . $file);
    }

    // Send completion message to the client
    wp_send_json(array('message' => 'Export completed'));
}
add_action('wp_ajax_ai_auto_cat_export_data', 'ai_auto_cat_export_data');

function ai_auto_cat_import_data() {
    $slugs = json_decode(stripslashes($_POST['slugs'])); // Get slugs from request

    // Import data from JSON file
    $file = plugin_dir_path(__FILE__) . 'export.json';
    if (!file_exists($file)) {
        error_log('Export file not found');
        wp_send_json(array('error' => 'Export file not found'));
        return;
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON decode error: ' . json_last_error_msg());
        wp_send_json(array('error' => 'JSON decode error: ' . json_last_error_msg()));
        return;
    }

    $processed_posts = array(); // To store the processed post IDs

    foreach ($data as $item) {
        $post_id = $item['id'];

        // Check if post ID exists
        if (!get_post($post_id)) {
            error_log('Post ID ' . $post_id . ' does not exist');
            continue;
        }

        // Remove all categories from the post
        wp_set_object_terms($post_id, array(), 'category');
        error_log('Removed all categories from post ID: ' . $post_id);

        $new_slugs = $item['slugs'];

        // Get new category IDs
        $new_categories = array();
        foreach ($new_slugs as $slug) {
            $term = get_term_by('slug', $slug, 'category');
            if ($term) {
                $new_categories[] = $term->term_id;
            }
        }

        // Update post categories
        $result = wp_set_post_categories($post_id, $new_categories);
        if ($result === false) {
            error_log('Error updating categories for post ID: ' . $post_id);
        } else {
            error_log('Successfully updated categories for post ID: ' . $post_id);
        }

        $processed_posts[] = $post_id; // Add the processed post ID to the array
    }

    // Check for posts in the export file that are not present on the live site
    $missing_posts = array_diff(array_column($data, 'id'), $processed_posts);
    foreach ($missing_posts as $missing_post) {
        error_log('Post ID ' . $missing_post . ' exists in the export file but not on the live WordPress site');
    }

    // Check for posts on the live site that are not present in the export file
    $existing_posts = get_posts(array('fields' => 'ids'));
    $extra_posts = array_diff($existing_posts, array_column($data, 'id'));
    foreach ($extra_posts as $extra_post) {
        error_log('Post ID ' . $extra_post . ' exists on the live WordPress site but not in the export file');
    }

    // Send completion message to the client
    wp_send_json(array('message' => 'Import completed'));
}
add_action('wp_ajax_ai_auto_cat_import_data', 'ai_auto_cat_import_data');

function reset_posts() {
    global $wpdb;
    $nonce = $_POST['nonce'];
    if (!wp_verify_nonce($nonce, 'reset_posts')) {
        error_log('Nonce verification failed');
        die('Nonce verification failed');
    }
    error_log('Nonce verification passed');
    $result = $wpdb->delete($wpdb->postmeta, array('meta_key' => 'ai_auto_cat_processed'));
    if ($result === false) {
        error_log('Error resetting posts');
    } else {
        error_log('Successfully reset ' . $result . ' posts');
    }
}
add_action('wp_ajax_reset_posts', 'reset_posts');