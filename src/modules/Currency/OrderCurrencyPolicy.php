<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Currency;

use FOSSBilling\InformationException;

/**
 * New prices, carts, and accounts use USD.
 * Existing CNY balances and transactions are not converted here.
 */
final class OrderCurrencyPolicy
{
    public const string CODE = 'USD';

    public static function normalize(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    public static function isOrderCurrency(?string $code): bool
    {
        return self::normalize($code) === self::CODE;
    }

    public static function assertNewSelection(?string $code): void
    {
        if (!self::isOrderCurrency($code)) {
            throw new InformationException('新的价格和结算只使用美元（USD）。');
        }
    }

    /**
     * Account credit may pay an invoice only when both sides are already USD.
     * A historical balance is not converted to cover a different currency.
     */
    public static function canApplyBalance(?string $accountCurrency, ?string $invoiceCurrency): bool
    {
        return self::isOrderCurrency($accountCurrency) && self::isOrderCurrency($invoiceCurrency);
    }
}
