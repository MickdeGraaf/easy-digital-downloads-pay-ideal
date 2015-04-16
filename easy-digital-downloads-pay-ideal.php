<?php
/**
 * Plugin Name: Pay.nl iDEAL for Easy Digital Downloads
 * Plugin URI: http://mickdegraaf.nl
 * Description: Adds iDEAL to Easy Digital Downloads via Pay.nl.
 * Author: Mick de Graaf
 * Author URI: http://mickdegraaf.nl
 * Version: 0.0.1
 * License: GPLv2 or later
 */
 
 
 
 
/**
 * pay_edd_register_gateway function.
 * 
 * @access public
 * @param mixed $gateways
 * @return void
 */
function pay_edd_register_gateway($gateways) {
	$gateways['pay_nl'] = array('admin_label' => 'Pay.nl iDEAL', 'checkout_label' => __('iDEAL', 'pay_edd'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'pay_edd_register_gateway');


/**
 * pay_edd_cc_form function.
 *
 * Removes default cc form
 * 
 * @access public
 * @return void
 */
function pay_edd_cc_form() {
	// register the action to remove default CC form
	return;
}
add_action('edd_pay_nl_cc_form', 'pay_edd_cc_form');

/**
 * pay_edd_process_payment function.
 * 
 * Handles payment on submit
 *
 * @access public
 * @param mixed $purchase_data
 * @return void
 */
function pay_edd_process_payment($purchase_data) {
	
	global $edd_options;
	
	if(edd_is_test_mode()) {
		$strUrl = 'https://rest-api.pay.nl/v5/Transaction/start/json?';//test url
	} else {
		$strUrl = 'https://rest-api.pay.nl/v5/Transaction/start/json?';	
	}
	
	// check for any stored errors
	$errors = edd_get_errors();
	if(!$errors) {
		
			$purchase_summary = edd_get_purchase_summary($purchase_data);
	 
			/**********************************
			* setup the payment details
			**********************************/
	 
			$payment = array( 
				'price' => $purchase_data['price'], 
				'date' => $purchase_data['date'], 
				'user_email' => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency' => $edd_options['currency'],
				'downloads' => $purchase_data['downloads'],
				'cart_details' => $purchase_data['cart_details'],
				'user_info' => $purchase_data['user_info'],
				'status' => 'pending'
			);
		
			// record the pending payment
			$payment = edd_insert_payment($payment);
			
			$settings = edd_get_settings();
			
			# Add arguments
			$arrArguments['token'] = $settings['api_token'];
			$arrArguments['serviceId'] = $settings['service_id'];
			$arrArguments['amount'] = (int)($purchase_data['price'] * 100);
			$arrArguments['finishUrl'] = get_permalink( edd_get_option( 'success_page', false ) );
			$arrArguments['transaction']['description'] = __('Order: ', 'pay_edd') . $payment;
			$arrArguments['ipAddress'] = $_SERVER['REMOTE_ADDR'];
			$arrArguments['paymentOptionId'] = 10;
			# Prepare and call API URL
			$strUrl .= http_build_query($arrArguments);
			$result = @file_get_contents($strUrl);
			$result = json_decode($result);
		
			add_post_meta($payment, 'pay_id', $result->transaction->transactionId);
			
			
			edd_empty_cart();
			
			wp_redirect($result->transaction->paymentURL);
				
			exit();
			
	} else {
		$fail = true; // errors were detected
	}


}
add_action('edd_gateway_pay_nl', 'pay_edd_process_payment');


/**
 * pay_edd_add_settings function.
 *
 * Adds pay.nl settings to gateway settings
 * 
 * @access public
 * @param mixed $settings
 * @return void
 */
function pay_edd_add_settings($settings) {
 
	$pay_gateway_settings = array(
		array(
			'id' => 'pay_nl_settings',
			'name' => '<strong>' . __('Pay.nl settings', 'pay_edd') . '</strong>',
			'desc' => __('Configure Pay.nl iDEAL', 'pay_edd'),
			'type' => 'header'
		),
		array(
			'id' => 'api_token',
			'name' => __('API Token', 'pay_edd'),
			'desc' => __('Enter your API token', 'pay_edd'),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'service_id',
			'name' => __('Service ID', 'pay_edd'),
			'desc' => __('Enter your service ID', 'pay_edd'),
			'type' => 'text',
			'size' => 'regular'
		)
	);
 
	return array_merge($settings, $pay_gateway_settings);	
}
add_filter('edd_settings_gateways', 'pay_edd_add_settings');


/**
 * pay_edd_listen_for_pay_ipn function.
 *
 * listens for ipn call and handles it. 
 *
 * @access public
 * @return void
 */
function pay_edd_listen_for_pay_ipn() {
	
	if ( isset( $_GET['pay-edd-listener'] ) && $_GET['pay-edd-listener'] == 'IPN' && pay_edd_is_valid_ip($_SERVER['REMOTE_ADDR']) ) {
		
		$payment = pay_edd_get_post_id_by_meta_key_and_value('pay_id', $_POST['order_id']);
		
		if($_POST['action'] == 'add' || $_POST['action'] == 'new_ppt') {
			edd_update_payment_status($payment, 'complete');
		}
		else if($_POST['action'] == 'cancel' ) {
			edd_update_payment_status($payment, 'failed');
		}
		else {
			edd_update_payment_status($payment, 'pending');
		}
		
		
		echo("TRUE");
		
		exit();	
		
	}
	
}
add_action( 'init', 'pay_edd_listen_for_pay_ipn' );


/**
 * pay_edd_listen_for_cancelled_or_expired function.
 * 
 * Listens for cancelled and expired on return to succes page
 *
 * @access public
 * @return void
 */
function pay_edd_listen_for_cancelled_or_expired(){
	global $post;
	if( isset($_GET['orderStatusId']) && $_GET['orderStatusId'] != 100 && is_page( edd_get_option( 'success_page', false ) ) ){
		wp_redirect( edd_get_failed_transaction_uri() );
		exit();
	}
}
add_action( 'template_redirect', 'pay_edd_listen_for_cancelled_or_expired');


/**
 * pay_edd_get_post_id_by_meta_key_and_value function.
 *
 * Gets post id by meta key 
 *
 * @access public
 * @param mixed $key
 * @param mixed $value
 * @return void
 */
function pay_edd_get_post_id_by_meta_key_and_value($key, $value) {
	global $wpdb;
	$meta = $wpdb->get_results("SELECT * FROM ".$wpdb->postmeta." WHERE meta_key='".esc_sql($key)."' AND meta_value='".esc_sql($value)."'");
	if (is_array($meta) && !empty($meta) && isset($meta[0])) {
		$meta = $meta[0];
		}	
	if (is_object($meta)) {
		return $meta->post_id;
		}
	else {
		return false;
		}
}


/**
 * pay_edd_is_valid_ip function.
 * 
 * Checks if IPN requist comes from a valid IP
 * 
 * @access public
 * @param mixed $ip
 * @return void
 */
function pay_edd_is_valid_ip($ip) {
	
	# Setup API Url
	$strUrl = "https://rest-api.pay.nl/v1/Validate/isPayServerIp/array_serialize?"; 
	
	# Add arguments
	$arrArguments = array();
	$arrArguments['ipAddress'] = $ip;
	
	# Prepare complete API URL
	$strUrl = $strUrl.http_build_query($arrArguments);
	$arrResult = unserialize(@file_get_contents($strUrl));
	
	# Cleanup API data
	unset($strUrl, $arrArguments);
	
	return $arrResult['result'];

	
}

?>
