CREATE TABLE IF NOT EXISTS `PREFIX_crossselling_processed_order` (
    `id_order` INT(11) UNSIGNED NOT NULL,
    PRIMARY KEY (`id_order`),
    FOREIGN KEY `cs_po_o` (`id_order`) REFERENCES `PREFIX_orders`(`id_order`) ON DELETE CASCADE
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE COLLATE=COLLATE_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_crossselling_product_pair` (
    `id_shop` INT(11) UNSIGNED NOT NULL,
    `id_product_1` INT(11) UNSIGNED NOT NULL,
    `id_product_2` INT(11) UNSIGNED NOT NULL,
    `cnt` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_shop`, `id_product_1`, `id_product_2`),
    FOREIGN KEY `cs_pp_p1` (`id_product_1`) REFERENCES `PREFIX_product`(`id_product`) ON DELETE CASCADE,
    FOREIGN KEY `cs_pp_p2` (`id_product_2`) REFERENCES `PREFIX_product`(`id_product`) ON DELETE CASCADE
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE COLLATE=COLLATE_TYPE;