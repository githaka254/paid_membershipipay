<?php
/**
 * Plugin Name: ipay Gateway for Paid Memberships Pro
 * Description: ipay Plugin Payment for Paid Memberships Pro
 * Version: 1.2
 * Author: ipay
 * License: GPLv2 or later
 */
defined('ABSPATH') or die('No script kiddies please!');
if (!function_exists('ipay_Pmp_Gateway_load')) {
    add_action('plugins_loaded', 'ipay_Pmp_Gateway_load', 20);

    DEFINE('KKD_ipayPMP', "ipay-paidmembershipspro");

    function ipay_Pmp_Gateway_load() 
    {
        // paid memberships pro required
        if (!class_exists('PMProGateway')) {
            return;
        }

        // load classes init method
        add_action('init', array('PMProGateway_ipay', 'init'));

        // plugin links
        add_filter('plugin_action_links', array('PMProGateway_ipay', 'plugin_action_links'), 10, 2);

        if (!class_exists('PMProGateway_ipay')) {
            /**
             * PMProGateway_ipay Class
             *
             * Handles ipay integration.
             *
             */
            class PMProGateway_ipay extends PMProGateway
            {

                function __construct($gateway = null)
                {
                    $this->gateway = $gateway;
                    $this->gateway_environment =  pmpro_getOption("gateway_environment");

                    return $this->gateway;
                }

                /**
                 * Run on WP init
                 */
                static function init() 
                {
                    //make sure ipay is a gateway option
                    add_filter('pmpro_gateways', array('PMProGateway_ipay', 'pmpro_gateways'));
                    
                    //add fields to payment settings
                    add_filter('pmpro_payment_options', array('PMProGateway_ipay', 'pmpro_payment_options'));
                    add_filter('pmpro_payment_option_fields', array('PMProGateway_ipay', 'pmpro_payment_option_fields'), 10, 2);
                    add_action('wp_ajax_kkd_pmpro_ipay_ipn', array('PMProGateway_ipay', 'kkd_pmpro_ipay_ipn'));
                    add_action('wp_ajax_nopriv_kkd_pmpro_ipay_ipn', array('PMProGateway_ipay', 'kkd_pmpro_ipay_ipn'));

                    //code to add at checkout
                    $gateway = pmpro_getGateway();
                    if ($gateway == "ipay") {
                        add_filter('pmpro_include_billing_address_fields', '__return_false');
                        add_filter('pmpro_required_billing_fields', array('PMProGateway_ipay', 'pmpro_required_billing_fields'));
                        add_filter('pmpro_include_payment_information_fields', '__return_false');
                        add_filter('pmpro_checkout_before_change_membership_level', array('PMProGateway_ipay', 'pmpro_checkout_before_change_membership_level'), 10, 2);
                        
                        add_filter('pmpro_gateways_with_pending_status', array('PMProGateway_ipay', 'pmpro_gateways_with_pending_status'));
                        add_filter('pmpro_pages_shortcode_checkout', array('PMProGateway_ipay', 'pmpro_pages_shortcode_checkout'), 20, 1);
                        add_filter('pmpro_checkout_default_submit_button', array('PMProGateway_ipay', 'pmpro_checkout_default_submit_button'));
                        // custom confirmation page
                       add_filter('pmpro_pages_shortcode_confirmation', array('PMProGateway_ipay', 'pmpro_pages_shortcode_confirmation'), 20, 1);
                    }
                }

                /**
                 * Redirect Settings to PMPro settings
                 */
                static function plugin_action_links($links, $file) 
                {
                    static $this_plugin;

                    if (false === isset($this_plugin) || true === empty($this_plugin)) {
                        $this_plugin = plugin_basename(__FILE__);
                    }

                    if ($file == $this_plugin) {
                        $settings_link = '<a href="'.admin_url('admin.php?page=pmpro-paymentsettings').'">'.__('Settings', KKD_ipayPMP).'</a>';
                        array_unshift($links, $settings_link);
                    }

                    return $links;
                }
                static function pmpro_checkout_default_submit_button($show)
                {
                    global $gateway, $pmpro_requirebilling;
                    
                    //show our submit buttons
                    ?>
                    <span id="pmpro_submit_span">
                    <input type="hidden" name="submit-checkout" value="1" />		
                    <input type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="<?php if ($pmpro_requirebilling) { _e('Check Out with ipay', 'pmpro'); } else { _e('Submit and Confirm', 'pmpro');}?> &raquo;" />		
                    </span>
                    <?php
                
                    //don't show the default
                    return false;
                }

                /**
                 * Make sure ipay is in the gateways list
                 */
                static function pmpro_gateways($gateways) 
                {
                    if (empty($gateways['ipay'])) {
                        $gateways = array_slice($gateways, 0, 1) + array("ipay" => __('ipay', KKD_ipayPMP)) + array_slice($gateways, 1);
                    }
                    return $gateways;
                }
                function kkd_pmpro_ipay_ipn() 
                {
                    // print_r('kkd_pmpro_ipay_ipn'); exit;
                    global $wpdb;
                    
                    define('SHORTINIT', true);
                    $input = @file_get_contents("php://input");
                    $response = json_decode($input);

                    $orderref = $response->orderRef;

                    // explode to check if orderRef has SUB as prefix
                    $result = explode("_", $orderref);
                    if (in_array("SUB", $result, TRUE)) {
                        self::renewpayment($response);
                    }

                    http_response_code(200);
                    exit();
                }

                /**
                 * Get a list of payment options that the ipay gateway needs/supports.
                 */
                static function getGatewayOptions() 
                {
                    $options = array (
                    "live",
                    "oid",
                    "inv",
                    "ttl",
                    "tel",
                    "eml",
                    "vid",
                    "curr",
                    "cbk" => " http://3942f0cd.ngrok.io/Collo/log.php",
                    "cst",
                    "crl",
                    "hash" => "demo"
         

    //             $dataString = '0' . $requestParams['oid'] . $requestParams['inv'] . $requestParams['ttl'] . $requestParams['tel'] . $requestParams['eml']
    // . 'demo' . 'KES' . $requestParams['cbk'] . '1' . '0';
    //             $hashkey = "demoCHANGED";
    //             $hashid = hash_hmac("sha1", $dataString, $hashkey);

                    );

                    return $options;
                }

                /**
                 * Set payment options for payment settings page.
                 */
                static function pmpro_payment_options($options) 
                {
                    //get ipay options
                    $ipay_options = self::getGatewayOptions();

                    //merge with others.
                    $options = array_merge($ipay_options, $options);

                    return $options;
                }

                /**
                 * Display fields for ipay options.
                 */
                static function pmpro_payment_option_fields($values, $gateway) 
                {
                    ?>
                     <tr class="pmpro_settings_divider gateway gateway_ipay" <?php if($gateway != "ipay") { ?>style="display: none;"<?php } ?>>
                        <td colspan="2">
                            <?php _e('ipay API Configuration', 'pmpro'); ?>
                        </td>
                    </tr>
                       
                    <tr class="gateway gateway_ipay" <?php if($gateway != "ipay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="title"><?php _e('Title', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="title" name="title" size="60" value="<?php echo esc_attr($values['title'])?>" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_ipay" <?php if($gateway != "ipay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="description"><?php _e('Description', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="description" name="description" size="60" value="<?php echo esc_attr($values['description'])?>" />
                        </td>
                    </tr>
                     <tr class="gateway gateway_ipay" <?php if($gateway != "ipay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="instructions"><?php _e('instructions', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="Instructions" name="Instructions" size="60" value="<?php echo esc_attr($values['description'])?>" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_ipay" <?php if($gateway != "ipay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="vid" value="demo"><?php _e('Vendor ID', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="vid" name="vid" size="60" value="<?php echo esc_attr($values['vid'])?>" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_ipay" <?php if($gateway != "ipay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="hash" value="demoCHANGED"><?php _e('Security Key', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="hash" name="hash" size="60" value="<?php echo esc_attr($values['hash'])?>" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_ipay" <?php if($gateway != "ipay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="live"><?php _e('Live/Demo', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="live" name="live" size="60" value="<?php echo esc_attr($values['Mpesa'])?>" />
                        </td>
                    </tr>
                    
                    <?php
                }

                /**
                 * Remove required billing fields
                 */
                static function pmpro_required_billing_fields($fields)
                {
                    unset($fields['bfirstname']);
                    unset($fields['blastname']);
                    unset($fields['baddress1']);
                    unset($fields['bcity']);
                    unset($fields['bstate']);
                    unset($fields['bzipcode']);
                    unset($fields['bphone']);
                    unset($fields['bemail']);
                    unset($fields['bcountry']);
                    unset($fields['CardType']);
                    unset($fields['AccountNumber']);
                    unset($fields['ExpirationMonth']);
                    unset($fields['ExpirationYear']);
                    unset($fields['CVV']);

                    return $fields;
                }

                static function pmpro_gateways_with_pending_status($gateways) {
                    // Execute 4
                    // print_r('pmpro_gateways_with_pending_status'); exit;
                    $morder = new MemberOrder();
                    // MemberOrder Object ( [gateway] => ipay [Gateway] => PMProGateway_ipay Object ( [gateway] => ipay [gateway_environment] => sandbox ) )
                    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));
                    // return MemberOrderID from Orders

                    if ((!in_array("ipay", $gateways)) && $found) {
                        array_push($gateways, "ipay");
                    } elseif (($key = array_search("ipay", $gateways)) !== false) {
                        unset($gateways[$key]);
                    }
                    
                    // print_r($gateways); exit;

                    return $gateways;
                }

                /**
                 * Instead of change membership levels, send users to ipay payment page.
                 */
                static function pmpro_checkout_before_change_membership_level($user_id, $morder)
                {
                    // Execute 2
                    // print_r('pmpro_checkout_before_change_membership_level'); exit;
                    global $wpdb, $discount_code_id;
                    
                    //if no order, no need to pay
                    if (empty($morder)) {
                        return;
                    }
                    if (empty($morder->code))
                        $morder->code = $morder->getRandomCode();
                        
                    $morder->payment_type = "ipay";
                    $morder->status = "pending";
                    $morder->user_id = $user_id;
                    $morder->saveOrder();

                    //save discount code use
                    if (!empty($discount_code_id))
                        $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");

                    $morder->Gateway->sendToipay($morder);
                }

                function sendToipay(&$order) 
                {
                    // Execute 3
                    // print_r(json_encode($order)); exit;
                    global $wp;

                    $mode = pmpro_getOption("cbk");
                    if ($mode == 'sandbox') {
                        $key = pmpro_getOption("mer");
                          $key = pmpro_getOption("vid");
                        $skey = pmpro_getOption("vid");
						  $skey = pmpro_getOption("cur");
						  $skey = pmpro_getOption("hash");
						  $skey = pmpro_getOption("oid");
						
                    } else {
                        $key = pmpro_getOption("hash");
                        $skey = pmpro_getOption("oid");
                    }
                    if ($key  == '' || $skey == '') {
                        echo "Api keys not set";
                        exit;
                    }

                    // set the plans details
                    $pmpro_level = $order->membership_level;
                    $plan_name = $pmpro_level->id .'_'. $pmpro_level->name;

                    // setting interval for the subscription
                    if (($pmpro_level->cycle_number > 0) && ($pmpro_level->billing_amount > 0) && ($pmpro_level->cycle_period != "")) {
                        if ($pmpro_level->cycle_number < 10 && $pmpro_level->cycle_period == 'Day') {
                            $interval = 'weekly';
                        } elseif (($pmpro_level->cycle_number == 90) && ($pmpro_level->cycle_period == 'Day')) {
                            $interval = 'quarterly';
                        } elseif (($pmpro_level->cycle_number >= 10) && ($pmpro_level->cycle_period == 'Day')) {
                            $interval = 'monthly';
                        } elseif (($pmpro_level->cycle_number == 3) && ($pmpro_level->cycle_period == 'Month')) {
                            $interval = 'quarterly';
                        } elseif (($pmpro_level->cycle_number > 0) && ($pmpro_level->cycle_period == 'Month')) {
                            $interval = 'monthly';
                        } elseif (($pmpro_level->cycle_number > 0) && ($pmpro_level->cycle_period == 'Year')) {
                            $interval = 'annually';
                        }

                        // amount
                        $amount = $pmpro_level->billing_amount;
                        if ($amount == 0) {
                            $amount = $pmpro_level->initial_payment;
                        }

                        // duration
                        $duration = $pmpro_level->billing_limit;
                        if ($duration == '0') {
                            $duration = '';
                        }

                        //Create Plan
                        $ipay_plan_url = 'https://api.ipaypay.co/v2/gpx/paymentplans/create';
                        // fetch Plan
                        $ipay_fetch_plan_url = 'https://api.ipaypay.co/v2/gpx/paymentplans/query?seckey='.$skey.'&q='.$plan_name;

                        $headers = array(
                            'Content-Type'  => 'application/json'
                        );

                        $checkargs = array(
                            'headers' => $headers,
                            'timeout' => 60
                        );

                        // Check if plan exist
                        $checkrequest = wp_remote_get($ipay_fetch_plan_url, $checkargs);
                        if (!is_wp_error($checkrequest)) {
                            $response = json_decode(wp_remote_retrieve_body($checkrequest));
                            if ($response->data->page_info->total >= 1) {
                                $planid = $response->data->paymentplans->id;
                                
                            } else {
                                //Create Plan
                                $body = array(
                                    'name'      => $plan_name,
                                    'amount'    => $amount,
                                    'interval'  => $interval,
                                    'duration'  => $duration,
                                    'seckey'    => $skey
                                );
                                $args = array(
                                    'body'      => json_encode($body),
                                    'headers'   => $headers,
                                    'timeout'   => 60
                                );

                                $request = wp_remote_post($ipay_plan_url, $args);
                                if (!is_wp_error($request)) {
                                    $ipay_response = json_decode(wp_remote_retrieve_body($request));
                                    $planid = $ipay_response->data->id;
                                    $plan_name = $ipay_response->data->name;
                                }
                            }

                        }
                        
                    } // end of subscription setting and plan

                    $params = array();
                    $amount = $order->PaymentAmount;
                    $amount_tax = $order->getTaxForPrice($amount);
                    $amount = round((float)$amount + (float)$amount_tax, 2);
            
                    //call directkit to get Webkit Token
                    $amount = floatval($order->InitialPayment);                    

                    $currency = pmpro_getOption("currency");
                    
                    $ipay_url = 'https://apis.ipayafrica.com/payments/v2/billing/fund';
                    $headers = array(
                        'Content-Type'  => 'application/json'
                    );

                    // request to make payment
                    $body = array(

                        'customer_email'        => $order->Email,
                        'amount'                => $amount,
                        'txref'                 => $order->code,
				        'PBFPubKey'             => $key,
                        'currency'              => $currency,
                        'payment_plan'          => $planid,
                        'redirect_url'          => pmpro_url("confirmation", "?level=" . $order->membership_level->id)

                    );
                    $args = array(
                        'body'      => json_encode($body),
                        'headers'   => $headers,
                        'timeout'   => 60
                    );

                    $request = wp_remote_post($ipay_url, $args);
                    // print_r($request);
                    // exit;
                    if (!is_wp_error($request)) {
                        $ipay_response = json_decode(wp_remote_retrieve_body($request));
                        if ($ipay_response->status == 'success'){
                            $url = $ipay_response->data->link;
                            wp_redirect($url);
                            exit;
                        } else {
                            $order->Gateway->delete($order);
                            wp_redirect(pmpro_url("checkout", "?level=" . $order->membership_level->id . "&error=" . $ipay_response->message));
                            exit();
                        }
                    } else {
                        $order->Gateway->delete($order);
                        wp_redirect(pmpro_url("checkout", "?level=" . $order->membership_level->id . "&error=Failed"));
                        exit();
                    }
                    exit;
                }

                // renew payment
                static function renewpayment($response) 
                {
                    global $wp, $wpdb;

                    if (isset($response->status) && ($response->status == 'successful')) {

                        $amount = $response->amount;
                        $old_order = new MemberOrder();
                        $txref = $response->txRef;
                        $email = $response->customer->email;
                        $old_order->getLastMemberOrderBySubscriptionTransactionID($txref);

                        if (empty($old_order)) { 
                            exit();
                        }
                        $user_id = $old_order->user_id;
                        $user = get_userdata($user_id);
                        $user->membership_level = pmpro_getMembershipLevelForUser($user_id);

                        if (empty($user)) { 
                            exit(); 
                        }

                        $morder = new MemberOrder();
                        $morder->user_id = $old_order->user_id;
                        $morder->membership_id = $old_order->membership_id;
                        $morder->InitialPayment = $amount;  //not the initial payment, but the order class is expecting this
                        $morder->PaymentAmount = $amount;
                        $morder->payment_transaction_id = $response->id;
                        $morder->subscription_transaction_id = $txref;

                        $morder->gateway = $old_order->gateway;
                        $morder->gateway_environment = $old_order->gateway_environment;
                        $morder->Email = $email;

                        $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
                        $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
                        $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);
                        
                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";
                        
                        $custom_level = array(
                            'user_id'           => $morder->user_id,
                            'membership_id'     => $pmpro_level->id,
                            'code_id'           => '',
                            'initial_payment'   => $pmpro_level->initial_payment,
                            'billing_amount'    => $pmpro_level->billing_amount,
                            'cycle_number'      => $pmpro_level->cycle_number,
                            'cycle_period'      => $pmpro_level->cycle_period,
                            'billing_limit'     => $pmpro_level->billing_limit,
                            'trial_amount'      => $pmpro_level->trial_amount,
                            'trial_limit'       => $pmpro_level->trial_limit,
                            'startdate'         => $startdate,
                            'enddate'           => $enddate
                        );
                        
                        //get CC info that is on file
                        $morder->expirationmonth = get_user_meta($user_id, "pmpro_ExpirationMonth", true);
                        $morder->expirationyear = get_user_meta($user_id, "pmpro_ExpirationYear", true);
                        $morder->ExpirationDate = $morder->expirationmonth . $morder->expirationyear;
                        $morder->ExpirationDate_YdashM = $morder->expirationyear . "-" . $morder->expirationmonth;

                        
                        //save
                        if ($morder->status != 'success') {
                            
                            if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                                $morder->status = "success";
                                $morder->saveOrder();
                            }
                                
                        }
                        $morder->getMemberOrderByID($morder->id);

                        //email the user their invoice
                        $pmproemail = new PMProEmail();
                        $pmproemail->sendInvoiceEmail($user, $morder);

                        do_action('pmpro_subscription_payment_completed', $morder);
                        exit();
                    }

                }

                static function pmpro_pages_shortcode_checkout($content) 
                {
                    // Execute 1
                    // print_r('pmpro_pages_shortcode_checkout'); exit;
                    $morder = new MemberOrder();
                    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));
                    if ($found) {
                        $morder->Gateway->delete($morder);
                    }
                    
                    if (isset($_REQUEST['error'])) {
                        global $pmpro_msg, $pmpro_msgt;

                        $pmpro_msg = __("IMPORTANT: Something went wrong during the payment. Please try again later or contact the site owner to fix this issue.<br/>" . urldecode($_REQUEST['error']), "pmpro");
                        $pmpro_msgt = "pmpro_error";

                        $content = "<div id='pmpro_message' class='pmpro_message ". $pmpro_msgt . "'>" . $pmpro_msg . "</div>" . $content;
                    }

                    return $content;
                }

                /**
                 * Custom confirmation page
                 */
                static function pmpro_pages_shortcode_confirmation($content, $reference = null)
                {

                    global $wpdb, $current_user, $pmpro_invoice, $pmpro_currency, $gateway;

                    if (!isset($_REQUEST['txref'])) {
                        $_REQUEST['txref'] = null;
                    }
                    if ($reference != null) {
                        $_REQUEST['txref'] = $reference;
                    }
                    
                    if (empty($pmpro_invoice)) {
                        $morder =  new MemberOrder($_REQUEST['txref']);
                        if (!empty($morder) && $morder->gateway == "ipay") $pmpro_invoice = $morder;
                    }
                        
                    if (!empty($pmpro_invoice) && $pmpro_invoice->gateway == "ipay" && isset($pmpro_invoice->total) && $pmpro_invoice->total > 0) {
                            $morder = $pmpro_invoice;
                        if ($morder->code == $_REQUEST['txref']) {
                            $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
                            $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
                            $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);
                                    
                            $mode = pmpro_getOption("gateway_environment");
                            if ($mode == 'sandbox') {
                                $key = pmpro_getOption("hash");
                            } else {
                                $key = pmpro_getOption("vid");

                            }

                            $ipay_url = 'https://apis.ipayafrica.com/payments/v2/billing/fund;
                            $headers = array(
                                'Content-Type' => 'application/json'
                            );
                            $body = array(
                                'SECKEY' => $key,
                                'txref' => $_REQUEST['txref']
                            );
                            $args = array(
                                'body' => json_encode($body),
                                'headers' => $headers,
                                'timeout' => 60
                            );

                            $request = wp_remote_post($ipay_url, $args);
                            // print_r($request);
                            // exit;

                            if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request) ) {
                                $ipay_response = json_decode(wp_remote_retrieve_body($request));
                                if ('successful' == $ipay_response->data->status && $ipay_response->data->chargecode == '00' || $ipay_response->data->chargecode == '0') {
                                    
                                    
                                    //$customer_code = $ipay_response->data->customer->customer_code;
                                    
                                    if (strlen($order->subscription_transaction_id) > 3) {
                                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $order->subscription_transaction_id, current_time("timestamp"))) . "'";
                                    } elseif (!empty($pmpro_level->expiration_number)) {
                                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";
                                    } else {
                                        $enddate = "NULL";
                                    }

                                    // 
                                    // die();

                                    //using the plan details to set as subscription details
                                    $morder->subscription_transaction_id = $ipay_response->data->txref;
                                    $morder->subscription_token = $ipay_response->data->txid;
                                    
                                    $custom_level = array(
                                        'user_id'           => $morder->user_id,
                                        'membership_id'     => $pmpro_level->id,
                                        'code_id'           => '',
                                        'initial_payment'   => $pmpro_level->initial_payment,
                                        'billing_amount'    => $pmpro_level->billing_amount,
                                        'cycle_number'      => $pmpro_level->cycle_number,
                                        'cycle_period'      => $pmpro_level->cycle_period,
                                        'billing_limit'     => $pmpro_level->billing_limit,
                                        'trial_amount'      => $pmpro_level->trial_amount,
                                        'trial_limit'       => $pmpro_level->trial_limit,
                                        'startdate'         => $startdate,
                                        'enddate'           => $enddate
                                    );

                                    if ($morder->status != 'success') {
                                        
                                        if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                                            $morder->membership_id = $pmpro_level->id;
                                            $morder->payment_transaction_id = $_REQUEST['txref'];
                                            $morder->status = "success";
                                            $morder->saveOrder();
                                        }
                                            
                                    }
                                    // echo "<pre>";
                                    // print_r($morder);
                                    // die();
                                    //setup some values for the emails
                                    if (!empty($morder)) {
                                        $pmpro_invoice = new MemberOrder($morder->id);
                                    } else {
                                        $pmpro_invoice = null;
                                    }

                                    $current_user->membership_level = $pmpro_level; //make sure they have the right level info
                                    $current_user->membership_level->enddate = $enddate;
                                    if ($current_user->ID) {
                                        $current_user->membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
                                    }
                                    
                                    //send email to member
                                    $pmproemail = new PMProEmail();
                                    $pmproemail->sendCheckoutEmail($current_user, $pmpro_invoice);

                                    //send email to admin
                                    $pmproemail = new PMProEmail();
                                    $pmproemail->sendCheckoutAdminEmail($current_user, $pmpro_invoice);

                                    $content = "<ul>
                                        <li><strong>".__('Account', KKD_ipayPMP).":</strong> ".$current_user->display_name." (".$current_user->user_email.")</li>
                                        <li><strong>".__('Order', KKD_ipayPMP).":</strong> ".$pmpro_invoice->code."</li>
                                        <li><strong>".__('Membership Level', KKD_ipayPMP).":</strong> ".$pmpro_level->name."</li>
                                        <li><strong>".__('Amount Paid', KKD_ipayPMP).":</strong> ".$pmpro_invoice->total." ".$pmpro_currency."</li>
                                    </ul>";

                                    ob_start();

                                    if (file_exists(get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php")) {
                                        include get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php";
                                    } else {
                                        include PMPRO_DIR . "/pages/confirmation.php";
                                    }
                                    
                                    $content .= ob_get_contents();
                                    ob_end_clean();

                                } else {

                                    $content = 'Invalid Reference';
                                    
                                }

                            } else {
                                
                                $content = 'Unable to Verify Transaction';

                            }
                            
                        } else {
                            
                            $content = 'Invalid Transaction Reference';
                        }
                    }
            
            
                    return $content;
                    
                }

                // cancel subscriptiom
                function cancel(&$order) 
                {
                    global $wpdb;

                    //no matter what happens below, we're going to cancel the order in our system
                    $order->updateStatus("cancelled");
                    $mode = pmpro_getOption("gateway_environment");
                    // $transaction_id = $order->subscription_transaction_id;
                    $ipay_txid = $order->subscription_token;

                    if ($mode == 'sandbox') {
                        $skey = pmpro_getOption("ipay_tsk");
                    } else {
                        $skey = pmpro_getOption("ipay_lsk");

                    }
                    if ($ipay_txid != "") {
                        $ipay_url = 'https://api.ipaypay.co/v2/gpx/subscriptions/'.$ipay_txid.'/cancel?fetch_by_tx=1';
                        $headers = array(
                            'Content-Type' => 'application/json'
                        );
                        $body = array(
                            'seckey' => $skey,
                        );
                        $args = array(
                            'body' => json_encode($body),
                            'headers' => $headers,
                            'timeout' => 60
                        );
                        $request = wp_remote_post($ipay_url, $args);
                        if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request)) {
                            $ipay_response = json_decode(wp_remote_retrieve_body($request));
                            if ('success' == $ipay_response->status && 'cancelled' == $ipay_response->data->status) {
                                
                                $wpdb->query("DELETE FROM $wpdb->pmpro_membership_orders WHERE id = '" . $order->id . "'");

                            }
                        }
                    }    
                }

                function delete(&$order) 
                {
                    //no matter what happens below, we're going to cancel the order in our system
                    $order->updateStatus("cancelled");
                    global $wpdb;
                    $wpdb->query("DELETE FROM $wpdb->pmpro_membership_orders WHERE id = '" . $order->id . "'");
                }
            }
        }
    }
}
?>