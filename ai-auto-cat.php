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

function send_to_ai_api($post_content, $available_categories, $available_tags, $min_cats, $max_cats, $min_tags, $max_tags) {
    error_log('Sending post content to AI API...');
    
    $api_key = get_option('ai_auto_cat_api_key');
    if (empty($api_key)) {
        error_log('Error: AI API Key is not set.');
        return false;
    }
    
    // Create strings from the arrays
    $available_categories_str = implode(', ', $available_categories);
    $available_tags_str = implode(', ', $available_tags);

    // Create a dynamic prompt based on whether tag processing is enabled or filtered out
    $has_tags = !empty($available_tags);
    $tag_instruction = $has_tags ? ", and between {$min_tags} and {$max_tags} relevant tags per post" : "";
    $tag_list_instruction = $has_tags ? "and tags in the preset lists" : "in the preset list";
    $tag_selection = $has_tags ? " - Select between {$min_tags} and {$max_tags} most relevant tags. If you cannot find enough highly relevant tags to meet the minimum of {$min_tags}, do your best to pick the most acceptable general tags to meet the threshold, but under no circumstances should you invent new tags.\n" : "";
    $exact_match = $has_tags ? "categories and tags" : "categories";
    $json_keys = $has_tags ? 'two keys: "categories" and "tags"' : 'one key: "categories"';
    $json_example = $has_tags ? "{\n  \"categories\": \"category1, category2, category3\",\n  \"tags\": \"tag1, tag2, tag3\"\n}" : "{\n  \"categories\": \"category1, category2, category3\"\n}";
    $specific_emphasis = $has_tags ? "categories and tags" : "categories";

    $tag_preset_block = $has_tags ? "\n`Preset Tag List`:\n```\n{$available_tags_str}\n```\n" : "";

    // Create a dynamic prompt with the available slugs and min/max limits using Heredoc
    $system_prompt = <<<PROMPT
You are an AI content categorization assistant. Analyze the provided blog post content to identify its main topic(s), theme(s), and area(s) of focus. Use the preset categorization list to assign between {$min_cats} and {$max_cats} relevant categories{$tag_instruction}. Prioritize the most appropriate ones first.

# Steps

1. **Read and Analyze**: Carefully examine the content to grasp the main ideas, themes, and specific topics discussed.
2. **Category and Tag Identification**:
 - Compare the contents topics with the categories {$tag_list_instruction}.
 - Select between {$min_cats} and {$max_cats} most relevant categories. If you cannot find enough highly relevant categories to meet the minimum of {$min_cats}, do your best to pick the most acceptable general categories to meet the threshold, but under no circumstances should you invent new categories.
{$tag_selection} - Ensure the first item in each list is the most relevant to the post content.
 - Only select {$exact_match} that EXACTLY match the provided list(s).
3. **Hierarchy Considerations**: Infer hierarchical relationships directly from the given slugs (e.g. if you see both "reviews" and "hotel-reviews", prioritize the more specific one if applicable). Do not rely on hardcoded rules.

# Output Format

Respond with a JSON object containing {$json_keys}. It should contain a comma-separated list of the slugs you selected.

```json
{$json_example}
```

# Notes

- Ensure to accurately reflect the contents emphasis using the most specific {$specific_emphasis} available.
- Maintain a consistent order of relevance with the primary item listed first.

`Preset Categorization List`:
```
{$available_categories_str}
```
{$tag_preset_block}
PROMPT;

    $headers = array(
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json'
    );

    $body = array(
        'model' => 'gpt-4o',
        'response_format' => array('type' => 'json_object'),
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

    $logs[] = 'Starting to process a new batch of posts...';
    $nonce = $_POST['nonce'];
    $category_slug = $_POST['category_slug'];
    $batch_size = $_POST['batch_size'];

    if (!wp_verify_nonce($nonce, 'process_posts_batch')) {
        $logs[] = 'Error: Security verification failed. Please refresh the page and try again.';
        wp_send_json_error(array('logs' => $logs));
        die();
    }

    // Fetch all existing category slugs
    $site_slugs = get_terms(array(
        'taxonomy' => 'category',
        'hide_empty' => false,
        'fields' => 'slugs'
    ));

    // Get min/max settings
    $min_cats = intval(get_option('ai_auto_cat_min_categories', 1));
    $max_cats = intval(get_option('ai_auto_cat_max_categories', 3));
    $min_tags = intval(get_option('ai_auto_cat_min_tags', 1));
    $max_tags = intval(get_option('ai_auto_cat_max_tags', 5));

    // Get candidate pool safeguards
    $enable_tags = get_option('ai_auto_cat_enable_tags', 1);
    $max_tag_candidates = intval(get_option('ai_auto_cat_max_tag_candidates', 50));
    $min_tag_usage = intval(get_option('ai_auto_cat_min_tag_usage', 1));

    $site_tags = array();

    if ($enable_tags) {
        // Fetch existing tags, ordering by count descending safely
        $tag_candidates = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => true,
            'number' => $max_tag_candidates,
            'orderby' => 'count',
            'order' => 'DESC',
            'fields' => 'all' // Fetch full objects so we can check count
        ));

        // Filter out tags that don't meet the minimum usage threshold
        if (!is_wp_error($tag_candidates) && is_array($tag_candidates)) {
            foreach ($tag_candidates as $tag) {
                if (isset($tag->count) && $tag->count >= $min_tag_usage && isset($tag->slug)) {
                    $site_tags[] = (string) $tag->slug; // strictly strings
                }
            }
        }

        if (empty($site_tags)) {
            $logs[] = 'Notice: Tagging is enabled, but no tags met the candidate pool requirements. AI will only assign categories.';
        }
    } else {
        $logs[] = 'Notice: Tagging is disabled in settings. AI will only assign categories.';
    }

    // Get enabled post types
    $enabled_post_types = get_option('ai_auto_cat_post_types', array('post'));
    if (empty($enabled_post_types)) {
        $logs[] = 'Error: No content types are selected in the plugin settings. Please select at least one content type to process.';
        wp_send_json_error(array('logs' => $logs));
        die();
    }

    // Process a batch of posts
    $args = array(
        'post_type' => $enabled_post_types,
        'post_status' => 'publish', // Only process published public content
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
    $logs[] = 'Found ' . $post_count . ' item(s) to inspect in this batch.';
    $processed_count = 0;
    $skipped_count = 0;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $raw_title = get_the_title();
            $post_content = get_the_content();
            $post_type = get_post_type();

            // 1. Normalize and validate title
            $clean_title = trim(strip_tags(html_entity_decode($raw_title)));
            $display_title = empty($clean_title) ? "(untitled {$post_type} #{$post_id})" : $clean_title;

            // 2. Normalize and validate content (Preflight check for URLs or extreme thin content)
            // Strip shortcodes, all HTML tags, and then explicitly remove full URLs to see what text remains
            $clean_content = strip_shortcodes($post_content);
            $clean_content = wp_strip_all_tags($clean_content);
            $clean_content = preg_replace('/\bhttps?:\/\/\S+/i', '', $clean_content); // Remove HTTP URLs
            $clean_content = trim($clean_content);

            // 3. Skip Logic
            if (empty($clean_title) && strlen($clean_content) < 50) {
                $logs[] = "Skipped {$post_type} #{$post_id} {$display_title}: Blank title and insufficient textual content.";
                update_post_meta($post_id, 'ai_auto_cat_processed', 'skipped_blank_and_thin');
                $skipped_count++;
                continue;
            }

            if (strlen($clean_content) < 50) {
                $logs[] = "Skipped {$post_type} #{$post_id} \"{$display_title}\": Insufficient textual content (e.g. URL-only or stub).";
                update_post_meta($post_id, 'ai_auto_cat_processed', 'skipped_low_content');
                $skipped_count++;
                continue;
            }

            $logs[] = "Analyzing {$post_type} #{$post_id}: \"{$display_title}\"...";

            $response_data = send_to_ai_api($post_content, $site_slugs, $site_tags, $min_cats, $max_cats, $min_tags, $max_tags);

            if ($response_data && isset($response_data->choices[0]->message->content)) {
                $ai_content = json_decode($response_data->choices[0]->message->content, true);

                if (is_array($ai_content)) {
                    $categories = isset($ai_content['categories']) ? explode(',', $ai_content['categories']) : array();
                    $logs[] = "AI suggested categories for {$post_type} #{$post_id}: " . (isset($ai_content['categories']) ? $ai_content['categories'] : 'None');

                    $category_ids = array();
                    foreach ($categories as $category) {
                        $term = get_term_by('slug', trim($category), 'category');
                        if ($term) {
                            $category_ids[] = $term->term_id;
                        }
                    }

                    $updated = false;

                    if (!empty($category_ids)) {
                        $categories_before = wp_get_post_categories($post_id, array('fields' => 'slugs'));
                        wp_set_post_categories($post_id, $category_ids, false);
                        $categories_after = wp_get_post_categories($post_id, array('fields' => 'slugs'));
                        $logs[] = "Successfully updated categories for {$post_type} #{$post_id}. Old: " . implode(', ', $categories_before) . ' -> New: ' . implode(', ', $categories_after);
                        $updated = true;
                    } else {
                        $logs[] = "Warning: No valid categories could be assigned for {$post_type} #{$post_id}.";
                    }

                    // Only process tags if tagging was enabled and candidates were found
                    if (!empty($site_tags)) {
                        $tags = isset($ai_content['tags']) ? explode(',', $ai_content['tags']) : array();
                        $logs[] = "AI suggested tags for {$post_type} #{$post_id}: " . (isset($ai_content['tags']) ? $ai_content['tags'] : 'None');

                        $tag_ids = array();
                        foreach ($tags as $tag) {
                            $term = get_term_by('slug', trim($tag), 'post_tag');
                            if ($term) {
                                $tag_ids[] = $term->term_id;
                            }
                        }

                        if (!empty($tag_ids)) {
                            $tags_before = wp_get_post_tags($post_id, array('fields' => 'slugs'));
                            wp_set_post_tags($post_id, $tag_ids, false);
                            $tags_after = wp_get_post_tags($post_id, array('fields' => 'slugs'));
                            $logs[] = "Successfully updated tags for {$post_type} #{$post_id}. Old: " . implode(', ', $tags_before) . ' -> New: ' . implode(', ', $tags_after);
                            $updated = true;
                        } else {
                            $logs[] = "Warning: No valid tags could be assigned for {$post_type} #{$post_id}.";
                        }
                    } else {
                        // Intentional empty block: tag assignment deliberately bypassed.
                    }

                    if ($updated) {
                        update_post_meta($post_id, 'ai_auto_cat_processed', true);
                        $processed_count++;
                    } else {
                        // Mark as processed but flag that assignment failed so it doesn't loop forever
                        update_post_meta($post_id, 'ai_auto_cat_processed', 'failed_assignment');
                    }
                } else {
                    $logs[] = "Error: Received invalid JSON format from AI for {$post_type} #{$post_id}.";
                    update_post_meta($post_id, 'ai_auto_cat_processed', 'failed_json');
                }
            } else {
                $logs[] = "Error: Could not get a valid response from AI for {$post_type} #{$post_id}.";
                update_post_meta($post_id, 'ai_auto_cat_processed', 'failed_api');
            }
        }
    } else {
        $logs[] = 'No eligible content found for processing in this category/batch.';
    }

    wp_reset_postdata();
    $error_count = $post_count - $processed_count - $skipped_count;
    $logs[] = "Finished processing this batch. Total inspected: {$post_count} | Successfully categorized: {$processed_count} | Skipped: {$skipped_count} | Errors/Failed: {$error_count}.";

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
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_min_categories', array('default' => 1));
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_max_categories', array('default' => 3));
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_min_tags', array('default' => 1));
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_max_tags', array('default' => 5));

    // Tag Candidate Pool Safeguards
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_enable_tags', array('default' => 1));
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_max_tag_candidates', array('default' => 50));
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_min_tag_usage', array('default' => 1));

    // Content Processing Scope
    register_setting('ai_auto_cat_settings_group', 'ai_auto_cat_post_types', array('default' => array('post')));
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

            <h3>General Scope</h3>
            <p>Select which types of content are eligible for auto-categorization.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Content Types to Process</th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><span>Content Types to Process</span></legend>
                            <?php
                            $saved_post_types = get_option('ai_auto_cat_post_types', array('post'));
                            $public_post_types = get_post_types(array('public' => true), 'objects');
                            foreach ($public_post_types as $pt) {
                                if ($pt->name === 'attachment') continue; // Exclude attachments by default as they usually lack robust content bodies
                                $checked = in_array($pt->name, $saved_post_types) ? 'checked="checked"' : '';
                                echo '<label><input type="checkbox" name="ai_auto_cat_post_types[]" value="' . esc_attr($pt->name) . '" ' . $checked . '> ' . esc_html($pt->labels->singular_name) . ' (' . esc_html($pt->name) . ')</label><br>';
                            }
                            ?>
                        </fieldset>
                        <p class="description">Only these selected post types will be batched to the AI. Note: AI categorization relies on text content; ensure you select post types that actually contain written content bodies.</p>
                    </td>
                </tr>
            </table>

            <h3>API Configuration</h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">OpenAI API Key</th>
                    <td><input type="password" name="ai_auto_cat_api_key" value="<?php echo esc_attr(get_option('ai_auto_cat_api_key')); ?>" style="width: 300px;" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Min/Max Categories</th>
                    <td>
                        <input type="number" name="ai_auto_cat_min_categories" value="<?php echo esc_attr(get_option('ai_auto_cat_min_categories', 1)); ?>" min="0" style="width: 70px;" />
                        <span> to </span>
                        <input type="number" name="ai_auto_cat_max_categories" value="<?php echo esc_attr(get_option('ai_auto_cat_max_categories', 3)); ?>" min="1" style="width: 70px;" />
                        <p class="description">Set the minimum and maximum number of categories the AI should assign per post.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Min/Max Tags</th>
                    <td>
                        <input type="number" name="ai_auto_cat_min_tags" value="<?php echo esc_attr(get_option('ai_auto_cat_min_tags', 1)); ?>" min="0" style="width: 70px;" />
                        <span> to </span>
                        <input type="number" name="ai_auto_cat_max_tags" value="<?php echo esc_attr(get_option('ai_auto_cat_max_tags', 5)); ?>" min="1" style="width: 70px;" />
                        <p class="description">Set the minimum and maximum number of tags the AI should assign per post.</p>
                    </td>
                </tr>
            </table>

            <h2>Tag Candidate Pool Safeguards</h2>
            <p>Control the tags that are sent to the AI for consideration. These settings help filter out unused or low-value tags, improving the AI's tag assignments. This does not delete any tags.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Enable Tagging</th>
                    <td>
                        <input type="hidden" name="ai_auto_cat_enable_tags" value="0" />
                        <input type="checkbox" name="ai_auto_cat_enable_tags" value="1" <?php checked(1, get_option('ai_auto_cat_enable_tags', 1), true); ?> />
                        <p class="description">Uncheck this to completely disable tag processing. The AI will only assign categories.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Max Tag Candidates</th>
                    <td>
                        <input type="number" name="ai_auto_cat_max_tag_candidates" value="<?php echo esc_attr(get_option('ai_auto_cat_max_tag_candidates', 50)); ?>" min="1" style="width: 70px;" />
                        <p class="description">The maximum number of existing tags to pass into the AI workflow. Limits the pool to the most frequently used tags. (Default: 50)</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Minimum Tag Usage</th>
                    <td>
                        <input type="number" name="ai_auto_cat_min_tag_usage" value="<?php echo esc_attr(get_option('ai_auto_cat_min_tag_usage', 1)); ?>" min="1" style="width: 70px;" />
                        <p class="description">A tag must be assigned to at least this many posts to be considered by the AI. Use this to ignore one-off or noisy tags. (Default: 1)</p>
                    </td>
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
        
        <div class="ai-auto-cat-console-container">
            <div class="ai-auto-cat-console-header">
                <h3>Process Logs</h3>
                <button type="button" id="clear-console" class="button">Clear Console</button>
            </div>
            <div id="ai-auto-cat-console" class="ai-auto-cat-console">
                <div class="log-entry" style="color: #646970;">Ready to process posts. Click 'Process Posts' to begin...</div>
            </div>
        </div>

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

        /* Simulated Console Styles */
        .ai-auto-cat-console-container {
            margin-top: 15px;
            margin-bottom: 30px;
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }

        .ai-auto-cat-console-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            border-bottom: 1px solid #c3c4c7;
            background: #f6f7f7;
        }

        .ai-auto-cat-console-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1d2327;
        }

        .ai-auto-cat-console {
            padding: 15px;
            height: 300px;
            overflow-y: auto;
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
            line-height: 1.6;
            color: #2c3338;
            background-color: #fafafa;
        }

        .ai-auto-cat-console .log-entry {
            margin-bottom: 6px;
            border-bottom: 1px solid #f0f0f1;
            padding-bottom: 4px;
        }

        .ai-auto-cat-console .log-entry:last-child {
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }

        .ai-auto-cat-console .log-time {
            color: #646970;
            margin-right: 8px;
            font-size: 11px;
        }

        .ai-auto-cat-console .log-success {
            color: #00a32a;
            font-weight: 600;
        }

        .ai-auto-cat-console .log-error {
            color: #d63638;
            font-weight: 600;
        }

        .ai-auto-cat-console .log-warning {
            color: #dba617;
            font-weight: 600;
        }
    </style>
    <script type="text/javascript">
        // Function to append to console
        function appendToConsole(message) {
            var consoleDiv = document.getElementById('ai-auto-cat-console');

            // Format time
            var now = new Date();
            var timeString = now.toLocaleTimeString([], { hour12: false });

            // Check for message type based on content
            var messageClass = '';
            if (message.toLowerCase().includes('error:')) {
                messageClass = 'log-error';
            } else if (message.toLowerCase().includes('warning:')) {
                messageClass = 'log-warning';
            } else if (message.toLowerCase().includes('successfully')) {
                messageClass = 'log-success';
            }

            // Create entry
            var entry = document.createElement('div');
            entry.className = 'log-entry';

            var timeSpan = document.createElement('span');
            timeSpan.className = 'log-time';
            timeSpan.textContent = '[' + timeString + ']';

            var msgSpan = document.createElement('span');
            if (messageClass) {
                msgSpan.className = messageClass;
            }
            msgSpan.textContent = message;

            entry.appendChild(timeSpan);
            entry.appendChild(msgSpan);

            consoleDiv.appendChild(entry);

            // Auto scroll to bottom
            consoleDiv.scrollTop = consoleDiv.scrollHeight;
        }

        // Clear console button listener
        document.getElementById('clear-console').addEventListener('click', function() {
            document.getElementById('ai-auto-cat-console').innerHTML = '';
        });

        document.getElementById('process-posts').addEventListener('click', function() {
            var categorySlug = document.getElementById('category-slug').value;
            var batchSize = document.getElementById('batch-size').value;

            appendToConsole('--- Starting Process Posts Task ---');
            appendToConsole('Sending request to server. Please wait...');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    var response = JSON.parse(this.responseText);
                    if (response.success) {
                        response.data.logs.forEach(function(log) {
                            appendToConsole(log);
                        });
                        appendToConsole('--- Process Posts Task Completed ---');
                    } else {
                        if (response.data && response.data.logs) {
                            response.data.logs.forEach(function(log) {
                                appendToConsole(log);
                            });
                        } else {
                            appendToConsole('Error: Received an unexpected error format from server.');
                        }
                    }
                } else {
                    appendToConsole('Error: Server returned status code ' + this.status);
                }
            };
            xhr.onerror = function() {
                appendToConsole('Error: A connection error occurred.');
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