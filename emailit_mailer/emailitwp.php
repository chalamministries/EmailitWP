<?php
/**
 * Plugin Name:       EmailItWP
 * Plugin URI:        https://github.com/stingray82/
 * Description:       Interface for configuring EmailIt API & SMTP settings for email delivery
 * Version:           1.31
 * Author:            Stingray82
 * Author URI:        https://github.com/stingray82/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       emailitwp
 * Domain Path:       /languages
 */

define( 'EMAILITWP_VERSION', '1.31');
define( 'EMAILIT_DEFAULT_FROM_NAME', '' );
define( 'EMAILIT_DEFAULT_FROM_EMAIL', '' );
define( 'EMAILIT_SMTP_HOST', 'smtp.emailit.com' );
define( 'EMAILIT_SMTP_USERNAME', 'emailit' );

function emailit_register_combined_settings() {
    // General Settings
    register_setting( 'emailit_combined_options', 'emailit_mode' );
    register_setting( 'emailit_combined_options', 'emailit_from_name' );
    register_setting( 'emailit_combined_options', 'emailit_from_email' );
    register_setting( 'emailit_combined_options', 'emailit_debug' );

    // SMTP Settings
    register_setting( 'emailit_combined_options', 'emailit_port' );
    register_setting( 'emailit_combined_options', 'emailit_smtp_password' );

    // API Settings
    register_setting( 'emailit_combined_options', 'emailit_api_key' );
}
add_action( 'admin_init', 'emailit_register_combined_settings' );

/**
 * Add a settings page under Tools if rup_emailit_hide is not defined.
 */

add_action('plugins_loaded', function() {
    if (!defined('rup_emailit_hide')) {
        add_action('admin_menu', function() {
            add_management_page(
                'EmailItWP Settings',
                'EmailItWP Settings',
                'manage_options',
                'emailitwp-settings',
                'emailit_combined_settings_page'
            );
        });
    }
});


/**
 * Enqueue admin scripts.
 */
function emailit_combined_enqueue_scripts( $hook ) {
    if ( $hook !== 'tools_page_emailitwp-settings' ) {
        return;
    }

    wp_enqueue_script(
        'emailit-ajax-script',
        plugin_dir_url( __FILE__ ) . 'emailit-ajax.js',
        [ 'jquery' ],
        '1.0.0',
        true
    );

    wp_localize_script( 'emailit-ajax-script', 'emailit_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'emailit_ajax_nonce' ),
    ] );
}
add_action( 'admin_enqueue_scripts', 'emailit_combined_enqueue_scripts' );

/**
 * Admin settings page.
 */
function emailit_combined_settings_page() {
    $current_mode = get_option('emailit_mode', 'SMTP');
    ?>
    <div class="wrap">
        <h1><svg id="Layer_2" class="h-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 53.02 45.95" style="height: 30px; vertical-align: middle; margin-left: 10px;">
                <g id="Layer_1-2">
                    <g id="mail-send-envelope--envelope-email-message-unopened-sealed-close">
                        <g id="Subtract">
                            <path d="m7.83,45.41c3.85.27,9.96.55,18.68.54,8.72,0,14.84-.29,18.68-.56,3.85-.27,6.9-3.18,7.25-7.06.29-3.34.58-8.38.57-15.36,0-.44,0-.88,0-1.3-3.14,1.45-6.31,2.83-9.51,4.13-2.95,1.19-6.15,2.39-9.11,3.29-2.91.89-5.74,1.55-7.88,1.55s-4.98-.66-7.88-1.55c-2.96-.9-6.16-2.1-9.11-3.29C6.31,24.5,3.14,23.13,0,21.68c0,.43,0,.86,0,1.31,0,6.98.29,12.02.58,15.36.34,3.89,3.4,6.79,7.25,7.06Z" fill="#15c182" stroke-width="0"></path>
                            <path d="m.05,17.8c.1-4.37.31-7.74.52-10.19C.91,3.73,3.97.83,7.82.56,11.67.29,17.78,0,26.5,0c8.72,0,14.84.28,18.68.54,3.85.27,6.91,3.17,7.25,7.06.22,2.45.43,5.81.53,10.19-.04.02-.08.03-.12.05l-.05.02-.16.08c-.98.46-1.95.91-2.94,1.35-2.48,1.12-4.98,2.19-7.51,3.22-2.9,1.17-6,2.33-8.82,3.19-2.87.88-5.27,1.4-6.85,1.4s-3.98-.52-6.85-1.39c-2.82-.86-5.92-2.02-8.82-3.19-3.52-1.42-7.01-2.95-10.45-4.56l-.16-.08-.05-.02s-.08-.04-.12-.05h0Z" fill="#007b5e" stroke-width="0"></path>
                        </g>
                    </g>
                </g>
            </svg>  EmailItWP Settings</h1>
            <h3> Remember API and SMTP Keys are different and you should make sure you are using the correct one </h3>
        <form method="post" action="admin-post.php">
            <input type="hidden" name="action" value="emailit_save_settings">
            <?php wp_nonce_field('emailit_save_settings_action', 'emailit_save_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="emailit_mode">Mode</label></th>
                    <td>
                        <select id="emailit_mode" name="emailit_mode" class="regular-text">
                            <option value="SMTP" <?php selected($current_mode, 'SMTP'); ?>>SMTP</option>
                            <option value="API" <?php selected($current_mode, 'API'); ?>>API</option>
                        </select>
                        <p class="description">Choose whether to use SMTP or API for sending emails.</p>
                    </td>
                </tr>
            </table>

            <div id="emailit_shared_fields">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="emailit_from_name">From Name</label></th>
                        <td>
                            <input type="text" id="emailit_from_name" name="emailit_from_name" value="<?php echo esc_attr(get_option('emailit_from_name', EMAILIT_DEFAULT_FROM_NAME)); ?>" class="regular-text" />
                            <p class="description">The name that will appear as the sender.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="emailit_from_email">From Email</label></th>
                        <td>
                            <input type="email" id="emailit_from_email" name="emailit_from_email" value="<?php echo esc_attr(get_option('emailit_from_email', EMAILIT_DEFAULT_FROM_EMAIL)); ?>" class="regular-text" />
                            <p class="description">The email address that will appear as the sender.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div id="emailit_dynamic_fields">
                <?php emailit_render_dynamic_fields($current_mode); ?>
            </div>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="emailit_debug">Enable Debugging</label></th>
                    <td>
                        <input type="checkbox" id="emailit_debug" name="emailit_debug" value="1" <?php checked(get_option('emailit_debug', 0), 1); ?> />
                        <p class="description">Enable detailed debugging output.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}


// Save only displayed fields
function emailit_save_settings() {
    if (
        !isset($_POST['emailit_save_settings_nonce']) ||
        !wp_verify_nonce($_POST['emailit_save_settings_nonce'], 'emailit_save_settings_action')
    ) {
        wp_die(__('Invalid request.', 'emailit-combined-interface'));
    }

    if (!current_user_can('manage_options')) {
        wp_die(__('Permission denied.', 'emailit-combined-interface'));
    }

    $mode = sanitize_text_field($_POST['emailit_mode'] ?? 'SMTP');
    update_option('emailit_mode', $mode);

    // Update shared fields
    update_option('emailit_from_name', sanitize_text_field($_POST['emailit_from_name'] ?? EMAILIT_DEFAULT_FROM_NAME));
    update_option('emailit_from_email', sanitize_email($_POST['emailit_from_email'] ?? EMAILIT_DEFAULT_FROM_EMAIL));
    update_option('emailit_debug', isset($_POST['emailit_debug']) ? 1 : 0);

    // Update mode-specific fields
    if ($mode === 'SMTP') {
        update_option('emailit_port', sanitize_text_field($_POST['emailit_port'] ?? '587'));
        update_option('emailit_smtp_password', sanitize_text_field($_POST['emailit_smtp_password'] ?? ''));
    } elseif ($mode === 'API') {
        update_option('emailit_api_key', sanitize_text_field($_POST['emailit_api_key'] ?? ''));
    }

    wp_redirect(admin_url('tools.php?page=emailitwp-settings&status=success'));
    exit;
}
add_action('admin_post_emailit_save_settings', 'emailit_save_settings');



/**
 * Render dynamic fields based on the selected mode.
 */
function emailit_render_dynamic_fields( $mode ) {
    if ( $mode === 'SMTP' ) {
        $smtp_ports = [
            '25'   => '25 (STARTTLS)',
            '465'  => '465 (SMTPS)',
            '587'  => '587 (STARTTLS)',
            '2525' => '2525 (STARTTLS)',
        ];
        $selected_port = get_option( 'emailit_port', 587 );
        $smtp_password = get_option( 'emailit_smtp_password', '' );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="emailit_port">SMTP Port</label></th>
                <td>
                    <select id="emailit_port" name="emailit_port" class="regular-text">
                        <?php foreach ( $smtp_ports as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php echo selected( $selected_port, $value, false ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="emailit_smtp_password">SMTP Password</label></th>
                <td>
                    <input type="password" id="emailit_smtp_password" name="emailit_smtp_password" value="<?php echo esc_attr( $smtp_password ); ?>" class="regular-text" />
                    <p class="description">The SMTP password for authentication.</p>
                </td>
            </tr>
        </table>
        <?php
    } elseif ( $mode === 'API' ) {
        $api_key = get_option( 'emailit_api_key', '' );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="emailit_api_key">API Key</label></th>
                <td>
                    <input type="password" id="emailit_api_key" name="emailit_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
                    <p class="description">The API key for EmailIt.</p>
                </td>
            </tr>
        </table>
        <?php
    }
}

/**
 * Handle AJAX requests to dynamically load SMTP or API fields.
 */
function emailit_ajax_load_fields() {
    check_ajax_referer( 'emailit_ajax_nonce', 'nonce' );
    $mode = $_POST['mode'] ?? 'SMTP';
    ob_start();
    emailit_render_dynamic_fields( $mode );
    echo ob_get_clean();
    wp_die();
}
add_action( 'wp_ajax_emailit_load_fields', 'emailit_ajax_load_fields' );

/**
 * Preserve settings for the inactive mode when saving.
 */
function emailit_preserve_settings( $value, $option, $old_value ) {
    $selected_mode = $_POST['emailit_mode'] ?? get_option( 'emailit_mode', 'SMTP' );

    if ( $selected_mode === 'SMTP' ) {
        $value['emailit_api_key'] = get_option( 'emailit_api_key', '' );
    } elseif ( $selected_mode === 'API' ) {
        $value['emailit_port'] = get_option( 'emailit_port', 587 );
        $value['emailit_smtp_password'] = get_option( 'emailit_smtp_password', '' );
    }

    return $value;
}
add_filter( 'pre_update_option_emailit_combined_options', 'emailit_preserve_settings', 10, 3 );

/**
 * Configure email sending based on selected mode with enhanced debugging.
 */
function emailit_send_email( $phpmailer ) {
    $mode = get_option( 'emailit_mode', 'SMTP' );
    $debug = get_option( 'emailit_debug', 0 );

    if ( $mode === 'SMTP' ) {
        $phpmailer->isSMTP();
        $phpmailer->Host       = EMAILIT_SMTP_HOST;
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Port       = get_option( 'emailit_port', 587 );
        $phpmailer->Username   = EMAILIT_SMTP_USERNAME;
        $phpmailer->Password   = get_option( 'emailit_smtp_password' );
        $phpmailer->SMTPSecure = 'tls';

        if ( $debug ) {
            $phpmailer->SMTPDebug  = 3;
            $phpmailer->Debugoutput = function( $str, $level ) {
                error_log( "SMTP Debug: $str" );
            };
        }
    } elseif ( $mode === 'API' ) {
        $apiKey = get_option( 'emailit_api_key' );
        $from   = get_option( 'emailit_from_name', EMAILIT_DEFAULT_FROM_NAME ) . ' <' . get_option( 'emailit_from_email', EMAILIT_DEFAULT_FROM_EMAIL ) . '>';
        $url    = 'https://api.emailit.com/v1/emails';

        $toAddresses = $phpmailer->getToAddresses();
        $subject     = $phpmailer->Subject;
        $htmlBody    = $phpmailer->Body;

        $to = implode( ',', array_column( $toAddresses, 0 ) );

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        $payload = json_encode( [
            'from'    => $from,
            'to'      => $to,
            'subject' => $subject,
            'html'    => $htmlBody,
        ] );

        $ch = curl_init( $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

        $response = curl_exec( $ch );

        if ( curl_errno( $ch ) ) {
            if ( $debug ) {
                error_log( 'API cURL Error: ' . curl_error( $ch ) );
            }
        } else {
            $responseCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            if ( $debug ) {
                error_log( 'API Response Code: ' . $responseCode );
                error_log( 'API Response: ' . $response );
            }
        }

        curl_close( $ch );

        // Prevent PHPMailer from sending the email
        $phpmailer->ClearAllRecipients();
        $phpmailer->ClearAttachments();
    }
}
add_action( 'phpmailer_init', 'emailit_send_email', 1 );

/**
 * Set From Name and Email.
 */
add_filter( 'wp_mail_from', function() {
    return get_option( 'emailit_from_email', EMAILIT_DEFAULT_FROM_EMAIL );
} );

add_filter( 'wp_mail_from_name', function() {
    return get_option( 'emailit_from_name', EMAILIT_DEFAULT_FROM_NAME );
} );
