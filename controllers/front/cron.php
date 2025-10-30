<?php
/**
 * Copyright (C) 2025-2025 thirty bees
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
 * @copyright 2025-2025 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

class CrossSellingCronModuleFrontController extends ModuleFrontController
{

    /** @var CrossSelling */
    public $module;

    /**
     * @throws PrestaShopException
     */
    public function initContent()
    {
        if (Tools::getValue('secure_key') === $this->module->getCronSecret()) {
            @set_time_limit(0);
            $interactive = !!Tools::getValue('sync');
            if (! $interactive) {
                ob_start();

                echo $this->module->name . ' cron';
                header('Connection: close');
                header('Content-Length: ' . ob_get_length());

                // flush
                if (ob_get_length() > 0) {
                    ob_end_flush();
                }
                flush();

                // abort
                ignore_user_abort(true);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
            }
            $this->module->processCron();
            die();
        }
        die('error');
    }

}
