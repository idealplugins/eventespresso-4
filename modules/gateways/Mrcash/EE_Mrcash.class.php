<?php
if (!defined('EVENT_ESPRESSO_VERSION'))
	exit('No direct script access allowed');

/**
 * Mister Cash plugin for Event Espresso 4
 *
 * @package			Event Espresso 
 * @subpackage		gateways/
 * @author			Eveline van den Boom, Yellow Melon B.V.
 * @copyright		(c) 2015 Yellow Melon B.V.
 * @copyright		Portions (c) 2008-2011 Event Espresso  All Rights Reserved.
 * @license			http://eventespresso.com/support/terms-conditions/   * see Plugin Licensing *
 * @link			http://www.eventespresso.com
 * @version		 	4.0
 *
 * ------------------------------------------------------------------------
 */

class EE_Mrcash extends EE_Offsite_Gateway {

	public $tpPaymethodDisplayName = 'Mister Cash';
	public $tpPaymethodName = 'Mrcash';
	public $tpPaymethodId   = 'MRC';

	private static $_instance = NULL;

	public static function instance(EEM_Gateways &$model) {
		// check if class object is instantiated
		if (self::$_instance === NULL or !is_object(self::$_instance) or ! ( self::$_instance instanceof  EE_Mrcash )) {
			self::$_instance = new self($model);
		}	
		return self::$_instance;
	}

	protected function __construct(EEM_Gateways &$model) {
		$this->_gateway_name = $this->tpPaymethodName; 
		$this->_button_base = $this->tpPaymethodId . '_60.png';
		$this->_path = str_replace('\\', '/', __FILE__);
		parent::__construct($model);
	}

	protected function _default_settings() 
	{
		$this->_payment_settings['rtlo'] = '93929';
		$this->_payment_settings['image_url'] = '';
		$this->_payment_settings['testmode'] = false;
		$this->_payment_settings['type'] = 'off-site';
		$this->_payment_settings['display_name'] = __($this->tpPaymethodDisplayName,'event_espresso');
		$this->_payment_settings['current_path'] = '';
		$this->_payment_settings['button_url'] = $this->_btn_img;
	}

	protected function _update_settings() 
	{
		$this->_payment_settings['rtlo'] = $_POST['rtlo'];
		$this->_payment_settings['image_url'] = $_POST['image_url'];
		$this->_payment_settings['testmode'] = $_POST['testmode'];
		$this->_payment_settings['button_url'] = isset( $_POST['button_url'] ) ? esc_url_raw( $_POST['button_url'] ) : '';
	}

	protected function _display_settings() 
	{	
	?>
		<tr>
			<th>
				<label><?php _e('Please Note', 'event_espresso'); ?></label>
			</th>
			<td>
				<?php _e('You need a TargetPay account to use this plugin. Sign up on <a href="http://www.targetpay.com" target="_blank">TargetPay.com</a>. You can use promotional code YM3R2A for a reduced iDEAL price...', 'event_espresso'); ?>
			</td>
		</tr>

		<tr>
			<th><label for="rtlo">
					<?php _e('TargetPay layout code', 'event_espresso'); ?>
				</label></th>
			<td><input class="regular-text" type="text" name="rtlo" size="35" id="rtlo" value="<?php echo $this->_payment_settings['rtlo']; ?>">
				<br />
				<span class="description">
					<?php _e('You can find this in your TargetPay account', 'event_espresso'); ?>
				</span></td>
		</tr>

		<tr>
			<th><label for="testmode">
					<?php _e('Use testmode', 'event_espresso'); ?>
				</label></th>
			<td><?php echo EEH_Form_Fields::select_input('testmode', $this->_yes_no_options, $this->_payment_settings['testmode']); ?></td>
		</tr>
		
		<?php
	}


	/* Silence is golden */

	protected function _display_settings_help() 
	{
	}

	/**
	 * Submit Payment Request (redirect)
	 * Generates a form with the redirect to the bank 
	 *
	 * @param string value of buttn text
	 * @return void
	 */

	public function submitPayment() {
		do_action( 'AHEE_log', __FILE__, __FUNCTION__, '' );

		$pre_form = "<html>\n";
		$pre_form .= "<head><title>Processing Payment...</title></head>\n";
		$pre_form .= "<body>\n";

		$form = "<script> window.location.href='".$this->_gatewayUrl."'; </script>";
		$post_form = "</body></html>\n";

		return array('pre-form' => $pre_form, 'form' => $form, 'post-form' => $post_form);
	}


	public function process_payment_start(EE_Line_Item $total_line_item, $transaction = null,$total_to_pay = NULL) {
		global $wpdb;

		$targetpay_settings = $this->_payment_settings;

		$item_num = 1;

		/* @var $transaction EE_Transaction */
		if( ! $transaction){
			$transaction = $total_line_item->transaction();
		}


		$primary_registrant = $transaction->primary_registration();
		
		require_once (dirname(__DIR__) . "/targetpay.class.php");
		$targetPay = new TargetPayCore ($this->tpPaymethodId, $targetpay_settings['rtlo'],  "fa7948cad2783f63e1d03b63620a5f64", "nl");
		if ($this->tpPaymethodId == "IDE") { $targetPay->setVersion (3); }

		$amount = round ($transaction->remaining()*100);
		$targetPay->setAmount ($amount);
		$targetPay->setDescription ( "Order nr. ". $transaction->ID() );
		$targetPay->setReturnUrl ($this->_get_return_url($primary_registrant));
		$targetPay->setCancelUrl ($this->_get_cancel_url());
		$targetPay->setReportUrl ($this->_get_notify_url($primary_registrant));

		$this->_gatewayUrl = $targetPay->startPayment();

        if (!$this->_gatewayUrl) {
        	throw new EE_Error($targetPay->getErrorMessage());
	   	} 

		/* Create table if not exists */

		$sql = "CREATE TABLE IF NOT EXISTS `".$wpdb->base_prefix."ee4_targetpay` (
				  `id` int(11) NOT NULL AUTO_INCREMENT,
				  `order_id` varchar(64) NOT NULL DEFAULT '',
				  `method` varchar(6) DEFAULT NULL,
				  `amount` int(11) DEFAULT NULL,
				  `targetpay_txid` varchar(64) DEFAULT NULL,
				  `targetpay_response` varchar(128) DEFAULT NULL,
				  `paid` datetime DEFAULT NULL,
				  PRIMARY KEY (`id`),
				  KEY `order_id` (`order_id`)
				) ENGINE=InnoDB  DEFAULT CHARSET=latin1";
		$wpdb->query ($sql);

        /* Save transaction data */

        $sql = "INSERT INTO `".$wpdb->base_prefix."ee4_targetpay` SET `order_id` = %s, `method` = %s, `targetpay_txid` = %s, `amount` = %s";

		$wpdb->get_results( 
			$wpdb->prepare( $sql,
            $transaction->ID(),
            $this->tpPaymethodId,
            $targetPay->getTransactionId(),
            $amount
		));		   	

		$this->_EEM_Gateways->set_off_site_form($this->submitPayment());
		$this->redirect_after_reg_step_3($transaction,$targetpay_settings['testmode']);
	}

	/**
	 * API output message
	 */

	public function	respond ($msg, $toErrorLog = false)
	{
		$this->_debug_log($msg);
		echo $msg." ";
		if ($toErrorLog) { 
			error_log ($msg); 
		}
	}


	/**
	 * Handles an IPN, verifies we haven't already processed this IPN, creates a payment if succesful
	 * and updates the provided transaction, and saves to DB
	 * @param EE_Transaction or ID $transaction
	 * @return boolean
	 */

	public function handle_ipn_for_transaction(EE_Transaction $transaction){
		global $org_options, $wpdb;

		$txid = $_POST["trxid"];

		$this->respond ("Start IPN for ".($transaction instanceof EE_Transaction)?$transaction->ID():'unknown');
		do_action('action_hook_espresso_log', __FILE__, __FUNCTION__, '');

		$targetpay_settings = $this->_payment_settings;

		/* Get transaction data from database */

		$sql = "SELECT * FROM `".$wpdb->base_prefix."ee4_targetpay` WHERE `order_id`=%s AND `targetpay_txid`=%s";
		$tpPayment = $wpdb->get_row( $wpdb->prepare( $sql, $transaction->ID(), $txid));

		if (!$tpPayment) {
			$this->respond("Transaction id=".$transaction->ID()." txid=".$txid." not found...", true);
        	return false;
		}

        /* Verify payment */
                        
		require_once (dirname(__DIR__) . "/targetpay.class.php");
		$targetPay = new TargetPayCore ($this->tpPaymethodId, $targetpay_settings['rtlo'],  "fa7948cad2783f63e1d03b63620a5f64", "nl");
        $payResult = $targetPay->checkPayment ($txid) || ($targetpay_settings['testmode']);

        if (!$payResult) {
        	$this->respond ($targetPay->getErrorMessage());

            /* Update temptable */
            $sql = "UPDATE `".$wpdb->base_prefix."ee4_targetpay` SET `targetpay_response` = '".$targetPay->getErrorMessage()."' WHERE `id`=%s";
			$wpdb->get_results( $wpdb->prepare( $sql, $tpPayment->id ));

            return false;
        } else {

            /* Update temptable */
            $sql = "UPDATE `".$wpdb->base_prefix."ee4_targetpay` SET `paid` = now() WHERE `id`=%s";
			$wpdb->get_results( $wpdb->prepare( $sql, $tpPayment->id ));

	   		/* Process payment */
			$transaction = $this->_TXN->ensure_is_obj($transaction);

			/* Verify the transaction exists */
			if(empty($transaction)){
				$this->respond ("Transaction not found... ");
				return false;
			}

			/* Set approved */
			$status = EEM_Payment::status_id_approved; 
			$gateway_response = __('Your payment is approved.', 'event_espresso');			

			$payment = $this->_PAY->get_payment_by_txn_id_chq_nmbr($txid);
			if(!empty($payment)) {
				$this->respond ("Duplicate callback...");
				return false;
			}else{
				$this->respond ("Payment created...");

				$primary_registrant = $transaction->primary_registration();
				$primary_registration_code = !empty($primary_registrant) ? $primary_registrant->reg_code() : '';

				$payment = EE_Payment::new_instance(array(
					'TXN_ID' => $transaction->ID(),
					'STS_ID' => $status,
					'PAY_timestamp' => current_time( 'mysql', FALSE ),
					'PAY_method' => sanitize_text_field($_POST['txn_type']),
					'PAY_amount' => round($tpPayment->amount / 100),
					'PAY_gateway' => $this->_gateway_name,
					'PAY_gateway_response' => $gateway_response,
					'PAY_txn_id_chq_nmbr' => $txid,
					'PAY_po_number' => NULL,
					'PAY_extra_accntng'=>$primary_registration_code,
					'PAY_via_admin' => false,
					'PAY_details' => $_POST
				));

				$payment->save();
				$this->respond("Done");
				return $this->update_transaction_with_payment($transaction,$payment);
			}
		}

	}

	public function espresso_display_payment_gateways( $selected_gateway = '' ) {
		$this->_css_class = $selected_gateway == $this->_gateway_name ? '' : ' hidden';
		echo $this->_generate_payment_gateway_selection_button();
		?>
		<div id="reg-page-billing-info-<?php echo $this->_gateway_name; ?>-dv" class="reg-page-billing-info-dv <?php echo $this->_css_class; ?>">
			<h3><?php _e('You have selected "'.$this->_payment_settings['display_name'].'" as your method of payment', 'event_espresso'); ?></h3>
			<p><?php _e('After finalizing your registration, you will be transferred to the website of your bank where your payment will be securely processed.', 'event_espresso'); ?></p>
		</div>

		<?php
	}

}

