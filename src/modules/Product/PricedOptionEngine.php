<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Product;

use FOSSBilling\InformationException;

/**
 * Prices one-of dropdown options in integer cents.
 *
 * The same quote is used by the order-page preview and by the cart. Client
 * money fields are ignored. A snapshot taken at checkout is what renewal
 * invoices use, so a later catalog change does not rewrite an existing order.
 */
final class PricedOptionEngine
{
    public const string SNAPSHOT_KEY = 'priced_option_snapshot';
    public const string SUMMARY_KEY = 'priced_option_summary';
    public const int VERSION = 1;

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function stripClientMoney(array $input): array
    {
        foreach ([
            'price',
            'setup_price',
            'total',
            'discount',
            'discount_price',
            'discount_setup',
            'rate',
            'conversion_rate',
            self::SNAPSHOT_KEY,
            self::SUMMARY_KEY,
            'priced_options',
        ] as $key) {
            unset($input[$key]);
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function quote(array $catalog, array $input, int $baseRecurringCents, int $baseSetupCents, string $baseCurrency = 'USD'): array
    {
        if ($baseRecurringCents < 0 || $baseSetupCents < 0) {
            throw new InformationException('基础价格无效');
        }

        $groups = $catalog['groups'] ?? null;
        if (!is_array($groups) || $groups === []) {
            throw new InformationException('该产品没有可计价的配置项');
        }

        $period = strtoupper(trim((string) ($input['period'] ?? '')));
        if ($period === '') {
            throw new InformationException('请选择计费周期');
        }
        $months = self::periodMonths($period);

        $rawOptions = $input['options'] ?? [];
        if (!is_array($rawOptions)) {
            throw new InformationException('配置选项无效');
        }

        $known = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                throw new InformationException('配置选项无效');
            }
            self::assertGroupBillable($group, $baseCurrency, $period);
            $known[(string) $group['code']] = true;
        }
        foreach (array_keys($rawOptions) as $code) {
            if (!isset($known[(string) $code])) {
                throw new InformationException('所选配置不属于该产品');
            }
        }

        $selections = [];
        $normalized = [];
        $optionRecurring = 0;
        $optionSetup = 0;

        foreach ($groups as $group) {
            $code = (string) $group['code'];
            $picked = array_key_exists($code, $rawOptions)
                ? self::normalizeSelection($rawOptions[$code], $group)
                : [self::defaultValue($group)];

            $count = count($picked);
            $min = (int) $group['min_count'];
            $max = (int) $group['max_count'];
            if ($count < $min || $count > $max) {
                throw new InformationException('选项数量超出允许范围');
            }

            $codes = [];
            foreach ($picked as $value) {
                self::assertValueStock($value);
                $monthly = (int) $value['monthly_cents'];
                $setup = (int) $value['setup_cents'];
                if ($monthly < 0 || $setup < 0) {
                    throw new InformationException('配置价格无效');
                }
                $recurring = $monthly * $months;
                $optionRecurring += $recurring;
                $optionSetup += $setup;
                $selections[] = [
                    'group_code' => $code,
                    'group_label' => (string) $group['label'],
                    'value_code' => (string) $value['code'],
                    'value_label' => (string) $value['label'],
                    'monthly_cents' => $monthly,
                    'setup_cents' => $setup,
                    'recurring_cents' => $recurring,
                ];
                $codes[] = (string) $value['code'];
            }
            $normalized[$code] = $max === 1 ? $codes[0] : $codes;
        }

        $recurring = $baseRecurringCents + $optionRecurring;
        $setup = $baseSetupCents + $optionSetup;

        return [
            'currency' => strtoupper($baseCurrency),
            'period' => $period,
            'period_months' => $months,
            'base_recurring_cents' => $baseRecurringCents,
            'base_setup_cents' => $baseSetupCents,
            'option_recurring_cents' => $optionRecurring,
            'option_setup_cents' => $optionSetup,
            'recurring_cents' => $recurring,
            'setup_cents' => $setup,
            'normalized_options' => $normalized,
            'selections' => $selections,
            'lines' => self::previewLines($baseRecurringCents, $baseSetupCents, $selections),
        ];
    }

    public static function assertPurchasable(bool $stockControl, int $quantityInStock, int $requested): void
    {
        if ($requested < 1) {
            throw new InformationException('数量无效');
        }
        if ($stockControl && $quantityInStock < $requested) {
            throw new InformationException('待资源就绪，暂不可订购');
        }
    }

    public static function amountToCents(float|int|string $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new InformationException('金额无效');
            }
            $amount = sprintf('%.2f', $amount);
        }
        $amount = trim($amount);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new InformationException('金额无效');
        }
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    public static function centsToAmount(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $amount = intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-' . $amount : $amount;
    }

    public static function convertCents(int $cents, string $rate): int
    {
        $rate = trim($rate);
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/', $rate)) {
            throw new InformationException('汇率无效');
        }
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 6), 6, '0');
        $rateMicros = ((int) $whole * 1000000) + (int) $fraction;
        $negative = $cents < 0;
        $product = abs($cents) * $rateMicros;
        $converted = intdiv($product + 500000, 1000000);

        return $negative ? -$converted : $converted;
    }

    /**
     * @param array<string, mixed> $quote
     *
     * @return array<string, mixed>
     */
    public static function snapshotFromQuote(array $quote): array
    {
        return [
            'version' => self::VERSION,
            'currency' => $quote['currency'],
            'period' => $quote['period'],
            'period_months' => $quote['period_months'],
            'base_recurring_cents' => $quote['base_recurring_cents'],
            'base_setup_cents' => $quote['base_setup_cents'],
            'option_recurring_cents' => $quote['option_recurring_cents'],
            'option_setup_cents' => $quote['option_setup_cents'],
            'recurring_cents' => $quote['recurring_cents'],
            'setup_cents' => $quote['setup_cents'],
            'selections' => $quote['selections'],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    public static function charge(array $snapshot, string $currency, string $rate, int $quantity): array
    {
        $quantity = max(1, $quantity);
        $recurringParts = [['base', (int) $snapshot['base_recurring_cents'], '基础价格', 'recurring']];
        $setupParts = [];
        if ((int) $snapshot['base_setup_cents'] > 0) {
            $setupParts[] = ['base-setup', (int) $snapshot['base_setup_cents'], '基础开通费', 'setup'];
        }
        foreach ($snapshot['selections'] as $selection) {
            if (!is_array($selection)) {
                throw new InformationException('配置快照无效');
            }
            $recurring = (int) $selection['recurring_cents'];
            $setup = (int) $selection['setup_cents'];
            $label = $selection['group_label'] . '：' . $selection['value_label'];
            if ($recurring > 0) {
                $recurringParts[] = [(string) $selection['group_code'], $recurring, $label, 'recurring'];
            }
            if ($setup > 0) {
                $setupParts[] = [(string) $selection['group_code'] . '-setup', $setup, '开通费：' . $label, 'setup'];
            }
        }

        $recurringAllocated = self::allocateConverted(array_map(static fn (array $part): int => $part[1], $recurringParts), $rate);
        $setupAllocated = self::allocateConverted(array_map(static fn (array $part): int => $part[1], $setupParts), $rate);

        $lines = [];
        foreach ($recurringParts as $index => $part) {
            $lines[] = [
                'code' => $part[0],
                'title' => $part[2],
                'billing' => 'recurring',
                'unit_cents' => $recurringAllocated[$index],
                'quantity' => $quantity,
                'activate' => $index === 0,
            ];
        }
        foreach ($setupParts as $index => $part) {
            $lines[] = [
                'code' => $part[0],
                'title' => $part[2],
                'billing' => 'setup',
                'unit_cents' => $setupAllocated[$index],
                'quantity' => 1,
                'activate' => false,
            ];
        }

        $unitRecurring = array_sum($recurringAllocated);
        $setupTotal = array_sum($setupAllocated);
        $chargedSnapshot = $snapshot;
        $chargedSnapshot['charged'] = [
            'currency' => strtoupper($currency),
            'rate' => $rate,
            'unit_recurring_cents' => $unitRecurring,
            'setup_cents' => $setupTotal,
            'lines' => $lines,
        ];

        return [
            'currency' => strtoupper($currency),
            'rate' => $rate,
            'unit_recurring_cents' => $unitRecurring,
            'setup_cents' => $setupTotal,
            'lines' => $lines,
            'snapshot' => $chargedSnapshot,
        ];
    }

    /**
     * @param array<string, mixed> $orderConfig
     *
     * @return list<array<string, mixed>>|null
     */
    public static function renewalLines(array $orderConfig, int $orderUnitPriceCents, ?string $orderCurrency = null): ?array
    {
        $snapshot = self::decodeSnapshot($orderConfig[self::SNAPSHOT_KEY] ?? null);
        if ($snapshot === null || !is_array($snapshot['charged'] ?? null)) {
            return null;
        }
        $charged = $snapshot['charged'];
        if ($orderCurrency !== null && strtoupper((string) ($charged['currency'] ?? '')) !== strtoupper($orderCurrency)) {
            return null;
        }
        if ((int) ($charged['unit_recurring_cents'] ?? -1) !== $orderUnitPriceCents) {
            return null;
        }

        $lines = [];
        foreach ($charged['lines'] ?? [] as $line) {
            if (!is_array($line) || ($line['billing'] ?? '') !== 'recurring') {
                continue;
            }
            $lines[] = $line;
        }
        if ($lines === []) {
            return null;
        }
        $sum = 0;
        foreach ($lines as $line) {
            $sum += (int) $line['unit_cents'];
        }
        if ($sum !== $orderUnitPriceCents) {
            return null;
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decodeSnapshot(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($value) || (int) ($value['version'] ?? 0) !== self::VERSION) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function summary(array $snapshot): string
    {
        $currency = (string) ($snapshot['charged']['currency'] ?? $snapshot['currency'] ?? 'USD');
        $lines = ['已选配置（' . $currency . '）'];
        $useCharged = is_array($snapshot['charged'] ?? null);
        if ($useCharged) {
            $lines[] = '基础价格：' . self::centsToAmount((int) $snapshot['charged']['lines'][0]['unit_cents']) . ' ' . $currency;
        } else {
            $lines[] = '基础价格：' . self::centsToAmount((int) $snapshot['base_recurring_cents']) . ' ' . $currency;
        }
        foreach ($snapshot['selections'] as $selection) {
            if (!is_array($selection)) {
                continue;
            }
            $lines[] = $selection['group_label'] . '：' . $selection['value_label']
                . '；周期加价 ' . self::centsToAmount((int) $selection['recurring_cents']) . ' USD'
                . '；开通费 ' . self::centsToAmount((int) $selection['setup_cents']) . ' USD';
        }
        if ($useCharged) {
            $lines[] = '本期周期费用合计：' . self::centsToAmount((int) $snapshot['charged']['unit_recurring_cents']) . ' ' . $currency;
            $lines[] = '一次性开通费合计：' . self::centsToAmount((int) $snapshot['charged']['setup_cents']) . ' ' . $currency . '（不计入续费）';
        } else {
            $lines[] = '周期费用合计：' . self::centsToAmount((int) $snapshot['recurring_cents']) . ' ' . $currency;
            $lines[] = '一次性开通费合计：' . self::centsToAmount((int) $snapshot['setup_cents']) . ' ' . $currency . '（不计入续费）';
        }
        $lines[] = '以上金额由服务器按目录价计算，不采用客户端提交的价格。';

        return implode("\n", $lines);
    }

    /**
     * @param list<int> $usdCents
     *
     * @return list<int>
     */
    public static function allocateConverted(array $usdCents, string $rate): array
    {
        if ($usdCents === []) {
            return [];
        }
        $target = self::convertCents(array_sum($usdCents), $rate);
        $allocated = [];
        $running = 0;
        $last = count($usdCents) - 1;
        foreach ($usdCents as $index => $cents) {
            if ($index === $last) {
                $allocated[] = $target - $running;

                continue;
            }
            $converted = self::convertCents($cents, $rate);
            $allocated[] = $converted;
            $running += $converted;
        }

        return $allocated;
    }

    /**
     * @param array<string, mixed> $group
     */
    private static function assertGroupBillable(array $group, string $baseCurrency, string $period): void
    {
        if (!($group['verified'] ?? false)) {
            throw new InformationException('该配置项尚未核实，不能计价');
        }
        if (!($group['active'] ?? true)) {
            throw new InformationException('该配置项尚未核实，不能计价');
        }
        $currency = strtoupper((string) ($group['currency'] ?? ''));
        if ($currency !== strtoupper($baseCurrency)) {
            throw new InformationException('币种与配置价格不匹配');
        }
        $periods = array_map(static fn (mixed $item): string => strtoupper((string) $item), $group['periods'] ?? []);
        if (!in_array($period, $periods, true)) {
            throw new InformationException('计费周期与配置价格不匹配');
        }
    }

    private static function periodMonths(string $period): int
    {
        return match ($period) {
            '1M' => 1,
            '3M' => 3,
            '6M' => 6,
            '12M', '1Y' => 12,
            '2Y' => 24,
            '3Y' => 36,
            default => throw new InformationException('计费周期与配置价格不匹配'),
        };
    }

    /**
     * @param array<string, mixed> $group
     *
     * @return array<string, mixed>
     */
    private static function defaultValue(array $group): array
    {
        foreach ($group['values'] as $value) {
            if (is_array($value) && ($value['is_default'] ?? false) && ($value['active'] ?? true)) {
                return $value;
            }
        }

        throw new InformationException('该配置项没有默认值');
    }

    /**
     * @param array<string, mixed> $group
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeSelection(mixed $raw, array $group): array
    {
        if (is_array($raw)) {
            if (!array_is_list($raw)) {
                throw new InformationException('配置选项无效');
            }
            $items = $raw;
        } else {
            $items = [$raw];
        }
        if ($items === []) {
            throw new InformationException('选项数量超出允许范围');
        }

        $picked = [];
        foreach ($items as $item) {
            if (is_array($item) || is_object($item)) {
                throw new InformationException('配置选项无效');
            }
            $picked[] = self::resolveValue($item, $group);
        }

        return $picked;
    }

    /**
     * @param array<string, mixed> $group
     *
     * @return array<string, mixed>
     */
    private static function resolveValue(mixed $raw, array $group): array
    {
        $needle = trim((string) $raw);
        if ($needle === '') {
            throw new InformationException('所选配置不属于该产品');
        }
        foreach ($group['values'] as $value) {
            if (!is_array($value) || !($value['active'] ?? true)) {
                continue;
            }
            if (ctype_digit($needle) && isset($value['id']) && (int) $value['id'] === (int) $needle) {
                return $value;
            }
            if ((string) $value['code'] === $needle) {
                return $value;
            }
        }

        throw new InformationException('所选配置不属于该产品');
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function assertValueStock(array $value): void
    {
        if (($value['stock_control'] ?? false) && (int) ($value['quantity_in_stock'] ?? 0) < 1) {
            throw new InformationException('该配置项库存不足');
        }
    }

    /**
     * @param list<array<string, mixed>> $selections
     *
     * @return list<array<string, mixed>>
     */
    private static function previewLines(int $baseRecurringCents, int $baseSetupCents, array $selections): array
    {
        $lines = [[
            'label' => '基础价格',
            'cents' => $baseRecurringCents,
            'billing' => 'recurring',
        ]];
        if ($baseSetupCents > 0) {
            $lines[] = [
                'label' => '基础开通费',
                'cents' => $baseSetupCents,
                'billing' => 'setup',
            ];
        }
        foreach ($selections as $selection) {
            $lines[] = [
                'label' => $selection['group_label'] . '：' . $selection['value_label'],
                'cents' => $selection['recurring_cents'],
                'billing' => 'recurring',
            ];
            if ($selection['setup_cents'] > 0) {
                $lines[] = [
                    'label' => '开通费：' . $selection['group_label'] . '：' . $selection['value_label'],
                    'cents' => $selection['setup_cents'],
                    'billing' => 'setup',
                ];
            }
        }

        return $lines;
    }
}
