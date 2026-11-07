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

    /**
     * A saved financial row may be edited only when its currency is left unchanged.
     * An omitted value is not a change; the caller keeps the stored code.
     */
    public static function assertStoredCurrency(?string $posted, ?string $stored): void
    {
        if (self::normalize($posted) !== self::normalize($stored)) {
            throw new InformationException('历史记录的币种保持原样，不能改成其他币种。');
        }
    }

    public static function isSameRate(string|float|null $posted, string|float|null $stored): bool
    {
        if ($posted === null || $posted === '') {
            return true;
        }
        if (!is_numeric($posted) || !is_numeric((string) $stored)) {
            return false;
        }

        return abs((float) $posted - (float) $stored) < 0.0000001;
    }
}
