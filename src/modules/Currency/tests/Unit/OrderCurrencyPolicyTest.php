<?php

declare(strict_types=1);

use Box\Mod\Currency\OrderCurrencyPolicy;

test('new currency selections accept only USD', function (): void {
    expect(OrderCurrencyPolicy::isOrderCurrency('USD'))->toBeTrue()
        ->and(OrderCurrencyPolicy::isOrderCurrency('usd'))->toBeTrue()
        ->and(OrderCurrencyPolicy::isOrderCurrency('CNY'))->toBeFalse();

    OrderCurrencyPolicy::assertNewSelection('USD');

    expect(fn () => OrderCurrencyPolicy::assertNewSelection('CNY'))
        ->toThrow(FOSSBilling\InformationException::class, '新的价格和结算只使用美元（USD）。');
});

test('stored currency can be repeated and cannot be relabeled', function (): void {
    OrderCurrencyPolicy::assertStoredCurrency('CNY', 'cny');

    expect(fn () => OrderCurrencyPolicy::assertStoredCurrency('USD', 'CNY'))
        ->toThrow(FOSSBilling\InformationException::class, '历史记录的币种保持原样，不能改成其他币种。');
});

test('historical rate comparison treats equivalent decimals as unchanged', function (): void {
    expect(OrderCurrencyPolicy::isSameRate('6.7', '6.700000'))->toBeTrue()
        ->and(OrderCurrencyPolicy::isSameRate('6.71', '6.700000'))->toBeFalse()
        ->and(OrderCurrencyPolicy::isSameRate(null, '6.700000'))->toBeTrue();
});

test('account credit applies only when the account and the invoice are both USD', function (): void {
    expect(OrderCurrencyPolicy::canApplyBalance('USD', 'USD'))->toBeTrue()
        ->and(OrderCurrencyPolicy::canApplyBalance('usd', 'USD'))->toBeTrue()
        ->and(OrderCurrencyPolicy::canApplyBalance('CNY', 'USD'))->toBeFalse()
        ->and(OrderCurrencyPolicy::canApplyBalance('USD', 'CNY'))->toBeFalse();
});

test('customer templates do not keep source-research wording', function (): void {
    $files = [
        dirname(__DIR__, 2) . '/../Orderbutton/templates/client/mod_orderbutton_product_configuration.html.twig',
        dirname(__DIR__, 2) . '/../Product/Service.php',
        dirname(__DIR__, 2) . '/../Product/PricedOptionEngine.php',
    ];
    $banned = ['源站', '已核对', '尚未核实', 'pid 119', implode('.', ['14925', '37'])];
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        foreach ($banned as $phrase) {
            expect($text)->not->toContain($phrase);
        }
    }
});
