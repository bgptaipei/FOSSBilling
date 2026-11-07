<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use Box\Mod\Product\Entity\Product;
use Box\Mod\Product\Entity\ProductPayment;
use Box\Mod\Product\PricedOptionEngine;
use Box\Mod\Product\Repository\ProductPaymentRepository;
use Box\Mod\Product\Service;

use function Tests\Helpers\container;

function pricedOptionDocument(): array
{
    $path = dirname(__DIR__, 2) . '/resources/hk7-priced-options.json';
    $document = json_decode((string) file_get_contents($path), true);
    expect($document)->toBeArray();

    return $document;
}

function pricedOptionCatalog(string $slug): array
{
    foreach (pricedOptionDocument()['products'] as $product) {
        if ($product['slug'] === $slug) {
            return ['groups' => $product['groups']];
        }
    }

    throw new RuntimeException('Missing verified product ' . $slug);
}

function pricedOptionProduct(string $slug): array
{
    foreach (pricedOptionDocument()['products'] as $product) {
        if ($product['slug'] === $slug) {
            return $product;
        }
    }

    throw new RuntimeException('Missing verified product ' . $slug);
}

function pricedOptionValueId(string $slug, string $groupCode, string $valueCode): int
{
    foreach (pricedOptionCatalog($slug)['groups'] as $group) {
        if ($group['code'] !== $groupCode) {
            continue;
        }
        foreach ($group['values'] as $value) {
            if ($value['code'] === $valueCode) {
                return (int) $value['id'];
            }
        }
    }

    throw new RuntimeException("Missing {$slug} {$groupCode} {$valueCode}");
}

test('verified defaults match the USD list price and have no setup fee', function (): void {
    foreach (pricedOptionDocument()['products'] as $product) {
        $quote = PricedOptionEngine::quote(
            ['groups' => $product['groups']],
            ['period' => '1M'],
            $product['base_monthly_cents'],
            0
        );

        expect($quote['recurring_cents'])->toBe($product['base_monthly_cents'])
            ->and($quote['setup_cents'])->toBe(0)
            ->and($quote['option_recurring_cents'])->toBe(0)
            ->and($quote['option_setup_cents'])->toBe(0);
    }
});

test('multiple HK7 options keep recurring and setup fees separate', function (): void {
    $product = pricedOptionProduct('mc-hk-cia-hpc-amd');
    $quote = PricedOptionEngine::quote(
        ['groups' => $product['groups']],
        [
            'period' => '1M',
            'price' => '0.01',
            'rate' => '0.01',
            'options' => [
                'bandwidth' => 'cia-20m',
                'ip' => 'ip-3',
                'vcpu' => 'vcpu-8',
                'memory' => 'ram-8g',
                'os_disk' => 'sys-90g',
                'data_disk' => 'data-100g',
                'snapshot' => 'snap-1',
                'backup' => 'bak-2',
                'antiddos' => 'ddos-5g',
                'os' => 'ubuntu',
            ],
        ],
        $product['base_monthly_cents'],
        0
    );

    expect($quote['option_recurring_cents'])->toBe(25360)
        ->and($quote['recurring_cents'])->toBe(34160)
        ->and($quote['setup_cents'])->toBe(3900)
        ->and($quote['option_setup_cents'])->toBe(3900);

    $recurring = 0;
    $setup = 0;
    foreach ($quote['lines'] as $line) {
        if ($line['billing'] === 'recurring') {
            $recurring += $line['cents'];
        } else {
            $setup += $line['cents'];
        }
    }
    expect($recurring)->toBe($quote['recurring_cents'])
        ->and($setup)->toBe($quote['setup_cents']);
});

test('live preview and checkout charge the same canonical cents', function (): void {
    $product = pricedOptionProduct('mc-hk-cia-hpc-amd');
    $input = [
        'period' => '1M',
        'options' => [
            'bandwidth' => 'cia-40m',
            'ip' => 'ip-5',
            'antiddos' => 'ddos-10g',
        ],
    ];
    $preview = PricedOptionEngine::quote(['groups' => $product['groups']], $input, $product['base_monthly_cents'], 0);
    $cart = PricedOptionEngine::quote(['groups' => $product['groups']], $input, $product['base_monthly_cents'], 0);
    $charged = PricedOptionEngine::charge(PricedOptionEngine::snapshotFromQuote($preview), 'USD', '1.000000', 1);

    expect($cart)->toBe($preview)
        ->and($charged['unit_recurring_cents'])->toBe($preview['recurring_cents'])
        ->and($charged['setup_cents'])->toBe($preview['setup_cents'])
        ->and($preview['recurring_cents'])->toBe(8800 + 12430 + 1720 + 9000)
        ->and($preview['setup_cents'])->toBe(3900);

    $lineRecurring = 0;
    $lineSetup = 0;
    foreach ($charged['lines'] as $line) {
        if ($line['billing'] === 'recurring') {
            $lineRecurring += $line['unit_cents'];
        } else {
            $lineSetup += $line['unit_cents'];
        }
    }
    expect($lineRecurring)->toBe($charged['unit_recurring_cents'])
        ->and($lineSetup)->toBe($charged['setup_cents']);
});

test('a value from another product or group is rejected', function (): void {
    $foreignId = pricedOptionValueId('mc-hk-gia-cloud-intel', 'bandwidth', 'gia-200m');
    $sameProductOtherGroup = pricedOptionValueId('mc-hk-cia-hpc-amd', 'vcpu', 'vcpu-8');

    expect(fn () => PricedOptionEngine::quote(
        pricedOptionCatalog('mc-hk-cia-hpc-amd'),
        ['period' => '1M', 'options' => ['bandwidth' => (string) $foreignId]],
        8800,
        0
    ))->toThrow(FOSSBilling\InformationException::class, '所选配置不属于该产品');

    expect(fn () => PricedOptionEngine::quote(
        pricedOptionCatalog('mc-hk-cia-hpc-amd'),
        ['period' => '1M', 'options' => ['bandwidth' => (string) $sameProductOtherGroup]],
        8800,
        0
    ))->toThrow(FOSSBilling\InformationException::class, '所选配置不属于该产品');

    expect(fn () => PricedOptionEngine::quote(
        pricedOptionCatalog('mc-hk-cia-hpc-amd'),
        ['period' => '1M', 'options' => ['not-a-group' => 'cia-10m']],
        8800,
        0
    ))->toThrow(FOSSBilling\InformationException::class, '所选配置不属于该产品');
});

test('forged client prices and rates do not change the quote', function (): void {
    $catalog = pricedOptionCatalog('mc-hk-gia-cloud-basic');
    $clean = PricedOptionEngine::quote($catalog, ['period' => '1M', 'options' => ['vcpu' => 'vcpu-4']], 6900, 0);
    $forged = PricedOptionEngine::quote($catalog, PricedOptionEngine::stripClientMoney([
        'period' => '1M',
        'price' => '0.01',
        'setup_price' => '0.01',
        'rate' => '0.01',
        'conversion_rate' => '0.01',
        'priced_option_snapshot' => '{"recurring_cents":1}',
        'options' => [
            'vcpu' => 'vcpu-4',
        ],
    ]), 6900, 0);

    expect(fn () => PricedOptionEngine::quote($catalog, [
        'period' => '1M',
        'options' => ['vcpu' => ['code' => 'vcpu-4', 'monthly_cents' => 0]],
    ], 6900, 0))->toThrow(FOSSBilling\InformationException::class, '配置选项无效');

    expect($forged['recurring_cents'])->toBe($clean['recurring_cents'])
        ->and($clean['recurring_cents'])->toBe(6900 + 4000)
        ->and($forged['setup_cents'])->toBe(0);
});

test('ip count bounds follow the verified steps and reject free-form counts', function (): void {
    $basic = PricedOptionEngine::quote(pricedOptionCatalog('mc-hk-gia-cloud-basic'), ['period' => '1M'], 6900, 0);
    expect($basic['normalized_options']['ip'])->toBe('ip-1');

    expect(fn () => PricedOptionEngine::quote(
        pricedOptionCatalog('mc-hk-gia-cloud-basic'),
        ['period' => '1M', 'options' => ['ip' => 'ip-2']],
        6900,
        0
    ))->toThrow(FOSSBilling\InformationException::class, '所选配置不属于该产品');

    $five = PricedOptionEngine::quote(
        pricedOptionCatalog('mc-hk-cia-hpc-amd'),
        ['period' => '1M', 'options' => ['ip' => 'ip-5']],
        8800,
        0
    );
    expect($five['option_recurring_cents'])->toBe(1720);

    $eight = PricedOptionEngine::quote(
        pricedOptionCatalog('mc-hk-gia-cloud-intel'),
        ['period' => '1M', 'options' => ['ip' => 'ip-8']],
        16000,
        0
    );
    expect($eight['option_recurring_cents'])->toBe(2580);

    expect(fn () => PricedOptionEngine::quote(
        pricedOptionCatalog('mc-hk-gia-cloud-intel'),
        ['period' => '1M', 'options' => ['ip' => ['ip-3', 'ip-8']]],
        16000,
        0
    ))->toThrow(FOSSBilling\InformationException::class, '选项数量超出允许范围');

    expect(fn () => PricedOptionEngine::quote(
        pricedOptionCatalog('mc-hk-gia-cloud-intel'),
        ['period' => '1M', 'options' => ['ip' => '9']],
        16000,
        0
    ))->toThrow(FOSSBilling\InformationException::class, '所选配置不属于该产品');
});

test('period and catalog currency are validated and display conversion uses the server rate', function (): void {
    expect(fn () => PricedOptionEngine::quote(pricedOptionCatalog('mc-hk-gia-cloud-basic'), ['period' => '3M'], 6900, 0))
        ->toThrow(FOSSBilling\InformationException::class, '计费周期与配置价格不匹配');

    expect(fn () => PricedOptionEngine::quote(pricedOptionCatalog('mc-hk-gia-cloud-basic'), ['period' => '1W'], 6900, 0))
        ->toThrow(FOSSBilling\InformationException::class, '计费周期与配置价格不匹配');

    $catalog = pricedOptionCatalog('mc-hk-gia-cloud-basic');
    $catalog['groups'][0]['currency'] = 'EUR';
    expect(fn () => PricedOptionEngine::quote($catalog, ['period' => '1M'], 6900, 0))
        ->toThrow(FOSSBilling\InformationException::class, '币种与配置价格不匹配');

    $quote = PricedOptionEngine::quote(pricedOptionCatalog('mc-hk-gia-cloud-basic'), ['period' => '1M', 'options' => ['vcpu' => 'vcpu-2']], 6900, 0);
    $charged = PricedOptionEngine::charge(PricedOptionEngine::snapshotFromQuote($quote), 'CNY', '6.700000', 1);
    expect($quote['recurring_cents'])->toBe(9900)
        ->and($charged['unit_recurring_cents'])->toBe(66330)
        ->and($charged['currency'])->toBe('CNY')
        ->and(PricedOptionEngine::convertCents(430, '6.700000'))->toBe(2881)
        ->and(PricedOptionEngine::convertCents(2580, '6.700000'))->toBe(17286);
});

test('out of stock options are rejected and an unpurchasable product can still be quoted', function (): void {
    $catalog = pricedOptionCatalog('mc-hk-gia-cloud-basic');
    $catalog['groups'][2]['values'][2]['stock_control'] = true;
    $catalog['groups'][2]['values'][2]['quantity_in_stock'] = 0;

    expect(fn () => PricedOptionEngine::quote($catalog, ['period' => '1M', 'options' => ['vcpu' => 'vcpu-4']], 6900, 0))
        ->toThrow(FOSSBilling\InformationException::class, '该配置项库存不足');

    $quote = PricedOptionEngine::quote(pricedOptionCatalog('mc-hk-gia-cloud-basic'), ['period' => '1M'], 6900, 0);
    expect($quote['recurring_cents'])->toBe(6900);
    expect(fn () => PricedOptionEngine::assertPurchasable(true, 0, 1))
        ->toThrow(FOSSBilling\InformationException::class, '待资源就绪，暂不可订购');
    PricedOptionEngine::assertPurchasable(false, 0, 1);
});

test('renewal uses the checkout snapshot and drops the one-time setup fee', function (): void {
    $product = pricedOptionProduct('mc-hk-cia-hpc-amd');
    $quote = PricedOptionEngine::quote(
        ['groups' => $product['groups']],
        ['period' => '1M', 'options' => ['antiddos' => 'ddos-100g', 'bandwidth' => 'cia-20m']],
        $product['base_monthly_cents'],
        0
    );
    $charged = PricedOptionEngine::charge(PricedOptionEngine::snapshotFromQuote($quote), 'USD', '1.000000', 1);
    $orderConfig = [
        PricedOptionEngine::SNAPSHOT_KEY => json_encode($charged['snapshot']),
    ];

    $renewal = PricedOptionEngine::renewalLines($orderConfig, $charged['unit_recurring_cents'], 'USD');
    expect($renewal)->not->toBeNull();
    $sum = 0;
    foreach ($renewal as $line) {
        expect($line['billing'])->toBe('recurring');
        $sum += $line['unit_cents'];
    }
    expect($sum)->toBe($quote['recurring_cents'])
        ->and($quote['setup_cents'])->toBe(3900)
        ->and($sum)->not->toBe($quote['recurring_cents'] + $quote['setup_cents']);

    $product['groups'][0]['values'][1]['monthly_cents'] = 1;
    $repriced = PricedOptionEngine::quote(
        ['groups' => $product['groups']],
        ['period' => '1M', 'options' => ['antiddos' => 'ddos-100g', 'bandwidth' => 'cia-20m']],
        $product['base_monthly_cents'],
        0
    );
    expect($repriced['recurring_cents'])->not->toBe($quote['recurring_cents']);
    $still = PricedOptionEngine::renewalLines($orderConfig, $charged['unit_recurring_cents'], 'USD');
    $stillSum = 0;
    foreach ($still as $line) {
        $stillSum += $line['unit_cents'];
    }
    expect($stillSum)->toBe($quote['recurring_cents']);
});

test('windows is unavailable on HK119 and HK60 and free on the other verified plans', function (): void {
    foreach (['mc-hk-gia-cloud-basic', 'mc-hk-cia-hpc-amd'] as $slug) {
        $codes = [];
        foreach (pricedOptionCatalog($slug)['groups'] as $group) {
            if ($group['code'] === 'os') {
                $codes = array_column($group['values'], 'code');
            }
        }
        expect($codes)->not->toContain('windows');
        expect(fn () => PricedOptionEngine::quote(pricedOptionCatalog($slug), ['period' => '1M', 'options' => ['os' => 'windows']], 100, 0))
            ->toThrow(FOSSBilling\InformationException::class, '所选配置不属于该产品');
    }

    foreach (['mc-hk-gia-cloud-intel', 'mc-hk-cia-cloud-intel', 'mc-hk-gia-cloud-amd', 'mc-hk-cdia-cloud-amd', 'mc-hk-cia-cloud-amd'] as $slug) {
        $product = pricedOptionProduct($slug);
        $quote = PricedOptionEngine::quote(
            ['groups' => $product['groups']],
            ['period' => '1M', 'options' => ['os' => 'windows']],
            $product['base_monthly_cents'],
            0
        );
        expect($quote['recurring_cents'])->toBe($product['base_monthly_cents'])
            ->and($quote['normalized_options']['os'])->toBe('windows');
    }
});

test('unverified source products are not given zero-priced options', function (): void {
    $document = pricedOptionDocument();
    expect($document['products'])->toHaveCount(7)
        ->and($document['unverified_products'])->toHaveCount(40);

    $verified = array_column($document['products'], 'slug');
    foreach ($document['unverified_products'] as $product) {
        expect($product)->not->toHaveKey('groups')
            ->and($product['reason'])->not->toBe('')
            ->and($verified)->not->toContain($product['slug']);
    }
    foreach (['hk-1c1g', 'hk-2c2g', 'hk-4c4g'] as $slug) {
        expect($verified)->not->toContain($slug);
        expect(array_column($document['unverified_products'], 'slug'))->not->toContain($slug);
    }

    $catalog = pricedOptionCatalog('mc-hk-gia-cloud-basic');
    $catalog['groups'][0]['verified'] = false;
    expect(fn () => PricedOptionEngine::quote($catalog, ['period' => '1M'], 6900, 0))
        ->toThrow(FOSSBilling\InformationException::class, '该配置项尚未核实，不能计价');
});

test('product pricing uses the catalog and ignores a forged order price', function (): void {
    $catalog = pricedOptionCatalog('mc-hk-gia-cloud-basic');
    $service = new class($catalog) extends Service {
        public function __construct(private array $catalog)
        {
        }

        protected function loadPricedOptionCatalog(Product $product): array
        {
            return $this->catalog;
        }

        protected function pricedOptionBaseCurrency(): string
        {
            return 'USD';
        }
    };

    $payment = new ProductPayment();
    $paymentId = new ReflectionProperty($payment, 'id');
    $paymentId->setValue($payment, 15);
    $payment->setType(ProductPayment::RECURRENT)->setPeriodPricing('m', 69.0, 0.0, true);
    $product = new Product();
    $productId = new ReflectionProperty($product, 'id');
    $productId->setValue($product, 52);
    $product->setType(Service::CUSTOM)
        ->setProductPaymentId(15)
        ->setStockControl(true)
        ->setQuantityInStock(0);
    $paymentRepo = Mockery::mock(ProductPaymentRepository::class);
    $paymentRepo->shouldReceive('find')->andReturn($payment);
    $di = container();
    $di['em'] = new class($paymentRepo) {
        public function __construct(private ProductPaymentRepository $repo)
        {
        }

        public function getRepository(string $class): object
        {
            return $this->repo;
        }
    };
    $service->setDi($di);

    $price = $service->getProductPrice($product, [
        'period' => '1M',
        'price' => '1.00',
        'options' => ['vcpu' => 'vcpu-2'],
    ]);
    $setup = $service->getProductSetupPrice($product, [
        'period' => '1M',
        'options' => ['antiddos' => 'ddos-5g'],
    ]);

    expect($price)->toBe(99.0)
        ->and($setup)->toBe(39.0);
    expect(fn () => PricedOptionEngine::assertPurchasable($product->isStockControl(), $product->getQuantityInStock(), 1))
        ->toThrow(FOSSBilling\InformationException::class, '待资源就绪，暂不可订购');
});
