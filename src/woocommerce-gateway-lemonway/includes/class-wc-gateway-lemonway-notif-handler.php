<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles responses from LemonWay Notification.
 */
class WC_Gateway_Lemonway_Notif_Handler
{
    /**
     * Pointer to gateway making the request.
     * @var WC_Gateway_Lemonway
     */
    protected $gateway;

    protected $_moneyin_trans_details = null;

    /**
     *
     * @var WC_Order
     */
    protected $order;

    /**
     * Constructor.
     */
    public function __construct($gateway)
    {
        add_action('woocommerce_api_wc_gateway_lemonway', array($this, 'check_response'));
        add_action('valid-lemonway-notif-request', array($this, 'valid_response'));
        $this->gateway = $gateway;
    }

    /**
     * Check for Notification IPN Response.
     */
    public function check_response()
    {
        $orderId = $this->isGet() ? wc_clean($_GET['response_wkToken']) : wc_clean($_POST['response_wkToken']);

        $this->order = wc_get_order($orderId);
        if (!$this->order) {
            wp_die('Lemonway notification Request Failure. No Order Found!', 'Lemonway Notification', array('response' => 500));
        }

        if ($this->isGet()) {
            WC_Gateway_Lemonway::log('GET: ' . print_r($_GET, true));

            if ($this->doubleCheck()) {
                do_action('valid-lemonway-notif-request', $this->order);
                wp_redirect(esc_url_raw($this->gateway->get_return_url($this->order)));
            } else {
                wp_die('Payment Error', 'Payment Error', array('response' => 500));
            }
        } elseif ($this->isPost() && $this->validate_notif(wc_clean($_POST['response_code']))) {
            WC_Gateway_Lemonway::log('POST: ' . print_r($_POST, true));
            do_action('valid-lemonway-notif-request', $this->order);
        } else {
            wp_die('LemonWay notification Request Failure', 'Lemonway Notification', array('response' => 500));
        }
    }


    /**
     * There was a valid response.
     * @param  WC_Order $order Woocommerce order
     */
    public function valid_response($order)
    {
        $this->payment_status_completed($order);
    }

    protected function isGet()
    {
        return strtoupper($_SERVER['REQUEST_METHOD']) == 'GET';
    }

    protected function isPost()
    {
        return strtoupper($_SERVER['REQUEST_METHOD']) == 'POST';
    }

    /**
     * Check LemonWay Notification validity.
     */
    protected function validate_notif($response_code)
    {
        if ($response_code != "0000") {
            return false;
        }

        return $this->doubleCheck();
    }

    protected function GetMoneyInTransDetails()
    {
        if (is_null($this->_moneyin_trans_details)) {
            //call directkit to get Webkit Token
            $params = array('transactionMerchantToken' => $this->order->id);

            //Call api to get transaction detail for this order
            try {
                $operation = $this->gateway->getDirectkit()->GetMoneyInTransDetails($params);
            } catch (Exception $e) {
                WC_Gateway_Lemonway::log($e->getMessage());
            }

            $this->_moneyin_trans_details = $operation;
        }

        return $this->_moneyin_trans_details;
    }

    /*
     *Double check
    */
    private function doubleCheck()
    {
        $ret = false;

        $operation = $this->GetMoneyInTransDetails();

        // Status 0 means success
        if ($operation && ($operation->INT_STATUS == 0)) {
            // CREDIT + COMMISSION
            $realAmount = $operation->CRED + $operation->COM;

            $amount = number_format((float)$this->order->total, 2, '.', '');

            if ($amount == $realAmount) {
                //Save Card Data if is register case
                $registerCard = get_post_meta($this->order->id, '_register_card', true);
                if ($registerCard) {
                    update_user_meta($this->order->get_user_id(), 'lw_card_type', $operation->EXTRA->TYP);
                    update_user_meta($this->order->get_user_id(), 'lw_card_num', $operation->EXTRA->NUM);
                    update_user_meta($this->order->get_user_id(), 'lw_card_exp', $operation->EXTRA->EXP);
                }

                $ret = true;
            }
        }

        return $ret;
    }

    /**
     * Complete order, add transaction ID and note.
     * @param  WC_Order $order
     * @param  string $txn_id
     * @param  string $note
     */
    protected function payment_complete($order, $txn_id = '', $note = '')
    {
        $order->add_order_note($note);
        $order->payment_complete($txn_id);
    }

    /**
     * Handle a completed payment.
     * @param WC_Order $order
     */
    protected function payment_status_completed($order)
    {
        if ($order->has_status('completed')) {
            WC_Gateway_Lemonway::log('Aborting, Order #' . $order->id . ' is already complete.');
            exit;
        }

        if ((!empty($_GET['response_wkToken']) || !empty($_POST['response_transactionId'])) && !$order->has_status('processing')) {
            $this->payment_complete($order, (wc_clean($_POST['response_transactionId'])), __('Notification payment completed', LEMONWAY_TEXT_DOMAIN));
        }
    }
}
