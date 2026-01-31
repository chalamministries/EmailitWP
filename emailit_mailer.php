<?php
/*
Plugin Name: EmailIt Mailer for WordPress
Plugin URI: https://github.com/chalamministries/EmailitWP
Description: Overrides WordPress default mail function to use EmailIt SDK
Version: 3.2.1
Author: Steven Gauerke
License: GPL2
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Add EmailIt SDK autoloader
require_once plugin_dir_path(__FILE__) . 'autoload.php';
if (!class_exists('EmailIt_Plugin_Updater')) {
    require_once plugin_dir_path(__FILE__) . 'class-emailit-updater.php';
}
require_once plugin_dir_path(__FILE__) . 'class-emailit-logger.php';

class EmailItMailer {
    private static $instance = null;
    private $options;
    private $option_name = 'emailit_settings';
    private static $api_active = false;
    private $sending_domains_option = 'emailit_sending_domains';
    private $tabs = [];
    private $logger;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
       if (is_admin()) {
           new EmailIt_Plugin_Updater(__FILE__, 'chalamministries', 'EmailitWP');
       }
       
        // Initialize options
       $this->options = get_option($this->option_name, [
           'api_key' => '',
           'from_email_prefix' => 'no-reply', // New default
           'from_email_domain' => '', // Will be populated from sending domains
           'from_name' => ''
       ]);
       
       // Register default tabs
         $this->register_default_tabs();
      
        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        // Add CSS for email input styling
        add_action('admin_head', [$this, 'add_email_input_styles']);
        //Test email action
        add_action('admin_init', [$this, 'process_test_email_submission']);
        
        add_filter('emailit_before_send_mail', [$this, 'process_email_data'], 10, 1);
        
        $this->logger = EmailIt_Logger::get_instance();
    }
    
    public function log_debug($message, $data = []) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
         error_log('EmailIt Debug: ' . $message . ($data ? ' Data: ' . json_encode($data) : ''));
       }
    }
    
    private function register_default_tabs() {
         $this->tabs = [
             'settings' => [
                 'label' => 'Settings',
                 'callback' => [$this, 'render_settings_tab'],
                 'position' => 10
             ],
             'docs' => [
                 'label' => 'Documentation',
                 'callback' => [$this, 'render_docs_tab'],
                 'position' => 100  // High number ensures it's the last tab
             ]
         ];
     }
    
     public function register_tab($id, $label, $callback, $position = 50) {
         $this->tabs[$id] = [
             'label' => $label,
             'callback' => $callback,
             'position' => $position
         ];
     }

    public function init() {
        // Test API connection on init
        self::$api_active = $this->test_api_connection();
        
        // Add admin notice if API is not active
        if (!self::$api_active) {
            add_action('admin_notices', [$this, 'show_api_inactive_notice']);
        }

        // Show upgrade notice for API key regeneration (until Feb 15, 2025)
        if ($this->should_show_upgrade_notice()) {
            add_action('admin_notices', [$this, 'show_upgrade_notice']);
            add_action('wp_ajax_emailit_dismiss_upgrade_notice', [$this, 'dismiss_upgrade_notice']);
        }

        add_action('emailit_process_email_batch', [$this, 'process_email_batch']);

        // Register the cron hook for async email sending
        add_action('emailit_send_mail_async', [$this, 'handle_async_email_cron']);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EmailIt: Registered emailit_send_mail_async action hook');
        }
        add_filter('cron_schedules', function($schedules) {
             $this->log_debug('Registering cron schedules');
             return $this->add_cron_interval($schedules);
         });
        
    }

    // API Connection and Domain Management Methods
    private function test_api_connection() {
        if (empty($this->options['api_key'])) {
            $this->clear_sending_domains();
            return false;
        }
    
        try {
            $client = new EmailIt\EmailItClient($this->options['api_key']);
            $response = $client->domains()->list(100, 1);
            
            $domainItems = [];
            if (isset($response['data']) && is_array($response['data'])) {
                $domainItems = $response['data'];
            } elseif (isset($response['items']) && is_array($response['items'])) {
                $domainItems = $response['items'];
            } elseif (isset($response['domains']) && is_array($response['domains'])) {
                $domainItems = $response['domains'];
            }

            if (!empty($domainItems)) {
                $domains = array_values(array_filter(array_map(function($domain) {
                    if (is_array($domain) && isset($domain['name'])) {
                        return $domain['name'];
                    }

                    return is_string($domain) ? $domain : null;
                }, $domainItems)));
                
                $this->update_sending_domains($domains);
                
                // If we don't have a domain selected yet, set the first one as default
                if (empty($this->options['from_email_domain']) && !empty($domains)) {
                    $this->options['from_email_domain'] = $domains[0];
                    update_option($this->option_name, $this->options);
                }
                
                return true;
            }
            
            $this->clear_sending_domains();
            return false;
            
        } catch (Exception $e) {
            $this->clear_sending_domains();
            
            $this->log_debug('EmailIt API connection test failed: ' . $e->getMessage());
          
            return false;
        }
    }

    public static function is_api_active() {
        return self::$api_active;
    }

    private function update_sending_domains(array $domains) {
        update_option($this->sending_domains_option, $domains, false);
    }

    private function clear_sending_domains() {
        delete_option($this->sending_domains_option);
    }

    public function get_sending_domains() {
        return get_option($this->sending_domains_option, []);
    }

    public function is_valid_sending_domain(string $email): bool {
        $domains = $this->get_sending_domains();
        if (empty($domains)) {
            return false;
        }

        $emailDomain = substr(strrchr($email, "@"), 1);
        return in_array($emailDomain, $domains);
    }

    // Admin UI Methods
    public function show_api_inactive_notice() {
        $settings_url = admin_url('options-general.php?page=emailit-settings');
        ?>
        <div class="notice notice-error">
            <p>
                <strong>EmailIt Sending is currently disabled.</strong> 
                Please check your <a href="<?php echo esc_url($settings_url); ?>">EmailIt Settings</a> and verify your API key.
            </p>
        </div>
        <?php
    }

    /**
     * Check if we should show the upgrade notice
     */
    private function should_show_upgrade_notice() {
        // Don't show after Feb 15, 2025
        if (time() > strtotime('2025-02-15 23:59:59')) {
            return false;
        }

        // Don't show if already dismissed
        if (get_option('emailit_upgrade_notice_dismissed')) {
            return false;
        }

        // Show on admin pages
        return is_admin();
    }

    /**
     * Show the upgrade notice
     */
    public function show_upgrade_notice() {
        $settings_url = admin_url('options-general.php?page=emailit-settings');
        ?>
        <div class="notice notice-warning is-dismissible" id="emailit-upgrade-notice">
            <p>
                <strong>EmailIt Mailer 3.0 - Important Update:</strong>
                If you upgraded from version 2.5.1 or earlier, you need to generate a new API key from your
                <a href="https://emailit.com/dashboard" target="_blank">EmailIt Dashboard</a> and update it in your
                <a href="<?php echo esc_url($settings_url); ?>">EmailIt Settings</a>.
                The API has been updated to v2 which requires new API credentials.
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $(document).on('click', '#emailit-upgrade-notice .notice-dismiss', function() {
                $.post(ajaxurl, {
                    action: 'emailit_dismiss_upgrade_notice',
                    _wpnonce: '<?php echo wp_create_nonce('emailit_dismiss_notice'); ?>'
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler to dismiss the upgrade notice
     */
    public function dismiss_upgrade_notice() {
        check_ajax_referer('emailit_dismiss_notice');
        update_option('emailit_upgrade_notice_dismissed', true);
        wp_die();
    }

    public function add_admin_menu() {
        add_options_page(
            'EmailIt Settings',
            'EmailIt Mailer',
            'manage_options',
            'emailit-settings',
            [$this, 'settings_page']
        );
    }
    
    public function add_email_input_styles() {
        ?>
        <style>
            .emailit-email-input-group {
                display: flex;
                gap: 0;
                max-width: 400px;
            }
            .emailit-email-input-group input {
                border-top-right-radius: 0;
                border-bottom-right-radius: 0;
                flex: 1;
            }
            .emailit-email-input-group select {
                border-top-left-radius: 0;
                border-bottom-left-radius: 0;
                border-left: none;
                min-width: 120px;
                background-color: #f0f0f1;
            }
            .emailit-email-input-group .at-symbol {
                background: #f0f0f1;
                padding: 0 8px;
                line-height: 30px;
                border: 1px solid #8c8f94;
                border-left: none;
                border-right: none;
                color: #50575e;
            }
        </style>
        <?php
    }
    
    public function from_email_callback() {
        $prefix = isset($this->options['from_email_prefix']) ? $this->options['from_email_prefix'] : 'no-reply';
        $selected_domain = isset($this->options['from_email_domain']) ? $this->options['from_email_domain'] : '';
        $domains = $this->get_sending_domains();
        
        echo '<div class="emailit-email-input-group">';
        echo '<input type="text" id="emailit_from_email_prefix" name="' . $this->option_name . '[from_email_prefix]" value="' . esc_attr($prefix) . '" class="regular-text">';
        echo '<span class="at-symbol">@</span>';
        echo '<select id="emailit_from_email_domain" name="' . $this->option_name . '[from_email_domain]">';
        if (empty($domains)) {
            echo '<option value="">No domains available</option>';
        } else {
            foreach ($domains as $domain) {
                echo '<option value="' . esc_attr($domain) . '"' . selected($domain, $selected_domain, false) . '>' . esc_html($domain) . '</option>';
            }
        }
        echo '</select>';
        echo '</div>';
        echo '<p class="description">Select your sending domain from the verified domains list.</p>';
    }

    // Settings Methods
    public function register_settings() {
        register_setting($this->option_name, $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);
    
        add_settings_section(
            'emailit_main_section',
            'Main Settings',
            [$this, 'section_callback'],
            'emailit-settings'
        );
    
        add_settings_field(
            'emailit_api_key',
            'API Key',
            [$this, 'api_key_callback'],
            'emailit-settings',
            'emailit_main_section'
        );
    
        add_settings_field(
            'emailit_from_email_prefix',
            'Default From Email',
            [$this, 'from_email_callback'],
            'emailit-settings',
            'emailit_main_section'
        );
    
        add_settings_field(
            'emailit_from_name',
            'Default From Name',
            [$this, 'from_name_callback'],
            'emailit-settings',
            'emailit_main_section'
        );
    }
    
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
    
        // Fire action to let plugins register their tabs
        do_action('emailit_register_tabs');
    
        // Sort tabs by position
        uasort($this->tabs, function($a, $b) {
            return $a['position'] <=> $b['position'];
        });
    
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <?php if (!self::$api_active): ?>
                <div class="notice notice-error" style="margin: 20px 0;">
                    <p>❌ EmailIt API connection failed. Please verify your API key.</p>
                </div>
            <?php endif; ?>
    
            <div style="margin: 20px 0;">
                <img src="<?php echo plugins_url('assets/emailit-logo.svg', __FILE__); ?>" alt="EmailIt Logo" style="max-width: 200px; height: auto;">
            </div>
            
            <h1 class="nav-tab-wrapper">
                <?php foreach ($this->tabs as $tab_id => $tab): ?>
                    <a href="?page=emailit-settings&tab=<?php echo esc_attr($tab_id); ?>" 
                       class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </h1>
    
            <?php 
            // Show settings saved message
            settings_errors('emailit_settings');
            
            // Render current tab content
            if (isset($this->tabs[$current_tab]['callback'])) {
                call_user_func($this->tabs[$current_tab]['callback']);
            }
            ?>
        </div>
        <?php
    }

   public function render_settings_tab() {
       ?>
       <div class="card" style="max-width: 800px; margin-top: 20px;">
           <form action="options.php" method="post">
               <?php
               settings_fields($this->option_name);
               do_settings_sections('emailit-settings');
               submit_button('Save Settings');
               ?>
           </form>
       </div>
       
       <div class="card" style="max-width: 800px; margin-top: 20px;">
           <h2>Send Test Email</h2>
           <p>Use this form to send a test email and verify your EmailIt configuration.</p>
           
           <form method="post" action="">
               <?php wp_nonce_field('emailit_send_test_email', 'emailit_test_email_nonce'); ?>
               
               <table class="form-table">
                   <tr>
                       <th scope="row"><label for="test_email_to">Send To</label></th>
                       <td>
                           <input type="email" id="test_email_to" name="test_email_to" 
                                  class="regular-text" 
                                  value="<?php echo esc_attr(get_option('admin_email')); ?>" required>
                           <p class="description">Email address to send the test to.</p>
                       </td>
                   </tr>
                   <tr>
                       <th scope="row"><label for="test_email_subject">Subject</label></th>
                       <td>
                           <input type="text" id="test_email_subject" name="test_email_subject" 
                                  class="regular-text" 
                                  value="EmailIt Test Email" required>
                       </td>
                   </tr>
                   <tr>
                       <th scope="row"><label>Email Content</label></th>
                       <td>
                           <p>The test email will include:</p>
                           <ul style="list-style-type: disc; margin-left: 20px;">
                               <li>Basic formatting test (bold, italic, links)</li>
                               <li>Sending domain verification</li>
                               <li>Current WordPress site information</li>
                               <li>Current date and time</li>
                           </ul>
                       </td>
                   </tr>
                   <tr>
                       <th scope="row"><label for="force_error">Force Error</label></th>
                       <td>
                           <label>
                               <input type="checkbox" id="force_error" name="force_error" value="1">
                               Deliberately trigger an error to test error logging
                           </label>
                           <p class="description">
                               This will attempt to send from an invalid domain to generate an API error.
                               The error will be logged and displayed in the email logs.
                           </p>
                       </td>
                   </tr>
               </table>
               
               <p>
                   <input type="submit" name="send_test_email" class="button button-primary" value="Send Test Email">
               </p>
           </form>
       </div>
       <?php
   }
   
    public function render_docs_tab() {
        ?>
        <style>
            .emailit-docs { max-width: 800px; margin-top: 20px; }
            .emailit-docs h2 { color: #15c182; border-bottom: 2px solid #15c182; padding-bottom: 10px; margin-top: 30px; }
            .emailit-docs h3 { color: #333; margin-top: 20px; }
            .emailit-docs code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
            .emailit-docs pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; border-left: 4px solid #15c182; }
            .emailit-docs pre code { background: none; padding: 0; }
            .emailit-docs .note { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 15px; margin: 15px 0; }
            .emailit-docs .tip { background: #d4edda; border-left: 4px solid #28a745; padding: 10px 15px; margin: 15px 0; }
            .emailit-docs .warning { background: #f8d7da; border-left: 4px solid #dc3545; padding: 10px 15px; margin: 15px 0; }
            .emailit-docs ul { margin-left: 20px; }
            .emailit-docs li { margin-bottom: 8px; }
            .emailit-docs table { border-collapse: collapse; width: 100%; margin: 15px 0; }
            .emailit-docs th, .emailit-docs td { border: 1px solid #ddd; padding: 10px; text-align: left; }
            .emailit-docs th { background: #f4f4f4; }
        </style>

        <div class="emailit-docs">
            <div class="card">
                <h2>Getting Started</h2>

                <h3>1. Get Your API Key</h3>
                <p>To use EmailIt Mailer, you need an API key from your EmailIt account:</p>
                <ol>
                    <li>Log in to your <a href="https://emailit.com/dashboard" target="_blank">EmailIt Dashboard</a></li>
                    <li>Navigate to <strong>Settings &gt; API Keys</strong></li>
                    <li>Click <strong>Create New API Key</strong></li>
                    <li>Copy the generated key and paste it in the Settings tab</li>
                </ol>

                <div class="warning">
                    <strong>Version 3.0 Notice:</strong> If you upgraded from version 2.5.1 or earlier, you must generate a new API key. The plugin now uses EmailIt API v2 which requires new credentials.
                </div>

                <h3>2. Configure a Sending Domain</h3>
                <p>Before sending emails, you need at least one verified sending domain:</p>
                <ol>
                    <li>In your EmailIt Dashboard, go to <strong>Sending Domains</strong></li>
                    <li>Add your domain and follow the DNS verification steps</li>
                    <li>Once verified, the domain will appear in the plugin settings</li>
                </ol>

                <h3>3. Set Your Default From Address</h3>
                <p>Configure the default sender information in the Settings tab. This will be used for all WordPress emails unless overridden by another plugin.</p>
            </div>

            <div class="card">
                <h2>How It Works</h2>

                <p>EmailIt Mailer replaces WordPress's default <code>wp_mail()</code> function with a custom implementation that sends emails through the EmailIt API.</p>

                <h3>Email Flow</h3>
                <ol>
                    <li>WordPress or a plugin calls <code>wp_mail()</code></li>
                    <li>The email is logged to the database with "pending" status</li>
                    <li>A WordPress cron job is scheduled to send the email asynchronously</li>
                    <li>The cron job sends the email via EmailIt API</li>
                    <li>The log entry is updated with "sent" or "failed" status</li>
                </ol>

                <div class="tip">
                    <strong>Tip:</strong> Asynchronous sending prevents your site from slowing down when sending emails, especially useful for bulk operations.
                </div>

                <h3>WordPress Cron</h3>
                <p>This plugin uses WordPress cron for email queuing. If you have <code>DISABLE_WP_CRON</code> set to <code>true</code>, you'll need to set up a real server cron job:</p>
                <pre><code>* * * * * wget -q -O - <?php echo esc_html(site_url('/wp-cron.php?doing_wp_cron')); ?> &gt;/dev/null 2>&1</code></pre>
            </div>

            <div class="card">
                <h2>Troubleshooting</h2>

                <h3>Emails Not Sending</h3>
                <table>
                    <tr>
                        <th>Issue</th>
                        <th>Solution</th>
                    </tr>
                    <tr>
                        <td>API key invalid</td>
                        <td>Generate a new API key from your EmailIt Dashboard and update it in Settings</td>
                    </tr>
                    <tr>
                        <td>No sending domains</td>
                        <td>Add and verify at least one sending domain in your EmailIt account</td>
                    </tr>
                    <tr>
                        <td>Cron jobs paused</td>
                        <td>Check if <code>DISABLE_WP_CRON</code> is true in wp-config.php and set up a server cron</td>
                    </tr>
                    <tr>
                        <td>Invalid from address</td>
                        <td>Ensure the "from" email domain matches one of your verified sending domains</td>
                    </tr>
                </table>

                <h3>Enable Debug Logging</h3>
                <p>Add these lines to your <code>wp-config.php</code> to enable detailed logging:</p>
                <pre><code>define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</code></pre>
                <p>Then check <code>wp-content/debug.log</code> for entries starting with "EmailIt:"</p>

                <h3>Check Cron Status</h3>
                <p>Install the <a href="https://wordpress.org/plugins/wp-crontrol/" target="_blank">WP Crontrol</a> plugin to view scheduled cron events. Look for <code>emailit_send_mail_async</code> events.</p>
            </div>

            <div class="card">
                <h2>For Developers</h2>

                <h3>Available Filters</h3>

                <p><strong>Modify the from email address:</strong></p>
                <pre><code>add_filter('wp_mail_from', function($from_email) {
    return 'custom@yourdomain.com';
});</code></pre>

                <p><strong>Modify the from name:</strong></p>
                <pre><code>add_filter('wp_mail_from_name', function($from_name) {
    return 'Custom Sender Name';
});</code></pre>

                <p><strong>Process email data before sending:</strong></p>
                <pre><code>add_filter('emailit_before_send_mail', function($args) {
    // Modify $args['to'], $args['subject'], $args['message'], etc.
    return $args;
});</code></pre>

                <h3>Register Custom Settings Tabs</h3>
                <pre><code>add_action('emailit_register_tabs', function() {
    $emailit = EmailItMailer::get_instance();
    $emailit->register_tab(
        'my_custom_tab',      // Tab ID
        'My Tab',             // Tab label
        'my_tab_callback',    // Render callback function
        50                    // Position (lower = earlier)
    );
});

function my_tab_callback() {
    echo '&lt;div class="card"&gt;Your tab content here&lt;/div&gt;';
}</code></pre>

                <h3>Checking API Status</h3>
                <pre><code>if (EmailItMailer::is_api_active()) {
    // API is connected and working
}</code></pre>

                <h3>Getting Sending Domains</h3>
                <pre><code>$emailit = EmailItMailer::get_instance();
$domains = $emailit->get_sending_domains();
// Returns array: ['domain1.com', 'domain2.com']</code></pre>
            </div>

            <div class="card">
                <h2>Frequently Asked Questions</h2>

                <h3>Does this work with contact form plugins?</h3>
                <p>Yes! EmailIt Mailer is compatible with any plugin that uses WordPress's standard <code>wp_mail()</code> function, including Contact Form 7, WPForms, Gravity Forms, and more.</p>

                <h3>Can I use multiple sending domains?</h3>
                <p>Yes. Add multiple domains in your EmailIt account, and they'll all be available in the plugin. The default domain is set in Settings, but other plugins can override it using the <code>wp_mail_from</code> filter.</p>

                <h3>Why are emails queued instead of sent immediately?</h3>
                <p>Asynchronous sending prevents your website from slowing down, especially when sending bulk emails. The email is queued and sent within seconds via WordPress cron.</p>

                <h3>How do I track email delivery?</h3>
                <p>Use your <a href="https://emailit.com/dashboard" target="_blank">EmailIt Dashboard</a> to view delivery statistics, open rates, click rates, and bounces.</p>

                <h3>What happens if the API is down?</h3>
                <p>If the API cannot be reached, the email will be logged as "failed" and you'll see an error in the debug log. The plugin will display a notice in the admin area when the API connection fails.</p>
            </div>

            <div class="card">
                <h2>Support</h2>
                <p>For support and bug reports:</p>
                <ul>
                    <li><strong>GitHub Issues:</strong> <a href="https://github.com/chalamministries/EmailitWP/issues" target="_blank">Report a bug or request a feature</a></li>
                    <li><strong>EmailIt Support:</strong> <a href="https://emailit.com/support" target="_blank">Contact EmailIt support</a></li>
                    <li><strong>Documentation:</strong> <a href="https://emailit.com/docs" target="_blank">Full API documentation</a></li>
                </ul>
            </div>
        </div>
        <?php
    }

    // Settings Callbacks
    public function section_callback() {
        echo '<p>Configure your EmailIt settings below.</p>';
    }

    public function api_key_callback() {
        $value = isset($this->options['api_key']) ? $this->options['api_key'] : '';
        echo '<input type="password" id="emailit_api_key" name="' . $this->option_name . '[api_key]" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function from_name_callback() {
        $value = isset($this->options['from_name']) ? $this->options['from_name'] : '';
        echo '<input type="text" id="emailit_from_name" name="' . $this->option_name . '[from_name]" value="' . esc_attr($value) . '" class="regular-text">';
    }

   public function sanitize_settings($input) {
       $sanitized = [];
       
       if (isset($input['api_key'])) {
           $sanitized['api_key'] = sanitize_text_field($input['api_key']);
       }
       
       if (isset($input['from_email_prefix'])) {
           $sanitized['from_email_prefix'] = sanitize_text_field($input['from_email_prefix']);
       }
       
       if (isset($input['from_email_domain'])) {
           $sanitized['from_email_domain'] = sanitize_text_field($input['from_email_domain']);
       }
       
       if (isset($input['from_name'])) {
           $sanitized['from_name'] = sanitize_text_field($input['from_name']);
       }
   
       // Add success message if settings are saved successfully
       if ($this->test_api_connection()) {
           add_settings_error(
               'emailit_settings',
               'settings_updated',
               '✅ EmailIt API connection successful!',
               'updated'
           );
       }
       
       return $sanitized;
   }

    // Email Related Methods
   /**
    * This is a patch for the EmailIt Mailer plugin to ensure 'to' fields are 
    * always arrays, even when they come in as strings.
    * 
    * Find the send_mail_async method in your emailit_mailer.php file and replace it with this version.
    */
    
    public function process_email_data($args) {
        // Check if this is a FluentCRM email by looking for our custom header
        $is_fluentcrm_email = false;
        
        if (!empty($args['headers'])) {
            $headers = is_array($args['headers']) ? $args['headers'] : explode("\n", str_replace("\r\n", "\n", $args['headers']));
            
            foreach ($headers as $header) {
                if (is_string($header) && strpos($header, ':') !== false) {
                    list($name, $value) = explode(':', $header, 2);
                    if (trim(strtolower($name)) === 'x-emailit-source' && trim($value) === 'FluentCRM') {
                        $is_fluentcrm_email = true;
                        break;
                    }
                } elseif (is_array($header) && isset($header['X-EmailIt-Source']) && $header['X-EmailIt-Source'] === 'FluentCRM') {
                    $is_fluentcrm_email = true;
                    break;
                }
            }
        }
        
        // If this is a FluentCRM email, apply your custom TO field logic
        if ($is_fluentcrm_email) {
            
            // if (isset($args['to']) && !is_array($args['to'])) {
            //     $args['to'] = [$args['to']];
            // }
            // 

        }
        
        return $args;
    }

    private function normalize_recipient_list($recipients): array
    {
        if (empty($recipients)) {
            return [];
        }

        if (!is_array($recipients)) {
            $recipients = strpos($recipients, ',') !== false
                ? array_map('trim', explode(',', $recipients))
                : [trim($recipients)];
        } else {
            $recipients = array_map(function ($recipient) {
                if (is_string($recipient)) {
                    return trim($recipient);
                }

                if (is_array($recipient)) {
                    $email = isset($recipient['email']) ? trim((string) $recipient['email']) : '';
                    if ($email === '') {
                        return '';
                    }

                    $name = isset($recipient['name']) ? trim((string) $recipient['name']) : '';
                    return $name !== '' ? sprintf('%s <%s>', $name, $email) : $email;
                }

                return '';
            }, $recipients);
        }

        $normalized = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            if (!is_string($recipient)) {
                continue;
            }

            $recipient = trim($recipient);
            if ($recipient === '') {
                continue;
            }

            $email = $this->extract_email_address($recipient);
            if ($email === '') {
                continue;
            }

            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $recipient;
        }

        return $normalized;
    }

    private function extract_email_address(string $recipient): string
    {
        if (preg_match('/<([^>]+)>/', $recipient, $matches)) {
            return sanitize_email(trim($matches[1]));
        }

        return sanitize_email(trim($recipient));
    }

    private function extract_emailit_message_id($response): ?string
    {
        if (!is_array($response) || empty($response)) {
            return null;
        }

        $keys = ['email_id', 'id', 'message_id', 'uuid'];
        foreach ($keys as $key) {
            $value = $this->recursive_find_value($response, $key);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function recursive_find_value(array $data, string $key)
    {
        if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
            return $data[$key];
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->recursive_find_value($value, $key);
                if ($found !== null && $found !== '') {
                    return $found;
                }
            }
        }

        return null;
    }
   
  public function send_mail_async($args) {
     $this->log_debug('Entering send_mail_async', $args);
     
     // Get log ID if available
     $log_id = isset($args['log_id']) ? $args['log_id'] : false;
     if (!$log_id) {
         // Try to find matching log entry
         $logger = EmailIt_Logger::get_instance();
         $log_id = $logger->find_log_id_by_properties($args);
     }
     
     // Check if this is a forced error test
     $is_forced_error = false;
     if (!empty($args['headers'])) {
         $headers = is_array($args['headers']) ? $args['headers'] : explode("\n", str_replace("\r\n", "\n", $args['headers']));
         
         foreach ($headers as $header) {
             if (is_string($header) && strpos($header, ':') !== false) {
                 list($name, $value) = explode(':', $header, 2);
                 $name = trim($name);
                 $value = trim($value);
                 
                 if (strtolower($name) === 'x-emailit-force-error' && trim($value) === 'true') {
                     $is_forced_error = true;
                     break;
                 }
             }
         }
     }
     
     // Don't proceed if API is not active (unless this is a forced error test)
     if (!self::$api_active && !$is_forced_error) {
         $this->log_debug('API not active, aborting');
         
         if ($log_id) {
             $logger = EmailIt_Logger::get_instance();
             $logger->update_email_status($log_id, 'failed', 'API connection is not active. Check API key configuration.');
         }
         
         return false;
     }
     
     try {
         // Get plugin settings
         $settings = $this->get_settings();
         $this->log_debug('Retrieved settings', $settings);
         
         if (empty($settings['api_key'])) {
             $this->log_debug('API key not configured');
             return false;
         }
     
         // Initialize EmailIt client
         $client = new EmailIt\EmailItClient($settings['api_key']);
         $email = $client->email();
     
         // Determine the from address and name
         $from_email = $settings['from_email']; // Default from our settings
         $from_name = $settings['from_name']; // Get default from name
         
         // Check if wp_mail_from filter is set (this is how we inject our invalid from for forced errors)
         $wp_mail_from = apply_filters('wp_mail_from', $from_email);
         $wp_mail_from_name = apply_filters('wp_mail_from_name', $from_name);
         
         // For forced error tests, we'll use whatever from address is provided, even if invalid
         if ($is_forced_error) {
              $random_string = substr(md5(time()), 0, 10);
              $invalid_domain = 'nonexistent-domain-' . $random_string . '.xyz';
              $invalid_from = 'error-test@' . $invalid_domain;
             $from_email = $invalid_from;
             $from_name = $wp_mail_from_name;
             $this->log_debug('Force error test using invalid from: ' . $from_email);
         } else if ($wp_mail_from !== $from_email && $this->is_valid_sending_domain($wp_mail_from)) {
             // For normal emails, only use if it's a valid sending domain
             $from_email = $wp_mail_from;
             $from_name = $wp_mail_from_name;
         }
         
         // Check headers for From: override (for normal emails)
         if (!$is_forced_error && !empty($args['headers'])) {
             foreach ($headers as $header) {
                 if (strpos($header, ':') !== false) {
                     list($name, $value) = explode(':', $header, 2);
                     $name = trim($name);
                     $value = trim($value);
                     
                     if (strtolower($name) === 'from') {
                         // Extract email and name from "Name <email@domain.com>" format
                         if (preg_match('/^(.*?)\s*<(.+?)>$/', $value, $matches)) {
                             $header_from_name = trim($matches[1]);
                             $header_from = trim($matches[2]);
                         } else {
                             $header_from = trim($value);
                             $header_from_name = ''; // No name provided
                         }
                         
                         // Only use this from address if it's from a valid sending domain
                         if ($this->is_valid_sending_domain($header_from)) {
                             $from_email = $header_from;
                             if ($header_from_name) {
                                 $from_name = $header_from_name;
                             }
                             break;
                         } else {
                             $this->log_debug('EmailIt invalid sending domain in headers: ' . $header_from);
                         }
                     }
                 }
             }
         }
       
         // Final validation of from email - skip for forced error tests
         if (!$is_forced_error && !$this->is_valid_sending_domain($from_email)) {
             $this->log_debug('EmailIt no valid sending domain found for from address: ' . $from_email);
             
             if ($log_id) {
                 $logger = EmailIt_Logger::get_instance();
                 $logger->update_email_status($log_id, 'failed', 'Invalid sending domain: ' . $from_email);
             }
             
             return false;
         }
       
         // Set the validated from address with name if available
         $from = $from_name ? sprintf('%s <%s>', $from_name, $from_email) : $from_email;
           
     
         $email->from($from)
               ->replyTo($from_email);
     
         // Process the rest of the headers
         if (!empty($args['headers'])) {
             foreach ($headers as $header) {
                 if (strpos($header, ':') !== false) {
                     list($name, $value) = explode(':', $header, 2);
                     $name = trim($name);
                     $value = trim($value);
                     
                     switch (strtolower($name)) {
                         case 'reply-to':
                             // Extract just the email from "Name <email@domain.com>" format
                             if (preg_match('/<([^>]+)>/', $value, $matches)) {
                                 $reply_email = trim($matches[1]);
                             } else {
                                 $reply_email = trim($value);
                             }
                             $email->replyTo($reply_email);
                             break;
                         case 'from':
                             // Already handled above
                             break;
                         case 'x-emailit-force-error':
                         case 'x-emailit-source':
                             // Skip internal headers
                             break;
                         case 'content-type':
                         case 'content-transfer-encoding':
                         case 'mime-version':
                             // Skip content-type headers - the API handles this automatically
                             break;
                         default:
                             $email->addHeader($name, $value);
                     }
                 }
             }
         }
     
         // Set subject - decode HTML entities (e.g., &#8211; -> –)
         $subject = html_entity_decode($args['subject'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
         $email->subject($subject);
     
         // Set message content - HTML and plain text
         $email->html($args['message']);
         if (isset($args['text_message'])) {
             $email->text($args['text_message']);
         } else {
             $email->text(wp_strip_all_tags($args['message']));
         }
     
         // Process attachments if they exist
         if (!empty($args['attachments'])) {
             $attachments = is_array($args['attachments']) ? $args['attachments'] : [$args['attachments']];
             foreach ($attachments as $attachment) {
                 if (file_exists($attachment)) {
                     $content = file_get_contents($attachment);
                     $filename = basename($attachment);
                     $mime_type = mime_content_type($attachment);
                     $email->addAttachment($filename, base64_encode($content), $mime_type);
                 }
             }
         }
     

        // Add BCC recipients if provided (used for batch sending)
        if (!empty($args['bcc'])) {
            $bccRecipients = $this->normalize_recipient_list($args['bcc']);
            if (!empty($bccRecipients)) {
                $email->bcc($bccRecipients);
            }
        }

        // Add CC recipients if provided
        if (!empty($args['cc'])) {
            $ccRecipients = $this->normalize_recipient_list($args['cc']);
            if (!empty($ccRecipients)) {
                $email->cc($ccRecipients);
            }
        }

        $normalizedTo = $this->normalize_recipient_list($args['to'] ?? []);
        if (empty($normalizedTo)) {
            $error_message = 'No valid recipients found for EmailIt send.';
            $this->log_debug($error_message, $args);

            if ($log_id) {
                $logger = EmailIt_Logger::get_instance();
                $logger->update_email_status($log_id, 'failed', $error_message);
            }

            return false;
        }

        $this->log_debug('Normalized recipients before send', $normalizedTo);

        try {
                $success = true;
                $error_message = '';
                $deliveredEmailIds = [];

                if (count($normalizedTo) > 1) {
                    // Send individual email to each recipient
                    foreach ($normalizedTo as $recipient) {
                        $email->to($recipient);

                        try {
                            $result = $email->send();
                            if (empty($result)) {
                                $success = false;
                                $error_message = 'Unknown error from EmailIt API. Empty response received.';
                                break;
                            }

                            $emailId = $this->extract_emailit_message_id($result);
                            if ($emailId) {
                                $deliveredEmailIds[] = $emailId;
                            }
                        } catch (Exception $e) {
                            $success = false;
                            $error_message = $e->getMessage() . "\n==DEBUG== " . json_encode($headers);
                            $this->log_debug('Error sending to ' . $recipient . ': ' . $error_message);
                            break; // Stop on first error
                        }
                    }
                } else {
                    // Single recipient
                    $recipient = $normalizedTo[0];
                    $email->to($recipient);

                    try {
                        $result = $email->send();
                        if (empty($result)) {
                            $success = false;
                            $error_message = 'Unknown error from EmailIt API. Empty response received.';
                        } else {
                            $emailId = $this->extract_emailit_message_id($result);
                            if ($emailId) {
                                $deliveredEmailIds[] = $emailId;
                            }
                        }
                    } catch (Exception $e) {
                        $success = false;
                        $error_message = $e->getMessage() . "\n==DEBUG== " . json_encode($headers);
                        $this->log_debug('Error sending to ' . $recipient . ': ' . $error_message);
                    }
                }

                // Update log status
                if ($log_id) {
                    $logger = EmailIt_Logger::get_instance();
                    $logger->update_email_status(
                        $log_id,
                        $success ? 'sent' : 'failed',
                        $error_message,
                        [
                            'email_id' => empty($deliveredEmailIds) ? null : implode(',', array_unique($deliveredEmailIds))
                        ]
                    );
                }

                $this->log_debug($success ? 'Async email sent successfully via EmailIt SDK' : 'Failed to send email: ' . $error_message);

                return $success;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
                $this->log_debug('Exception in send_mail_async: ' . $error_message);
                if ($log_id) {
                    $logger = EmailIt_Logger::get_instance();
                    $logger->update_email_status($log_id, 'failed', $error_message);
                }

                return false;
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->log_debug('Exception in send_mail_async: ' . $error_message);
            if ($log_id) {
                $logger = EmailIt_Logger::get_instance();
                $logger->update_email_status($log_id, 'failed', $error_message);
            }
            
            return false;
        }
  }
  

    public function process_email_batch($args) {
        $batch = $args['batch'];

        if (empty($batch)) {
            wp_clear_scheduled_hook('emailit_process_email_batch', [$args]);
            return;
        }

        // First recipient goes in TO, rest go in BCC for a single API call
        $to_recipient = array_shift($batch);
        $bcc_recipients = $batch; // Remaining recipients

        $email_args = [
            'to' => $to_recipient,
            'subject' => $args['subject'],
            'message' => $args['message'],
            'headers' => $args['headers'],
            'text_message' => isset($args['text_message']) ? $args['text_message'] : null
        ];

        // Add BCC recipients if there are any
        if (!empty($bcc_recipients)) {
            $email_args['bcc'] = $bcc_recipients;
        }

        // Log the batch email
        $log_id = $this->logger->log_email($email_args);

        // Add log ID to args for tracking
        if ($log_id) {
            $email_args['log_id'] = $log_id;
        }

        // Send the email (single API call)
        $this->send_mail_async($email_args);

        wp_clear_scheduled_hook('emailit_process_email_batch', [$args]);
    }

    public function add_cron_interval($schedules) {
        $schedules['emailit_every_minute'] = array(
            'interval' => 60, // 60 seconds = 1 minute
            'display'  => 'Every Minute'
        );
        return $schedules;
    }

    /**
     * Handle the async email cron event
     */
    public function handle_async_email_cron($args) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EmailIt: Cron job emailit_send_mail_async executing');
            error_log('EmailIt: Args received: ' . print_r($args, true));
        }
        $this->send_mail_async($args);
    }

    public function get_settings() {
        $settings = $this->options;
        // Combine email parts for the from_email setting
        if (!empty($settings['from_email_prefix']) && !empty($settings['from_email_domain'])) {
            $settings['from_email'] = $settings['from_email_prefix'] . '@' . $settings['from_email_domain'];
        } else {
            $settings['from_email'] = '';
        }
        return $settings;
    }
    
    public function process_test_email_submission() {
        if (
            isset($_POST['send_test_email']) && 
            isset($_POST['emailit_test_email_nonce']) && 
            wp_verify_nonce($_POST['emailit_test_email_nonce'], 'emailit_send_test_email')
        ) {
            $to = sanitize_email($_POST['test_email_to']);
            $subject = sanitize_text_field($_POST['test_email_subject']);
            $force_error = isset($_POST['force_error']) ? (bool) $_POST['force_error'] : false;
            
            if (!is_email($to)) {
                add_settings_error(
                    'emailit_test_email',
                    'invalid_email',
                    'Please enter a valid email address.',
                    'error'
                );
                return;
            }
            
            // Generate the test email content
            $html_content = $this->get_test_email_content($force_error);
            $text_content = wp_strip_all_tags($html_content);
            
            if ($force_error) {
                // Generate a random string to create a non-existent domain
                $random_string = substr(md5(time()), 0, 10);
                $invalid_domain = 'nonexistent-domain-' . $random_string . '.xyz';
                $invalid_from = 'error-test@' . $invalid_domain;
                
                // Create a temporary filter to use invalid sender domain
                add_filter('wp_mail_from', function($from) use ($invalid_from) {
                    return $invalid_from;
                });
                
                add_filter('wp_mail_from_name', function($name) {
                    return 'EmailIt Error Test';
                });
                
                // Standard headers
                $headers = [
                    'Content-Type: text/html; charset=UTF-8',
                    'X-EmailIt-Source: Test',
                    'X-EmailIt-Force-Error: true'
                ];
                
                // Log what we're doing
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('EmailIt Test: Forcing error with invalid sender: ' . $invalid_from);
                }
                
                try {
                    // Use our direct wp_mail override
                    $result = wp_mail($to, $subject . ' (Forced Error Test)', $html_content, $headers, []);
                    
                    add_settings_error(
                        'emailit_test_email',
                        'forced_error',
                        'Error test initiated! Check the email logs to see the error details. The test email attempted to send from the invalid domain "' . esc_html($invalid_domain) . '".',
                        'updated'
                    );
                } catch (Exception $e) {
                    add_settings_error(
                        'emailit_test_email',
                        'error_caught',
                        'Error caught as expected: ' . esc_html($e->getMessage()),
                        'updated'
                    );
                }
                
                // Remove our temporary filters
                remove_filter('wp_mail_from', '__return_empty_string', 1);
                remove_filter('wp_mail_from_name', '__return_empty_string', 1);
                
            } else {
                // Normal email sending
                $headers = [
                    'Content-Type: text/html; charset=UTF-8',
                    'X-EmailIt-Source: Test'
                ];
                
                $result = wp_mail($to, $subject, $html_content, $headers);
                
                if ($result) {
                    add_settings_error(
                        'emailit_test_email',
                        'email_sent',
                        'Test email has been sent! Please check your inbox.',
                        'updated'
                    );
                } else {
                    add_settings_error(
                        'emailit_test_email',
                        'email_failed',
                        'Failed to send test email. Check your EmailIt settings and try again.',
                        'error'
                    );
                }
            }
        }
    }
    
    /**
     * Generate HTML content for the test email
     */
    private function get_test_email_content($force_error = false) {
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        $admin_email = get_option('admin_email');
        $date_time = date('Y-m-d H:i:s');
        
        // Get the current from email
        $from_email = $this->options['from_email_prefix'] . '@' . $this->options['from_email_domain'];
        
        // Get EmailIt sending domains
        $sending_domains = $this->get_sending_domains();
        $domain_list = !empty($sending_domains) 
            ? implode(', ', $sending_domains) 
            : 'No sending domains configured';
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
                .container { padding: 20px; }
                .header { background-color: #15c182; color: white; padding: 15px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-left: 1px solid #ddd; border-right: 1px solid #ddd; }
                .footer { background: #f1f1f1; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 5px 5px; border: 1px solid #ddd; }
                .section { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
                h2 { color: #15c182; }
                .success { color: #15c182; }
                .warning { color: #ff9800; }
                .error { color: #f44336; }
                table { border-collapse: collapse; width: 100%; }
                th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>EmailIt Test Email<?php echo $force_error ? ' (Error Test)' : ''; ?></h1>
                </div>
                <div class="content">
                    <?php if ($force_error): ?>
                    <div class="section" style="background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffc107;">
                        <h2 style="color: #856404;">Error Test Mode</h2>
                        <p>This email is part of a test to verify error logging. It will be sent to a non-existent domain and is expected to fail.</p>
                        <p>Check your email logs to see the error details.</p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="section">
                        <h2>Formatting Test</h2>
                        <p>This paragraph is in <strong>bold</strong>, <em>italic</em>, and has a <a href="<?php echo esc_url($site_url); ?>">link to your site</a>.</p>
                        <p>Here's a list of items:</p>
                        <ul>
                            <li>Item 1 with <strong>formatting</strong></li>
                            <li>Item 2 with <em>emphasis</em></li>
                            <li>Item 3 with a <a href="https://anthropic.com">link to Anthropic</a></li>
                        </ul>
                    </div>
                    
                    <div class="section">
                        <h2>Sending Domain Information</h2>
                        <p>
                            <strong>From Email:</strong> <?php echo esc_html($from_email); ?>
                            <?php if ($this->is_valid_sending_domain($from_email)): ?>
                                <span class="success">✓ Valid sending domain</span>
                            <?php else: ?>
                                <span class="error">✗ Not a valid sending domain</span>
                            <?php endif; ?>
                        </p>
                        <p><strong>Available Sending Domains:</strong> <?php echo esc_html($domain_list); ?></p>
                    </div>
                    
                    <div class="section">
                        <h2>WordPress Site Information</h2>
                        <table>
                            <tr>
                                <th>Site Name</th>
                                <td><?php echo esc_html($site_name); ?></td>
                            </tr>
                            <tr>
                                <th>Site URL</th>
                                <td><?php echo esc_html($site_url); ?></td>
                            </tr>
                            <tr>
                                <th>Admin Email</th>
                                <td><?php echo esc_html($admin_email); ?></td>
                            </tr>
                            <tr>
                                <th>PHP Version</th>
                                <td><?php echo esc_html(phpversion()); ?></td>
                            </tr>
                            <tr>
                                <th>WordPress Version</th>
                                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                <div class="footer">
                    <p>This test email was sent from EmailIt Mailer plugin on <?php echo esc_html($date_time); ?></p>
                    <p>If you received this email, your EmailIt configuration is working correctly!</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

// Initialize the plugin
$emailit_mailer = EmailItMailer::get_instance();
$emailit_mailer->init();
    
   if (!function_exists('wp_mail')) {
       /**
        * Override WordPress mail function to use EmailIt SDK
        * 
        * @param string|array $to Array or comma-separated list of email addresses to send message.
        * @param string $subject Email subject
        * @param string $message Message contents
        * @param string|array $headers Optional. Additional headers.
        * @param string|array $attachments Optional. Files to attach.
        * @return bool Whether the email contents were sent successfully.
        */
       function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
       
           //$emailit_mailer->log_debug("================= Sending email to: " . json_encode($to) . " [" . $subject . "]");
          
           
           // Convert string 'to' recipients to array format
           if (!is_array($to)) {
               $to = explode(',', $to);
               // Trim whitespace from each recipient
               $to = array_map('trim', $to);
           }
           
           // Create email args
           $args = array(
               'to' => $to,
               'subject' => $subject,
               'message' => $message,
               'headers' => $headers,
               'attachments' => $attachments
           );
           
           // Log the email
           $logger = EmailIt_Logger::get_instance();
           $log_id = $logger->log_email($args);
           
           // Add log ID to args for tracking
           if ($log_id) {
               $args['log_id'] = $log_id;
           }
           
           // Schedule a cron event to send the email asynchronously
           $timestamp = time() + 5; // Schedule 5 seconds in the future
           $hook = 'emailit_send_mail_async';

           // Debug: Check if cron is disabled
           if (defined('WP_DEBUG') && WP_DEBUG) {
               error_log('EmailIt: Attempting to schedule cron event');
               error_log('EmailIt: DISABLE_WP_CRON = ' . (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'true' : 'false'));
               error_log('EmailIt: Hook = ' . $hook);
               error_log('EmailIt: Timestamp = ' . $timestamp . ' (current time: ' . time() . ')');
           }

           $scheduled = wp_schedule_single_event($timestamp, $hook, array($args));

           if (defined('WP_DEBUG') && WP_DEBUG) {
               error_log('EmailIt: wp_schedule_single_event returned: ' . var_export($scheduled, true));

               // Check if the event was actually scheduled
               $next = wp_next_scheduled($hook, array($args));
               error_log('EmailIt: wp_next_scheduled returned: ' . var_export($next, true));
           }

           // If cron scheduling failed, log error but still return true
           // (the email was logged, it just won't be sent via cron)
           if ($scheduled === false) {
               if (defined('WP_DEBUG') && WP_DEBUG) {
                   error_log('EmailIt: WARNING - Cron scheduling failed!');
               }
           }

           return true;
       }
   }
   
   // Add activation hook to ensure options are set up
   register_activation_hook(__FILE__, function() {
       $options = get_option('emailit_settings');
       if ($options === false) {
           add_option('emailit_settings', [
               'api_key' => '',
               'from_email' => get_option('admin_email'),
               'from_name' => get_option('blogname')
           ]);
       }
   });
   
   add_action('wp_ajax_emailit_get_email_content', function() {
       $logger = EmailIt_Logger::get_instance();
       $logger->ajax_get_email_content();
   });
   
   // Create plugin assets directory and save the logo
   add_action('plugins_loaded', function() {
       $assets_dir = plugin_dir_path(__FILE__) . 'assets';
       if (!file_exists($assets_dir)) {
           mkdir($assets_dir, 0755, true);
       }
       
       $logo_path = $assets_dir . '/emailit-logo.svg';
       if (!file_exists($logo_path)) {
           $logo_content = '<?xml version="1.0" encoding="UTF-8"?><svg id="Layer_2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 297.04 90.19"><g id="Layer_1-2"><path d="m70.06,45.47c0-11.31,7.91-19.53,19.15-19.54,11.84,0,19.23,10.25,17.5,23.14h-25.41c.76,4.46,4.6,6.49,8.22,6.49,3.32,0,6.18-1.81,7.69-4.6l9.13,4.97c-3.32,5.66-9.65,8.98-16.81,8.98-11.31,0-19.46-8.14-19.46-19.45Zm26.32-5.36c-.75-3.09-3.47-4.98-6.94-4.97-3.54,0-6.71,1.81-7.46,4.98h14.4Z" stroke-width="0"/><path d="m112.67,64.16V26.68s9.87,0,9.87,0l.08,2.04c1.96-1.36,4.6-2.79,8.22-2.79,4.37,0,8.14,1.66,10.86,4.52,2.64-2.56,6.03-4.53,10.63-4.53,9.43,0,15.84,7.16,15.84,17.49v20.74s-10.02,0-10.02,0v-20.13c0-5.28-2.42-8.45-6.72-8.44-2.26,0-4,1.13-4.98,3.09.15,1.81.23,3.7.23,5.58v19.91s-10.1,0-10.1,0v-20.13c0-5.28-2.42-8.45-6.65-8.44-4.37,0-7.16,3.4-7.16,8.07v20.51s-10.1,0-10.1,0Z" stroke-width="0"/><path d="m173.89,52.22c0-7.92,6.48-12.9,16.36-13.35,1.73-.08,3.54.07,5.13.38l-.08-.6c-.3-1.66-1.89-3.09-4.53-3.32-3.17-.3-7.54,1.06-12.14,3.7l-3.47-9.05c5.5-3.09,10.33-4.08,15.31-4.08,9.58,0,16.14,6.03,16.14,16.13v22.09s-9.87,0-9.87,0v-1.81c-2.26,1.43-5.05,2.57-8.52,2.57-8.29,0-14.33-4.97-14.33-12.66Zm22.17-1.67l.15-2.19c-1.66-.38-3.39-.53-5.35-.45-4.37.3-6.79,2.19-6.79,4.3,0,1.96,1.74,3.69,4.68,3.69,3.32-.08,6.71-2.11,7.31-5.36Z" stroke-width="0"/><path d="m211.58,17.14c0-3.24,2.41-5.81,5.73-5.81,3.24,0,5.81,2.56,5.81,5.8,0,3.32-2.56,5.81-5.8,5.81-3.32,0-5.73-2.49-5.73-5.8Zm.32,46.98V26.64s10.17,0,10.17,0v37.48s-10.17,0-10.17,0Z" stroke-width="0"/><path d="m249.45,62c-9.65,6.41-20.51,1.06-20.52-10.7l-.09-39.97h10.03s.09,39.21.09,39.21c0,4.37,2.87,5.13,6.79,2.94l3.7,8.52Z" stroke-width="0"/><path d="m253.58,17.13c0-3.24,2.41-5.81,5.73-5.81,3.24,0,5.81,2.56,5.81,5.8,0,3.32-2.56,5.81-5.8,5.81-3.32,0-5.73-2.49-5.73-5.8Zm.32,46.98V26.63s10.17,0,10.17,0v37.48s-10.17,0-10.17,0Z" stroke-width="0"/><path d="m294.16,60.85c-11.08,7.62-23.98,3.63-23.98-9.12l-.09-40.42h10.1s0,15.3,0,15.3h8.37s0,9.65,0,9.65h-8.37s.08,14.1.08,14.1c0,4.9,4.53,5.5,9.05,2.33l4.83,8.14Z" stroke-width="0"/><g id="mail-send-envelope--envelope-email-message-unopened-sealed-close"><g id="Subtract"><path d="m7.83,63.82c3.85.27,9.96.55,18.68.54,8.72,0,14.84-.29,18.68-.56,3.85-.27,6.9-3.18,7.25-7.06.29-3.34.58-8.38.57-15.36,0-.44,0-.88,0-1.3-3.14,1.45-6.31,2.83-9.51,4.13-2.95,1.19-6.15,2.39-9.11,3.29-2.91.89-5.74,1.55-7.88,1.55s-4.98-.66-7.88-1.55c-2.96-.9-6.16-2.1-9.11-3.29-3.2-1.29-6.38-2.67-9.51-4.12,0,.43,0,.86,0,1.31,0,6.98.29,12.02.58,15.36.34,3.89,3.4,6.79,7.25,7.06Z" fill="#15c182" stroke-width="0"/><path d="m.05,36.22c.1-4.37.31-7.74.52-10.19.34-3.89,3.4-6.79,7.25-7.06,3.85-.27,9.96-.55,18.68-.56,8.72,0,14.84.28,18.68.54,3.85.27,6.91,3.17,7.25,7.06.22,2.45.43,5.81.53,10.19-.04.02-.08.03-.12.05l-.05.02-.16.08c-.98.46-1.95.91-2.94,1.35-2.48,1.12-4.98,2.19-7.51,3.22-2.9,1.17-6,2.33-8.82,3.19-2.87.88-5.27,1.4-6.85,1.4s-3.98-.52-6.85-1.39c-2.82-.86-5.92-2.02-8.82-3.19-3.52-1.42-7.01-2.95-10.45-4.56l-.16-.08-.05-.02s-.08-.04-.12-.05h0Z" fill="#007b5e" stroke-width="0"/></g></g></g></svg>';
           file_put_contents($logo_path, $logo_content);
       }
       
   });