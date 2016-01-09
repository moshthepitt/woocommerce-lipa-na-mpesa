<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/*
Plugin Name: Woocommerce Lipa na MPESA
Plugin URI: https://github.com/moshthepitt/woocommerce-lipa-na-mpesa
Description: Allows use of Kenyan payment processor Lipa na MPESA - http://kopokopo.com.
Version: 0.1
Author: Kelvin Jayanoris
Author URI: http://jayanoris.com
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Copyright 2012  Kelvin Jayanoris 

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 3, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USAv
*/
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	// Hooks for adding/ removing the database table, and the wpcron to check them
	register_activation_hook(__FILE__, 'woocommerce_lipa_na_mpesa_install');
	register_activation_hook(__FILE__, 'woocommerce_lipa_na_mpesa_ipn_task');
	register_uninstall_hook(__FILE__, 'lipa_na_mpesa_on_uninstall');

	define('LIPANAMPESA_PLUGIN_URL', plugin_dir_url(__FILE__));
	define('LIPANAMPESA_PLUGIN_DIR', WP_PLUGIN_DIR.'/'.dirname(plugin_basename(__FILE__)));	

	function woocommerce_lipa_na_mpesa_install() {
	  global $wpdb;

	  $table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa";
	  $ipn_table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa_ipn"; 

	  $charset_collate = $wpdb->get_charset_collate();

	  $sql = "
	  CREATE TABLE $table_name (
	    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
	    `order_id` int(11) unsigned NOT NULL,
	    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
	    `mpesa_phone` varchar(50) NOT NULL,
	    `mpesa_receipt` varchar(50) NOT NULL,
	    PRIMARY KEY (`id`),
	    UNIQUE KEY `order_id` (`order_id`,`mpesa_receipt`)
	  ) $charset_collate;";

		$sql = $sql . "
		CREATE TABLE $ipn_table_name (
		  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
		  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		  `service_name` varchar(50) NOT NULL,
	    `business_number` varchar(50) NOT NULL,
	    `transaction_reference` varchar(50) NOT NULL,
	    `internal_transaction_id` varchar(255) NOT NULL,
	    `transaction_timestamp` varchar(255) NOT NULL,
	    `transaction_type` varchar(50) NOT NULL,
	    `account_number` varchar(255) NOT NULL,
	    `sender_phone` varchar(50) NOT NULL,
	    `first_name` varchar(255) NOT NULL,
	    `middle_name` varchar(255) NOT NULL,
	    `last_name` varchar(255) NOT NULL,
	    `amount` int(11) unsigned NOT NULL,
	    `currency` varchar(50) NOT NULL,
	    `duplicate` int(1) unsigned NOT NULL,
		  PRIMARY KEY (`id`)
		) $charset_collate;";

	  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	  dbDelta($sql);
	}

	function lipa_na_mpesa_on_uninstall()	{
	  // Clean up i.e. delete the table, wp_cron already removed on deacivate
	  global $wpdb;
	  $table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa"; 
	  $ipn_table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa_ipn"; 
	  $wpdb->query("DROP TABLE IF EXISTS $table_name");
	  $wpdb->query("DROP TABLE IF EXISTS $ipn_table_name");
	} 

	function woocommerce_lipa_na_mpesa_ipn_task() {
		$timestamp = wp_next_scheduled('woocommerce_lipa_na_mpesa_ipn_reconciler');
		//If $timestamp == false it hasn't been done previously
	  if( $timestamp == false ){
	    //Schedule the event for right now, then to repeat hourly
	    wp_schedule_event(time(), 'hourly', 'woocommerce_lipa_na_mpesa_ipn_reconciler');
	  }
	}
	//Hook our function in
	add_action('woocommerce_lipa_na_mpesa_ipn_reconciler', 'woocommerce_lipa_na_mpesa_reconcile_ipn');
	function woocommerce_lipa_na_mpesa_reconcile_ipn(){
  	global $wpdb;
  	$table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa";
  	$ipn_table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa_ipn";

		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `$table_name`
				 WHERE created_at >= NOW() - INTERVAL 1 HOUR
				 ORDER BY created_at DESC
				", ""
		 )
		);

		if(!empty($records)) {
			foreach ($records as $record) {
				$this_order = wc_get_order($record->order_id);
				if ($this_order->get_status() == "pending") {
					$ipn_records = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM `$ipn_table_name`
							 WHERE `transaction_reference` = %s
							",
						  $record->mpesa_receipt
					 )
					);
					if(!empty($ipn_records)) {
						$ipn_record = end($ipn_records);
		  	  	if ((int)$ipn_record->amount >= $order->get_total()) {
		  	  		$note = __("FULLY PAID: Payment of $ipn_record->currency $ipn_record->amount from $ipn_record->first_name $ipn_record->middle_name $ipn_record->last_name, phone number $ipn_record->sender_phone and MPESA reference $ipn_record->transaction_reference confirmed by KopoKopo", 'woocommerce');
				    	$order->add_order_note($note);
				    	$order->payment_complete();
		  	  	} else {
		  	  		// underpayment
		  	  		$note = __("PARTLY PAID: Received $ipn_record->currency $ipn_record->amount from $ipn_record->first_name $ipn_record->middle_name $ipn_record->last_name, phone number $ipn_record->sender_phone and MPESA reference $ipn_record->transaction_reference", 'woocommerce');
		  	  		$order->add_order_note($note);			    	
		  	  	}
					}
				}
			}
		}
	}

	add_action('plugins_loaded', 'init_lipa_na_mpesa_gateway');

	function init_lipa_na_mpesa_gateway() {
		class WC_LipaNaMPESA_Gateway extends WC_Payment_Gateway {

			function __construct() {
				$this->id           = 'lipa_na_mpesa';
				$this->method_title = __('Lipa na MPESA', 'woocommerce');
				$this->method_description = __('Allows payments through Lipa na MPESA.', 'woocommerce');
				$this->has_fields   = true;
				$this->testmode     = ($this->get_option('testmode') === 'yes') ? true : false;
				$this->debug	      = $this->get_option('debug');

				// Load the settings.
				$this->init_form_fields();
				$this->init_settings();

				// Get settings
				$this->title              					= $this->get_option('title');
				$this->field_title        					= $this->get_option('field_title');
				$this->phone_title        					= $this->get_option('phone_title');
				$this->till_number        					= $this->get_option('till_number');
				$this->description        					= $this->get_option('description');
				$this->instructions       					= $this->get_option('instructions', $this->description);
				$this->enable_for_methods 					= $this->get_option('enable_for_methods', array());
				$this->enable_for_virtual 					= $this->get_option('enable_for_virtual', 'yes') === 'yes' ? true : false;
				$this->auto_complete_virtual_orders = $this->get_option('auto_complete_virtual_orders', 'yes') === 'yes' ? true : false;
				$this->kopokopo_api_key   					= $this->get_option('kopokopo_api_key');

				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
				add_action('woocommerce_thankyou_lipa_na_mpesa', array($this, 'thankyou_page'));

				// Customer Emails
				add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
			}

			/**
			 * Initialise Gateway Settings Form Fields
			 */
			public function init_form_fields() {
				$shipping_methods = array();

				if (is_admin())
					foreach (WC()->shipping()->load_shipping_methods() as $method) {
						$shipping_methods[ $method->id ] = $method->get_title();
					}

					$mpesa_instructions = '
						<div class="mpesa-instructions">
						  <p>
						    <h3>' . __('Payment Instructions', 'woocommerce') . '</h3>
						    <p>
						      ' . __('On your Safaricom phone go the M-PESA menu', 'woocommerce') . '</br>
						      ' . __('Select Lipa Na M-PESA and then select Buy Goods and Services', 'woocommerce') . '</br>
						      ' . __('Enter the Till Number', 'woocommerce') . ' <strong>' . $this->till_number . '</strong> </br>
						      ' . __('Enter exactly the amount due', 'woocommerce') . '</br>
						      ' . __('Follow subsequent prompts to complete the transaction.', 'woocommerce') . ' </br>
						      ' . __('You will receive a confirmation SMS from M-PESA with a Confirmation Code.', 'woocommerce') . ' </br>
						      ' . __('After you receive the confirmation code, please input your phone number and the confirmation code that you received from M-PESA below.', 'woocommerce') . '</br>
						    </p>
						  </p>
						</div>      
					';

					$this->form_fields = array(
						'enabled' => array(
							'title'   => __('Enable/Disable', 'woocommerce'),
							'type'    => 'checkbox',
							'label'   => __('Enable Lipa na MPESA', 'woocommerce'),
							'default' => 'no'
							),
						'title' => array(
							'title'       => __('Title', 'woocommerce'),
							'type'        => 'text',
							'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
							'default'     => __('Lipa na MPESA', 'woocommerce'),
							'desc_tip'    => true,
							),
						'till_number' => array(
							'title'       => __('Lipa na MPESA Till Number', 'woocommerce'),
							'type'        => 'text',
							'description' => __('The Lipa na MPESA till number where money is sent to.', 'woocommerce'),
							'desc_tip'    => true,
							),
						'description' => array(
							'title'       => __('Description', 'woocommerce'),
							'type'        => 'textarea',
							'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
							'default'     => $mpesa_instructions,
							'desc_tip'    => true,
							),
						'instructions' => array(
							'title'       => __('Instructions', 'woocommerce'),
							'type'        => 'textarea',
							'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce'),
							'default'     => $mpesa_instructions,
							'desc_tip'    => true,
							),
						'field_title' => array(
							'title'       => __('Confirmation Code Field Title', 'woocommerce'),
							'type'        => 'text',
							'description' => __('This controls the MPESA confirmation field title which the user sees during checkout.', 'woocommerce'),
							'default'     => __('MPESA Confirmation Code', 'woocommerce'),
							'desc_tip'    => true,
							),
						'phone_title' => array(
							'title'       => __('Phone Number Field Title', 'woocommerce'),
							'type'        => 'text',
							'description' => __('This controls the MPESA phone number field title which the user sees during checkout.', 'woocommerce'),
							'default'     => __("MPESA Phone Number", 'woothemes'),
							'desc_tip'    => true,
							),
						'enable_for_methods' => array(
							'title'             => __('Enable for shipping methods', 'woocommerce'),
							'type'              => 'multiselect',
							'class'             => 'wc-enhanced-select',
							'css'               => 'width: 450px;',
							'default'           => '',
							'description'       => __('If Lipa na MPESA is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce'),
							'options'           => $shipping_methods,
							'desc_tip'          => true,
							'custom_attributes' => array(
								'data-placeholder' => __('Select shipping methods', 'woocommerce')
								)
							),
						'enable_for_virtual' => array(
							'title'             => __('Accept for virtual orders', 'woocommerce'),
							'label'             => __('Accept Lipa na MPESA if the order is virtual', 'woocommerce'),
							'type'              => 'checkbox',
							'default'           => 'yes'
							),
						'auto_complete_virtual_orders' => array(
							'title'             => __('Auto-complete for virtual orders', 'woocommerce'),
							'label'             => __('Automatically mark virtual orders as completed once payment is received', 'woocommerce'),
							'type'              => 'checkbox',
							'default'           => 'no'
							),						
						'kopokopo_api_key' => array(
							'title'       => __('KopoKopo API Key', 'woocommerce'),
							'type'        => 'text',
							'description' => __('The API Key received from KopoKopo.com.', 'woocommerce'),
							'desc_tip'    => true,
							),
						);

			}

			/**
			 * Check If The Gateway Is Available For Use
			 *
			 * @return bool
			 */
			public function is_available() {
				$order          = null;
				$needs_shipping = false;

				// Test if shipping is needed first
				if (WC()->cart && WC()->cart->needs_shipping()) {
					$needs_shipping = true;
				} elseif (is_page(wc_get_page_id('checkout')) && 0 < get_query_var('order-pay')) {
					$order_id = absint(get_query_var('order-pay'));
					$order    = wc_get_order($order_id);

					// Test if order needs shipping.
					if (0 < sizeof($order->get_items())) {
						foreach ($order->get_items() as $item) {
							$_product = $order->get_product_from_item($item);
							if ($_product && $_product->needs_shipping()) {
								$needs_shipping = true;
								break;
							}
						}
					}
				}

				$needs_shipping = apply_filters('woocommerce_cart_needs_shipping', $needs_shipping);

				// Virtual order, with virtual disabled
				if (! $this->enable_for_virtual && ! $needs_shipping) {
					return false;
				}

				// Check methods
				if (! empty($this->enable_for_methods) && $needs_shipping) {

					// Only apply if all packages are being shipped via chosen methods, or order is virtual
					$chosen_shipping_methods_session = WC()->session->get('chosen_shipping_methods');

					if (isset($chosen_shipping_methods_session)) {
						$chosen_shipping_methods = array_unique($chosen_shipping_methods_session);
					} else {
						$chosen_shipping_methods = array();
					}

					$check_method = false;

					if (is_object($order)) {
						if ($order->shipping_method) {
							$check_method = $order->shipping_method;
						}

					} elseif (empty($chosen_shipping_methods) || sizeof($chosen_shipping_methods) > 1) {
						$check_method = false;
					} elseif (sizeof($chosen_shipping_methods) == 1) {
						$check_method = $chosen_shipping_methods[0];
					}

					if (! $check_method) {
						return false;
					}

					$found = false;

					foreach ($this->enable_for_methods as $method_id) {
						if (strpos($check_method, $method_id) === 0) {
							$found = true;
							break;
						}
					}

					if (! $found) {
						return false;
					}
				}

				return parent::is_available();
			}


			public function payment_fields() {
				if ($description = $this->get_description()) {
				  echo wpautop(wptexturize($description));
				}

				echo '
					<p class="form-row form-row form-row-wide woocommerce-validated" id="mpesa_phone_field" data-o_class="form-row form-row form-row-wide">
						<label for="mpesa_phone" class="">' . $this->phone_title . ' <abbr class="required" title="required">*</abbr></label>
						<input type="text" class="input-text " name="mpesa_phone" id="mpesa_phone" placeholder="' . $this->phone_title . '" />
					</p>
					<p class="form-row form-row form-row-wide woocommerce-validated" id="mpesa_code_field" data-o_class="form-row form-row form-row-wide">
						<label for="mpesa_code" class="">' . $this->field_title . ' <abbr class="required" title="required">*</abbr></label>
						<input type="text" class="input-text " name="mpesa_code" id="mpesa_code" placeholder="' . $this->field_title . '" />
					</p>
					';
			}

			public function validate_fields() { 

				if ($_POST['mpesa_code']) {
					$success = true;
				} else {					
					$error_message = __("The ", 'woothemes') . $this->field_title . __(" field is required", 'woothemes');
					wc_add_notice(__('Field error: ', 'woothemes') . $error_message, 'error');
					$success = False;
				}

				if ($_POST['mpesa_phone']) {
					$success = true;
				} else {					
					$error_message = __("The ", 'woothemes') . $this->phone_title . __(" field is required", 'woothemes');
					wc_add_notice(__('Field error: ', 'woothemes') . $error_message, 'error');
					$success = False;
				}

				return $success;
			}

	    /**
	     * Process the payment and return the result
	     *
	     * @param int $order_id
	     * @return array
	     */
	    public function process_payment($order_id) {

	    	$order = wc_get_order($order_id);

				// Mark as processing (payment won't be taken until delivery)
	    	$order->update_status('pending', __('Waiting to verify MPESA payment.', 'woocommerce'));

				// Reduce stock levels
	    	$order->reduce_order_stock();

				// Remove cart
	    	WC()->cart->empty_cart();

	    	// Save confirmation code as note from customer
	    	$order->add_order_note($this->phone_title . ": " . $_POST['mpesa_phone'], $is_customer_note=1);
	    	$order->add_order_note($this->field_title . ": " . $_POST['mpesa_code'], $is_customer_note=1);

	    	// save to DB
	    	global $wpdb;
	    	$table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa"; 
	    	$ipn_table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa_ipn";

	    	$mpesa_input_phone = trim($_POST['mpesa_phone']);
	    	$mpesa_input_code = trim($_POST['mpesa_code']);

	    	$wpdb->insert($table_name, array(
	    	   "order_id" => $order->id,
	    	   "mpesa_receipt" => $mpesa_input_code,
	    	   "mpesa_phone" => $mpesa_input_phone
	    	));

	    	// check if IPN from KopoKopo is already received
	    	// query for the MPESA Receipt AKA transaction_reference
	    	$ipn_records = $wpdb->get_results(
	    		$wpdb->prepare(
	    			"SELECT * FROM `$ipn_table_name`
	    			 WHERE `transaction_reference` = %s
	    			",
	    		  $mpesa_input_code
	    	 )
	    	);

	    	if(!empty($ipn_records)) {
	    		$ipn_record = end($ipn_records);
	  	  	if ((int)$ipn_record->amount >= $order->get_total()) {
	  	  		$note = __("FULLY PAID: Payment of $ipn_record->currency $ipn_record->amount from $ipn_record->first_name $ipn_record->middle_name $ipn_record->last_name, phone number $ipn_record->sender_phone and MPESA reference $ipn_record->transaction_reference confirmed by KopoKopo", 'woocommerce');
			    	$order->add_order_note($note);
			    	$order->payment_complete();
	  	  	} else {
	  	  		// underpayment
	  	  		$note = __("PARTLY PAID: Received $ipn_record->currency $ipn_record->amount from $ipn_record->first_name $ipn_record->middle_name $ipn_record->last_name, phone number $ipn_record->sender_phone and MPESA reference $ipn_record->transaction_reference", 'woocommerce');
	  	  		$order->add_order_note($note);			    	
	  	  	}
	    	}

				// Return thankyou redirect
	    	return array(
	    		'result' 	=> 'success',
	    		'redirect'	=> $this->get_return_url($order)
	    		);
	    }

	    /**
	     * Output for the order received page.
	    */
	    public function thankyou_page() {
	    	if ($this->instructions) {
	    		echo wpautop(wptexturize($this->instructions));
	    	}
	    }

	    /**
	     * Add content to the WC emails.
	     *
	     * @access public
	     * @param WC_Order $order
	     * @param bool $sent_to_admin
	     * @param bool $plain_text
	     */
	    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
	    	if ($this->instructions && ! $sent_to_admin && 'lipa_na_mpesa' === $order->payment_method) {
	    		echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
	    	}
	    }

	  }
	}

	function add_lipa_na_mpesa_gateway($methods) {
		$methods[] = 'WC_LipaNaMPESA_Gateway'; 
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'add_lipa_na_mpesa_gateway');

	// KopoKopo API
	function woocommerce_lipa_na_mpesa_kopokopo_ipn_listener() {
    // check for your custom query var
    // If you are paranoid you can also check the value of the var
    if (! isset($_GET['KOPKOPO_IPN_LISTENER'])) {
      // if query var is not present then not API call	    	
      return;
    }    

  	$response = array(
	   "status" => "03" // invalid
		);

		// Get all the fields from the post request
		$inputJSON = file_get_contents('php://input');
		$inputData = json_decode($inputJSON, TRUE);

		if(!is_array($inputData)) {
			$inputData = array();
		}

		$signature = "";
		if(isset($inputData['signature'])){
	    $signature = $inputData['signature'];
	    unset($inputData['signature']);
		} 	

		// create a Base64 encoded signature using API_KEY as the secret key
		// the signature is a Base64 encoded HMAC(Hash Message Authentication Code)
		// Described well in the KopoKopo API documentation		
		ksort($inputData);
		$base_string = "";
		foreach ($inputData as $key => $value) {
			if ($base_string == "") {
				$base_string = $base_string . $key . "=" . $value;
			} else {
				$base_string = $base_string . "&" . $key . "=" . $value;
			}				
		}

		$lipa_na_mpesa_gateway = new WC_LipaNaMPESA_Gateway();
	  $kopokopo_api_key = $lipa_na_mpesa_gateway->kopokopo_api_key;
	  $signature_created = base64_encode(hash_hmac("sha1", $base_string, $kopokopo_api_key, true));

	  if ($signature_created == $signature) {		  	
      $service_name = "";
      $business_number = "";
      $transaction_reference = "";
      $internal_transaction_id = "";
      $transaction_timestamp = "";
      $transaction_type = "";
      $amount = 0;
      $first_name = "";
      $last_name = "";
      $middle_name = "";
      $sender_phone = "";
      $currency = "";
      $account_number = "";
  		
  		if(isset($inputData['service_name'])){
  	    $service_name = $inputData['service_name'];
  		}
  		if(isset($inputData['business_number)'])){
  	    $business_number = $inputData['business_number'];
  		}
  		if(isset($inputData['transaction_reference'])){
  	    $transaction_reference = $inputData['transaction_reference'];
  		}
  		if(isset($inputData['internal_transaction_id'])){
  	    $internal_transaction_id = $inputData['internal_transaction_id'];
  		}
  		if(isset($inputData['transaction_timestamp'])){
  	    $transaction_timestamp = $inputData['transaction_timestamp'];
  		}
  		if(isset($inputData['transaction_type'])){
  	    $transaction_type = $inputData['transaction_type'];
  		}
  		if(isset($inputData['amount'])){
  	    $amount = $inputData['amount'];
  		}
  		if(isset($inputData['first_name'])){
  	    $first_name = $inputData['first_name'];
  		}
  		if(isset($inputData['last_name'])){
  	    $last_name = $inputData['last_name'];
  		}
  		if(isset($inputData['middle_name'])){
  	    $middle_name = $inputData['middle_name'];
  		}
  		if(isset($inputData['sender_phone'])){
  	    $sender_phone = $inputData['sender_phone'];
  		}
  		if(isset($inputData['currency'])){
  	    $currency = $inputData['currency'];
  		}
  		if(isset($inputData['account_number'])){
  	    $account_number = $inputData['account_number'];
  		}

  		global $wpdb;
  		$table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa";
  		$ipn_table_name = $wpdb->prefix . "woocommerce_lipa_na_mpesa_ipn";

  		// check if this has been received before
  		$ipn_records = $wpdb->get_results(
  			$wpdb->prepare(
  				"SELECT * FROM `$ipn_table_name`
  				 WHERE `internal_transaction_id` = %s OR `transaction_reference` = %s
  				",
  			  $internal_transaction_id, $transaction_reference
  		 )
  		); 

  		if (empty($ipn_records)) {
  			$duplicate = 0;
  		} else {
  			$duplicate = 1;
  		}

	  	$wpdb->insert($ipn_table_name, array(
	  		'service_name' 							=> $service_name,
	  		'business_number' 					=> $business_number,
	  		'transaction_reference' 		=> $transaction_reference,
	  		'internal_transaction_id' 	=> $internal_transaction_id,
	  		'transaction_timestamp' 		=> $transaction_timestamp,
	  		'transaction_type' 					=> $transaction_type,
	  		'amount' 										=> $amount,
	  		'first_name' 								=> $first_name,
	  		'last_name' 								=> $last_name,
	  		'middle_name' 							=> $middle_name,
	  		'sender_phone' 							=> $sender_phone,
	  		'currency' 									=> $currency,
	  		'account_number' 						=> $account_number,
	  		'duplicate'									=> $duplicate
	  	));	

	  	if ($duplicate == 0) {
  	  	// query for the MPESA Receipt AKA transaction_reference
  	  	$records = $wpdb->get_results(
  	  		$wpdb->prepare(
  	  			"SELECT * FROM `$table_name`
  	  			 WHERE `mpesa_receipt` = %s
  	  			",
  	  		  $transaction_reference
  	  	 )
  	  	);

  	  	if (empty($records)) {
		    	$response = array(
				   "status" => "02" // Account not found
					);
  	  	} else {
	  	  	$record = end($records);
	  	  	$this_order = wc_get_order($record->order_id);		
	  	  	if ($this_order->get_status() == "pending") {  	  	
		  	  	// check that it is paid in full
		  	  	if ((int)$amount >= $this_order->get_total()) {
				    	$this_order->add_order_note(__("FULLY PAID: Payment of $currency $amount from $first_name $middle_name $last_name, phone number $sender_phone and MPESA reference $transaction_reference confirmed by KopoKopo", 'woocommerce'));
				    	$this_order->payment_complete();
		  	  	} else {
		  	  		// underpayment
		  	  		$note = __("PARTLY PAID: Received $currency $amount from $first_name $middle_name $last_name, phone number $sender_phone and MPESA reference $transaction_reference", 'woocommerce');
		  	  		$this_order->add_order_note($note);			    	
		  	  	}
	  	    	$response = array(
	  			   	"status" => "01", // Accepted
	  			   	"description" => "Accepted", 
	  			  	"subscriber_message" => "We have received your payment of " . $amount . " for Order No. " . $record->order_id
	  				);
	  			} else {
	  				// this order payment status is something other than pending
			    	$response = array(
					   	"status" => "01", // Accepted
					   	"description" => "Accepted", 
					  	"subscriber_message" => "" 
						);
	  			}
  	  	}	  	  		  	  	
  	  } else {
  	  	// we had already dealt with this transaction
	    	$response = array(
			   	"status" => "01", // Accepted
			   	"description" => "Accepted", 
			  	"subscriber_message" => "" 
				);
  	  }
	  } else {
	  	// something is wrong - maybe fraudulent
	  }

	  wp_send_json($response);	
    exit;
	}
	add_action('init', 'woocommerce_lipa_na_mpesa_kopokopo_ipn_listener');

	// Mark virtual orders as completed automatically
	add_filter('woocommerce_payment_complete_order_status', 'woocommerce_lipa_na_mpesa_virtual_order_completion', 10, 2);
	 
	function woocommerce_lipa_na_mpesa_virtual_order_completion($order_status, $order_id) {
		$lipa_na_mpesa_gateway = new WC_LipaNaMPESA_Gateway();
	  $auto_complete_virtual_orders = $lipa_na_mpesa_gateway->auto_complete_virtual_orders;
	  if ($auto_complete_virtual_orders) {
		  $order = new WC_Order($order_id);	 
		  if ('processing' == $order_status &&
		    ('on-hold' == $order->status || 'pending' == $order->status || 'failed' == $order->status )) {	 
		    $virtual_order = null;	 
		    if ( count( $order->get_items() ) > 0 ) {	 
		      foreach( $order->get_items() as $item ) {	 
		        if ( 'line_item' == $item['type'] ) {	 
		          $_product = $order->get_product_from_item($item);	 
		          if (!$_product->is_virtual()) {
		            // once we've found one non-virtual product we know we're done, break out of the loop
		            $virtual_order = false;
		            break;
		          } else {
		            $virtual_order = true;
		          }
		        }
		      }
		    }	 
		    // virtual order, mark as completed
		    if ($virtual_order) {
		      return 'completed';
		    }
		  }	
		} 
	  // non-virtual order, return original status
	  return $order_status;
	}

	// Kenya Counties
	add_filter('woocommerce_states', 'KE_woocommerce_counties');

	function KE_woocommerce_counties($states) {

	  $states['KE'] = array(
			30	=>	__("Baringo", "woocommerce"),
			36	=>	__("Bomet", "woocommerce"),
			39	=>	__("Bungoma", "woocommerce"),
			40	=>	__("Busia", "woocommerce"),
			28	=>	__("Elgeyo-Marakwet", "woocommerce"),
			14	=>	__("Embu", "woocommerce"),
			7		=>	__("Garissa", "woocommerce"),
			43	=>	__("Homa Bay", "woocommerce"),
			11	=>	__("Isiolo", "woocommerce"),
			34	=>	__("Kajiado", "woocommerce"),
			37	=>	__("Kakamega", "woocommerce"),
			35	=>	__("Kericho", "woocommerce"),
			22	=>	__("Kiambu", "woocommerce"),
			3		=>	__("Kilifi", "woocommerce"),
			20	=>	__("Kirinyaga", "woocommerce"),
			45	=>	__("Kisii", "woocommerce"),
			42	=>	__("Kisumu", "woocommerce"),
			15	=>	__("Kitui", "woocommerce"),
			2		=>	__("Kwale", "woocommerce"),
			31	=>	__("Laikipia", "woocommerce"),
			5		=>	__("Lamu", "woocommerce"),
			16	=>	__("Machakos", "woocommerce"),
			17	=>	__("Makueni", "woocommerce"),
			9		=>	__("Mandera", "woocommerce"),
			10	=>	__("Marsabit", "woocommerce"),
			12	=>	__("Meru", "woocommerce"),
			44	=>	__("Migori", "woocommerce"),
			1		=>	__("Mombasa", "woocommerce"),
			21	=>	__("Murang'a", "woocommerce"),
			47	=>	__("Nairobi County", "woocommerce"),
			32	=>	__("Nakuru", "woocommerce"),
			29	=>	__("Nandi", "woocommerce"),
			33	=>	__("Narok", "woocommerce"),
			46	=>	__("Nyamira", "woocommerce"),
			18	=>	__("Nyandarua", "woocommerce"),
			19	=>	__("Nyeri", "woocommerce"),
			25	=>	__("Samburu", "woocommerce"),
			41	=>	__("Siaya", "woocommerce"),
			6		=>	__("Taita-Taveta", "woocommerce"),
			4		=>	__("Tana River", "woocommerce"),
			13	=>	__("Tharaka-Nithi", "woocommerce"),
			26	=>	__("Trans Nzoia", "woocommerce"),
			23	=>	__("Turkana", "woocommerce"),
			27	=>	__("Uasin Gishu", "woocommerce"),
			38	=>	__("Vihiga", "woocommerce"),
			8		=>	__("Wajir", "woocommerce"),
			24	=>	__("West Pokot", "woocommerce"),

	 );

	  return $states;
	}

	 //Woocommerce doesn't have KES by default so add it if not already added
	 add_filter('woocommerce_currencies', 'add_kenya_shilling_currency');

	 function add_kenya_shilling_currency($currencies) {
	   if(!isset($currencies['KES'])||!isset($currencies['KSH'])){
	    $currencies['KES'] = __('Kenyan Shilling', 'woocommerce');	    
	    return $currencies;
	  }
	}

	// Kenya Currency
	 add_filter('woocommerce_currency_symbol', 'add_kenya_shilling_currency_symbol', 10, 2);
	 function add_kenya_shilling_currency_symbol($currency_symbol, $currency) {
	   switch($currency) {
	    case 'KES': $currency_symbol = 'KES'; 
	    break;
	  }
	  return $currency_symbol;
	}
}