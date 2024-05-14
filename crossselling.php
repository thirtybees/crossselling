<?php
/**
 * Copyright (C) 2017-2024 thirty bees
 * Copyright (C) 2007-2016 PrestaShop SA
 *
 * thirty bees is an extension to the PrestaShop software by PrestaShop SA.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017-2024 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark of PrestaShop SA.
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

class CrossSelling extends Module
{
    /** Configuration keys */
    const SETTINGS_NUMBER_OF_PRODUCTS = 'CROSSSELLING_NBR';
    const SETTINGS_DISPLAY_PRICE = 'CROSSSELLING_DISPLAY_PRICE';
    const SETTINGS_LAST_UPDATE = 'CROSSSELLING_LAST_UPDATE_TS';

    /** Entry form input fields */
    const INPUT_NUMBER_OF_PRODUCTS = 'number_of_products';
    const INPUT_DISPLAY_PRICE = 'display_price';
    const SUBMIT_ACTION = 'submitCross';

    /** How often should we attempt to update cache table, default 5 minutes */
    const UPDATE_PERIOD = 60 * 5;

    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'crossselling';
        $this->tab = 'front_office_features';
        $this->version = '2.1.0';
        $this->author = 'thirty bees';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Block Cross Selling');
        $this->description = $this->l('Adds a "Customers who bought this product also bought..." section to every product page.');
        $this->tb_versions_compliancy = '> 1.0.0';
        $this->tb_min_version = '1.0.0';
        $this->ps_versions_compliancy = ['min' => '1.5.6.1', 'max' => '1.6.99.99'];
    }

    /**
     * @return bool
     *
     * @throws PrestaShopException
     */
    public function install($createTables = true)
    {
        return (
            parent::install() &&
            $this->installDb($createTables) &&
            $this->registerHook('productFooter') &&
            $this->registerHook('header') &&
            $this->registerHook('shoppingCart') &&
            $this->registerHook('actionOrderStatusPostUpdate')
         );
    }

    /**
     * @param bool $full
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function uninstall($full = true)
    {
        Configuration::deleteByName(static::SETTINGS_DISPLAY_PRICE);
        Configuration::deleteByName(static::SETTINGS_NUMBER_OF_PRODUCTS);
        Configuration::deleteByName(static::SETTINGS_LAST_UPDATE);

        return (
            parent::uninstall() &&
            $this->uninstallDb($full)
        );
    }

    /**
     * @return bool
     *
     * @throws PrestaShopException
     */
    public function reset()
    {
        return (
            $this->uninstall(false) &&
            $this->install(false)
        );
    }

    /**
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        /** @var AdminController $controller */
        $controller = $this->context->controller;

        $html = '';

        if (Tools::isSubmit(static::SUBMIT_ACTION)) {
            $displayPrice = (bool)Tools::getValue(static::INPUT_DISPLAY_PRICE);
            $numberOfProducts = $this->sanitizeNumberOfProducts((int)Tools::getValue(static::INPUT_NUMBER_OF_PRODUCTS));
            Configuration::updateValue(static::SETTINGS_DISPLAY_PRICE, $displayPrice ? 1 : 0);
            Configuration::updateValue(static::SETTINGS_NUMBER_OF_PRODUCTS, $numberOfProducts);

            $this->_clearCache('crossselling.tpl');
            $html = $this->displayConfirmation($this->l('Settings updated successfully'));
        }

        return (
            $html .
            $this->renderForm($controller)
        );
    }

    /**
     * @return void
     */
    public function hookHeader()
    {
        if (!isset($this->context->controller->php_self) || !in_array($this->context->controller->php_self, ['product', 'order', 'order-opc'])) {
            return;
        }

        if (in_array($this->context->controller->php_self, ['order']) && Tools::getValue('step')) {
            return;
        }

        $this->context->controller->addCSS(($this->_path).'css/crossselling.css', 'all');
        $this->context->controller->addJS(($this->_path).'js/crossselling.js');
        $this->context->controller->addJqueryPlugin(['scrollTo', 'serialScroll', 'bxslider']);
    }

    /**
     * Updates related products table
     *
     * @param bool $full
     *
     * @return void
     *
     * @throws PrestaShopException
     */
    protected function updateRelatedProducts($full = false)
    {
        $lockName = 'crosseling_update';
        $conn = Db::getInstance();
        $success = (int)$conn->getValue("SELECT GET_LOCK('$lockName', 1) as `success`");
        if (! $success) {
            return;
        }

        try {
            Configuration::updateGlobalValue(static::SETTINGS_LAST_UPDATE, time());

            if ($full) {
                $conn->delete('crossselling_processed_order');
                $conn->delete('crossselling_product_pair');
            }

            $lockOrders = (new DbQuery())
                ->select('o.id_order')
                ->from('orders', 'o')
                ->where('o.valid')
                ->where('NOT EXISTS(SELECT 1 FROM ' . _DB_PREFIX_ . 'crossselling_processed_order po WHERE po.id_order = o.id_order)')
                ->orderBy('o.id_order');
            $orders = $conn->executeS($lockOrders);
            if (!$orders) {
                return;
            }

            $orderIds = array_map('intval', array_column($orders, 'id_order'));
            $orderGroups = array_chunk($orderIds, 50);
            foreach ($orderGroups as $orderGroup) {
                if ($orderGroup) {
                    $conn->execute("INSERT IGNORE INTO " . _DB_PREFIX_ . "crossselling_processed_order(id_order) VALUES (" . implode('),(', $orderGroup) . ")");

                    $sql = (new DbQuery())
                        ->select('o.id_shop')
                        ->select('p1.id_product AS id_product_1')
                        ->select('p2.id_product AS id_product_2')
                        ->select('COUNT(1) as cnt')
                        ->from('orders', 'o')
                        ->innerJoin('order_detail', 'od1', 'od1.id_order = o.id_order')
                        ->innerJoin('product', 'p1', 'p1.id_product = od1.product_id')
                        ->innerJoin('order_detail', 'od2', 'od2.id_order = o.id_order')
                        ->innerJoin('product', 'p2', 'p2.id_product = od2.product_id')
                        ->where('od1.id_order_detail != od2.id_order_detail')
                        ->where('p1.id_product != p2.id_product')
                        ->where('o.id_order IN (' . implode(',', $orderGroup) . ')')
                        ->groupBy('o.id_shop, p1.id_product, p2.id_product');

                    $conn->execute(
                        "INSERT INTO " . _DB_PREFIX_ . "crossselling_product_pair(id_shop, id_product_1, id_product_2, cnt) " .
                        $sql .
                        " ON DUPLICATE KEY UPDATE cnt = cnt + VALUES(cnt)"
                    );
                }
            }
        } finally {
            $conn->execute("SELECT RELEASE_LOCK('$lockName')");
        }
    }

    /**
     * Returns recommended products for $productIds
     *
     * @param array $productIds an array of product ids
     *
     * @return array
     *
     * @throws PrestaShopException
     */
    protected function getOrderProducts(array $productIds)
    {
        $productIds = array_filter(array_map('intval', $productIds));
        if (!$productIds) {
            return [];
        }

        // incremental update of related products
        $lastUpdateTs = (int)Configuration::getGlobalValue(static::SETTINGS_LAST_UPDATE);
        if (time() > ($lastUpdateTs + static::UPDATE_PERIOD)) {
            $this->updateRelatedProducts();
        }

        $conn = Db::getInstance();
        $langId = (int)$this->context->language->id;

        $implodedProductIds = implode(',', $productIds);
        $selectedProductSql = (new DbQuery())
            ->select('p.id_product_2, SUM(p.cnt) as total_cnt')
            ->from('crossselling_product_pair', 'p')
            ->innerJoin('product_shop', 'ps', '(ps.id_product = p.id_product_2 AND ps.id_shop = p.id_shop)')
            ->where('p.id_shop = ' . (int)Context::getContext()->shop->id)
            ->where("p.id_product_1 IN ($implodedProductIds)")
            ->where("p.id_product_2 NOT IN ($implodedProductIds)")
            ->where('ps.active')
            ->where('ps.visibility IN ("both", "catalog")')
            ->groupBy('p.id_product_2')
            ->orderBy('total_cnt DESC')
            ->limit($this->getNumberOfProducts());
        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();
            if (!$groups) {
                $groups = [(int)Group::getCurrent()->id];
            }
            $subQuery = (new DbQuery())
                ->select('1')
                ->from('category_product', 'cp')
                ->innerJoin('category_group', 'cg', '(cp.id_category = cg.id_category)')
                ->where('cp.id_category = ps.id_category_default')
                ->where('cp.id_product = ps.id_product')
                ->where('cg.id_group IN (' . implode(',', $groups) . ')');
            $selectedProductSql->where("EXISTS($subQuery)");
        }

        $selectedProducts = $conn->executeS($selectedProductSql);
        if (!$selectedProducts) {
            return [];
        }

        $link = $this->context->link;
        $displayPrice = $this->displayPrice();
        $priceWithTax = Product::getTaxCalculationMethod() === PS_TAX_INC;

        $result = [];
        foreach ($selectedProducts as $row) {
            $productId = (int)$row['id_product_2'];
            $obj = new Product($productId, false, $langId);
            $obj->loadStockData();
            $cover = Image::getCover($productId);
            if ($cover) {
                $imageId = $cover['id_image'];
            } else {
                $imageId = 0;
            }
            $product = [
                'id_product' => $productId,
                'name' => $obj->name,
                'description' => $obj->description_short,
                'link_rewrite' => $obj->link_rewrite,
                'show_price' => $obj->show_price,
                'category' => Category::getLinkRewrite((int)$obj->id_category_default, $langId),
                'ean13' => $obj->ean13,
                'id_image' => $imageId,
                'image' => $link->getImageLink($obj->link_rewrite, $imageId, 'home'),
                'link' => $link->getProductLink($obj),
            ];
            if ($displayPrice) {
                $product['displayed_price'] = Product::getPriceStatic($productId, $priceWithTax, null);
            }
            $product['allow_oosp'] = Product::isAvailableWhenOutOfStock($obj->out_of_stock);
            $product['quantity'] = $obj->quantity;
            $result[$productId . '-' . $imageId] = $product;
        }

        return $result;
    }

    /**
     * Returns module content
     *
     * @param array $params
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookShoppingCart($params)
    {
        if (!$params['products']) {
            return null;
        }

        $products = [];
        foreach ($params['products'] as $product) {
            $productId = (int)$product['id_product'];
            if ($productId) {
                $products[] = $productId;
            }
        }
        $products = array_unique($products, SORT_NUMERIC);
        $cacheId = 'crossselling|shoppingcart|'.md5(implode('|', $products));

        return $this->renderProductList($cacheId, $products);
    }

    /**
     * @param array $params
     *
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookProductTabContent($params)
    {
        return $this->hookProductFooter($params);
    }

    /**
     * @param array $params
     *
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function displayProductListReviews($params)
    {
        return $this->hookProductFooter($params);
    }

    /**
     * Returns module content for product footer
     *
     * @param array $params
     *
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookProductFooter($params)
    {
        $productId = (int)$params['product']->id;
        $cacheId = 'crossselling|productfooter|' . $productId;
        $productIds = [ $productId ];

        return $this->renderProductList($cacheId, $productIds);
    }

    /**
     * @param array $params
     *
     * @return void
     * @throws PrestaShopException
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->_clearCache('crossselling.tpl');
    }

    /**
     * @param AdminController $controller
     *
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderForm($controller)
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Display price on products'),
                        'name' => static::INPUT_DISPLAY_PRICE,
                        'desc' => $this->l('Show the price on the products in the block.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            ]
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Number of displayed products'),
                        'name' => static::INPUT_NUMBER_OF_PRODUCTS,
                        'class' => 'fixed-width-xs',
                        'desc' => $this->l('Set the number of products displayed in this block.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ]
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = static::SUBMIT_ACTION;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab
            .'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    public function getConfigFieldsValues()
    {
        return [
            static::INPUT_NUMBER_OF_PRODUCTS => $this->getNumberOfProducts(),
            static::INPUT_DISPLAY_PRICE => $this->displayPrice(),
        ];
    }

    /**
     * @param bool $create
     *
     * @return bool
     *
     * @throws PrestaShopException
     */
    private function installDb($create)
    {
        if (!$create) {
            return true;
        }
        return $this->executeSqlScript('install');
    }

    /**
     * @param bool $drop
     *
     * @return bool
     *
     * @throws PrestaShopException
     */
    private function uninstallDb($drop)
    {
        if (!$drop) {
            return true;
        }
        return $this->executeSqlScript('uninstall', false);
    }

    /**
     * @param string $script
     * @param bool $check
     *
     * @return bool
     *
     * @throws PrestaShopException
     */
    public function executeSqlScript($script, $check = true)
    {
        $file = dirname(__FILE__) . '/sql/' . $script . '.sql';
        if (!file_exists($file)) {
            return false;
        }
        $sql = file_get_contents($file);
        if (!$sql) {
            return false;
        }

        $sql = str_replace(
            [
                'PREFIX_',
                'ENGINE_TYPE',
                'CHARSET_TYPE',
                'COLLATE_TYPE',
            ],
            [
                _DB_PREFIX_,
                _MYSQL_ENGINE_,
                'utf8mb4',
                'utf8mb4_unicode_ci',
            ],
            $sql
        );
        $sql = preg_split("/;\s*[\r\n]+/", $sql);
        foreach ($sql as $statement) {
            $stmt = trim($statement);
            if ($stmt) {
                try {
                    if (!Db::getInstance()->execute($stmt)) {
                        PrestaShopLogger::addLog($this->name . ": sql script $script: $stmt: error");
                        if ($check) {
                            return false;
                        }
                    }
                } catch (Exception $e) {
                    PrestaShopLogger::addLog($this->name . ": sql script $script: $stmt: $e");
                    if ($check) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * @return int
     *
     * @throws PrestaShopException
     */
    protected function getNumberOfProducts()
    {
        return $this->sanitizeNumberOfProducts((int)Configuration::get(static::SETTINGS_NUMBER_OF_PRODUCTS));
    }

    /**
     * @param $value
     *
     * @return int
     */
    protected function sanitizeNumberOfProducts($value)
    {
        $value = (int)$value;
        if ($value <= 0) {
            return 10;
        }
        return $value;
    }

    /**
     * @return bool
     * @throws PrestaShopException
     */
    protected function displayPrice()
    {
        return (bool)Configuration::get(static::SETTINGS_DISPLAY_PRICE);
    }

    /**
     * @param string $cacheId
     * @param array $productIds
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    protected function renderProductList($cacheId, $productIds): string
    {
        if (!$this->isCached('crossselling.tpl', $this->getCacheId($cacheId))) {
            $products = $this->getOrderProducts($productIds);
            if ($products) {
                $this->smarty->assign([
                    'orderProducts' => $products,
                    'middlePosition_crossselling' => round(count($products) / 2, 0),
                    'crossDisplayPrice' => $this->displayPrice(),
                ]);
            }
        }
        return $this->display(__FILE__, 'crossselling.tpl', $this->getCacheId($cacheId));
    }

}
