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
 * @package    shoppaymodes_test
 * @category   local
 * @author     Valery Fremaux (valery.fremaux@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/local/shop/paymodes/paymode.class.php');
require_once($CFG->dirroot.'/local/shop/classes/Bill.class.php');
Use \local_shop\Bill;

/**
* A generic class for making payment tests
* not enabled in production.
*/
class shop_paymode_test extends shop_paymode{

    function __construct(&$shopblockinstance) {
        parent::__construct('test', $shopblockinstance, true, true);
    }

    // prints a payment porlet in an order form
    function print_payment_portlet(&$shoppingcart) {
        global $CFG;

        $shopurl = new moodle_url('/local/shop/front/view.php');
        $ipnurl = new moodle_url('/local/shop/paymodes/test/test_ipn.php');

        echo '<table cellspacing="30">';
        echo '<tr><td colspan="4" align="left">';
        echo get_string('testadvice', 'shoppaymodes_test');
        echo '</td></tr>';
        echo '<tr>';

        // This is interactive payment triggering immediately successfull payment.
        echo '<td>';
        echo '<form name="paymentform" action="'.$shopurl.'" >';
        echo '<input type="hidden" name="shopid" value="'.$this->theshop->id.'">';
        echo '<input type="hidden" name="transid" value="'.$shoppingcart->transid.'" />';
        echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
        echo '<input type="hidden" name="view" value="payment" />';
        echo '<input type="hidden" name="what" value="navigate" />';
        echo '<input type="submit" name="pay" value="'.get_string('interactivepay', 'shoppaymodes_test').'">';
        echo '</form>';
        echo '</td>';

        // this stands for delayed payment, as check or bank wired transfer, needing backoffice
        // post check to activate production.
        echo '<td>';
        echo '<form name="paymentform" action="'.$shopurl.'" target="_blank">';
        echo '<input type="hidden" name="shopid" value="'.$this->theshop->id.'">';
        echo '<input type="hidden" name="transid" value="'.$shoppingcart->transid.'" />';
        echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
        echo '<input type="hidden" name="what" value="navigate" />';
        echo '<input type="hidden" name="view" value="payment" />';
        echo '<input type="hidden" name="delayed" value="1" />';
        echo '<input type="submit" name="pay" value="'.get_string('paydelayedforipn', 'shoppaymodes_test').'">';
        echo '</form>';
        echo '</td>';

        // In IPN Payemnt (delayed return from payment peer) we may have no track of shopid
        echo '<td>';
        echo '<form name="paymentform" action="'.$ipnurl.'" target="_blank" >';
        echo '<input type="hidden" name="transid" value="'.$shoppingcart->transid.'" />';
        echo '<input type="submit" name="pay" value="'.get_string('ipnpay', 'shoppaymodes_test').'" />';
        echo '</form>';
        echo '</td>';

        echo '<td>';
        echo '<form name="paymentform" action="'.$ipnurl.'" target="_blank" >';
        echo '<input type="hidden" name="transid" value="'.$shoppingcart->transid.'" />';
        echo '<input type="hidden" name="finish" value="1" />';
        echo '<input type="submit" name="pay" value="'.get_string('ipnpayandclose', 'shoppaymodes_test').'" />';
        echo '</form>';
        echo '</td>';

        echo '</tr>';
        echo '</table>';
    }

    // prints a payment porlet in an order form
    function print_invoice_info(&$billdata = null) {
    }

    function print_complete() {
        echo shop_compile_mail_template('bill_complete_text', array(), 'local_shop');
    }

    // processes a payment return
    function process() {
        global $OUTPUT;

        $delayed = optional_param('delayed', 0, PARAM_BOOL);
        $transid = required_param('transid', PARAM_TEXT);
        shop_trace("[$transid]  Test Processing : paying");

        try {
            $afullbill = Bill::get_by_transaction($transid);

            if ($delayed) {
                $afullbill->status = 'PENDING';
                $afullbill->save(true);
                shop_trace("[$transid]  Test Interactive : Payment Success but waiting IPN for processing");
                return false; // has not yet payed
            } else {
                $afullbill->status = 'SOLDOUT';
                $afullbill->save(true);
                shop_trace("[$transid]  Test Interactive : Payment Success");
                return true; // has payed
            }
        }
        catch (Exception $e) {
            shop_trace("[$transid]  Test Interactive : Transaction ID Error");
            echo $OUTPUT->notification(get_string('ipnerror', 'shoppaymodes_test'), 'error');
        }
    }

    function is_instant_payment() {
        return true;
    }

    // processes a payment asynchronous confirmation
    function process_ipn() {
        global $CFG, $OUTPUT;

        $transid = required_param('transid', PARAM_TEXT);
        $close = optional_param('finish', false, PARAM_BOOL);

        shop_trace("[$transid]  Test IPN : examinating");
        mtrace("[$transid]  Test IPN : examinating");

        try {
            mtrace("Testing IPN production ");
            $afullbill = Bill::get_by_transaction($transid);

            $ipncall = true;
            $cmd = 'produce';
            $returnstatus = include($CFG->dirroot.'/local/shop/front/produce.controller.php');
            $controller = new \local_shop\front\production_controller($afullbill, true, true);

            mtrace("[$transid]  Test IPN : Payment Success, transferring to production controller");
            shop_trace("[$transid]  Test IPN : Payment Success, transferring to production controller");

            $afullbill->status = 'SOLDOUT';
            $afullbill->save(true);

            // Lauch production from a SOLDOUT state
            $controller->process('produce', !$close);

            die;
        }
        catch(Exception $e) {
            shop_trace("[$transid]  Test IPN : Transaction ID Error");
            mtrace($OUTPUT->notification(get_string('ipnerror', 'shoppaymodes_test'), 'error'));
        }
    }

    // provides global settings to add to shop settings when installed
    function settings(&$settings) {
    }
}