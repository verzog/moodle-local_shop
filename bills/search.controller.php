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
 * Form for editing HTML block instances.
 *
 * @package     local_shop
 * @categroy    local
 * @author      Valery Fremaux <valery.fremaux@gmail.com>
 * @copyright   Valery Fremaux <valery.fremaux@gmail.com> (MyLearningFactory.com)
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_shop\bills;

defined('MOODLE_INTERNAL') || die;

class search_controller {

    protected $theshop;

    public function __construct($theshop) {
        $this->theshop = $theshop;
    }

    public function process($cmd) {
        global $DB;

        if ($cmd == 'search') {
            $error = false;
            $by = optional_param('by', '', PARAM_TEXT);
            $billid = optional_param('billid', '', PARAM_INT);
            $billkey = optional_param('billkey', '', PARAM_TEXT);
            $customername = optional_param('customername', '', PARAM_TEXT);
            $datefrom = optional_param('datefrom', '', PARAM_INT);

            switch ($by) {
                case 'id':
                    $whereclause = " id = '{$billid}' ";
                    break;
                case "name":
                    $whereclause = " UPPER(lastname) LIKE '{$customername}%' ";
                    break;
                case "key":
                    $whereclause = " UPPER(transactionid) LIKE '{$billkey}%' ";
                    break;
                case "date":
                    $whereclause = " emissiondate > '{$datefrom}' ";
                    break;
                default:
                    $error = true;
            }
            if (!$error) {
                $sql = "
                   SELECT
                      b.*
                   FROM
                      {local_shop_bill} as b,
                      {local_shop_customer} as c
                   WHERE
                      b.userid = c.id AND
                      $whereclause
                ";
                if ($bills = $DB->get_records->sql($sql)) {

                    if (count($bills) == 1) {
                        $billrecord = array_pop($bills);
                        $billid = $billrecord->id;
                        // One only result. Switch directly to intranet/bills/viewBill with adequate Id.
                        $params = array('view' => 'viewBill', 'id' => $this->theshop->id, 'billid' => $billid);
                        redirect(new moodle_url('/local/shop/bills/view.php', $params));
                    }
                    return $bills;
                }
            }
        }
    }
}