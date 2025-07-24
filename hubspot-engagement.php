<?php
/**
 * Plugin Name: Hubspot Engagements
 * Description: Hubspot Email sending and track metadata. After completing order.
 * Version: 1.0
 * Author: XXXX
 */
// Register Plugin menu under setting of wordpress defaul setting menu
add_action('admin_menu', 'mhg_add_plugin_settings_submenu');
function mhg_add_plugin_settings_submenu() {
    add_options_page(
        'HubSpot Engagement Settings',   // Page title
        'HubSpot Engagement',            // Menu title 
        'manage_options',             // Capability
        'mhg-plugin-settings',        // Slug
        'mhg_render_plugin_settings_page' 
    );
}

function mhg_render_plugin_settings_page() {
    $hubspot_token = esc_attr(get_option('mhg_hubspot_token', ''));
    ?>
<div class="wrap">
    <h1>HubSpot Engagement Settings</h1>

    <?php if (isset($_GET['settings-updated'])): ?>
    <div class="notice notice-success is-dismissible">
        <p>Settings updated successfully.</p>
    </div>
    <?php endif; ?>
    <p style="font-size: 15px; font-weight: 500; margin-bottom:5px;">Hubspot Connection.</p>
    <span> Easy and Simple plugin for Setup. Just collect API access token from Hubspot and Click on Build Connection.</span>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="mhg_save_settings">
        <?php wp_nonce_field('mhg_save_settings_action', 'mhg_save_settings_nonce'); ?>
        <div class="form-table">
            <span style="font-weight: 500; font-size: 14px; padding-bottom: 0px;">HubSpot Private Token</span>
            <div class="field">
                <input type="text" name="hubspot_token" id="hubspot_token" class="regular-text"
                    style="font-weight: 500; font-style: italic;margin-top: 4px;" value="<?php echo $hubspot_token; ?>">
            </div>
        </div>
        <div class="mgh" style="margin-top: -20px"><?php submit_button('Build Connection'); ?></div>
    </form>
</div>
<?php
}

add_action('admin_post_mhg_save_settings', 'mhg_handle_settings_save');
function mhg_handle_settings_save() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized user');
    }

    if (!isset($_POST['mhg_save_settings_nonce']) || !wp_verify_nonce($_POST['mhg_save_settings_nonce'], 'mhg_save_settings_action')) {
        wp_die('Invalid nonce');
    }

    $token = sanitize_text_field($_POST['hubspot_token'] ?? '');

    if (!empty($token)) {
        update_option('mhg_hubspot_token', $token);
    }

    wp_redirect(add_query_arg('settings-updated', 'true', admin_url('options-general.php?page=mhg-plugin-settings')));
    exit;
}

// Hook only after WooCommerce is loaded
define('HUBSPOT_TOKEN', get_option('mhg_hubspot_token', ''));

add_action('plugins_loaded', 'mhg_load_after_woocommerce');
function mhg_load_after_woocommerce() {
    if (class_exists('WooCommerce')) {
        add_action('woocommerce_payment_complete', 'mhg_log_email_to_hubspot', 20, 1);
    } else {
        error_log("WooCommerce not active or not loaded.");
    }
}

function mhg_log_email_to_hubspot($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $email_address = $order->get_billing_email();
    $first_name    = $order->get_billing_first_name();
    $last_name     = $order->get_billing_last_name();
    $customer_name = $first_name . ' ' . $last_name;
    // Get product names
    $product_names = [];
    foreach ($order->get_items() as $item) {
        $product_names[] = $item->get_name();
    }
    $product_list = implode(', ', $product_names);
    $subject       = 'Order Completed for ' . $customer_name;
    $body          = 'Order #' . $order->get_id() . ' has been completed. Course or Event Name : ' .  $product_list . ' Order Placed By ' . $customer_name .'.';
    $timestamp     = round(microtime(true) * 1000);

    error_log("WooCommerce payment complete: Order #$order_id, Email: $email_address");

    $contact_id = mhg_create_or_get_hubspot_contact($email_address, $first_name, $last_name);

    if ($contact_id) {
        mhg_create_hubspot_engagement($contact_id, $subject, $body, $timestamp, $email_address);
    }
}

// ----------------- HUBSPOT CONTACT ------------------
function mhg_create_or_get_hubspot_contact($email, $first_name, $last_name) {
    $url = "https://api.hubapi.com/crm/v3/objects/contacts/{$email}?idProperty=email";
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . HUBSPOT_TOKEN,
            'Content-Type'  => 'application/json',
        ]
    ]);

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200 && isset($body['id'])) {
        return $body['id'];
    }

    // Create new contact
    $create_url = "https://api.hubapi.com/crm/v3/objects/contacts";

    $response = wp_remote_post($create_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . HUBSPOT_TOKEN,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'properties' => [
                'email'     => $email,
                'firstname' => $first_name,
                'lastname'  => $last_name,
            ]
        ])
    ]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['id'] ?? null;
}

// ----------------- HUBSPOT EMAIL ENGAGEMENT ------------------
function mhg_create_hubspot_engagement($contact_id, $subject, $body, $timestamp, $to_email) {
    $url = "https://api.hubapi.com/engagements/v1/engagements";
    $payload = [
        'engagement' => [
            'active'    => true,
            'type'      => 'EMAIL',
            'timestamp' => $timestamp,
        ],
        'associations' => [
            'contactIds' => [$contact_id],
        ],
        'metadata' => [
            'from'    => ['email' => 'your@domain.com'],
            'to'      => [['email' => $to_email]],
            'subject' => $subject,
            'text'    => wp_strip_all_tags($body),
            'html'    => $body,
            'status'  => 'SENT',
        ]
    ];

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . HUBSPOT_TOKEN,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        error_log('HubSpot engagement error: ' . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);
        error_log("HubSpot response ($code): $res_body");
        //Save message-id and engagement-id mapping here
        $res = json_decode($res_body, true);
        $engagement_id = $res['engagement']['id'] ?? null;
        $message_id = $res['metadata']['messageId'] ?? null;
        // You must fetch message ID from Mailgun headers (if you inject it)
        if ($engagement_id && $message_id) {
            update_option('mhg_engagement_map_' . $message_id, $engagement_id);
        }
    }
}
