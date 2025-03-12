<?php
/**
 * EmailIt Logger Class
 * 
 * Handles logging of email sending for the EmailIt Mailer plugin
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
	exit;
}

class EmailIt_Logger {
	private static $instance = null;
	private $table_name;
	private $logs_per_page = 20;
	
	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor
	 */
	private function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'emailit_logs';
		
		// Create table if needed
		add_action('plugins_loaded', [$this, 'create_table']);
		
		// Register logging tab in EmailIt settings
		add_action('emailit_register_tabs', [$this, 'register_logs_tab']);
		
		// Register retention settings
		add_action('admin_init', [$this, 'register_retention_settings']);
		
		// Add cleanup routine for old logs
		add_action('emailit_cleanup_logs', [$this, 'cleanup_old_logs']);
		
		// Schedule log cleanup if not already scheduled
		if (!wp_next_scheduled('emailit_cleanup_logs')) {
			wp_schedule_event(time(), 'daily', 'emailit_cleanup_logs');
		}
		
		// Register AJAX handler
		add_action('wp_ajax_emailit_get_email_content', [$this, 'ajax_get_email_content']);
	}
	
	/**
	 * Create the logs table if it doesn't exist
	 */
	public function create_table() {
		global $wpdb;
		
		$table_name = $this->table_name;
		$charset_collate = $wpdb->get_charset_collate();
		
		// Check if the table exists before trying to create it
		if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
			$sql = "CREATE TABLE $table_name (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email_subject varchar(255) NOT NULL,
				email_from varchar(255) NOT NULL,
				email_to longtext NOT NULL,
				recipient_count int(11) NOT NULL DEFAULT 1,
				source varchar(50) DEFAULT NULL,
				html_content longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				sent_at datetime DEFAULT NULL,
				status varchar(20) DEFAULT 'pending',
				PRIMARY KEY (id),
				KEY email_to (email_to(191)),
				KEY created_at (created_at),
				KEY status (status)
			) $charset_collate;";
			
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);
		}
	}
	
	/**
	 * Log an email when it's initially queued
	 * 
	 * @param array $args Email arguments including to, subject, message, etc.
	 * @return int|false The log ID if successful, false otherwise
	 */
	public function log_email($args) {
		global $wpdb;
		
		// Format the 'to' field for storage
		$to = $args['to'];
		if (!is_array($to)) {
			$to = [$to];
		}
		
		// Count recipients
		$recipient_count = count($to);
		
		// Serialize the 'to' array for storage
		$to_serialized = maybe_serialize($to);
		
		// Check for source in headers
		$source = $this->extract_source_from_headers($args['headers']);
		
		// Store email data
		$result = $wpdb->insert(
			$this->table_name,
			[
				'email_subject' => $args['subject'],
				'email_from' => $this->extract_from_from_headers($args['headers']),
				'email_to' => $to_serialized,
				'recipient_count' => $recipient_count,
				'source' => $source,
				'html_content' => $args['message'],
				'created_at' => current_time('mysql'),
				'status' => 'pending'
			],
			[
				'%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'
			]
		);
		
		
		if ($result) {
			return $wpdb->insert_id;
		}
		
		return false;
	}
	
	/**
	 * Find a log ID by unique properties (for updating status)
	 * 
	 * @param array $args Email arguments
	 * @return int|false The log ID if found, false otherwise
	 */
	public function find_log_id_by_properties($args) {
		global $wpdb;
		
		// Format the 'to' field for matching
		$to = $args['to'];
		if (!is_array($to)) {
			$to = [$to];
		}
		
		// Serialize the 'to' array for storage
		$to_serialized = maybe_serialize($to);
		
		// Find most recent matching log with 'pending' status
		$query = $wpdb->prepare(
			"SELECT id FROM {$this->table_name} 
			WHERE email_subject = %s 
			AND email_to = %s 
			AND status = 'pending' 
			ORDER BY created_at DESC 
			LIMIT 1",
			$args['subject'],
			$to_serialized
		);
		
		return $wpdb->get_var($query);
	}
	
	/**
	 * AJAX handler for getting email content
	 */
	public function ajax_get_email_content() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'emailit_view_content')) {
			wp_send_json_error(['message' => 'Security check failed']);
		}
		
		// Get log ID
		$log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
		if (!$log_id) {
			wp_send_json_error(['message' => 'Invalid log ID']);
		}
		
		// Get content
		$content = $this->get_email_content($log_id);
		if ($content === false) {
			wp_send_json_error(['message' => 'Email content not found']);
		}
		
		// Create iframe content for safe display
		$iframe_content = '<iframe srcdoc="' . esc_attr($content) . '" style="width: 100%; height: 500px; border: 1px solid #ddd;"></iframe>';
		
		wp_send_json_success(['content' => $iframe_content]);
	}
	
	/**
	 * Get email content by ID for display in modal
	 * 
	 * @param int $log_id The ID of the log
	 * @return string|false The HTML content or false if not found
	 */
	public function get_email_content($log_id) {
		global $wpdb;
		
		$query = $wpdb->prepare(
			"SELECT html_content FROM {$this->table_name} WHERE id = %d",
			$log_id
		);
		
		return $wpdb->get_var($query);
	}
	
	/**
	 * Delete logs by ID
	 * 
	 * @param array $log_ids The IDs of the logs to delete
	 * @return int|false The number of rows deleted, or false on error
	 */
	private function delete_logs($log_ids) {
		global $wpdb;
		
		if (empty($log_ids)) {
			return false;
		}
		
		$placeholders = implode(',', array_fill(0, count($log_ids), '%d'));
		$query = "DELETE FROM {$this->table_name} WHERE id IN ({$placeholders})";
		
		return $wpdb->query($wpdb->prepare($query, $log_ids));
	}
	
	/**
	 * Update the status of an email when it's sent
	 * 
	 * @param int $log_id The ID of the log entry
	 * @param string $status The new status ('sent' or 'failed')
	 * @return bool Whether the update was successful
	 */
	public function update_email_status($log_id, $status = 'sent') {
		global $wpdb;
		
		$result = $wpdb->update(
			$this->table_name,
			[
				'status' => $status,
				'sent_at' => current_time('mysql')
			],
			['id' => $log_id],
			['%s', '%s'],
			['%d']
		);
		
		return $result !== false;
	}
	
	/**
	 * Extract the source from email headers
	 * 
	 * @param mixed $headers Email headers
	 * @return string|null The source if found, null otherwise
	 */
	private function extract_source_from_headers($headers) {
		if (empty($headers)) {
			return null;
		}
		
		$headers_array = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", $headers));
		
		foreach ($headers_array as $header) {
			if (is_string($header) && strpos($header, ':') !== false) {
				list($name, $value) = explode(':', $header, 2);
				$name = trim(strtolower($name));
				$value = trim($value);
				
				// Check for known source headers
				if ($name === 'x-emailit-source') {
					return $value;
				} elseif ($name === 'x-bbpress') {
					return 'BBPress';
				}
			} elseif (is_array($header) && isset($header['X-EmailIt-Source'])) {
				return $header['X-EmailIt-Source'];
			}
		}
		
		return null;
	}
	
	/**
	 * Extract the from address from email headers
	 * 
	 * @param mixed $headers Email headers
	 * @return string The from address or a default value
	 */
	private function extract_from_from_headers($headers) {
		if (empty($headers)) {
			return get_bloginfo('name') . ' <noreply@' . parse_url(home_url(), PHP_URL_HOST) . '>';
		}
		
		$headers_array = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", $headers));
		
		foreach ($headers_array as $header) {
			if (is_string($header) && strpos($header, ':') !== false) {
				list($name, $value) = explode(':', $header, 2);
				$name = trim(strtolower($name));
				$value = trim($value);
				
				if ($name === 'from') {
					return $value;
				}
			} elseif (is_array($header) && isset($header['From'])) {
				return $header['From'];
			}
		}
		
		return get_bloginfo('name') . ' <noreply@' . parse_url(home_url(), PHP_URL_HOST) . '>';
	}
	
	/**
	 * Register the logs tab in the EmailIt settings
	 */
	public function register_logs_tab() {
		$emailit = EmailItMailer::get_instance();
		$emailit->register_tab('logs', 'Email Logs', [$this, 'render_logs_tab'], 15);
	}
	
	/**
	 * Render the logs tab content
	 */
	/**
	 * Render the logs tab content
	 */
	public function render_logs_tab() {
		// Handle bulk actions
		$this->handle_bulk_actions();
		
		// Get current page number
		$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
		
		// Get and apply filters
		$filters = $this->get_current_filters();
		$logs = $this->get_logs($page, $filters);
		$total_logs = $this->get_total_logs_count($filters);
		$total_pages = ceil($total_logs / $this->logs_per_page);
		
		// Get retention settings
		$retention_settings = get_option('emailit_retention_settings', [
			'retention_type' => 'days',
			'retention_days' => 30,
			'retention_count' => 10000
		]);
		
		// Render tabs for Logs and Settings
		$current_section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'list';
		?>
		<div class="wrap">
			<h2>Email Logs</h2>
			
			<h2 class="nav-tab-wrapper">
				<a href="?page=emailit-settings&tab=logs&section=list" class="nav-tab <?php echo $current_section === 'list' ? 'nav-tab-active' : ''; ?>">Log List</a>
				<a href="?page=emailit-settings&tab=logs&section=settings" class="nav-tab <?php echo $current_section === 'settings' ? 'nav-tab-active' : ''; ?>">Retention Settings</a>
			</h2>
			
			<?php if ($current_section === 'settings'): ?>
				<div class="card" style="max-width: 800px; margin-top: 20px;">
					<form method="post" action="options.php">
						<?php
						settings_fields('emailit_retention_settings');
						do_settings_sections('emailit-retention');
						submit_button('Save Retention Settings');
						?>
					</form>
					
					<script>
						jQuery(document).ready(function($) {
							// Toggle visibility of settings based on selection
							function toggleRetentionFields() {
								var type = $('#emailit_retention_type').val();
								
								if (type === 'days') {
									$('.retention-days-row').show();
									$('.retention-count-row').hide();
								} else {
									$('.retention-days-row').hide();
									$('.retention-count-row').show();
								}
							}
							
							// Run on page load
							toggleRetentionFields();
							
							// Run when the type changes
							$('.retention-type-select').on('change', toggleRetentionFields);
						});
					</script>
				</div>
			<?php else: ?>
				<form method="get" action="">
					<input type="hidden" name="page" value="emailit-settings">
					<input type="hidden" name="tab" value="logs">
					
					<div class="tablenav top">
						<div class="alignleft actions">
							<select name="filter_status">
								<option value="">All Statuses</option>
								<option value="pending" <?php selected($filters['status'], 'pending'); ?>>Pending</option>
								<option value="sent" <?php selected($filters['status'], 'sent'); ?>>Sent</option>
								<option value="failed" <?php selected($filters['status'], 'failed'); ?>>Failed</option>
							</select>
							
							<select name="filter_source">
								<option value="">All Sources</option>
								<?php foreach ($this->get_available_sources() as $source): ?>
									<option value="<?php echo esc_attr($source); ?>" <?php selected($filters['source'], $source); ?>>
										<?php echo esc_html($source); ?>
									</option>
								<?php endforeach; ?>
							</select>
							
							<input type="text" name="filter_email" 
								   placeholder="Search by email address" 
								   value="<?php echo esc_attr($filters['email']); ?>">
							
							<input type="text" name="filter_subject" 
								   placeholder="Search by subject" 
								   value="<?php echo esc_attr($filters['subject']); ?>">
							
							<input type="submit" class="button" value="Filter">
							<?php if (!empty($filters['email']) || !empty($filters['subject']) || !empty($filters['status']) || !empty($filters['source'])): ?>
								<a href="<?php echo admin_url('options-general.php?page=emailit-settings&tab=logs'); ?>" class="button">Reset</a>
							<?php endif; ?>
						</div>
						
						<div class="alignleft actions">
							<select name="bulk_action">
								<option value="">Bulk Actions</option>
								<option value="delete">Delete</option>
							</select>
							
							<input type="submit" class="button action" value="Apply">
						</div>
						
						<?php if ($total_pages > 1): ?>
							<div class="tablenav-pages">
								<span class="displaying-num"><?php echo $total_logs; ?> items</span>
								<span class="pagination-links">
									<?php
									// First page link
									if ($page > 1): ?>
										<a class="first-page button" href="<?php echo add_query_arg('paged', 1); ?>">
											<span class="screen-reader-text">First page</span>
											<span aria-hidden="true">«</span>
										</a>
									<?php else: ?>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
									<?php endif; ?>
									
									<?php 
									// Previous page link
									if ($page > 1): ?>
										<a class="prev-page button" href="<?php echo add_query_arg('paged', max(1, $page - 1)); ?>">
											<span class="screen-reader-text">Previous page</span>
											<span aria-hidden="true">‹</span>
										</a>
									<?php else: ?>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
									<?php endif; ?>
									
									<span class="paging-input">
										<span class="tablenav-paging-text">
											<?php echo $page; ?> of <span class="total-pages"><?php echo $total_pages; ?></span>
										</span>
									</span>
									
									<?php
									// Next page link
									if ($page < $total_pages): ?>
										<a class="next-page button" href="<?php echo add_query_arg('paged', min($total_pages, $page + 1)); ?>">
											<span class="screen-reader-text">Next page</span>
											<span aria-hidden="true">›</span>
										</a>
									<?php else: ?>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
									<?php endif; ?>
									
									<?php
									// Last page link
									if ($page < $total_pages): ?>
										<a class="last-page button" href="<?php echo add_query_arg('paged', $total_pages); ?>">
											<span class="screen-reader-text">Last page</span>
											<span aria-hidden="true">»</span>
										</a>
									<?php else: ?>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
									<?php endif; ?>
								</span>
							</div>
						<?php endif; ?>
					</div>
					
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<input type="checkbox" id="cb-select-all-1">
								</td>
								<th scope="col" class="manage-column">Date</th>
								<th scope="col" class="manage-column">Subject</th>
								<th scope="col" class="manage-column">From</th>
								<th scope="col" class="manage-column">To</th>
								<th scope="col" class="manage-column">Source</th>
								<th scope="col" class="manage-column">Status</th>
								<th scope="col" class="manage-column">Actions</th>
							</tr>
						</thead>
						
						<tbody id="the-list">
							<?php if (empty($logs)): ?>
								<tr>
									<td colspan="8">No logs found.</td>
								</tr>
							<?php else: ?>
								<?php foreach ($logs as $log): ?>
									<tr>
										<th scope="row" class="check-column">
											<input type="checkbox" name="log_ids[]" value="<?php echo esc_attr($log->id); ?>">
										</th>
										<td>
											<?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($log->created_at))); ?>
											<?php if (!empty($log->sent_at)): ?>
												<br>
												<small>Sent: <?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($log->sent_at))); ?></small>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html($log->email_subject); ?></td>
										<td><?php echo esc_html($log->email_from); ?></td>
										<td>
											<?php 
											$recipients = maybe_unserialize($log->email_to);
											if (is_array($recipients) && count($recipients) > 1): ?>
												<span class="recipients-count" data-log-id="<?php echo esc_attr($log->id); ?>">
													(<?php echo count($recipients); ?>) Multiple Recipients
													<span class="dashicons dashicons-arrow-down-alt2"></span>
												</span>
												<div id="recipients-list-<?php echo esc_attr($log->id); ?>" class="recipients-list" style="display: none;">
													<ul>
														<?php foreach ($recipients as $recipient): ?>
															<li><?php echo esc_html($recipient); ?></li>
														<?php endforeach; ?>
													</ul>
												</div>
											<?php elseif (is_array($recipients) && count($recipients) === 1): ?>
												<?php echo esc_html($recipients[0]); ?>
											<?php else: ?>
												<?php echo esc_html(is_array($recipients) ? implode(', ', $recipients) : $recipients); ?>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html($log->source ?: 'Unknown'); ?></td>
										<td>
											<?php 
											$status_class = '';
											switch ($log->status) {
												case 'sent':
													$status_class = 'status-success';
													break;
												case 'pending':
													$status_class = 'status-pending';
													break;
												case 'failed':
													$status_class = 'status-error';
													break;
											}
											?>
											<span class="status-indicator <?php echo esc_attr($status_class); ?>">
												<?php echo esc_html(ucfirst($log->status)); ?>
											</span>
										</td>
										<td>
											<a href="#" class="view-content" data-log-id="<?php echo esc_attr($log->id); ?>">View</a> | 
											<a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'delete_log', 'log_id' => $log->id]), 'delete_log_' . $log->id); ?>"
											   class="delete-log" 
											   onclick="return confirm('Are you sure you want to delete this log?');">Delete</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</form>
				
				<!-- Email Content Modal -->
				<div id="emailit-content-modal" class="emailit-modal" style="display: none;">
					<div class="emailit-modal-content">
						<span class="emailit-modal-close">&times;</span>
						<h2>Email Content</h2>
						<div id="emailit-modal-body"></div>
					</div>
				</div>
				
				<style>
					.status-indicator {
						display: inline-block;
						padding: 3px 8px;
						border-radius: 3px;
						font-size: 12px;
						font-weight: 500;
					}
					.status-success {
						background-color: #d4edda;
						color: #155724;
					}
					.status-pending {
						background-color: #fff3cd;
						color: #856404;
					}
					.status-error {
						background-color: #f8d7da;
						color: #721c24;
					}
					.recipients-count {
						cursor: pointer;
						color: #0073aa;
					}
					.recipients-list {
						margin-top: 5px;
						padding: 10px;
						background: #f9f9f9;
						border: 1px solid #e5e5e5;
						max-height: 150px;
						overflow-y: auto;
					}
					.recipients-list ul {
						margin: 0;
						padding: 0 0 0 20px;
					}
					
					/* Modal styles */
					.emailit-modal {
						position: fixed;
						z-index: 100000;
						left: 0;
						top: 0;
						width: 100%;
						height: 100%;
						overflow: auto;
						background-color: rgba(0,0,0,0.4);
					}
					.emailit-modal-content {
						background-color: #fefefe;
						margin: 5% auto;
						padding: 20px;
						border: 1px solid #888;
						width: 80%;
						max-width: 800px;
						max-height: 80vh;
						overflow: auto;
					}
					.emailit-modal-close {
						color: #aaa;
						float: right;
						font-size: 28px;
						font-weight: bold;
						cursor: pointer;
					}
					.emailit-modal-close:hover,
					.emailit-modal-close:focus {
						color: black;
						text-decoration: none;
					}
				</style>
				
				<script>
					jQuery(document).ready(function($) {
						// Toggle recipients list
						$('.recipients-count').click(function() {
							var logId = $(this).data('log-id');
							$('#recipients-list-' + logId).slideToggle();
							$(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
						});
						
						// Handle modal
						$('.view-content').click(function(e) {
							e.preventDefault();
							var logId = $(this).data('log-id');
							
							$.ajax({
								url: ajaxurl,
								type: 'POST',
								data: {
									action: 'emailit_get_email_content',
									log_id: logId,
									nonce: '<?php echo wp_create_nonce('emailit_view_content'); ?>'
								},
								success: function(response) {
									if (response.success) {
										$('#emailit-modal-body').html(response.data.content);
										$('#emailit-content-modal').show();
									} else {
										alert('Error: ' + response.data.message);
									}
								},
								error: function() {
									alert('An error occurred while fetching email content.');
								}
							});
						});
						
						// Close modal
						$('.emailit-modal-close').click(function() {
							$('#emailit-content-modal').hide();
						});
						
						// Close modal when clicking outside of it
						$(window).click(function(e) {
							if ($(e.target).is('.emailit-modal')) {
								$('.emailit-modal').hide();
							}
						});
					});
				</script>
			<?php endif; ?>
		</div>
	<?php
	}
	
	private function get_current_filters() {
		return [
			'email' => isset($_GET['filter_email']) ? sanitize_text_field($_GET['filter_email']) : '',
			'subject' => isset($_GET['filter_subject']) ? sanitize_text_field($_GET['filter_subject']) : '',
			'status' => isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '',
			'source' => isset($_GET['filter_source']) ? sanitize_text_field($_GET['filter_source']) : '',
		];
	}
	
	private function handle_bulk_actions() {
		// Single log deletion
		if (isset($_GET['action']) && $_GET['action'] === 'delete_log' && isset($_GET['log_id'])) {
			$log_id = intval($_GET['log_id']);
			
			if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_log_' . $log_id)) {
				wp_die('Security check failed');
			}
			
			$this->delete_logs([$log_id]);
			
			wp_redirect(admin_url('options-general.php?page=emailit-settings&tab=logs&deleted=1'));
			exit;
		}
		
		// Bulk actions
		if (isset($_GET['bulk_action']) && !empty($_GET['bulk_action']) && isset($_GET['log_ids']) && is_array($_GET['log_ids'])) {
			$action = sanitize_text_field($_GET['bulk_action']);
			$log_ids = array_map('intval', $_GET['log_ids']);
			
			switch ($action) {
				case 'delete':
					$this->delete_logs($log_ids);
					wp_redirect(admin_url('options-general.php?page=emailit-settings&tab=logs&bulk_deleted=' . count($log_ids)));
					exit;
					break;
			}
		}
	}
	
	private function get_available_sources() {
		global $wpdb;
		
		$query = "SELECT DISTINCT source FROM {$this->table_name} WHERE source IS NOT NULL ORDER BY source";
		$sources = $wpdb->get_col($query);
		
		return $sources;
	}
	
	private function get_logs($page, $filters) {
		global $wpdb;
		
		$offset = ($page - 1) * $this->logs_per_page;
		
		$query = "SELECT * FROM {$this->table_name} WHERE 1=1";
		$query_args = [];
		
		// Apply filters
		if (!empty($filters['email'])) {
			$query .= " AND email_to LIKE %s";
			$query_args[] = '%' . $wpdb->esc_like($filters['email']) . '%';
		}
		
		if (!empty($filters['subject'])) {
			$query .= " AND email_subject LIKE %s";
			$query_args[] = '%' . $wpdb->esc_like($filters['subject']) . '%';
		}
		
		if (!empty($filters['status'])) {
			$query .= " AND status = %s";
			$query_args[] = $filters['status'];
		}
		
		if (!empty($filters['source'])) {
			$query .= " AND source = %s";
			$query_args[] = $filters['source'];
		}
		
		// Add ordering and limit
		$query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query_args[] = $this->logs_per_page;
		$query_args[] = $offset;
		
		// Prepare and execute query
		$prepared_query = empty($query_args) ? $query : $wpdb->prepare($query, $query_args);
		return $wpdb->get_results($prepared_query);
	}
	
	private function get_total_logs_count($filters) {
		global $wpdb;
		
		$query = "SELECT COUNT(*) FROM {$this->table_name} WHERE 1=1";
		$query_args = [];
		
		// Apply filters
		if (!empty($filters['email'])) {
			$query .= " AND email_to LIKE %s";
			$query_args[] = '%' . $wpdb->esc_like($filters['email']) . '%';
		}
		
		if (!empty($filters['subject'])) {
			$query .= " AND email_subject LIKE %s";
			$query_args[] = '%' . $wpdb->esc_like($filters['subject']) . '%';
		}
		
		if (!empty($filters['status'])) {
			$query .= " AND status = %s";
			$query_args[] = $filters['status'];
		}
		
		if (!empty($filters['source'])) {
			$query .= " AND source = %s";
			$query_args[] = $filters['source'];
		}
		
		// Prepare and execute query
		$prepared_query = empty($query_args) ? $query : $wpdb->prepare($query, $query_args);
		return $wpdb->get_var($prepared_query);
	}
	
	/**
	 * Clean up old logs based on configured settings
	 */
	public function cleanup_old_logs() {
		global $wpdb;
		
		// Get retention settings
		$retention_settings = get_option('emailit_retention_settings', [
			'retention_type' => 'days', // 'days' or 'count'
			'retention_days' => 30,     // Default 30 days
			'retention_count' => 10000  // Default 10,000 records
		]);
		
		if ($retention_settings['retention_type'] === 'days') {
			// Delete logs older than X days
			$days = intval($retention_settings['retention_days']);
			if ($days > 0) {
				$date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
				
				$query = $wpdb->prepare(
					"DELETE FROM {$this->table_name} WHERE created_at < %s",
					$date
				);
				
				$deleted = $wpdb->query($query);
				
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log("EmailIt Log Cleanup: Deleted {$deleted} logs older than {$days} days");
				}
			}
		} else {
			// Keep only the most recent X records
			$count = intval($retention_settings['retention_count']);
			if ($count > 0) {
				// First count total records
				$total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
				
				if ($total_records > $count) {
					// Calculate how many to delete
					$to_delete = $total_records - $count;
					
					// Get the ID of the oldest record to keep
					$oldest_to_keep = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d, 1",
							$count - 1
						)
					);
					
					if ($oldest_to_keep) {
						$deleted = $wpdb->query(
							$wpdb->prepare(
								"DELETE FROM {$this->table_name} WHERE id < %d",
								$oldest_to_keep
							)
						);
						
						if (defined('WP_DEBUG') && WP_DEBUG) {
							error_log("EmailIt Log Cleanup: Deleted {$deleted} logs, keeping most recent {$count} records");
						}
					}
				}
			}
		}
	}
	
	/**
	 * Register retention settings fields
	 */
	public function register_retention_settings() {
		register_setting('emailit_retention_settings', 'emailit_retention_settings', [
			'sanitize_callback' => [$this, 'sanitize_retention_settings']
		]);
		
		add_settings_section(
			'emailit_retention_section',
			'Log Retention Settings',
			[$this, 'retention_section_callback'],
			'emailit-retention'
		);
		
		add_settings_field(
			'emailit_retention_type',
			'Retention Type',
			[$this, 'retention_type_callback'],
			'emailit-retention',
			'emailit_retention_section'
		);
		
		add_settings_field(
			'emailit_retention_days',
			'Days to Keep Logs',
			[$this, 'retention_days_callback'],
			'emailit-retention',
			'emailit_retention_section',
			['class' => 'retention-days-row']
		);
		
		add_settings_field(
			'emailit_retention_count',
			'Number of Logs to Keep',
			[$this, 'retention_count_callback'],
			'emailit-retention',
			'emailit_retention_section',
			['class' => 'retention-count-row']
		);
	}
	
	/**
	 * Retention section description callback
	 */
	public function retention_section_callback() {
		echo '<p>Configure how long to keep email logs before automatically deleting them.</p>';
	}
	
	/**
	 * Retention type field callback
	 */
	public function retention_type_callback() {
		$options = get_option('emailit_retention_settings', [
			'retention_type' => 'days',
			'retention_days' => 30,
			'retention_count' => 10000
		]);
		
		$type = isset($options['retention_type']) ? $options['retention_type'] : 'days';
		
		echo '<select id="emailit_retention_type" name="emailit_retention_settings[retention_type]" class="regular-text retention-type-select">';
		echo '<option value="days" ' . selected($type, 'days', false) . '>Keep logs for a number of days</option>';
		echo '<option value="count" ' . selected($type, 'count', false) . '>Keep a maximum number of logs</option>';
		echo '</select>';
		echo '<p class="description">Choose whether to retain logs based on age or total count.</p>';
	}
	
	/**
	 * Retention days field callback
	 */
	public function retention_days_callback() {
		$options = get_option('emailit_retention_settings', [
			'retention_type' => 'days',
			'retention_days' => 30,
			'retention_count' => 10000
		]);
		
		$days = isset($options['retention_days']) ? intval($options['retention_days']) : 30;
		
		echo '<input type="number" id="emailit_retention_days" name="emailit_retention_settings[retention_days]" min="1" max="365" value="' . esc_attr($days) . '" class="regular-text">';
		echo '<p class="description">Logs older than this number of days will be deleted.</p>';
	}
	
	/**
	 * Retention count field callback
	 */
	public function retention_count_callback() {
		$options = get_option('emailit_retention_settings', [
			'retention_type' => 'days',
			'retention_days' => 30,
			'retention_count' => 10000
		]);
		
		$count = isset($options['retention_count']) ? intval($options['retention_count']) : 10000;
		
		echo '<input type="number" id="emailit_retention_count" name="emailit_retention_settings[retention_count]" min="100" max="1000000" value="' . esc_attr($count) . '" class="regular-text">';
		echo '<p class="description">Only keep this many most recent logs (older ones will be deleted).</p>';
	}
	
	/**
	 * Sanitize retention settings
	 */
	public function sanitize_retention_settings($input) {
		$sanitized = [];
		
		if (isset($input['retention_type'])) {
			$sanitized['retention_type'] = in_array($input['retention_type'], ['days', 'count']) ? $input['retention_type'] : 'days';
		} else {
			$sanitized['retention_type'] = 'days';
		}
		
		if (isset($input['retention_days'])) {
			$sanitized['retention_days'] = intval($input['retention_days']);
			// Ensure it's between 1 and 365
			$sanitized['retention_days'] = max(1, min(365, $sanitized['retention_days']));
		} else {
			$sanitized['retention_days'] = 30;
		}
		
		if (isset($input['retention_count'])) {
			$sanitized['retention_count'] = intval($input['retention_count']);
			// Ensure it's between 100 and 1,000,000
			$sanitized['retention_count'] = max(100, min(1000000, $sanitized['retention_count']));
		} else {
			$sanitized['retention_count'] = 10000;
		}
		
		return $sanitized;
	}
}