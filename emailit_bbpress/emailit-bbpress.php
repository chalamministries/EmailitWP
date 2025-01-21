<?php
/*
Plugin Name: EmailIt BBPress Integration
Plugin URI: 
Description: BBPress integration for EmailIt Mailer
Version: 1.6
Author: Steven Gauerke
Requires at least: 5.8
Requires PHP: 7.4
License: GPL2
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
	exit;
}

class EmailItBBPress {
	private static $instance = null;
	
	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('plugins_loaded', [$this, 'init']);
		add_action('emailit_register_tabs', [$this, 'register_bbpress_tab']);
	}
	
	public function register_bbpress_tab() {
		$emailit = EmailItMailer::get_instance();
		// Register before docs (100) but after settings (10)
		$emailit->register_tab('bbpress', 'BBPress', [$this, 'render_bbpress_tab'], 90);
	}

	public function init() {
		// Check if required plugins are active
		if (!$this->check_dependencies()) {
			return;
		}

		// Remove bbPress's default notification function
		remove_action('bbp_new_reply', 'bbp_notify_topic_subscribers', 11);
		
		// Add our custom notification handler
		add_action('bbp_new_reply', [$this, 'custom_bbp_notify_topic_subscribers'], 11, 5);
	}

	private function check_dependencies() {
		if (!function_exists('is_plugin_active')) {
			include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		}

		$missing_plugins = [];

		if (!is_plugin_active('bbpress/bbpress.php')) {
			$missing_plugins[] = 'BBPress';
		}

		if (!is_plugin_active('emailit_mailer/emailit_mailer.php')) {
			$missing_plugins[] = 'EmailIt Mailer';
		}

		if (!empty($missing_plugins)) {
			add_action('admin_notices', function() use ($missing_plugins) {
				$message = 'EmailIt BBPress Integration requires the following plugins: ' . implode(', ', $missing_plugins);
				echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
			});
			return false;
		}

		return true;
	}
	
	public function render_bbpress_tab() {
		?>
		<div class="card" style="max-width: 800px; margin-top: 20px;">
			<h2>BBPress Integration Settings</h2>
			<!-- Your BBPress settings content here -->
			Custom Email Template Coming Soon!
		</div>
		<?php
	}

	public function custom_bbp_notify_topic_subscribers($reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $reply_author = 0) {
		// Get subscriber IDs
		$user_ids = bbp_get_topic_subscribers($topic_id, true);
		
		if (empty($user_ids)) {
			return false;
		}
		
		if(WP_DEBUG) {
			error_log("BB Press notify Users");
			error_log(print_r($user_ids, true));
		}
		
		// Get reply author ID if not provided
		if (!$reply_author) {
			$reply_author = bbp_get_reply_author_id($reply_id);
		}
		
		$recipients = [];
		foreach ($user_ids as $user_id) {
			if ($user_id != $reply_author) {
				$user = get_userdata($user_id);
				if (!empty($user->user_email)) {
					$recipients[] = $user->user_email;
				}
			}
		}
		
		if (empty($recipients)) {
			return false;
		}
		
		// Get reply details
		$reply_author_name = bbp_get_reply_author_display_name($reply_id);
		$topic_title = bbp_get_topic_title($topic_id);
		$reply_url = bbp_get_reply_url($reply_id);
		$forum_id = bbp_get_topic_forum_id($topic_id);
		$forum_title = bbp_get_forum_title($forum_id);
		
		$custom_logo_id = get_theme_mod('custom_logo');
		$logo_url = '';
		if ($custom_logo_id) {
			$logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
		}
		
		// Build HTML email
		$html_message = $this->get_email_template([
			'logo_url' => $logo_url,
			'forum_title' => $forum_title,
			'topic_title' => $topic_title,
			'reply_author_name' => $reply_author_name,
			'reply_content' => bbp_get_reply_content($reply_id),
			'reply_url' => $reply_url
		]);
	
		// Create plain text version
		$text_message = $this->get_text_email_content([
			'reply_author_name' => $reply_author_name,
			'reply_content' => bbp_get_reply_content($reply_id),
			'forum_title' => $forum_title,
			'topic_title' => $topic_title,
			'reply_url' => $reply_url
		]);
		
		// Batch size - how many emails to process in each cron job
		$batch_size = 10;
		
		// Split recipients into batches and schedule a job for each batch
		$batches = array_chunk($recipients, $batch_size);
		
		foreach($batches as $index => $batch) {
			if(WP_DEBUG) {
				
				error_log("Here's the batch");
				error_log(json_encode($batch));
			}
			wp_schedule_event(
				time() + ($index * 60),
				'emailit_every_minute',
				'emailit_process_email_batch',
				[[
					'batch' => $batch,
					'subject' => '[' . wp_specialchars_decode($forum_title, ENT_QUOTES) . '] ' . $topic_title,
					'message' => $html_message,
					'headers' => [
						'Content-Type: text/html; charset=UTF-8',
						'X-bbPress: ' . bbp_get_version(),
						'From: ' . get_bloginfo('name') . ' <noreply@' . parse_url(home_url(), PHP_URL_HOST) . '>'
					],
					'text_message' => $text_message
				]]
			);
		}
		
		return true;
	}

	private function get_email_template($data) {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<style>
				body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
				.container { max-width: 600px; margin: 20px auto; padding: 20px; }
				.header img { max-height: 60px; width: auto; }
				.header { border-bottom: 2px solid #15c182; padding-bottom: 10px; margin-bottom: 20px; }
				<?php echo empty($data['logo_url']) ? '.header { text-align: center; font-size: 24px; font-weight: bold; }' : ''; ?>
				.forum-title { color: #666; font-size: 14px; margin-bottom: 10px; }
				.topic-title { font-size: 20px; font-weight: bold; margin-bottom: 20px; color: #333; }
				.reply-content { background: #f9f9f9; padding: 20px; border-left: 4px solid #15c182; margin-bottom: 20px; }
				.author { color: #15c182; font-weight: bold; margin-bottom: 10px; }
				.footer { border-top: 1px solid #eee; margin-top: 20px; padding-top: 20px; font-size: 12px; color: #666; }
				.button { display: inline-block; padding: 10px 20px; background-color: #15c182; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<?php if (!empty($data['logo_url'])): ?>
						<img src="<?php echo esc_url($data['logo_url']); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" style="height: 60px;">
					<?php else: ?>
						<?php echo esc_html(get_bloginfo('name')); ?>
					<?php endif; ?>
				</div>
				<div class="forum-title">
					Forum: <?php echo esc_html($data['forum_title']); ?>
				</div>
				<div class="topic-title">
					<?php echo esc_html($data['topic_title']); ?>
				</div>
				<div class="reply-content">
					<div class="author"><?php echo esc_html($data['reply_author_name']); ?> wrote:</div>
					<?php echo wpautop(stripslashes($data['reply_content'])); ?>
				</div>
				<a href="<?php echo esc_url($data['reply_url']); ?>" class="button">View Post</a>
				<div class="footer">
					<p>You are receiving this email because you subscribed to this forum topic.</p>
					<p>To unsubscribe from these notifications, visit the topic and click "Unsubscribe".</p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	private function get_text_email_content($data) {
		$text = sprintf("%s wrote:\n\n", $data['reply_author_name']);
		$text .= sprintf("%s\n\n", wp_strip_all_tags($data['reply_content']));
		$text .= sprintf("Forum: %s\n", $data['forum_title']);
		$text .= sprintf("Topic: %s\n", $data['topic_title']);
		$text .= sprintf("Post Link: %s\n\n", $data['reply_url']);
		$text .= "-----------\n\n";
		$text .= "You are receiving this email because you subscribed to this forum topic.\n";
		$text .= "Login and visit the topic to unsubscribe from these emails.";
		
		return $text;
	}
}

// Initialize the plugin
EmailItBBPress::get_instance();