<?php
/*
  Plugin Name: Crypto Payment Gateway DPay for WooCommerce
  Plugin URI: https://dpay.tech
  Description: Pugin Payment Gateway DPay
  Contributors: Dpay
  Installation:
  Version: 1.0.1
  Author: dpay
  Text Domain: dpay
  Tags: dpay, dpay.tech
  Tested up to: 6.1.1
  Requires PHP: 7.4
  License: GPLv2 or later
  License URI: http://www.gnu.org/licenses/gpl-2.0.html

  */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_filter('woocommerce_payment_gateways', 'dpay_add_gateway_class');
function dpay_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Dpay_Gateway'; // your class name is here
    return $gateways;
}
add_action('plugins_loaded', 'dpay_init_gateway_class');
function dpay_init_gateway_class()
{
    class WC_Dpay_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'dpay'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'Dpay Gateway';
            $this->method_description = 'Thiết lập thanh toán đơn hàng qua cổng thanh toán Dpay'; // will be displayed on the options page

            $this->supports = array(
                'subscriptions',
                'products',
                'subscription_cancellation',
                'subscription_reactivation',
                'subscription_suspension',
                'subscription_amount_changes',
                'subscription_payment_method_change',
                'subscription_date_changes',
                'default_credit_card_form',
                'refunds',
                'pre-orders'
            );

            $this->init_form_fields();

            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');

            $this->token = $this->get_option('token');
            $this->currency_convert = $this->get_option('currency_convert');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
            add_action( 'woocommerce_api_dpay_ipn', array($this, 'webhook_api_dpay_ipn'));    
            add_action( 'woocommerce_api_dpay_redirect_url', array($this, 'webhook_api_dpay_redirect_url')); 
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Dpay Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Credit Card',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with your credit card via our super-cool payment gateway.',
                ),
                'token' => array(
                    'title'       => 'API TOKEN',
                    'type'        => 'textarea'
                ),
                'currency_convert' => array(
                    'title'       => 'rate',
                    'type'        => 'float',
                    'default'     => 1,
                )
            );
        }

        public function get_icon()
        {
            $icon_html = '<img style="max-height: 35px;" src="' . plugins_url('assets/img/logo.png', __FILE__) . '" alt="' . esc_attr__('Dpay acceptance mark', 'woocommerce') . '" />';
            return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
        }

        public function payment_fields()
        {
        }

        public function payment_scripts()
        {
            if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
                return;
            }
            if ('no' === $this->enabled) {
                return;
            }
            if (empty($this->token) || empty($this->token)) {
                return;
            }
        }

        public function validate_fields()
        {
        }

        public function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);

            $ipnUrl = get_home_url() . '/wc-api/dpay_ipn';
            $redirectUrl = get_home_url() . '/wc-api/dpay_redirect_url?orderId='.$order_id;
            $totalamount = $order->get_total() / $this->currency_convert;
            
            $endpoint = 'https://api.dpay.tech/v1/gateway/create';
            
            $token = $this->token;
            $orderInfo = 'Thanh toán đơn hàng ' . $order_id;
            $amount = round($totalamount);
            $orderId = $this->dpay_genInvoiceID($order_id);
            $requestId = time() . time();
            
            $data = array(
                'requestId' => $requestId,
                'amount' => $amount,
                'orderId' => $orderId,
                'orderInfo' => $orderInfo,
                'ipnUrl' => $ipnUrl,
                'redirectUrl' => $redirectUrl
            );
            $result = $this->dpay_execPostRequest($endpoint, json_encode($data));

            if (!is_wp_error($result)) {
                $jsonResult = json_decode($result, true);
                if ($jsonResult['status'] == 200){
                    return array(
                        'result' => 'success',
                        'redirect' => esc_url($jsonResult['data']['payUrl'])
                    );
                } else {
                    wc_add_notice(esc_html($jsonResult['message']) . ' Please try again.', 'error');
                    return;
                }
            } else {
                wc_add_notice('Connection error.', 'error');
                return;
            }
        }
        public function webhook_api_dpay_redirect_url()
        {
            global $woocommerce;

            if (sanitize_text_field($_GET['orderId'])) {
                $orderid = $this->dpay_getInvoiceID(sanitize_text_field($_GET['orderId']));
                $order = new WC_Order($orderid);
                wp_redirect($this->get_return_url($order));
                // if (sanitize_text_field($_GET['resultCode']) != 0) {
                //     $url = $order->get_checkout_payment_url();
                //     wp_redirect($url);
                // } else {
                //     $woocommerce->cart->empty_cart();
                //     $order->add_order_note(__('The order process was successful. Waiting for payment confirmation', 'tino'), true);
                // wp_redirect($this->get_return_url($order));
                // }
            }
        }

        public function webhook_api_dpay_ipn()
        {
            global $woocommerce;
            $response = json_decode(file_get_contents('php://input'), true);
            $return = [];
            $verified = false;
            try {
                if ($response) {
                    $orderId = sanitize_text_field($response['orderId']);
                    $requestId = sanitize_text_field($response['requestId']);
                    $amount = sanitize_text_field($response['amount']);
                    $transId = sanitize_text_field($response['transId']);
                    $resultCode = sanitize_text_field($response['resultCode']);
                    $txhash = sanitize_text_field($response['txhash']);
                    $message = sanitize_text_field($response['message']);
                    $responseTime = sanitize_text_field($response['responseTime']);
                    
                }
                if ($resultCode == 0) {

                    $orderid = $this->dpay_getInvoiceID(sanitize_text_field($response['orderId']));
                    $order = new WC_Order($orderid);
                    $order->payment_complete(esc_html(sanitize_text_field($response['transId'])));
                    $order->reduce_order_stock();
                    $order->add_order_note(esc_html($response['message']) . ' - Transaction id: ' . sanitize_text_field($response['transId']) . ' TxHash: ' . sanitize_text_field($response['txHash']), true);

                    return true;
                }
            } catch (\Exception $e) {
                echo "bug";
            }
        }

        public function dpay_execPostRequest($url, $data)
        {
            $args = array(
                'method'      => 'POST',
                'body'        => $data,
                'timeout'     => '10',
                'redirection' => '5',
                'httpversion' => '1.0',
                'blocking'    => true,
                'headers'     => array(
                    'token'=> $this->token,
                    'Content-Type' => 'application/json',
                    'Content-Length' => strlen($data)
                ),
                'cookies'     => array(),
            );
            $response = wp_remote_post($url, $args);
            return wp_remote_retrieve_body($response);
        }

        public function dpay_genInvoiceID($invoiceId)
        {
            $invoiceId = $invoiceId . 'DPAY' . time();
            return $invoiceId;
        }
        public function dpay_getInvoiceID($invoiceId)
        {
            $invoiceId = strstr($invoiceId, 'DPAY', true);
            $invoiceId = str_replace('DPAY', '', $invoiceId);
            return $invoiceId;
        }
    }
}
