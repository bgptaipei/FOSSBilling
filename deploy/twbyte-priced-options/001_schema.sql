-- Priced dropdown options. Additive only: new tables, no changes to
-- product, product_payment, client_order, invoice_item, or currency.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `product_option_group` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) NOT NULL,
  `code` varchar(64) NOT NULL,
  `label` varchar(255) NOT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `min_count` int(11) NOT NULL DEFAULT 1,
  `max_count` int(11) NOT NULL DEFAULT 1,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `periods` varchar(64) NOT NULL DEFAULT '1M',
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_option_group_product_code` (`product_id`, `code`),
  KEY `product_option_group_product_idx` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_option_value` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `group_id` bigint(20) NOT NULL,
  `code` varchar(64) NOT NULL,
  `label` varchar(255) NOT NULL,
  `monthly_price_cents` int(11) NOT NULL DEFAULT 0,
  `setup_price_cents` int(11) NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `stock_control` tinyint(1) NOT NULL DEFAULT 0,
  `quantity_in_stock` int(11) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_option_value_group_code` (`group_id`, `code`),
  KEY `product_option_value_group_idx` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
