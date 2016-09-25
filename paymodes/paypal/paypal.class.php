<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

/**
 * @package    shoppaymodes_paypal
 * @category   local
 * @author     Valery Fremaux (valery.fremaux@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot.'/local/shop/paymodes/paymode.class.php');
require_once($CFG->dirroot.'/local/shop/locallib.php');
require_once($CFG->dirroot.'/local/shop/classes/Bill.class.php');
require_once($CFG->dirroot.'/local/shop/classes/Shop.class.php');

Use \local_shop\Bill;
Use \local_shop\Shop;

class shop_paymode_paypal extends shop_paymode {

    function __construct(&$shop) {
        parent::__construct('paypal', $shop, true, true);
    }

    function is_instant_payment() {
        return true;
    }

    // prints a payment porlet in an order form
    /**
     * @param object $portlet a data stub that contains required information for the portlet raster
     */
    function print_payment_portlet(&$shoppingcart) {
        global $CFG;

        if ($shoppingcart->usedistinctinvoiceinfo) {
            $paymentinfo = $shoppingcart->invoicinginfo;
        } else {
            $paymentinfo = $shoppingcart->customerinfo;
        }

        $paypalsupportedlangs = array ('AU','AT','BE','BR','CA','CH','CN','DE','ES','GB','FR','IT','NL','PL','PT','RU','US');

        echo '<div id="shop-panel-caption">';
        echo shop_compile_mail_template('door_transfer_text', array(), 'shoppaymodes_paypal');
        echo '</div>';

        echo '<div id="shop-panel">';

        $portlet = new StdClass();
        $portlet->amount = $shoppingcart->finalshippedtaxedtotal;
        $portlet->firstname = $paymentinfo['firstname'];
        $portlet->lastname = $paymentinfo['lastname'];
        $portlet->address = $paymentinfo['address'];
        $portlet->city = $paymentinfo['city'];
        $portlet->country = $paymentinfo['country'];
        $portlet->zip = $paymentinfo['zip'];
        $portlet->email = $shoppingcart->customerinfo['email']; // invoicing info has no mail
        $portlet->transid = $shoppingcart->transid; // no need special format for online transaction id here.
        $portlet->shipping = @$shoppingcart->shipping;
        $portlet->currency = $this->theshop->get_currency();
        $portlet->return_url = $CFG->wwwroot.'/local/shop/paymodes/paypal/process.php?id='.$this->theshop->id.'&transid='.$portlet->transid;
        $portlet->notify_url = $CFG->wwwroot.'/local/shop/paymodes/paypal/paypal_ipn.php';
        $portlet->cancel_url = $CFG->wwwroot.'/local/shop/paymodes/paypal/cancel.php?id='.$this->theshop->id.'&transid='.$portlet->transid;
        $portlet->paypallogo_url = $CFG->wwwroot.'/local/shop/paymodes/paypal/pix/logo_paypal_106x29.png';
        $portlet->lang = strtoupper(current_language());
        if (!in_array($paypalsupportedlangs, $portlet->lang)) {
            $portlet->lang = 'US';
        }

        include($CFG->dirroot.'/local/shop/paymodes/paypal/paypalAPI.portlet.php');

        echo '</div>';
        echo '<div id="shop-panel-nav">';

        echo '<p><span class="shop-procedure-cancel">X</span>';
        $cancelstr = get_string('cancel');
        $cancelurl = new moodle_url('/local/shop/front/view.php', array('step' => 'shop', 'id' => $this->theshop->id));
        echo '<a href="'.$cancelurl.'" class="smalltext">'.$cancelstr.'</a>';

        echo '</div>';
    }

    /**
     * prints a payment porlet in an order form
     */
    function print_invoice_info(&$billdata = null) {
        echo get_string($this->name.'paymodeinvoiceinfo', 'shoppaymodes_paypal', '');
    }

    function print_complete() {
        $config = get_config('local_shop');
        echo shop_compile_mail_template('bill_complete_text', '', 'local_shop');
    }

    /**
    * guesses it is a paypal transaction 
    *
    function identify_transaction(&$transid, &$cmd) {
        $action = optional_param('what', '', PARAM_TEXT);
        if ($action == 'paypalback' || $action == 'paypalbackasync') {
            $transid = required_param('invoice', PARAM_TEXT);
        }
    }
    */

    /**
    * Cancels the order and return to shop
    */ 
    function cancel() {
        global $CFG;

        $transid = required_param('transid', PARAM_RAW);

        // cancel shopping cart
        unset($SESSION->shoppingcart);

        $aFullBill = Bill::get_by_transaction($transid);
        $aFullBill->onlinetransactionid = $transid;
        $aFullBill->paymode = 'paypal';
        $aFullBill->status = SHOP_BILL_CANCELLED;
        $aFullBill->save(true);
        shop_trace('Paypal Interactive Cancellation');

        redirect(new moodle_url('/local/shop/front/view.php', array('view' => 'shop', 'id' => $this->theshop->id)));
    }

    /**
     * Processes interactively an order payment request.
     * In thje Paypal process, the payment processing only can be performed
     * waiting for an IPN call that needs answer back to Paypal and acknowledge (VERIFIED)
     */
    function process() {
        shop_trace('Paypal Return Controller');

        $transid = required_param('transid', PARAM_RAW);

        $aFullBill = Bill::get_by_transaction($transid);

        $this->theshop = $aFullBill->theshop;

        // bill could already be SOLDOUT by IPN    so do nothing
        // process it only if needind to process.
        if ($aFullBill->status == SHOP_BILL_PLACED) {
            // Bill has not yet been soldout nor produced by an IPN notification
            $aFullBill->status = SHOP_BILL_PENDING;
            $aFullBill->save(true);

            shop_trace("[$transid] Paypal Return Controller Complete : Redirecting");
            redirect(new moodle_url('/local/shop/front/view.php', array('view' => 'produce', 'id' => $this->theshop->id, 'transid' => $transid)));
        }
    }

    // Processes a payment asynchronous confirmation
    function process_ipn() {
        global $CFG;

        $config = get_config('local_shop');

        // get all input parms
        $transid = required_param('invoice', PARAM_TEXT);
        list($instanceid) = required_param('custom', PARAM_TEXT); // get the shopid. Not sure its needed any more

        if (empty($transid)) {
            shop_trace("[ERROR] Paypal IPN : Empty Transaction ID");
            die;
        }

        if (!$aFullBill = Bill::get_by_transaction($transid)) {
            shop_trace("[$transid] Paypal IPN ERROR : No such order");
            die;
        }

        // Pass reference from bill.
        $this->theshop = $aFullBill->theshop;

        $txnid = required_param('txn_id', PARAM_TEXT);
        $data = new StdClass;
        $validationquery = 'cmd=_notify-validate';
        $querystring = '';

        shop_trace("[$transid] Paypal IPN : paypal txn : $txnid");
        shop_trace("[$transid] Paypal IPN : paypal trans : $transid");

        foreach ($_POST as $key => $value) {
            $value = stripslashes($value);
            $querystring .= "&$key=".urlencode($value);
            $data->$key = $value;
            shop_trace("[$transid] Paypal IPN : paypal $key : ".$value);
        }

        $validationquery .= $querystring;
        // control for replicated notifications (normal operations)
        if (empty($config->test) && $DB->record_exists('shop_paypal_ipn', array('txnid' => $txnid))) {
            shop_trace("[$transid] Paypal IPN : paypal event collision on $txnid");
            shop_email_paypal_error_to_admin("Paypal IPN : Transaction $txnid is being repeated.", $data);
            die;
        } else {
            $paypalipn = new Stdclass;
            $paypalipn->txnid = $txnid;
            $paypalipn->transid = $transid;
            $paypalipn->paypalinfo = $querystring;
            $paypalipn->result = '';
            $paypalipn->timecreated = time();
            shop_trace("[$transid] Paypal IPN : Recording paypal event");
            try {
                $DB->insert_record('shop_paypal_ipn', $paypalipn);
            } catch(Exception $e) {
                shop_trace("[$transid] Paypal IPN : Recording paypal event error");
            }
        }

        /*
         * Warning : Paypal Sandbox may NOT activate any IPN back call.
         * See further faking answer solution for testing paypal.
         */
        if (empty($config->test)) {
            $paypalurl = 'https://www.paypal.com/cgi-bin/webscr';
        } else {
            $paypalurl = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
        }

        if (empty($config->test)) {
            // fetch the file on the consumer side and store it here through a CURL call
            $ch = curl_init("{$paypalurl}?$validationquery");
            shop_trace("[$transid] Paypal IPN : sending validation request: "."{$paypalurl}?$validationquery");
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Moodle');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/xml charset=UTF-8"));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $rawresponse = curl_exec($ch);
        } else {
            // We fake an IPN validation in test mode.
            shop_trace("[$transid] Paypal IPN : faking validation request for test: "."{$paypalurl}?$validationquery");
            $rawresponse = 'VERIFIED'; // just for testing end of procedure
        }
        if ($rawresponse) {
            if ($rawresponse == 'VERIFIED') {
                if ($data->payment_status != "Completed" and $data->payment_status != "Pending") {
                    shop_email_paypal_error_to_admin("Paypal IPN : Status not completed nor pending. Check transaction with customer.", $data);

                    if (!empty($config->test)) {
                        mtrace("Paypal IPN : Status not completed nor pending. Check transaction with customer.");
                    } else {
                        shop_trace("[$transid] Paypal IPN : Status not completed nor pending. Check transaction with customer.");
                    }
                    die;
                }
                $sellerexpectedname = (empty($config->test)) ? $config->paypalsellername : $config->paypalsellertestname;
                if ($data->business != $sellerexpectedname) {   // Check that the business account is the one we want it to be
                    shop_email_paypal_error_to_admin("Paypal IPN : Business email is $data->business (not $config->paypalsellername)", $data);
                    if (!empty($config->test)) {
                        mtrace("Paypal IPN : Business email is $data->business (not $config->paypalsellername)");
                    } else {
                        shop_trace("[$transid] Paypal IPN : Business email is $data->business (not $config->paypalsellername)");
                    }
                    die;
                }
                $DB->set_field('shop_paypal_ipn', 'result', 'VERIFIED', array('txnid' => $txnid));
                shop_trace("[$transid] Paypal IPN : Recording VERIFIED STATE on ".$txnid);
                if (!empty($config->test)) {
                    mtrace('Paypal IPN : Recording VERIFIED STATE on '.$txnid);
                }
                // Bill has not yet been soldout through an IPN notification
                // sold it out and update both DB and memory record
                if ($aFullBill->status != SHOP_BILL_SOLDOUT) {
                    // stores the back code of paypal
                    $tx = required_param('invoice', PARAM_TEXT);
                    $aFullBill->onlinetransactionid = $tx;
                    $aFullBill->paymode = 'paypal';
                    $aFullBill->status = SHOP_BILL_SOLDOUT;
                    $aFullBill->paymentfee = 0 + @$data->mc_fee;
                    $aFullBill->save(true);

                    shop_trace("[$transid] Paypal IPN Start Production");
                    // perform final production
                    $action = 'produce';
                    include_once($CFG->dirroot.'/local/shop/front/produce.controller.php');
                    $controller = new \local_shop\front\production_controller($aFullBill);
                    $controller->process($action);
                    shop_trace("[{$transid}] Paypal IPN End Production");
                }

                shop_trace("[$transid] Paypal IPN : End of transaction");
                if (!empty($config->test)) {
                    mtrace('Paypal IPN : End of transaction');
                }
            }
        } else {
            shop_trace('[ERROR] Paypal IPN : ERROR');
        }
    }

    // provides global settings to add to shop settings when installed
    function settings(&$settings) {

        $settings->add(new admin_setting_heading('local_shop_'.$this->name, get_string($this->name.'paymodeparams', 'shoppaymodes_paypal', $this->name), ''));

        $settings->add(new admin_setting_configtext('local_shop/paypalsellertestname', get_string('paypalsellertestname', 'shoppaymodes_paypal'),
                           get_string('configpaypalsellername', 'shoppaymodes_paypal'), '', PARAM_TEXT));

        $settings->add(new admin_setting_configtext('local_shop/paypalsellertestitemname', get_string('sellertestitemname', 'shoppaymodes_paypal'),
                           get_string('configselleritemname', 'shoppaymodes_paypal'), '', PARAM_TEXT));

        $settings->add(new admin_setting_configtext('local_shop/paypalsellername', get_string('paypalsellername', 'shoppaymodes_paypal'),
                           get_string('configpaypalsellername', 'shoppaymodes_paypal'), '', PARAM_TEXT));

        $settings->add(new admin_setting_configtext('local_shop/paypalselleritemname', get_string('selleritemname', 'shoppaymodes_paypal'),
                           get_string('configselleritemname', 'shoppaymodes_paypal'), '', PARAM_TEXT));
    }
}