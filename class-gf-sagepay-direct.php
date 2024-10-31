<?php

add_action('wp', array( 'GFSagePayDirect', 'maybe_thankyou_page' ), 5);

GFForms::include_payment_addon_framework();

class GFSagePayDirect extends GFPaymentAddOn {

	protected $_version = GF_SAGEPAYDIRECT_VERSION;
	protected $_min_gravityforms_version = '1.9.12';
	protected $_slug = 'gravityformssagepaydirect';
	protected $_path = 'gravityformssagepaydirect/sagepay-direct.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Opayo Direct Add-On';
	protected $_short_title = 'Opayo Direct';
	protected $_requires_credit_card = true;
	protected $_supports_callbacks = true;

	// Members plugin integration
	protected $_capabilities = array(
		'gravityforms_sagepay_direct',
		'gravityforms_sagepay_direct_uninstall',
		'gravityforms_sagepay_direct_plugin_page'
	);

	// Permissions
	protected $_capabilities_settings_page = 'gravityforms_sagepay_direct';
	protected $_capabilities_form_settings = 'gravityforms_sagepay_direct';
	protected $_capabilities_uninstall = 'gravityforms_sagepay_direct_uninstall';
	protected $_capabilities_plugin_page = 'gravityforms_sagepay_direct_plugin_page';

	/**
	 * @var array $_args_for_deprecated_hooks Will hold a few arrays which are needed by some deprecated hooks, keeping them out of the $authorization array so that potentially sensitive data won't be exposed in logging statements.
	 */
	private $_args_for_deprecated_hooks = array();

	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFSagePayDirect();
		}

		return self::$_instance;
	}

	//----- SETTINGS PAGES ----------//
	public function plugin_settings_fields() {

		$ipn_url = get_bloginfo('url') . '/?page=gf_sagepay_direct_ipn';

		$description = '<p style="text-align: left;">' . sprintf( esc_html__( 'Opayo Direct is a payment gateway for merchants. Use Gravity Forms to collect payment information and process it through your Opayo Direct account. If you don\'t have a account, you can %ssign up for one here%s. Your sites IPN URL is %s', 'gf-sagepay-direct-patsatech' ), '<a href="http://bitly.com/1kNuzrx" target="_blank">', '</a>', $ipn_url ) . '</p>';

		return array(
			array(
				'title'       => esc_html__( 'Opayo Direct Account Information', 'gf-sagepay-direct-patsatech' ),
				'description' => $description,
				'fields'      => array(
					array(
						'name'          => 'mode',
						'label'         => esc_html__( 'Mode', 'gf-sagepay-direct-patsatech' ),
						'type'          => 'radio',
						'default_value' => 'test',
						'choices'       => array(
							array(
								'label' => esc_html__( 'Production', 'gf-sagepay-direct-patsatech' ),
								'value' => 'production',
							),
							array(
								'label' => esc_html__( 'Test', 'gf-sagepay-direct-patsatech' ),
								'value' => 'test',
							),
						),
						'horizontal'    => true,
					),
					array(
						'name'              => 'vendorname',
						'label'             => esc_html__( 'Vendor Name', 'gf-sagepay-direct-patsatech' ),
						'type'              => 'text',
						'class'             => 'medium',
						'default_value'			=> '',
					),
					array(
						'name'          => 'transactiontype',
						'label'         => esc_html__( 'Transaction Type', 'gf-sagepay-direct-patsatech' ),
						'type'          => 'radio',
						'default_value' => 'PAYMENT',
						'choices'       => array(
							array(
								'label' => esc_html__( 'Payment', 'gf-sagepay-direct-patsatech' ),
								'value' => 'PAYMENT',
							),
							array(
								'label' => esc_html__( 'Deferred', 'gf-sagepay-direct-patsatech' ),
								'value' => 'DEFFERRED',
							),
							array(
								'label' => esc_html__( 'Authenticate', 'gf-sagepay-direct-patsatech' ),
								'value' => 'AUTHENTICATE',
							),
						),
						'horizontal'    => true,
					),
				),
			),
		);
	}

	public function is_valid_plugin_key() {
		return $this->is_valid_key();
	}

	public function is_valid_custom_key() {
		//get override settings
		$apiSettingsEnabled = $this->get_setting( 'apiSettingsEnabled' );
		if ( $apiSettingsEnabled ) {
			$custom_settings['overrideVendorName'] = $this->get_setting( 'overrideVendorName' );
			$custom_settings['overrideTransactionType']   = $this->get_setting( 'overrideTransactionType' );
			$custom_settings['overrideMode']   = $this->get_setting( 'overrideMode' );

			return $this->is_valid_key( $custom_settings );
		}

		return false;
	}

	public function is_valid_key( $settings = array() ) {
		$api_settings = $this->get_aim( $settings );

		if ( empty($api_settings['vendorname']) && empty($api_settings['transactiontype']) && empty($api_settings['mode']) ) {
			$this->log_debug( __METHOD__ . '(): Please make sure you have entered the correct settings.' );

			return false;
		} else {
			return true;
		}
	}

	private function get_aim( $local_api_settings = array() ) {

		if ( ! empty( $local_api_settings ) ) {
			$api_settings = array(
				'vendorname'  => rgar( $local_api_settings, 'overrideVendorName' ),
				'mode'      => rgar( $local_api_settings, 'overrideMode' ),
				'transactiontype' => rgar( $local_api_settings, 'overrideTransactionType' )
			);
		} else {
			$api_settings = $this->get_api_settings( $local_api_settings );
		}

		return $api_settings;
	}

	private function get_api_settings( $local_api_settings ) {

		//for Opayo Direct, each feed can have its own login id and transaction key specified which overrides the master plugin one
		//use the custom settings if found, otherwise use the master plugin settings


		if ( isset($this->current_feed['meta']['apiSettingsEnabled']) && !empty($this->current_feed['meta']['apiSettingsEnabled']) ) {

			$vendorname  = $this->current_feed['meta']['overrideVendorName'];
			$transactiontype = $this->current_feed['meta']['overrideTransactionType'];
			$mode = $this->current_feed['meta']['overrideMode'];

			return array( 'vendorname' => $vendorname, 'transactiontype' => $transactiontype, 'mode' => $mode );

		} else {
			$settings = $this->get_plugin_settings();

			return array(
				'vendorname'  => rgar( $settings, 'vendorname' ),
				'transactiontype' => rgar( $settings, 'transactiontype' ),
				'mode' => rgar( $settings, 'mode' )
			);
		}

	}

	//-------- Form Settings ---------

	/**
	 * Prevent feeds being listed or created if the api keys aren't valid.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return $this->is_valid_plugin_key();
	}

	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		$transaction_type = parent::get_field( 'transactionType', $default_settings );
		$choices          = $transaction_type['choices'];
		$add_donation     = true;
		unset($transaction_type[2]);
		unset($choices[2]);
		foreach ( $choices as $choice ) {
			//add donation option if it does not already exist
			if ( $choice['value'] == 'donation' ) {
				$add_donation = false;
			}
		}
		if ( $add_donation ) {
			//add donation transaction type
			$choices[] = array( 'label' => __( 'Donations', 'gravityforms-sagepay-form' ), 'value' => 'donation' );
		}
		$transaction_type['choices'] = $choices;
		$default_settings            = $this->replace_field( 'transactionType', $transaction_type, $default_settings );

		$default_settings[3]['fields'][0]['field_map'][] = array('label' => 'First Name', 'name' =>  'first_name','required' =>  '' );

		$default_settings[3]['fields'][0]['field_map'][] = array('label' => 'Last Name', 'name' =>  'last_name','required' =>  '' );

		//remove default options before adding custom
		$default_settings = parent::remove_field( 'options', $default_settings );

		$fields = array(
			array(
				'name'    => 'options',
				'label'   => esc_html__( 'Options', 'gf-sagepay-direct-patsatech' ),
				'type'    => 'options',
				'tooltip' => '<h6>' . esc_html__( 'Options', 'gf-sagepay-direct-patsatech' ) . '</h6>' . esc_html__( 'Turn on or off the available Opayo Direct checkout options.', 'gf-sagepay-direct-patsatech' ),
			),
		);

		//Add post fields if form has a post
		$form = $this->get_current_form();

		$default_settings = $this->add_field_after( 'billingInformation', $fields, $default_settings );


		return $default_settings;
	}


	public function settings_options($field, $echo = true)
	{
		$checkboxes = array(
				'name'    => 'delay_notification',
				'type'    => 'checkboxes',
				'onclick' => 'ToggleNotifications();',
				'choices' => array(
						array(
								'label' => __('Send notifications only when payment is received.', 'gf-sagepay-direct-patsatech'),
								'name'  => 'delayNotification',
						),
				)
		);

		$html = $this->settings_checkbox($checkboxes, false);

		$html .= $this->settings_hidden(array( 'name' => 'selectedNotifications', 'id' => 'selectedNotifications' ), false);

		$form                      = $this->get_current_form();
		$has_delayed_notifications = $this->get_setting('delayNotification');
		ob_start(); ?>
		<ul id="gf_sagepay_direct_notification_container" style="padding-left:20px; margin-top:10px; <?php echo $has_delayed_notifications ? '' : 'display:none;' ?>">
			<?php
						if (! empty($form) && is_array($form['notifications'])) {
								$selected_notifications = $this->get_setting('selectedNotifications');
								if (! is_array($selected_notifications)) {
										$selected_notifications = array();
								}

								//$selected_notifications = empty($selected_notifications) ? array() : json_decode($selected_notifications);

								foreach ($form['notifications'] as $notification) {
										?>
					<li class="gf_sagepay_direct_notification">
						<input type="checkbox" class="notification_checkbox" value="<?php echo $notification['id'] ?>" onclick="SaveNotifications();" <?php checked(true, in_array($notification['id'], $selected_notifications)) ?> />
						<label class="inline" for="gf_sagepay_selected_notifications"><?php echo $notification['name']; ?></label>
					</li>
				<?php
								}
						} ?>
		</ul>
		<script type='text/javascript'>
			function SaveNotifications() {
				var notifications = [];
				jQuery('.notification_checkbox').each(function () {
					if (jQuery(this).is(':checked')) {
						notifications.push(jQuery(this).val());
					}
				});
				jQuery('#selectedNotifications').val(jQuery.toJSON(notifications));
			}

			function ToggleNotifications() {

				var container = jQuery('#gf_sagepay_direct_notification_container');
				var isChecked = jQuery('#delaynotification').is(':checked');

				if (isChecked) {
					container.slideDown();
					jQuery('.gf_sagepay_direct_notification input').prop('checked', true);
				}
				else {
					container.slideUp();
					jQuery('.gf_sagepay_direct_notification input').prop('checked', false);
				}

				SaveNotifications();
			}
		</script>
		<?php

		$html .= ob_get_clean();

		if ($echo) {
				echo $html;
		}

		return $html;
	}


	public function checkbox_input_change_post_status( $choice, $attributes, $value, $tooltip ) {
		$markup = $this->checkbox_input( $choice, $attributes, $value, $tooltip );

		$dropdown_field = array(
			'name'     => 'update_post_action',
			'choices'  => array(
				array( 'label' => '' ),
				array( 'label' => esc_html__( 'Mark Post as Draft', 'gf-sagepay-direct-patsatech' ), 'value' => 'draft' ),
				array( 'label' => esc_html__( 'Delete Post', 'gf-sagepay-direct-patsatech' ), 'value' => 'delete' ),

			),
			'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
		);
		$markup .= '&nbsp;&nbsp;' . $this->settings_select( $dropdown_field, false );

		return $markup;
	}

	public function supported_billing_intervals() {
		//authorize.net does not use years or weeks, override framework function
		$billing_cycles = array(
			'day'   => array( 'label' => esc_html__( 'day(s)', 'gf-sagepay-direct-patsatech' ), 'min' => 7, 'max' => 365 ),
			'month' => array( 'label' => esc_html__( 'month(s)', 'gf-sagepay-direct-patsatech' ), 'min' => 1, 'max' => 12 )
		);

		return $billing_cycles;
	}

	/**
	 * Append the phone field to the default billing_info_fields added by the framework.
	 *
	 * @return array
	 */
	public function billing_info_fields() {

		$fields = parent::billing_info_fields();

		$fields[] = array(
				'name'     => 'phone',
				'label'    => esc_html__( 'Phone', 'gf-sagepay-direct-patsatech' ),
				'required' => false
		);

		return $fields;
	}

	/**
	 * Add supported notification events.
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return array
	 */
	public function supported_notification_events( $form ) {
		if ( ! $this->has_feed( $form['id'] ) ) {
			return false;
		}

		return array(
			'complete_payment'          => esc_html__( 'Payment Completed', 'gf-sagepay-direct-patsatech' ),
			'fail_payment'          => esc_html__( 'Payment Failed', 'gf-sagepay-direct-patsatech' ),
		);
	}
	


	private function getRequestHeaders() {
		$headers = array();
		foreach($_SERVER as $key => $value) {
			if (substr($key, 0, 5) <> 'HTTP_') {
				continue;
			}
			$header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
			$headers[$header] = $value;
		}
		return $headers;
	}


	public static function get_ip_address() {
		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// Proxy servers can send through this header like this: X-Forwarded-For: client1, proxy1, proxy2
			// Make sure we always only send through the first IP in the list which should always be the client IP.
			return (string) rest_is_ip_address( trim( current( preg_split( '/,/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}







	//------ AUTHORIZE AND CAPTURE SINGLE PAYMENT ------//
	public function authorize( $feed, $submission_data, $form, $entry ) {

		$txnArray = $this->get_payment_transaction( $feed, $submission_data, $form, $entry );

		$names = $this->get_first_last_name( $submission_data['card_name'] );

		if( isset($entry[$feed['meta']['billingInformation_first_name']]) ){
			$names['first_name'] = $entry[$feed['meta']['billingInformation_first_name']];
		}

		$ship_last_name = '';
		if( isset($entry[$feed['meta']['billingInformation_last_name']]) ){
			$names['last_name'] = $entry[$feed['meta']['billingInformation_last_name']];
		}

		$settings = $this->get_aim();

		//$entry_id = GFAPI::add_entry( $entry );
		//$entry_id = $entry['id'];
		//$entry = GFAPI::get_entry( $entry_id );

		$credit_card = preg_replace( '/(?<=\d)\s+(?=\d)/', '', trim( $txnArray['card'] ) );
		$month = $txnArray['month'];
		$year = $txnArray['year'];

		$amt = $txnArray['amount'];

		$currency = GFCommon::get_currency();

		$cardtype = $this->wdgetCardType($txnArray['card']);

		$country = GFCommon::get_country_code($submission_data["country"]);

		$basket = ':Order Total:---:---:---:---:'.$amt;
		$basket = '1:'.$basket;

		$time_stamp = date("ymdHis");
		$orderid = $settings['vendorname'] . "-" . $time_stamp ;

		$ipn_url = get_bloginfo('url') . '/?page=gf_sagepay_direct_ipn&oid='.$orderid;

		$sd_arg['ReferrerID']            = 'CC923B06-40D5-4713-85C1-700D690550BF';
		$sd_arg['Amount']                = $amt;
		$sd_arg['CustomerEMail']         = $submission_data["email"];
		$sd_arg['BillingSurname']        = $names["last_name"];
		$sd_arg['BillingFirstnames']     = $names["first_name"];
		$sd_arg['BillingAddress1']       = $submission_data["address"];
		$sd_arg['BillingAddress2']       = $submission_data["address2"];
		$sd_arg['BillingCity']           = $submission_data["city"];

		if ($country == 'US') {
				$sd_arg['BillingState']      = $submission_data["state"];
		} else {
				$sd_arg['BillingState']      = '';
		}

		$sd_arg['BillingPostCode']       = $submission_data["zip"];
		$sd_arg['BillingCountry']        = $country;
		$sd_arg['BillingPhone']          = $submission_data["phone"];


		$sd_arg['DeliverySurname']        = $names["last_name"];
		$sd_arg['DeliveryFirstnames']     = $names["first_name"];
		$sd_arg['DeliveryAddress1']       = $submission_data["address"];
		$sd_arg['DeliveryAddress2']       = $submission_data["address2"];
		$sd_arg['DeliveryCity']           = $submission_data["city"];

		if ($country == 'US') {
				$sd_arg['DeliveryState']      = $submission_data["state"];
		} else {
				$sd_arg['DeliveryState']      = '';
		}

		$sd_arg['DeliveryPostCode']       = $submission_data["zip"];
		$sd_arg['DeliveryCountry']        = $country;

		$sd_arg['DeliveryPhone']        = $submission_data["phone"];
		$sd_arg['CardHolder']           = $submission_data['card_name'];
		$sd_arg['CardNumber']           = $credit_card;
		$sd_arg['StartDate']            = '';
		$sd_arg['ExpiryDate']           = $month . $year;
		$sd_arg['CV2']                  = $txnArray['cvv'];
		$sd_arg['CardType']             = $cardtype;
		$sd_arg['VPSProtocol']          = "4.00";
		$sd_arg['Vendor']               = $settings['vendorname'];
		$sd_arg['Description']          = sprintf(__('Order #%s', 'woo-sagepay-patsatech'), $orderid);
		$sd_arg['Currency']             = $currency;
		$sd_arg['TxType']               = $settings['transactiontype'];
		$sd_arg['VendorTxCode']         = $orderid;
		//$sd_arg['Basket']               = $basket;

		$header = $this->getRequestHeaders();

		$sd_arg['ClientIPAddress']          = $this->get_ip_address();
		$sd_arg['BrowserJavascriptEnabled'] = 0;
		$sd_arg['BrowserAcceptHeader']      = $header['Accept'];
		$sd_arg['BrowserLanguage']          = substr($header['Accept-Language'], 0, 2);
		$sd_arg['BrowserUserAgent']         = $header['User-Agent'];
		$sd_arg['ThreeDSNotificationURL']   = $ipn_url;
		$sd_arg['ChallengeWindowSize']      = "05";


		$this->log_error( __METHOD__ . '(): Request from Opayo => ' . print_r($sd_arg,true) );

		$post_values = "";
		foreach ($sd_arg as $key => $value) {
				$post_values .= "$key=" . urlencode($value) . "&";
		}
		$post_values = rtrim($post_values, "& ");

		if ($settings['mode'] == 'test') {
				$gateway_url = 'https://test.sagepay.com/gateway/service/vspdirect-register.vsp';
		} else {
				$gateway_url = 'https://live.sagepay.com/gateway/service/vspdirect-register.vsp';
		}

		$response = wp_remote_post($gateway_url, array(
			'body' => $post_values,
			'method' => 'POST',
			'sslverify' => false
		));

		$wpd_session['sagepay_vtc'] = $orderid;

		$wpd_session['sagepay_amount'] = $amt;

		$this->log_error( __METHOD__ . '(): Request URL from Opayo => ' . $gateway_url );

		if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
			$resp = array();
			$lines = preg_split('/\r\n|\r|\n/', $response['body']);
			foreach ($lines as $line) {
				$key_value = preg_split('/=/', $line, 2);
				if (count($key_value) > 1) {
					$resp[trim($key_value[0])] = trim($key_value[1]);
				}
			}

			set_transient('sagepay_response'.$orderid, $resp, 60*60);

			$this->log_error( __METHOD__ . '(): Response from Opayo => ' . print_r($resp,true) );

			if ($resp['Status'] == "OK" || $resp['Status'] == "REGISTERED" || $resp['Status'] == "AUTHENTICATED") {

						set_transient('sagepay_direct'.$orderid, $wpd_session, 60*60);

						$auth = array(
							'is_authorized'    => true,
							'transaction_id'   => $orderid,
							'captured_payment' => array(
								'is_success'     => true,
								'error_message'  => '',
								'transaction_id' => $orderid,
								'amount'         => $amt
							),
						);




			} elseif ($resp['Status'] == "3DAUTH") {
				if ($resp['3DSecureStatus'] == 'OK') {
					if (isset($resp['ACSURL']) && ( isset($resp['MD']) || isset($resp['CReq']) )) {
						
						$entry_id = GFAPI::add_entry( $entry );
						$entry = GFAPI::get_entry( $entry_id );
						$wpd_session['sagepay_oid'] = $entry_id;

						$wpd_session['sagepay_acsurl'] = $resp['ACSURL'];

						if( isset($resp['PAReq']) && !empty($resp['PAReq']) ){
							$wpd_session['sagepay_pareq'] = $resp['PAReq'];
						}

						if( isset($resp['CReq']) && !empty($resp['CReq']) ){
							$wpd_session['sagepay_pareq'] = "";
							$wpd_session['sagepay_creq'] = $resp['CReq'];
						}

						if( isset($resp['MD']) && !empty($resp['MD']) ){
							$wpd_session['sagepay_md'] = $resp['MD'];
						}

						$wpd_session['sagepay_vpstxid'] = $resp['VPSTxId'];

						set_transient('sagepay_direct'.$orderid, $wpd_session, 60*60);

						$this->log_error( __METHOD__ . '(): Response => ' .sprintf(__("Opayo Direct Payment Intiated waiting for 3D Secure response. Transaction Id: %s", "gf-sagepay-direct-patsatech"), $orderid) );

						$auth = array(
							'is_authorized'  => false,
							'transaction_id' => $orderid,
							'error_message'  => 'Opayo Direct Payment Intiated waiting for 3D Secure response.'
						);

						GFPaymentAddOn::add_note($entry['id'], sprintf(__("Opayo Direct Payment Intiated waiting for 3D Secure response. Transaction Id: %s", "gf-sagepay-direct-patsatech"), $orderid));

						
						wp_redirect($ipn_url.'&sagepay_status=3ds'); exit;

					}
				}
			} else {
									
				if (isset($resp['StatusDetail'])) {
					$error = sprintf(__('Transaction Failed. %s - %s', 'woo-sagepay-patsatech'), $resp['Status'], $resp['StatusDetail']);

					$this->log_error( __METHOD__ . '(): '.$error );

					$auth = array(
						'is_authorized'  => false,
						//'transaction_id' => $orderid,
						'error_message'  => 'Payment failed. Response => '.$error
					);

				} else {
					$error = sprintf(__('Transaction Failed with %s - unknown error.', 'woo-sagepay-patsatech'), $resp['Status']);

					$this->log_error( __METHOD__ . '(): '.$error );

					$auth = array(
						'is_authorized'  => false,
						//'transaction_id' => $orderid,
						'error_message'  => 'Payment failed. Response => '.$error
					);
				}
			}
		} else {
			
			$error = __('Gateway Error. Please Notify the Store Owner about this error.', 'woo-sagepay-patsatech');

			$this->log_error( __METHOD__ . '(): '.$error.'-------'.print_r($response,true) );

			$auth = array(
				'is_authorized'  => false,
				//'transaction_id' => $orderid,
				'error_message'  => $error
			);
		}

		return $auth;

	}

	public function get_payment_transaction( $feed, $submission_data, $form, $entry ) {

		$transaction = $this->get_aim();

		$feed_name = rgar( $feed['meta'], 'feedName' );
		$this->log_debug( __METHOD__ . "(): Initializing new Opayo Direct object based on feed #{$feed['id']} - {$feed_name}." );

		$order_id = empty( $invoice_number ) ? uniqid() : $invoice_number; //???

		$amount = number_format($submission_data['payment_amount'], 2, '.', '');

		$pan = $submission_data["card_number"];

		$txnArray = array(
			'orderid' => $order_id,
			'amount' => $amount,
			'card' => $submission_data["card_number"],
			'cvv' => $submission_data["card_security_code"],
			'month' => str_pad($submission_data["card_expiration_date"][0], 2, "0", STR_PAD_LEFT),
			'year' => substr($submission_data["card_expiration_date"][1], -2),
			'entry' => $entry
		);

		$this->log_debug( __METHOD__ . '(): $submission_data line_items => ' . print_r( $submission_data['line_items'], 1 ) );

		return $txnArray;

	}

	/**
	 * Check if the current entry was processed by this add-on.
	 *
	 * @param int $entry_id The ID of the current Entry.
	 *
	 * @return bool
	 */
	public function is_payment_gateway( $entry_id ) {

		if ( $this->is_payment_gateway ) {
			return true;
		}

		$gateway = gform_get_meta( $entry_id, 'payment_gateway' );

		return in_array( $gateway, array( 'Opayo Direct', $this->_slug ) );
	}

	// HELPERS
	private function remove_spaces( $text ) {

		$text = str_replace( "\t", ' ', $text );
		$text = str_replace( "\n", ' ', $text );
		$text = str_replace( "\r", ' ', $text );

		return $text;

	}

	private function truncate( $text, $max_chars ) {
		if ( strlen( $text ) <= $max_chars ) {
			return $text;
		}

		return substr( $text, 0, $max_chars );
	}

	private function get_first_last_name( $text ) {
		$names      = explode( ' ', $text );
		$first_name = rgar( $names, 0 );
		$last_name  = '';
		if ( count( $names ) > 1 ) {
			$last_name = rgar( $names, count( $names ) - 1 );
		}

		$names_array = array( 'first_name' => $first_name, 'last_name' => $last_name );

		return $names_array;
	}



	public function callback()
	{

			if (! $this->is_gravityforms_supported()) {
					return false;
			}

			$this->log_debug('IPN request received. Starting to process...');
			$this->log_debug(print_r($_REQUEST, true));

			$settings = $this->get_plugin_settings();

			$oid = $_REQUEST['oid'];
			
			$ipn_url = get_bloginfo('url') . '/?page=gf_sagepay_direct_ipn&oid='.$oid;

			$wpd_session = get_transient('sagepay_direct'.$oid);

			$entry = GFAPI::get_entry($wpd_session['sagepay_oid']);

			$feed = $this->get_payment_feed($entry);

			$transaction_id = $wpd_session['sagepay_vtc'];

			$amount = $wpd_session['sagepay_amount'];

			if( ( isset( $_REQUEST['MD'] ) && !empty( $_REQUEST['PaRes'] ) ) || isset($_REQUEST['cres']) ){


						if( isset($_REQUEST['cres']) ){
							$request_array = array(
							  'CRes' => $_REQUEST['cres'],
							  'VPSTxId' => $wpd_session['sagepay_vpstxid'],
							);
						}elseif( isset($_REQUEST['PaRes']) ){
							$request_array = array(
							  'MD' => $_REQUEST['MD'],
							  'PARes' => $_REQUEST['PaRes'],
							  'VendorTxCode' => $wpd_session['sagepay_vtc'],
							);
						}

						$request = http_build_query($request_array);

						$params = array(
							'body' => $request,
							'method' => 'POST',
							'sslverify' => false
						);

						if ($settings['mode'] == 'test') {
								$gateway_url = 'https://test.sagepay.com/gateway/service/direct3dcallback.vsp';
						} else {
								$gateway_url = 'https://live.sagepay.com/gateway/service/direct3dcallback.vsp';
						}

						$response = wp_remote_post($gateway_url, array(
							'body' => $request,
							'method' => 'POST',
							'sslverify' => false
						));

						//wp_die($return_url.'<br><pre>'.print_r($entry,true)); exit;

				    if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
				        $resp = array();
				        $lines = preg_split('/\r\n|\r|\n/', $response['body']);
				        foreach ($lines as $line) {
				            $key_value = preg_split('/=/', $line, 2);
				            if (count($key_value) > 1) {
				                $resp[trim($key_value[0])] = trim($key_value[1]);
				            }
				        }

						set_transient('sagepay_response'.$oid, $resp, 60*60);


				        if ($resp['Status'] == "OK" || $resp['Status'] == "REGISTERED" || $resp['Status'] == "AUTHENTICATED") {

							//----- Processing IPN ------------------------------------------------------------//
							$this->log_debug('Processing IPN...');
							$action = $this->process_ipn($feed, $entry, 'completed', $transaction_id, $amount );
							$this->log_debug('IPN processing complete.');

				        } elseif ($resp['Status'] == "3DAUTH") {
				            if ($resp['3DSecureStatus'] == 'OK') {
								if (isset($resp['ACSURL']) && ( isset($resp['PAReq']) || isset($resp['CReq'] ))) {

									$wpd_session['sagepay_acsurl'] = $resp['ACSURL'];

									if( isset($resp['PAReq']) && !empty($resp['PAReq']) ){
										$wpd_session['sagepay_pareq'] = $resp['PAReq'];
									}
			
									if( isset($resp['CReq']) && !empty($resp['CReq']) ){
										$wpd_session['sagepay_pareq'] = "";
										$wpd_session['sagepay_creq'] = $resp['CReq'];
									}
			
									$wpd_session['sagepay_md'] = $resp['MD'];
			
									$wpd_session['sagepay_vpstxid'] = $resp['VPSTxId'];
			
									set_transient('sagepay_direct'.$oid, $wpd_session, 60*60);
			
									$this->log_error( __METHOD__ . '(): Response => ' .sprintf(__("Opayo Direct Payment Intiated waiting for 3D Secure response. Transaction Id: %s", "gf-sagepay-direct-patsatech"), $orderid) );
								
									GFPaymentAddOn::add_note($entry['id'], sprintf(__("Opayo Direct Payment Intiated waiting for 3D Secure response. Transaction Id: %s", "gf-sagepay-direct-patsatech"), $orderid));
			
									$params = '';
									if( !empty($wpd_session['sagepay_pareq']) ){
										$params .= '<input type="hidden" name="PaReq" value="'. $wpd_session['sagepay_pareq'] .'" />';
										$params .= '<input type="hidden" name="MD" value="'. $wpd_session['sagepay_md'] .'" />';
			
									}
									if( !empty($wpd_session['sagepay_creq']) ){
										$params .= '<input type="hidden" name="creq" value="'. $wpd_session['sagepay_creq'] .'" />';
										$params .= '<input type="hidden" name="threeDSSessionData" value="'. str_replace(array("{", "}"), "", $resp['VPSTxId']) .'" />';
									}
			
			
									echo '<!DOCTYPE html>
										<html>
										<head>
										<script>
											window.onload = function(e){
												document.getElementById("sagepay_direct_payment_form").submit();
											}
										</script>
										</head>
										<body>
											<form action="'.$wpd_session['sagepay_acsurl'].'" method="post" name="sagepay_direct_payment_form" target="_self"  id="sagepay_direct_payment_form" >
												'.$params.'
												<input type="hidden" name="TermUrl" value="'. $ipn_url .'" />
												<input type="submit" />
												<b> Please wait while you are being redirected.</b>
											</form>
										</body>
										</html>';
			
									exit;

				                }
				            }
				        } else {
				            if (isset($resp['StatusDetail'])) {
				                $error = sprintf(__('Transaction Failed. %s - %s', 'woo-sagepay-patsatech'), $resp['Status'], $resp['StatusDetail']);

								$this->log_error( __METHOD__ . '(): '.$error );

								GFPaymentAddOn::add_note($entry['id'], 'Payment failed. Response => '.$error );

				            } else {
				                $error = sprintf(__('Transaction Failed with %s - unknown error.', 'woo-sagepay-patsatech'), $resp['Status']);

								$this->log_error( __METHOD__ . '(): '.$error );

								GFPaymentAddOn::add_note($entry['id'], 'Payment failed. Response => '.$error );

				            }

							$url = add_query_arg('message', $error, add_query_arg('sagepay_status', 'error', $ipn_url ) );
							wp_redirect($url); exit;
	
						}
				    } else {

				        $error = __('Gateway Error. Please Notify the Store Owner about this error.', 'woo-sagepay-patsatech');

						$this->log_error( __METHOD__ . '(): '.$error );

						GFPaymentAddOn::add_note($entry['id'], $error );

						$url = add_query_arg('message', $error, add_query_arg('sagepay_status', 'error', $ipn_url ) );
						wp_redirect($url); exit;

				    }


					$return_url = $this->return_url($entry["form_id"], $entry["id"], $entry['source_url']);

					wp_redirect( $return_url ); exit();


			} elseif( isset( $_REQUEST['sagepay_status'] ) && $_REQUEST['sagepay_status'] == '3ds' ){


				$wpd_session = get_transient('sagepay_direct'.$oid);


				$params = '';
				if( !empty($wpd_session['sagepay_pareq']) ){
					$params .= '<input type="hidden" name="PaReq" value="'. $wpd_session['sagepay_pareq'] .'" />';
					$params .= '<input type="hidden" name="MD" value="'. $wpd_session['sagepay_md'] .'" />';

				}
				if( !empty($wpd_session['sagepay_creq']) ){
					$params .= '<input type="hidden" name="creq" value="'. $wpd_session['sagepay_creq'] .'" />';
					$params .= '<input type="hidden" name="threeDSSessionData" value="'. str_replace(array("{", "}"), "", $wpd_session['sagepay_vpstxid'])  .'" />';
				}


				echo '<!DOCTYPE html>
					<html>
					<head>
					<script>
						window.onload = function(e){
							document.getElementById("sagepay_direct_payment_form").submit();
						}
					</script>
					<style>
						.center {
							text-align: center;
							padding-top: 200px;
						}
						.button {
							display: inline-block;
							padding: 10px 20px;
							color: white;
							background-color: #4CAF50;
							text-decoration: none;
							border-radius: 4px;
							font-size: 16px;
						}
					</style>
					</head>
					<body>
					<div class="center">

						<form action="'.$wpd_session['sagepay_acsurl'].'" method="post" name="sagepay_direct_payment_form" target="_self"  id="sagepay_direct_payment_form" >
							'.$params.'
							<input type="hidden" name="TermUrl" value="'. $ipn_url .'" />
							<input class="button" type="submit" /></br>
							<b> Please wait while you are being redirected.</b>
						</form>
					</div>
					</body>
					</html>';

				exit;




			} elseif( isset( $_REQUEST['sagepay_status'] ) && $_REQUEST['sagepay_status'] == 'error' ){

				$wpd_session = get_transient('sagepay_response'.$oid);

				//----- Processing IPN ------------------------------------------------------------//
				$this->log_debug('Processing IPN...');
				$action = $this->process_ipn($feed, $entry, 'failed', $transaction_id, $amount);
				$this->log_debug('IPN processing complete.');	

				$return_url = $this->return_url($entry["form_id"], $entry["id"], $entry['source_url']);

				$return_url = add_query_arg('message', $_REQUEST['meesage'], $return_url );

				$this->log_debug($oid.'Return URL:'.$return_url);	

				$this->log_debug($oid.'Stored Response:'.print_r($wpd_session,true));	


				echo '
				<!DOCTYPE html>
				<html>
				<head>
					<title>Opayo Payment Status</title>
					<style>
						.center {
							text-align: center;
							padding-top: 200px;
						}
						.button {
							display: inline-block;
							padding: 10px 20px;
							color: white;
							background-color: #4CAF50;
							text-decoration: none;
							border-radius: 4px;
							font-size: 16px;
						}
					</style>
				</head>
				<body>
					<div class="center">
					<p><b>Status: </b>'.$wpd_session['Status'].'</p>
					<p><b>StatusDetail: </b>'.$wpd_session['StatusDetail'].'</p>'.$_REQUEST['meesage'].'
					<a class="button" href="'.$entry['source_url'].'" class="button">Try submitting the Form Again.</a>
					<a class="button" href="'.get_bloginfo('url').'" class="button">Homepage</a>
					</div>
				</body>
				</html>
				';
				exit;

				

			}

			return $action;
	}

	private function process_ipn($feed, $entry, $status, $transaction_id, $amount)
	{
		$this->log_debug("Payment status: {$status} - Transaction ID: {$transaction_id} - Amount: {$amount}");

		$form = GFFormsModel::get_form_meta($entry['form_id']);

		$action = array();
		//handles products and donation
		switch (strtolower($status)) {
			case 'completed':
				//creates transaction
				$action['id']               = $transaction_id;
				$action['type']             = 'complete_payment';
				$action['transaction_id']   = $transaction_id;
				$action['amount']           = $amount;
				$action['entry_id']         = $entry['id'];
				$action['payment_date']     = gmdate('y-m-d H:i:s');
				$action['payment_method']    = 'Opayo';
				$action['ready_to_fulfill'] = ! $entry['is_fulfilled'] ? true : false;


				update_post_meta( $entry['id'], 'transaction_id', $transaction_id );


				$this->fulfill_order($entry, $transaction_id, $amount);
				//update lead, add a note
				GFAPI::update_entry($entry);

				if (! $this->is_valid_initial_payment_amount($entry['id'], $amount)) {
						//create note and transaction
						$this->log_debug('Payment amount does not match product price. Entry will not be marked as Approved.');
						GFPaymentAddOn::add_note($entry['id'], sprintf(__('Payment amount (%s) does not match product price. Entry will not be marked as Approved. Transaction Id: %s', 'gf-sagepay-direct-patsatech'), GFCommon::to_money($amount, $entry['currency']), $transaction_id));
						GFPaymentAddOn::insert_transaction($entry['id'], 'payment', $transaction_id, $amount);

						$action['abort_callback'] = true;
				} else {
						GFPaymentAddOn::insert_transaction($entry['id'], 'payment', $transaction_id, $amount);
				}
				
				$this->checkout_fulfillment( $transaction_id, $entry, $feed, $form );

				$this->complete_payment($entry, $action);

				return $action;
				break;

			case 'failed':
				$action['id']             = $transaction_id;
				$action['type']           = 'fail_payment';
				$action['transaction_id'] = $transaction_id;
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $amount;

				//update lead, add a note
				GFAPI::update_entry($entry);


				GFPaymentAddOn::add_note($entry['id'], sprintf(__("Payment has Failed. Transaction Id: %s", "gf-sagepay-direct-patsatech"), $transaction_id));

				return $action;
				break;
		}

	}


	public function checkout_fulfillment( $transaction_id, $entry, $feed, $form ) {

		if ( method_exists( $this, 'trigger_payment_delayed_feeds' ) ) {
			$this->trigger_payment_delayed_feeds( $transaction_id, $feed, $entry, $form );
		}

	}


	public function is_callback_valid()
	{
			if (rgget('page') != 'gf_sagepay_direct_ipn') {
					return false;
			}

			return true;
	}


	public function wdgetCardType($CCNumber){

		$creditcardTypes = array(
				array('Name'=>'AMEX','cardLength'=>array(15),'cardPrefix'=>array('34', '37'))
					,array('Name'=>'MAESTRO','cardLength'=>array(12, 13, 14, 15, 16, 17, 18, 19),'cardPrefix'=>array('5018', '5020', '5038', '6304', '6759', '6761', '6763'))
					,array('Name'=>'MC','cardLength'=>array(16),'cardPrefix'=>array('51', '52', '53', '54', '55'))
					,array('Name'=>'VISA','cardLength'=>array(13,16),'cardPrefix'=>array('4'))
					,array('Name'=>'JCB','cardLength'=>array(16),'cardPrefix'=>array('3528', '3529', '353', '354', '355', '356', '357', '358'))
					,array('Name'=>'DC','cardLength'=>array(14),'cardPrefix'=>array('300', '301', '302', '303', '304', '305', '36'))
					,array('Name'=>'DC','cardLength'=>array(16),'cardPrefix'=>array('54', '55'))
					,array('Name'=>'DC','cardLength'=>array(14),'cardPrefix'=>array('300','305'))
			);

			$CCNumber= trim($CCNumber);
		$type='VISA-SSL';
			foreach ($creditcardTypes as $card){
				if (! in_array(strlen($CCNumber),$card['cardLength'])) {
						continue;
					}
					$prefixes = '/^('.implode('|',$card['cardPrefix']).')/';
					if(preg_match($prefixes,$CCNumber) == 1 ){
						$type= $card['Name'];
							break;
			}
		}
			return $type;
	}


	private function is_valid_initial_payment_amount($entry_id, $amount_paid)
	{

			//get amount initially sent to sagepay
			$amount_sent = gform_get_meta($entry_id, 'payment_amount');
			if (empty($amount_sent)) {
					return true;
			}

			$epsilon = 0.00001;
			$is_equal = abs(floatval($amount_paid) - floatval($amount_sent)) < $epsilon;
			$is_greater = floatval($amount_paid) > floatval($amount_sent);

			//initial payment is valid if it is equal to or greater than product/subscription amount
			if ($is_equal || $is_greater) {
					return true;
			}

			return false;
	}


	    public function return_url($form_id, $lead_id, $url)
	    {

					$pageURL = $url.'/';

	        $ids_query = "ids={$form_id}|{$lead_id}";
	        $ids_query .= '&hash=' . wp_hash($ids_query);

	        return add_query_arg('gf_sagepay_direct_return', base64_encode($ids_query), $pageURL);
	    }


	    public static function maybe_thankyou_page()
	    {
	        $instance = self::get_instance();

	        if (! $instance->is_gravityforms_supported()) {
	            return;
	        }

	        if ($str = rgget('gf_sagepay_direct_return')) {
	            $str = base64_decode($str);

	            parse_str($str, $query);
	            if (wp_hash('ids=' . $query['ids']) == $query['hash']) {
	                list($form_id, $lead_id) = explode('|', $query['ids']);

	                $form = GFAPI::get_form($form_id);
	                $lead = GFAPI::get_entry($lead_id);

	                if (! class_exists('GFFormDisplay')) {
	                    require_once(GFCommon::get_base_path() . '/form_display.php');
	                }

	                $confirmation = GFFormDisplay::handle_confirmation($form, $lead, false);

	                if (is_array($confirmation) && isset($confirmation['redirect'])) {
	                    header("Location: {$confirmation['redirect']}");
	                    exit;
	                }
					if( isset($_REQUEST['error']) && !empty($_REQUEST['error']) ){

						GFFormDisplay::$submission[ $form_id ] = array( 'is_confirmation' => false, 'confirmation_message' => $_REQUEST['error'], 'form' => $form, 'lead' => $lead );

					}else{

						GFFormDisplay::$submission[ $form_id ] = array( 'is_confirmation' => true, 'confirmation_message' => $confirmation, 'form' => $form, 'lead' => $lead );

					}
	            }
	        }
	    }



		public function delay_notification($is_disabled, $notification, $form, $entry)
		{
				$feed = $this->get_payment_feed($entry);
				$submission_data = $this->get_submission_data($feed, $form, $entry);

				if (! $feed || empty($submission_data['payment_amount'])) {
						return $is_disabled;
				}

				$selected_notifications = is_array(rgar($feed['meta'], 'selectedNotifications')) ? rgar($feed['meta'], 'selectedNotifications') : array();

				return isset($feed['meta']['delayNotification']) && in_array($notification['id'], $selected_notifications) ? true : $is_disabled;
		}



	    public function fulfill_order(&$entry, $transaction_id, $amount, $feed = null)
	    {
	        if (! $feed) {
							$form_id = $entry['form_id'];

							$feed = $this->get_feeds($form_id);

							$feed = $feed[0];
	        }

	        $form = GFFormsModel::get_form_meta($entry['form_id']);

	        if (rgars($feed, 'meta/delayNotification')) {
	            //sending delayed notifications
	            $notifications = rgars($feed, 'meta/selectedNotifications');
	            GFCommon::send_notifications($notifications, $form, $entry, true, 'form_submission');
	        }

	        $this->log_debug('Before gform_sagepay_fulfillment.');
	        do_action('gform_sagepay_fulfillment', $entry, $feed, $transaction_id, $amount);
	        $this->log_debug('After gform_sagepay_fulfillment.');
	    }

}
