<?php
/*
Plugin Name: EmailIt Mailer for WordPress
Plugin URI: https://github.com/chalamministries/EmailitWP
Description: Overrides WordPress default mail function to use EmailIt SDK
Version: 2.4
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

        
        add_action('emailit_process_email_batch', [$this, 'process_email_batch']);
        $this->log_debug('Registering cron hooks');
        add_action('emailit_send_mail_async', function($args) {
             $this->log_debug('Cron job emailit_send_mail_async executing', $args);
             $this->send_mail_async($args);
             });
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
            $response = $client->sendingDomains()->list(100, 1);
            
            if (isset($response['data']) && is_array($response['data'])) {
                $domains = array_map(function($domain) {
                    return $domain['name'];
                }, $response['data']);
                
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
       <?php 
   }
   
    public function render_docs_tab() {
        ?>
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Documentation</h2>
            <!-- Documentation content will go here -->
            <p>Documentation content placeholder.</p>
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
   
  public function send_mail_async($args) {
     $this->log_debug('Entering send_mail_async', $args);
     
     // Get log ID if available
     $log_id = isset($args['log_id']) ? $args['log_id'] : false;
     if (!$log_id) {
         // Try to find matching log entry
         $logger = EmailIt_Logger::get_instance();
         $log_id = $logger->find_log_id_by_properties($args);
     }
     
      // Don't proceed if API is not active
      if (!self::$api_active) {
           $this->log_debug('API not active, aborting');
           
           if ($log_id) {
               $logger->update_email_status($log_id, 'failed');
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
  
          // Inside the send_mail_async method in EmailItMailer class, replace the from email setting section with:
          
            // Determine the from address and name
            $from_email = $settings['from_email']; // Default from our settings
            $from_name = $settings['from_name']; // Get default from name
            
            // Check if wp_mail_from filter is set
            $wp_mail_from = apply_filters('wp_mail_from', $from_email);
            $wp_mail_from_name = apply_filters('wp_mail_from_name', $from_name);
            
            if ($wp_mail_from !== $from_email && $this->is_valid_sending_domain($wp_mail_from)) {
                $from_email = $wp_mail_from;
                $from_name = $wp_mail_from_name;
            }
          
            // Check headers for From: override
            if (!empty($args['headers'])) {
                $headers = is_array($args['headers']) ? $args['headers'] : explode("\n", str_replace("\r\n", "\n", $args['headers']));
                
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
          
            // Final validation of from email
            if (!$this->is_valid_sending_domain($from_email)) {
             
                $this->log_debug('EmailIt no valid sending domain found for from address: ' . $from_email);
              
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
                              $email->replyTo($value);
                              break;
                          case 'from':
                              // Already handled above
                              break;
                          default:
                              $email->addHeader($name, $value);
                      }
                  }
              }
          }
  
          // Set subject
          $email->subject($args['subject']);
  
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
  
          // Process recipients
          $to = $args['to'];
          
         
            $this->log_debug("just before send: " . json_encode($args['to']));
          
          
          $success = true;
          if (is_array($to)) {
              // Send individual email to each recipient
              foreach ($to as $recipient) {
                  $email->to($recipient);
                
                      $this->log_debug('Email sent to: ' . $recipient);
                  
                  $result = $email->send();
                  if (!$result) {
                      $success = false;
                  }
              }
          } else {
              // Single recipient
              $email->to($to);
              $result = $email->send();
              if (!$result) {
                  $success = false;
              }
              
              
                  $this->log_debug('Email sent to: ' . $to);
              
          }
          
          // Update log status
          if ($log_id) {
              $logger = EmailIt_Logger::get_instance();
              $logger->update_email_status($log_id, $success ? 'sent' : 'failed');
          }

              $this->log_debug('Async email sent successfully via EmailIt SDK');
          
  
          return $success;
  
      } catch (Exception $e) {
           $this->log_debug('Exception in send_mail_async: ' . $e->getMessage());
           if ($log_id) {
               $logger = EmailIt_Logger::get_instance();
               $logger->update_email_status($log_id, 'failed');
           }
           
           return false;
       }
  }
  

    public function process_email_batch($args) {
        
      
        foreach($args['batch'] as $recipient) {
            $email_args = [
                'to' => $recipient,
                'subject' => $args['subject'],
                'message' => $args['message'],
                'headers' => $args['headers'],
                'text_message' => isset($args['text_message']) ? $args['text_message'] : null
            ];
            
            // Log the individual email
            $log_id = $this->logger->log_email($email_args);
            
            // Add log ID to args for tracking
            if ($log_id) {
                $email_args['log_id'] = $log_id;
            }
            
            // Send the email
            $this->send_mail_async($email_args);
        }
        
        wp_clear_scheduled_hook('emailit_process_email_batch', [$args]);
    }

    public function add_cron_interval($schedules) {
        $schedules['emailit_every_minute'] = array(
            'interval' => 60, // 60 seconds = 1 minute
            'display'  => 'Every Minute'
        );
        return $schedules;
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
           
           // Add debugging for cron scheduling
           $timestamp = time();
           $hook = 'emailit_send_mail_async';
           
           
            //$emailit_mailer->log_debug("Scheduling cron event: Hook: {$hook}, Timestamp: {$timestamp}");
          
           
           $scheduled = wp_schedule_single_event($timestamp, $hook, array($args));
           
          
           //$emailit_mailer->log_debug("Cron scheduling result: " . ($scheduled ? "Success" : "Failed"));
         
           
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