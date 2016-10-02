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

/**
 * @package   local_shop
 * @category  local
 * @author    Valery Fremaux (valery.fremaux@gmail.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_shop\front;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/local/shop/front/front.controller.php');

class customer_controller extends front_controller_base {

    public function process($cmd) {
        global $SESSION, $USER, $DB;

        if ($cmd == 'revalidate') {

            // This comes after a customer login with a owned moodle account.
            $errors = shop_validate_customer($this->theblock);

        } else if ($cmd == 'navigate') {

            $shoppingcart = $SESSION->shoppingcart;

            $shoppingcart->usedistinctinvoiceinfo = optional_param('usedistinctinvoiceinfo', 0, PARAM_BOOL);

            $customerinfofields = preg_grep('/customerinfo::/', array_keys($_POST));
            foreach ($customerinfofields as $cif) {
                $cifshort = str_replace('customerinfo::', '', $cif);
                $shoppingcart->customerinfo[$cifshort] = optional_param($cif, '', PARAM_TEXT);
            }

            $invoiceinfofields = preg_grep('/invoiceinfo::/', array_keys($_POST));
            if (!empty($invoiceinfofields)) {
                foreach ($invoiceinfofields as $iif) {
                    $iifshort = str_replace('invoiceinfo::', '', $iif);
                    $shoppingcart->invoiceinfo[$iifshort] = optional_param($iif, '', PARAM_TEXT);
                }
            }

            if (!empty($config->hasshipping)) {
                $shoppingcart->shipping = $thecatalog->calculate_shipping();
                $shoppingcart->finalshippedtaxedtotal = $shoppingcart->finaltaxedtotal + $shoppingcart->shipping->value;
            } else {
                // This is the last final payable amount.
                $SESSION->shoppingcart->finalshippedtaxedtotal = $SESSION->shoppingcart->finaltaxedtotal;
            }

            if ($back = optional_param('back', '', PARAM_TEXT)) {
                $params = array('view' => $this->theshop->get_prev_step('customer'), 'shopid' => $this->theshop->id, 'back' => 1);
                redirect(new \moodle_url('/local/shop/front/view.php', $params));
            } else {

                $errors = shop_validate_customer($this->theblock);

                if ($shoppingcart->usedistinctinvoiceinfo) {
                    $errors = $errors + shop_validate_invoicing();
                }

                if (empty($errors)) {
                    /*
                     * register customer in customer table now
                     * this allows us to catch customer list, even if not going through the whole purchase
                     * process. We will always update data for the same email.
                     * this is not considered as reliable data, user accounts are...
                     */
                    $params = array('email' => $shoppingcart->customerinfo['email']);
                    if (!$customer = $DB->get_record('local_shop_customer', $params)) {
                        $customer = (object) $shoppingcart->customerinfo;
                        $customer->timecreated = time();

                        // This is for a new customer coming from inside out registered members. Bind it immediately.
                        if (isloggedin() && !isguestuser()) {
                            $customer->hasaccount = $USER->id;
                        }

                        if (!empty($shoppingcart->usedistinctinvoiceinfo)) {
                            // Store snapshot of current invoice info as default data for this customer.
                            $customer->invoiceinfo = serialize($SESSION->shoppingcart->invoiceinfo);
                        } else {
                            $customer->invoiceinfo = '';
                        }
                        $shoppingcart->customerinfo['id'] = $DB->insert_record('local_shop_customer', $customer);
                    } else {
                        $shoppingcart->customerinfo['id'] = $customer->id;
                        $DB->update_record('local_shop_customer', $customer);
                    }

                    $next = $this->theshop->get_next_step('customer');
                    $params = array('view' => $next, 'shopid' => $this->theshop->id, 'blockid' => 0 + @$this->theblock->id);
                    redirect(new \moodle_url('/local/shop/front/view.php', $params));
                }
            }
        }
    }
}