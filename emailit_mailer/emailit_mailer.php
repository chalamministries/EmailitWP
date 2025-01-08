<?php
/*
Plugin Name: EmailIt Mailer for WordPress
Plugin URI: 
Description: Overrides WordPress default mail function to use EmailIt SDK
Version: 1.1
Author: Your Name
License: GPL2
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Add EmailIt SDK autoloader
require_once plugin_dir_path(__FILE__) . 'autoload.php';


class EmailItMailer {
    private static $instance = null;
    private $options;
    private $option_name = 'emailit_settings';
    
    public function init() {
        // Remove bbPress's default notification function
        remove_action('bbp_new_reply', 'bbp_notify_topic_subscribers', 11);
        
        // Add our custom notification handler
        add_action('bbp_new_reply', [$this, 'custom_bbp_notify_topic_subscribers'], 11, 5);
    }
    
   public function custom_bbp_notify_topic_subscribers($reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $reply_author = 0) {
       // Get subscriber IDs
       $user_ids = bbp_get_topic_subscribers($topic_id, true);
       
       if (empty($user_ids)) {
           return false;
       }
       
       // Get reply author ID if not provided
       if (!$reply_author) {
           $reply_author = bbp_get_reply_author_id($reply_id);
       }
       
       // Get reply details
       $reply_author_name = bbp_get_reply_author_display_name($reply_id);
       
       // Build the email
       $subject = '[' . wp_specialchars_decode(get_option('blogname'), ENT_QUOTES) . '] ' . bbp_get_topic_title($topic_id);
       $message = $reply_author_name . ' wrote:' . "\n\n";
       $message .= bbp_get_reply_content($reply_id) . "\n\n";
       $message .= "Post Link: " . bbp_get_reply_url($reply_id) . "\n\n";
       $message .= "-----------\n\n";
       $message .= "You are receiving this email because you subscribed to a forum topic.\n\n";
       $message .= "Login and visit the topic to unsubscribe from these emails.";
       
       // Headers with preferred From address
       $headers = array(
           'X-bbPress: ' . bbp_get_version(),
           'From: Logical Investor <noreply@logicalinvestor.net>'
       );
       
       // Send individual emails to each subscriber, excluding the reply author
       foreach ($user_ids as $user_id) {
           // Skip if this user is the reply author
           if ($user_id == $reply_author) {
               continue;
           }
           
           $user = get_userdata($user_id);
           if (!empty($user->user_email)) {
               wp_mail($user->user_email, $subject, $message, $headers);
           }
       }
       
       return true;
   }
   
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize options
        $this->options = get_option($this->option_name, [
            'api_key' => '',
            'from_email' => '',
            'from_name' => ''
        ]);

        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
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
            'emailit_from_email',
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

    public function sanitize_settings($input) {
        $sanitized = [];
        
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['from_email'])) {
            $sanitized['from_email'] = sanitize_email($input['from_email']);
        }
        
        if (isset($input['from_name'])) {
            $sanitized['from_name'] = sanitize_text_field($input['from_name']);
        }
        
        return $sanitized;
    }

    public function section_callback() {
        echo '<p>Configure your EmailIt settings below.</p>';
    }

    public function api_key_callback() {
        $value = isset($this->options['api_key']) ? $this->options['api_key'] : '';
        echo '<input type="password" id="emailit_api_key" name="' . $this->option_name . '[api_key]" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function from_email_callback() {
        $value = isset($this->options['from_email']) ? $this->options['from_email'] : '';
        echo '<input type="email" id="emailit_from_email" name="' . $this->option_name . '[from_email]" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function from_name_callback() {
        $value = isset($this->options['from_name']) ? $this->options['from_name'] : '';
        echo '<input type="text" id="emailit_from_name" name="' . $this->option_name . '[from_name]" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <div style="margin: 20px 0;">
                <img src="<?php echo plugins_url('assets/emailit-logo.svg', __FILE__); ?>" alt="EmailIt Logo" style="max-width: 200px; height: auto;">
            </div>
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('emailit-settings');
                submit_button('Save Settings');
                ?>
            </form>
            <?php if ($this->test_api_connection()): ?>
                <div class="notice notice-success">
                    <p>✅ EmailIt API connection successful!</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function test_api_connection() {
        if (empty($this->options['api_key'])) {
            return false;
        }

        try {
            $client = new EmailIt\EmailItClient($this->options['api_key']);
            // Try to list audiences as a simple API test
            $client->audiences()->list(1, 1);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function get_settings() {
        return $this->options;
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
           
           // Debug logging
           error_log('=================== WP MAIL CALLED ===================');
           error_log('To: ' . (is_array($to) ? json_encode($to) : $to));
           error_log('Subject: ' . $subject);
           error_log('Headers: ' . (is_array($headers) ? json_encode($headers) : $headers));
           
           // Get the calling function info
           $backtrace = debug_backtrace();
           error_log('Called by: ' . json_encode(array_slice($backtrace, 0, 3)));
           
           try {
               // Get plugin settings
               $emailit_mailer = EmailItMailer::get_instance();
               $settings = $emailit_mailer->get_settings();
               
               if (empty($settings['api_key'])) {
                   error_log('EmailIt API key not configured');
                   return false;
               }
   
               // Initialize EmailIt client
               $client = new EmailIt\EmailItClient($settings['api_key']);
               $email = $client->email();
   
               // Set default sender from settings
               $email->from($settings['from_email'])
                     ->replyTo($settings['from_email']);
   
               // Process recipients
               if (is_array($to)) {
                   // If multiple recipients, send to first one and BCC the rest
                   $email->to($to[0]);
                   for ($i = 1; $i < count($to); $i++) {
                       $email->addHeader('Bcc', $to[$i]);
                   }
               } else {
                   $email->to($to);
               }
   
               // Set subject and message
               $email->subject($subject)
                     ->html($message)
                     ->text(wp_strip_all_tags($message));
   
               // Process headers
               if (!empty($headers)) {
                   $headers = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", $headers));
                   
                   foreach ($headers as $header) {
                       if (strpos($header, ':') !== false) {
                           list($name, $value) = explode(':', $header, 2);
                           $name = trim($name);
                           $value = trim($value);
                           
                           // Handle special headers
                           switch (strtolower($name)) {
                               case 'from':
                                   $email->from($value);
                                   break;
                               case 'reply-to':
                                   $email->replyTo($value);
                                   break;
                               default:
                                   $email->addHeader($name, $value);
                           }
                       }
                   }
               }
   
               // Process attachments
               if (!empty($attachments)) {
                   $attachments = is_array($attachments) ? $attachments : [$attachments];
                   
                   foreach ($attachments as $attachment) {
                       if (file_exists($attachment)) {
                           $content = file_get_contents($attachment);
                           $filename = basename($attachment);
                           $mime_type = mime_content_type($attachment);
                           
                           $email->addAttachment($filename, base64_encode($content), $mime_type);
                       }
                   }
               }
   
               // Send email
               $result = $email->send();
               
               // Log success if debug mode is enabled
               if (defined('WP_DEBUG') && WP_DEBUG) {
                   error_log('Email sent successfully via EmailIt SDK');
               }
   
               return true;
   
           } catch (Exception $e) {
               // Log errors if debug mode is enabled
               if (defined('WP_DEBUG') && WP_DEBUG) {
                   error_log('EmailIt SDK error: ' . $e->getMessage());
               }
               return false;
           }
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
       if (defined('WP_DEBUG') && WP_DEBUG) {
           error_log('EmailIt Mailer plugin loaded');
       }
   });
   
