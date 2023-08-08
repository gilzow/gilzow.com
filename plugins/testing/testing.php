<?php
/**
 * Plugin Name:     testing
 * Plugin URI:      https://gilzow.com/testing
 * Description:
 * Author:          Paul Gilzow
 * Author URI:      https://gilzow.com/
 * Text Domain:     testing
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         TESTING
 */

if (function_exists('add_filter')) {
	add_filter('show_password_fields', 'wpDirAuth_hidePassFields');
	add_filter('allow_password_reset','wpDirAuth_allowPasswordReset',10,2);
}

function wpDirAuth_hidePassFields() {
	echo '<fieldset><legend>'
		. __('Directory Password Update')
		. '</legend><p class="desc">'
		. 'You cant change your password here...'
		. '</p></fieldset>';

	return false;
}

function wpDirAuth_allowPasswordReset($bool,$intUserID)
{
	//echo "<p>Function ", __FUNCTION__, ' running... <p>';
	$mxdReturn = true;
	$boolDirAuthEnabled = 1;
	if(1 === $boolDirAuthEnabled){
		$intDirAuthUser = 1;
		if (1 === $intDirAuthUser){
			$strPasswordReset = '<h3>Error: Unable to reset password</h3>';
			$strPasswordReset .= get_site_option('dirAuthChangePassMsg');
			add_filter('login_message',function(){return 'You cant do that.';});
			$mxdReturn = new WP_Error('invalid_username',$strPasswordReset);
		}
	}

	return $mxdReturn;
}

