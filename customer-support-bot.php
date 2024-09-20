<?php
/*
Plugin Name: Customer Support Bot
Description: Customer Support Bot is designed to help users when the assistant is not available.
Version: 0.1.6
Author: Admin
Text Domain: customer-support-bot
Domain Path: /languages
*/

// Define Agentive API key and Assistant ID securely on the server-side
if (!defined('AGENTIVE_API_KEY')) {
    define('AGENTIVE_API_KEY', '664c990c-f470-4c0f-a67c-98056db461ae');
}

if (!defined('AGENTIVE_ASSISTANT_ID')) {
    define('AGENTIVE_ASSISTANT_ID', '66ca9fa5-d934-4cf5-8dde-c73173b1a0cc');
}

// Load text domain for translations
function vacw_load_textdomain() {
    load_plugin_textdomain('customer-support-bot', false, basename(dirname(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'vacw_load_textdomain');

// Enqueue scripts and styles for the front end
function vacw_enqueue_scripts() {
    $timestamp = time();  // Cache busting to ensure latest versions of CSS/JS are loaded

    wp_enqueue_style('vacw-style', plugins_url('assets/assets/style.css', __FILE__), array(), $timestamp);
    wp_enqueue_script('vacw-script', plugins_url('assets/assets/script.js', __FILE__), array('jquery'), $timestamp, true);
    
    // Get bot greeting from the settings
    $bot_greeting = get_option('vacw_bot_greeting', 'Hi! How can I assist you today?');
    
    wp_localize_script('vacw-script', 'vacw_settings', array(
        'ajax_url'   => admin_url('admin-ajax.php'),
        'avatar_url' => esc_url(get_option('vacw_avatar_url', plugins_url('assets/default-avatar.png', __FILE__))),
        'bot_greeting' => esc_html($bot_greeting), // Pass the greeting to the front-end
        'security'   => wp_create_nonce('vacw_nonce_action'),
    ));
}
add_action('wp_enqueue_scripts', 'vacw_enqueue_scripts');

// Register settings page
function vacw_register_settings_page() {
    add_options_page(
        __('Chat Widget Settings', 'customer-support-bot'),
        __('Chat Widget', 'customer-support-bot'),
        'manage_options',
        'vacw-settings',
        'vacw_settings_page'  // Make sure the function exists
    );
}
add_action('admin_menu', 'vacw_register_settings_page');

function vacw_enqueue_admin_tailwind() {
    wp_enqueue_style('tailwindcss', 'https://cdn.tailwindcss.com');
}
add_action('admin_enqueue_scripts', 'vacw_enqueue_admin_tailwind');

// The missing function to render the settings page
function vacw_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Customer Support Bot Settings', 'customer-support-bot'); ?></h1>
        <form method="post" action="options.php">
            <?php
                settings_fields('vacw_settings_group');
                do_settings_sections('vacw_settings_group');
                submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register settings including the bot greeting
function vacw_register_settings() {
    register_setting('vacw_settings_group', 'vacw_avatar_url', 'sanitize_text_field');
    register_setting('vacw_settings_group', 'vacw_assistant_name', 'sanitize_text_field');
    register_setting('vacw_settings_group', 'vacw_openai_api_key', 'vacw_sanitize_and_encrypt_api_key');
    register_setting('vacw_settings_group', 'vacw_bot_greeting', 'sanitize_text_field');  // Register bot greeting setting
}
add_action('admin_init', 'vacw_register_settings');

// Sanitize and encrypt the OpenAI API key
function vacw_sanitize_and_encrypt_api_key($api_key) {
    $api_key = sanitize_text_field($api_key);
    if (!empty($api_key) && defined('VACW_ENCRYPTION_KEY')) {
        $encrypted_key = openssl_encrypt($api_key, 'AES-256-CBC', VACW_ENCRYPTION_KEY, 0, substr(VACW_ENCRYPTION_KEY, 0, 16));
        return $encrypted_key;
    }
    return '';
}

// Decrypt the OpenAI API key
function vacw_get_decrypted_api_key() {
    $encrypted_key = get_option('vacw_openai_api_key');
    if (!empty($encrypted_key) && defined('VACW_ENCRYPTION_KEY')) {
        $decrypted_key = openssl_decrypt($encrypted_key, 'AES-256-CBC', VACW_ENCRYPTION_KEY, 0, substr(VACW_ENCRYPTION_KEY, 0, 16));
        return $decrypted_key;
    }
    return '';
}

// Add chat widget to the footer (front-end display of the chat widget)
function vacw_add_chat_widget() {
    try {
        echo '<div id="chatbot-container">';
        include(plugin_dir_path(__FILE__) . 'assets/index.html');  // Ensure the path to index.html is correct
        echo '</div>';
    } catch (Exception $e) {
        error_log('Error displaying chat widget: ' . $e->getMessage());
        echo '<div>' . __('Error loading chat widget. Please contact the administrator.', 'customer-support-bot') . '</div>';
    }
}
add_action('wp_footer', 'vacw_add_chat_widget');

// Handle Agentive API session initialization
function vacw_initialize_session() {
    // Verify nonce for security
    check_ajax_referer('vacw_nonce_action', 'security');

    // Check user capability
    if (!current_user_can('read')) {
        wp_send_json_error('Unauthorized user');
        wp_die();
    }

    $api_url = 'https://agentivehub.com/api/chat/session';
    $api_key = AGENTIVE_API_KEY;
    $assistant_id = AGENTIVE_ASSISTANT_ID;

    // Prepare the request body for session initialization
    $args = array(
        'headers' => array(
            'Content-Type'  => 'application/json; charset=utf-8',
        ),
        'body'    => json_encode(array(
            'api_key'      => $api_key,
            'assistant_id' => $assistant_id,
        )),
    );

    // Send the request to Agentive API for session creation
    $response = wp_remote_post($api_url, $args);

    if (is_wp_error($response)) {
        error_log('Error communicating with Agentive API: ' . $response->get_error_message());
        wp_send_json_error('Error communicating with the Agentive API.');
    } else {
        $response_body = wp_remote_retrieve_body($response);
        if (!$response_body) {
            error_log('Empty response from Agentive API.');
            wp_send_json_error('Empty response from the Agentive API.');
        } else {
            wp_send_json_success(json_decode($response_body));
        }
    }
    wp_die();
}
add_action('wp_ajax_vacw_initialize_session', 'vacw_initialize_session');

// Handle Agentive API bot response communication
function vacw_get_bot_response() {
    // Verify nonce for security
    check_ajax_referer('vacw_nonce_action', 'security');

    // Check user capability
    if (!current_user_can('read')) {
        wp_send_json_error('Unauthorized user');
        wp_die();
    }

    $api_url = 'https://agentivehub.com/api/chat';
    $api_key = AGENTIVE_API_KEY;
    $assistant_id = AGENTIVE_ASSISTANT_ID;
    $session_id = sanitize_text_field($_POST['session_id']);
    $user_message = sanitize_text_field($_POST['message']);

    // Prepare the request body for the Agentive API
    $args = array(
        'headers' => array(
            'Content-Type'  => 'application/json; charset=utf-8',
        ),
        'body'    => json_encode(array(
            'api_key'      => $api_key,
            'session_id'   => $session_id,
    'type'         => 'custom_code',
    'assistant_id' => $assistant_id,
    'messages'     => array(
        array('role' => 'user', 'content' => $user_message),
    ),
    )),
);

// Send the request to Agentive API
$response = wp_remote_post($api_url, $args);

if (is_wp_error($response)) {
    error_log('Error communicating with Agentive API: ' . $response->get_error_message());
    wp_send_json_error('Error communicating with the Agentive API.');
} else {
    $response_body = wp_remote_retrieve_body($response);
    if (!$response_body) {
        error_log('Empty response from Agentive API.');
        wp_send_json_error('Empty response from the Agentive API.');
    } else {
        wp_send_json_success(json_decode($response_body));
    }
}
wp_die();
}
add_action('wp_ajax_vacw_get_bot_response', 'vacw_get_bot_response');
