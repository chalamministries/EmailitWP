# EmailIt Mailer for WordPress

A WordPress plugin that seamlessly integrates with the EmailIt SDK to handle all your WordPress email needs through the EmailIt API.

## Description

EmailIt Mailer replaces WordPress's default mail functionality with EmailIt's robust email delivery service. This ensures better deliverability, tracking capabilities, and management of your website's email communications.

## Features

- Seamlessly overrides WordPress's wp_mail() function
- Easy configuration through WordPress admin interface
- Support for multiple sending domains
- Handles HTML and plain text emails
- Supports attachments
- Compatible with all WordPress plugins that use wp_mail()
- Asynchronous email sending through WordPress cron
- Custom email headers support
- Configurable "From" name and email address
- Automatic plain text version generation from HTML content

## Installation

1. Upload the `emailit-mailer` directory to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > EmailIt Mailer to configure your API key and sending domain

## Configuration

1. Obtain an API key from your EmailIt account
2. Navigate to WordPress admin panel > Settings > EmailIt Mailer
3. Enter your API key
4. Configure your sending domain
5. Set your default "From" name and email address

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Valid EmailIt API key
- At least one verified sending domain in your EmailIt account

## FAQ

### Why aren't my emails being sent?

Check the following:
- Verify your API key is correct
- Ensure you have at least one verified sending domain
- Check that the "From" email domain matches one of your verified sending domains
- Enable WP_DEBUG to see detailed error logs

### Can I use multiple sending domains?

Yes, you can configure multiple sending domains in your EmailIt account and select which one to use in the plugin settings.

### Does this work with contact form plugins?

Yes, the plugin is compatible with any WordPress plugin that uses the standard wp_mail() function.

### How do I check if emails are being delivered?

You can track email delivery status through your EmailIt dashboard.

## Support

For support issues:
1. Enable WP_DEBUG in your wp-config.php file
2. Check the WordPress debug.log file for error messages
3. Contact EmailIt support with the error details

## Changelog

### 3.0.0
- **BREAKING CHANGE**: Updated to EmailIt API v2 - requires new API key generation
- Fixed cron job scheduling for async email sending
- Fixed MIME encoding issue causing raw multipart content to display
- Improved header filtering to prevent Content-Type conflicts
- Added upgrade notice for users migrating from 2.5.1 or earlier
- Enhanced debug logging for troubleshooting

### 1.8
- Initial public release
- Added support for async email sending
- Implemented multiple sending domain support
- Added email debug logging
- Improved error handling and validation

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by Steven Gauerke

## Security

- The plugin sanitizes all input and validates data before sending
- API keys are stored securely in WordPress options
- All API communication is done over HTTPS
- Input validation is performed on email addresses and domains
- Headers are sanitized to prevent email header injection

## Best Practices

1. Always use a verified sending domain
2. Configure SPF and DKIM records for your sending domains
3. Monitor your email delivery rates through the EmailIt dashboard
4. Keep the plugin updated to the latest version
5. Use HTML and plain text versions of your emails for better deliverability

## Development

For developers looking to extend the plugin:

### Available Filters

```php
// Modify the from email address
add_filter('wp_mail_from', function($from_email) {
	return 'custom@yourdomain.com';
});

// Modify the from name
add_filter('wp_mail_from_name', function($from_name) {
	return 'Custom Name';
});
```

### Available Actions

```php
// Register custom settings tab
add_action('emailit_register_tabs', function() {
	$emailit_mailer = EmailItMailer::get_instance();
	$emailit_mailer->register_tab(
		'custom_tab',
		'Custom Tab',
		'render_custom_tab_callback',
		50
	);
});
```