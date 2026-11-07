-- Removes priced-option catalogs only.
-- Does not delete or rewrite client_order, invoice_item, product, or product_payment.
-- Orders already placed keep their config JSON and the price stored on the order.
-- After dropping these tables, the previous application code ignores the extra config keys.

DROP TABLE IF EXISTS `product_option_value`;
DROP TABLE IF EXISTS `product_option_group`;
