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

namespace local_shop;

defined('MOODLE_INTERNAL') || die();

/**
 * Form for editing HTML block instances.
 *
 * @package     local_shop
 * @category    local
 * @author      Valery Fremaux <valery.fremaux@gmail.com>
 * @copyright   Valery Fremaux <valery.fremaux@gmail.com> (MyLearningFactory.com)
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/local/shop/classes/CatalogItem.class.php');

/**
 * User object is provided for direct Object Mapping of the _user database model
 */
class Catalog extends ShopObject {

    static $table = 'local_shop_catalog';

    var $categories;

    var $ismaster;

    var $isslave;

    function __construct($idorrecord, $light = false) {

        parent::__construct($idorrecord, self::$table);

        if ($idorrecord) {

            if ($light) return; // this builds a lightweight proxy of the Shop, without catalogue

            if ($this->record->groupid) {
                if ($this->record->id == $this->record->groupid) {
                    $this->ismaster = 1;
                } else {
                    $this->isslave = 1;
                }
            }
            $this->categories = $this->get_categories();

        } else {
            $this->record->name = get_string('newcatalog', 'local_shop');
            $this->record->description = '';
            $this->record->descriptionformat = FORMAT_MOODLE;
            $this->record->isslave = 0;
            $this->record->ismaster = 0;
            $this->record->salesconditions = '';
            $this->record->salesconditionsformat = FORMAT_MOODLE;
            $this->record->groupid = 0;
            $this->record->countryrestrictions = '';
        }
    }

    /**
     * Get all catalog ids that reside in the same catalog dependency group
     * @param int $catalogid
     * @return an array of ids that are linked to this catalog
     */
    function getGroupMembers() {
        global $DB;

        $members = array();
        $sql = "
            SELECT
                id,
                id = groupid as ismaster
            FROM
                {".self::$table."}
            WHERE
                groupid IS NOT NULL AND 
                groupid = ?
            ORDER BY
                ismaster DESC
        ";
        $members = $DB->get_records_sql($sql, array($this->id));
        if (count($members) == 0) $members[] = $this->id;
        return $members;
    }

    /**
     * Get catalogs known categories
     */
    function get_categories($local = false, $visible = 1) {
        global $DB;

        // Get true fetch if local are required.
        if (!empty($this->categories) && !$local) {
            return $this->categories;
        }

        // Get local categories.
        if (!$localcategories = $DB->get_records_select('local_shop_catalogcategory', " catalogid = ? AND visible = ? ", array($this->id, $visible), 'sortorder', '*,0 as masterrecord')) {
            $localcategories = array();
        }
        if ($local) {
            return $localcategories;
        }

        // Get all master categories.
        $mastercategories = array();
        if ($this->isslave) {
            if (!$mastercategories = $DB->get_records_select('local_shop_catalogcategory', " catalogid = ? AND visible = ? ", array($this->groupid, $visible), 'sortorder', '*,1 as masterrecord')) {
                $mastercategories = array();
            }
        }

        $this->categories = $mastercategories + $localcategories;

        return $this->categories;
    }

    /**
     * get the full productline from categories
     *
     */
    function get_all_products(&$shopproducts) {
        global $CFG, $SESSION, $DB, $OUTPUT;

        $context = \context_system::instance();

        $categories = $this->get_categories();

        if (empty($categories)) {
            return array();
        }

        $isloggedinclause = '';
        if (empty($SESSION->shopseeall)) {
            $isloggedinclause = (isloggedin() && !isguestuser()) ? ' AND ci.onlyforloggedin > -1  ' : ' AND ci.onlyforloggedin < 1';
        }

        $shopproducts = array();
        foreach ($categories as $key => $aCategory) {
            // get master catalog items
            /**
             * product might be standalone product or set or bundle
             */
            if ($this->isslave) {
                $sql = "
                   SELECT
                      ci.*
                   FROM
                      {local_shop_catalogitem} as ci
                   WHERE
                      ci.catalogid = '{$this->groupid}' AND
                      ci.categoryid = '{$aCategory->id}' AND
                      ci.status IN ('AVAILABLE','PROVIDING') AND
                      ci.setid = 0
                      $isloggedinclause
                   ORDER BY
                      ci.shortname
                ";
                $catalogitems = $DB->get_records_sql($sql);
                foreach ($catalogitems as $cirec) {
                    $ci = new CatalogItem($cirec);
                    $ci->thumb = $ci->get_thumb_url();
                    $ci->image = $ci->get_image_url();
                    $ci->masterrecord = 1;
                    $shopproducts[$ci->code] = $ci;
                    $categories[$key]->products[$ci->code] = $ci;
                }
            }
            // override with slave versions
            $sql = "
               SELECT
                  ci.*
               FROM
                  {local_shop_catalogitem} as ci
               WHERE
                  catalogid = '{$this->id}' AND
                  categoryid = '{$aCategory->id}' AND
                  ci.status IN ('AVAILABLE','PROVIDING') AND
                  setid = 0
                  $isloggedinclause
               ORDER BY
                  ci.shortname
            ";
            if ($catalogitems = $DB->get_records_sql($sql)) {
                foreach ($catalogitems as $cirec) {
                    $ci = new CatalogItem($cirec);
                    $ci->thumb = $ci->get_thumb_url();
                    $ci->image = $ci->get_image_url();
                    $ci->masterrecord = 0;
                    $shopproducts[$ci->code] = $ci;
                    $categories[$key]->products[$ci->code] = $ci;
                }
            }
        }

        // Complementary processing for sets : fetch set elements and eventual overrides.
        if (!empty($shopproducts)) {
            foreach (array_values($shopproducts) as $ci) {
                if ($ci->isset) {

                    // Get set elements in master catalog (same set code).
                    if ($this->isslave) {
                        $sql = "
                          SELECT
                            ci.*
                          FROM
                            {local_shop_catalogitem} as ci,
                            {local_shop_catalogitem} as cis
                          WHERE
                            ci.setid = cis.id AND
                            cis.code = '{$ci->code}' AND
                            ci.status IN ('AVAILABLE','PROVIDING') AND
                            ci.catalogid = '{$this->groupid}'
                            $isloggedinclause
                          ORDER BY
                            ci.shortname
                        ";
                        $catalogitems = $DB->get_records_sql($sql);
                        foreach ($catalogitems as $cirec) {
                            $ci1 = new CatalogItem($cirec);
                            $ci1->thumb = $ci1->get_thumb_url();
                            $ci1->image = $ci1->get_image_url();
                            $ci1->masterrecord = 1;
                            $ci->setElement($ci1);
                        }
                    }
                    // override with local versions
                    $sql = "
                      SELECT
                        ci.*
                      FROM
                        {local_shop_catalogitem} as ci
                      WHERE
                        ci.setid = '{$ci->id}' AND
                        ci.status IN ('AVAILABLE','PROVIDING') AND
                        ci.catalogid = '{$this->id}'
                        $isloggedinclause
                         ORDER BY
                        ci.shortname
                    ";

                    if ($catalogitems = $DB->get_records_sql($sql)) {
                        foreach ($catalogitems as $cirec) {
                            $ci1 = new CatalogItem($cirec);
                            $ci1->thumb = $ci1->get_thumb_url();
                            $ci1->image = $ci1->get_image_url();
                            $ci1->masterrecord = 0;
                            $ci->setElement($ci1);
                        }
                    }
                    $shopproducts[$ci->code]->set = $ci;
                }
            }
        }

        return $categories;
    }

    /**
     * get the full productline from categories
     *
     */
    function get_all_products_for_admin(&$shopproducts) {
        global $CFG, $SESSION, $DB, $OUTPUT;

        $context = \context_system::instance();

        $categories = $this->get_categories();

        if (empty($categories)) {
            return array();
        }

        $shopproducts = array();
        foreach ($categories as $key => $aCategory) {
            // get master catalog items
            /**
             * product might be standalone product or set or bundle
             */
            if ($this->isslave) {
                $sql = "
                   SELECT
                      ci.*
                   FROM
                      {local_shop_catalogitem} as ci
                   WHERE
                      ci.catalogid = '{$this->groupid}' AND
                      ci.categoryid = '{$aCategory->id}' AND
                      ci.setid = 0
                   ORDER BY
                      ci.shortname
                ";
                $catalogitems = $DB->get_records_sql($sql);
                foreach ($catalogitems as $cirec) {
                    $ci = new CatalogItem($cirec);
                    $ci->thumb = $ci->get_thumb_url();
                    $ci->image = $ci->get_image_url();
                    $ci->masterrecord = 1;
                    $shopproducts[$ci->code] = $ci;
                    $categories[$key]->products[$ci->code] = $ci;
                }
            }
            // override with slave versions
            $sql = "
               SELECT
                  ci.*
               FROM
                  {local_shop_catalogitem} as ci
               WHERE
                  catalogid = '{$this->id}' AND
                  categoryid = '{$aCategory->id}' AND
                  setid = 0
               ORDER BY
                  ci.shortname
            ";
            if ($catalogitems = $DB->get_records_sql($sql)) {
                foreach ($catalogitems as $cirec) {
                    $ci = new CatalogItem($cirec);
                    $ci->thumb = $ci->get_thumb_url();
                    $ci->image = $ci->get_image_url();
                    $ci->masterrecord = 0;
                    $shopproducts[$ci->code] = $ci;
                    $categories[$key]->products[$ci->code] = $ci;
                }
            }
        }

        // Complementary processing for sets : fetch set elements and eventual overrides.
        if (!empty($shopproducts)) {
            $elementcodes = array();
            foreach (array_values($shopproducts) as $ci) {
                if ($ci->isset) {

                    // Get set elements in master catalog (same set code).
                    if ($this->isslave) {
                        $sql = "
                          SELECT
                            ci.*
                          FROM
                            {local_shop_catalogitem} as ci,
                            {local_shop_catalogitem} as cis
                          WHERE
                            ci.setid = cis.id AND
                            cis.code = '{$ci->code}' AND
                            ci.catalogid = '{$this->groupid}'
                          ORDER BY
                            ci.shortname
                        ";
                        $catalogitems = $DB->get_records_sql($sql);
                        foreach ($catalogitems as $cirec) {
                            // echo "Getting element $cirec->name from master <br/>";
                            $ci1 = new CatalogItem($cirec);
                            $ci1->thumb = $ci1->get_thumb_url();
                            $ci1->image = $ci1->get_image_url();
                            $ci1->masterrecord = 1;
                            $ci->setElement($ci1);
                            $elementcodes[$cirec->code] = $cirec->id;
                        }
                    }
                    // override with local versions
                    $sql = "
                      SELECT
                        ci.*
                      FROM
                        {local_shop_catalogitem} as ci
                      WHERE
                        ci.setid = '{$ci->id}' AND
                        ci.catalogid = '{$this->id}'
                      ORDER BY
                        ci.shortname
                    ";

                    if ($catalogitems = $DB->get_records_sql($sql)) {
                        foreach ($catalogitems as $cirec) {
                            // echo "Getting element $cirec->name $cirec->code from override <br/>";
                            $ci1 = new CatalogItem($cirec);
                            $ci1->thumb = $ci1->get_thumb_url();
                            $ci1->image = $ci1->get_image_url();
                            $ci1->masterrecord = 0;
                            $ci->setElement($ci1);
                            // Remove master version of this product 
                            $ci->deleteElement($elementcodes[$cirec->code]);
                        }
                    }
                    $shopproducts[$ci->code]->set = $ci;
                }
            }
        }

        return $categories;
    }

    /**
     * Get a single catalogitem using short code as key
     * �param string $code the priduct shortcode
     * @return a CatalogItem object
     */
    function get_product_by_code($code) {
        global $DB;

        return new CatalogItem($DB->get_record('local_shop_catalogitem', array('catalogid' => $this->id, 'code' => $code)));
    }

    /**
     * Queries a catalog to find a complete catalog item instance
     * @param string $shortname the shortname of the product 
     * @return a CatalogItem object
     */
    function get_product_by_shortname($shortname) {
        global $DB;

        return new CatalogItem($DB->get_record('local_shop_catalogitem', array('catalogid' => $this->id, 'shortname' => $shortname)));
    }

    /**
     * Get all true products in this catalog.
     * True products are independant products, or master records
     * for a set or a bundle.
     * @return an array of products/items keyed by item shortcode.
     */
    function get_products($order = 'code', $dir = 'ASC', $categoryid = '') {
        global $DB;

        $products = array();

        if ($categoryid) {
            $items = $DB->get_records_select('local_shop_catalogitem', ' catalogid = :catalogid AND categoryid = :categoryid AND setid = 0 or (setid = id) ', array('catalogid' => $this->id, 'categoryid' => $categoryid), " $order $dir");
        } else {
            $items = $DB->get_records_select('local_shop_catalogitem', ' catalogid = :catalogid AND setid = 0 or (setid = id) ', array('catalogid' => $this->id), " $order $dir");
        }

        if ($items) {
            foreach ($items as $item) {
                $products[$item->code] = new CatalogItem($item);
            }
        }

        return $products;
    }

    /**
     * @param text $country Country code
     * @param text $zipcode Customer zipcode
     * @param array $order array of ordered elements (quantity keyed by catalogitem label)
     * @return an object providing entries for a billitem setup as shipping additional
     * pseudo product
     */
    function calculate_shipping($shoppingcart = null) {
        global $DB, $SESSION;

        if (!$shoppingcart) $shoppingcart = $SESSION->shoppingcart;

        shop_trace("[{$shoppingcart->transid}] shop Shipping Calculation for [$shoppingcart->customerinfo->country][$shoppingcart->customerinfo->zipcode]");
        if (!$shipzones = $DB->get_records('local_shop_catalogshipzone', array('catalogid' => $this->id))) {
            shop_trace('No shipzones');
            // echo "noshipzones ";
            $return = new StdClass;
            $return->value = 0;
            return $return;
        }

        // Determinating shipping zone.
        function reduce_and($v, $w) {
            return $v && $w;
        }
        function reduce_or($v, $w) {
            return $v || $w;
        }
        $applicable = null;
        foreach ($shipzones as $z) {
            if ($z->zonecode == '00') {
                $defaultzone = $z;
                continue; // optional '00' special default zone is considered 'in fine'
            }
            $ands = preg_split('/&\|/', $z->applicability); // detokenize &
            for ($i = 0 ; $i < count($ands) ; $i++) {
                // echo "examinating and rule ".$ands[$i];
                if (strstr('|', $ands[$i])) {
                    $ors = preg_split('/\|/', $ands[$i]); // detokenize |
                    for ($j = 0 ; $j < count($ors) ; $j++) {
                        $ors[$j] = shop_resolve_zone_rule($shoppingcart->customerinfo->country, $shoppingcart->customerinfo->zipcode, $ors[$j]);
                    }
                    $ands[$i] = array_reduce($ors, 'reduce_or', false);
                } else {
                    // echo "processing unique and rule ".$ands[$i];
                    $ands[$i] = shop_resolve_zone_rule($shopppingcart->customerinfo->country, $shopppingcart->customerinfo->zipcode, $ands[$i]);
                }
            }
            if (array_reduce($ands, 'reduce_and', true)) {
                $applicable = $z;
                break;
            } else {
                if (isset($defaultzone)) {
                    $applicable = $defaultzone;
                    break;
                }
                // in spite of shipzones found in the way, none applicable
                shop_trace("[{$transactionid}] No shipzone applicable for [$country][$zipcode]");
                // echo "no shipzone applicable ";
                $return->value = 0;
                return $return;
            }
        }
        shop_trace("[{$transactionid}] shop Shipping : Found applicable zone $applicable->zonecode ");
        // checking bill scope shipping for zone 
        if ($applicable->billscopeamount != 0) {
            shop_trace("[{$transactionid}] shop Shipping : Using bill scope amount ");
            $return->value = $applicable->billscopeamount;
            $return->code = 'SHIP_';
            $return->taxcode = $applicable->taxid;
            // calculate tax amounts
               $return->taxedvalue = shop_calculate_taxed($return->value, $applicable->taxid);
               return $return;
        }
        shop_trace("[{$transactionid}] shop Shipping : Examinating shippings");
        // examinating products
        if ($shippings = $DB->get_records('local_shop_catalogshipping', array('zoneid' => $applicable->id))) {
            $return->code = 'SHIP_';
            $return->taxcode = $applicable->taxid;
            $return->value = 0;
            foreach ($shippings as $sh) {
                $shippedproduct = $DB->get_record('local_shop_catalogitem', array('code' => $sh->productcode));
                // must be a valid product in order AND have some items required
                if (array_key_exists($shippedproduct->shortname, $order) && $order[$shippedproduct->shortname] > 0) {
                    if ($sh->value > 0) {
                        $return->value += $sh->value;
                    } else {
                        if (!empty($sh->formula)) {
                            $A = $sh->a;
                            $B = $sh->b;
                            $C = $sh->c;
                            $HT = $shippedproduct->price1;
                            $TTC = shop_calculate_taxed($shippedproduct->price1, $shippedproduct->taxcode);
                            $Q = $order[$shippedproduct->shortname];
                            eval($sh->formula.';');
                            $return->value += 0 + @$SHP;
                        } else {
                            $return->value += 0;
                        }
                    }
                }
            }
            if ($return->value > 0) {
                   $return->taxedvalue = shop_calculate_taxed($return->value, $applicable->taxid);
               } else {
                   $return->taxedvalue = 0;
               }
               return $return;
        }
        // void return if no shipping solution
        shop_trace("[{$transactionid}] shop Shipping : No shipping solution");
        // echo "no shipping solution";
        $return->value = 0;
        return $return;
    }

    function is_not_used() {
        global $DB;

        return 0 == $DB->count_records('local_shop', array('catalogid' => $this->id));
    }

    /**
     * Get all catalog items for a catalog and for given user.
     * @param int $catalogid the catalog ID
     * @param string $order the column for ordering list
     * @param string $dir the sort direction, ASC or DESC
     * @param bool $nosets if set, ignore product sets
     * @param int $userid the product owner. 0 means site owned products, null will display all products.
     */
    function get_products_by_code($order = 'code', $dir = 'ASC', $masterrecords = 0, $nosets = false, $userid = null) {
        global $DB;
    
        $nosetsql = ($nosets) ? " NOT (setid != 0 AND isset = 0) AND " : '' ;
        $useridsql = (is_null($userid)) ? '' : ' AND ci.userid = ? ';
    
        $sql = "
            SELECT
               ci.code as code,
               ci.*,
               IF(t.id IS NULL, 0, t.ratio) as tax,
               $masterrecords as masterrecord
            FROM
               {local_shop_catalogitem} as ci
            LEFT JOIN
               {local_shop_tax} as t
            ON
               ci.taxcode = t.id
            WHERE
               $nosetsql
               catalogid = ?
               $useridsql
            ORDER BY
               $order $dir
        ";

        $params = array($this->id);
        if (!empty($userid)) $params[] = $userid;

        // echo $sql;
        $allproducts = array();
        if ($catalogitems = $DB->get_records_sql($sql, $params)) {
            foreach ($catalogitems as $cirec) {
                $ci = new CatalogItem($cirec);
                $allproducts[$ci->code] = $ci;
            }
        }
        return $allproducts;
    }

    /**
    * checks in purchased products the role equipement requirement
    * @TODO : scan shoping cart and get role req info from products
    */
    function check_required_roles() {
        return array('student', '_supervisor');
    }

    /**
     * checks purchased products and quantities and calculates the neaded amount of seats.
     * We need check in catalog definition id product is seat driven or not. If seat driven
     * the quantity adds to seat couts. If not, 1 seat is added to the seat count.
     */
    function check_required_seats() {
        global $SESSION;

        $seats = 0;

        if (empty($SESSION->shoppingcart->order)) return 0;

        foreach ($SESSION->shoppingcart->order as $shortname => $quantity) {
            $product = $this->get_product_by_shortname($shortname);
            if ($product->quantaddressesusers == SHOP_QUANT_AS_SEATS) {
                $seats += $quantity;
            } elseif ($product->quantaddressesusers == SHOP_QUANT_ONE_SEAT) {
                $seats += 1;
            }
        }

        $SESSION->shoppingcart->seats = $seats;

        return $seats;
    }

    /**
     * Restricts list of available countries per catalog.
     */
    function process_country_restrictions(&$choices) {
        $restricted = array();
        if (!empty($catalog->countryrestrictions)) {
            $restrictedcountries = explode(',', $catalog->countryrestrictions);
            foreach ($restrictedcountries as $rc) {
                // blind ignore unkown codes...
                $cc = strtoupper($rc);
                if (array_key_exists($cc, $choices)) {
                    $restricted[$rc] = $choices[$cc];
                }
            }
            $choices = $restricted;
        }
    }

    /**
     * Restricts list of available countries per catalog.
     */
    static function process_merged_country_restrictions(&$choices) {
        global $DB;

        if ($DB->count_records_select('local_shop_catalog', " countryrestrictions = '' ")) {
            // Quick pass through.
            return;
        }

        $allcatalogs = $DB->get_records('local_shop_catalog', array(), 'id', 'id,countryrestrictions');

        $restrictedcountries = array();
        foreach ($allcatalogs as $c) {
            $restrictedcountries = $restrictedcountries + explode(',', $c->countryrestrictions);
        }

        $restricted = array();
        if (!empty($restrictedcpountries)) {
            foreach ($restrictedcountries as $rc) {
                // blind ignore unkown codes...
                $cc = strtoupper($rc);
                if (array_key_exists($cc, $choices)) {
                    $restricted[$rc] = $choices[$cc];
                }
            }
            $choices = $restricted;
        }
    }

    static function get_instances($filter = array(), $order = '', $fields = '*', $limitfrom = 0, $limitnum = '') {
        return parent::_get_instances(self::$table, $filter, $order, $fields, $limitfrom, $limitnum);
    }

    static function get_instances_for_admin() {
        global $DB;

        if ($instances = self::get_instances()) {
            foreach ($instances as $c) {
                $instances[$c->id]->items = CatalogItem::count(array('catalogid' => $c->id));
            }
        }

        return $instances;
    }
}