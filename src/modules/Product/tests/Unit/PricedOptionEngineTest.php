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
                'antiddos' => 'ddos-5g',
                'os' => 'ubuntu',
            ],
        ],
        $product['base_monthly_cents'],
        0
    );

    expect($quote['option_recurring_cents'])->toBe(21360)
        ->and($quote['recurring_cents'])->toBe(30160)
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

test('windows has no verified price and snapshot backup is not split into two charges', function (): void {
    $document = pricedOptionDocument();
    expect($document['uncertain'])->toHaveCount(2);
    $uncertain = array_column($document['uncertain'], 'code');
    expect($uncertain)->toContain('snapshot_backup')
        ->and($uncertain)->toContain('os_windows');

    foreach ($document['products'] as $product) {
        $codes = [];
        $snapshotGroups = 0;
        $backupGroups = 0;
        foreach ($product['groups'] as $group) {
            if ($group['code'] === 'os') {
                $codes = array_column($group['values'], 'code');
                expect($group['verified'])->toBeTrue();
            }
            if ($group['code'] === 'snapshot') {
                ++$snapshotGroups;
            }
            if ($group['code'] === 'backup') {
                ++$backupGroups;
            }
            if ($group['code'] === 'snapshot_backup') {
                expect($group['verified'])->toBeFalse()
                    ->and($group['values'])->toBe([]);
            }
        }
        expect($codes)->not->toContain('windows')
            ->and($snapshotGroups)->toBe(0)
            ->and($backupGroups)->toBe(0);

        $quote = PricedOptionEngine::quote(
            ['groups' => $product['groups']],
            ['period' => '1M'],
            $product['base_monthly_cents'],
            0
        );
        expect($quote['recurring_cents'])->toBe($product['base_monthly_cents']);

        expect(fn () => PricedOptionEngine::quote(
            ['groups' => $product['groups']],
            ['period' => '1M', 'options' => ['os' => 'windows']],
            $product['base_monthly_cents'],
            0
        ))->toThrow(FOSSBilling\InformationException::class, '所选配置不属于该产品');

        expect(fn () => PricedOptionEngine::quote(
            ['groups' => $product['groups']],
            ['period' => '1M', 'options' => ['snapshot_backup' => 'snap-1']],
            $product['base_monthly_cents'],
            0
        ))->toThrow(FOSSBilling\InformationException::class, '该配置项尚未核实，不能计价');
    }
});

test('verified bandwidth memory storage and ip surcharges match the handoff cfg lines', function (): void {
    $expected = [
        'mc-hk-gia-cloud-basic' => [
            'bandwidth' => ['gia-100m' => [0, 0]],
            'ip' => ['ip-1' => [0, 0]],
            'vcpu' => ['vcpu-1' => [0, 0], 'vcpu-2' => [3000, 0], 'vcpu-4' => [4000, 0]],
            'memory' => ['ram-2g' => [0, 0]],
            'os_disk' => ['sys-10g' => [0, 0]],
            'data_disk' => ['data-none' => [0, 0]],
        ],
        'mc-hk-cia-hpc-amd' => [
            'bandwidth' => ['cia-10m' => [0, 0], 'cia-20m' => [7000, 0], 'cia-40m' => [12430, 0]],
            'ip' => ['ip-1' => [0, 0], 'ip-2' => [430, 0], 'ip-3' => [860, 0], 'ip-4' => [1290, 0], 'ip-5' => [1720, 0]],
            'vcpu' => ['vcpu-4' => [0, 0], 'vcpu-8' => [1500, 0], 'vcpu-16' => [3200, 0], 'vcpu-32' => [20000, 0]],
            'memory' => ['ram-4g' => [0, 0], 'ram-8g' => [1500, 0], 'ram-16g' => [4300, 0], 'ram-32g' => [10000, 0], 'ram-64g' => [21500, 0]],
            'os_disk' => ['sys-50g' => [0, 0], 'sys-90g' => [1500, 0]],
            'data_disk' => ['data-none' => [0, 0], 'data-50g' => [1500, 0], 'data-100g' => [3000, 0], 'data-200g' => [6000, 0], 'data-500g' => [20000, 0]],
        ],
        'mc-hk-gia-cloud-intel' => [
            'bandwidth' => ['gia-100m' => [0, 0], 'gia-200m' => [12430, 0], 'gia-500m' => [23310, 0]],
            'memory' => ['ram-16g' => [0, 0], 'ram-32g' => [10000, 0], 'ram-64g' => [21500, 0]],
            'os_disk' => ['sys-100g' => [0, 0], 'sys-200g' => [1100, 0], 'sys-500g' => [2600, 0]],
            'data_disk' => ['data-900g' => [0, 0], 'data-2tb' => [3000, 0], 'data-4tb' => [8400, 0]],
            'vcpu' => ['vcpu-8' => [0, 0], 'vcpu-16' => [4300, 0], 'vcpu-32' => [10000, 0]],
        ],
        'mc-hk-cia-cloud-intel' => [
            'bandwidth' => ['cia-10m' => [0, 0], 'cia-20m' => [3000, 0], 'cia-40m' => [12430, 0]],
            'memory' => ['ram-4g' => [0, 0], 'ram-16g' => [4300, 0], 'ram-32g' => [10000, 0], 'ram-64g' => [21500, 0]],
            'os_disk' => ['sys-60g' => [0, 0], 'sys-100g' => [500, 0], 'sys-200g' => [1100, 0], 'sys-500g' => [2600, 0]],
            'data_disk' => ['data-100g' => [0, 0], 'data-900g' => [1500, 0], 'data-2tb' => [3000, 0], 'data-4tb' => [6000, 0]],
            'vcpu' => ['vcpu-4' => [0, 0], 'vcpu-8' => [1500, 0], 'vcpu-16' => [4300, 0], 'vcpu-32' => [10000, 0]],
        ],
        'mc-hk-gia-cloud-amd' => [
            'bandwidth' => ['gia-100m' => [0, 0], 'gia-200m' => [12430, 0], 'gia-500m' => [23310, 0]],
            'memory' => ['ram-16g' => [0, 0], 'ram-32g' => [10000, 0], 'ram-64g' => [21500, 0]],
            'os_disk' => ['sys-100g' => [0, 0], 'sys-200g' => [1100, 0], 'sys-500g' => [2600, 0]],
            'data_disk' => ['data-900g' => [0, 0], 'data-2tb' => [3000, 0], 'data-4tb' => [6000, 0], 'data-8tb' => [18000, 0], 'data-16tb' => [36000, 0]],
            'vcpu' => ['vcpu-8' => [0, 0], 'vcpu-16' => [4300, 0], 'vcpu-32' => [10000, 0]],
        ],
        'mc-hk-cdia-cloud-amd' => [
            'bandwidth' => ['cdia-50m' => [0, 0]],
            'memory' => ['ram-16g' => [0, 0], 'ram-32g' => [10000, 0], 'ram-64g' => [21500, 0]],
            'os_disk' => ['sys-100g' => [0, 0], 'sys-200g' => [1100, 0], 'sys-500g' => [2600, 0]],
            'data_disk' => ['data-900g' => [0, 0], 'data-2tb' => [3000, 0], 'data-4tb' => [6000, 0]],
            'vcpu' => ['vcpu-8' => [0, 0], 'vcpu-16' => [2200, 0], 'vcpu-32' => [10000, 0]],
        ],
        'mc-hk-cia-cloud-amd' => [
            'bandwidth' => ['cia-10m' => [0, 0], 'cia-20m' => [3000, 0], 'cia-40m' => [12430, 0], 'cia-100m' => [99900, 0]],
            'memory' => ['ram-4g' => [0, 0], 'ram-16g' => [4300, 0], 'ram-32g' => [10000, 0], 'ram-64g' => [21500, 0]],
            'os_disk' => ['sys-60g' => [0, 0], 'sys-100g' => [1500, 0]],
            'data_disk' => ['data-100g' => [0, 0], 'data-900g' => [1500, 0], 'data-2tb' => [3000, 0], 'data-4tb' => [6000, 0]],
            'vcpu' => ['vcpu-4' => [0, 0], 'vcpu-8' => [1500, 0], 'vcpu-16' => [4300, 0], 'vcpu-32' => [10000, 0], 'vcpu-64' => [44500, 0]],
        ],
    ];
    $ddos = [
        'ddos-2g' => [0, 0],
        'ddos-5g' => [6000, 3900],
        'ddos-10g' => [9000, 3900],
        'ddos-40g' => [69930, 3900],
        'ddos-100g' => [120000, 3900],
    ];

    foreach ($expected as $slug => $groups) {
        $actual = [];
        foreach (pricedOptionCatalog($slug)['groups'] as $group) {
            if (!($group['verified'] ?? false)) {
                continue;
            }
            $actual[$group['code']] = [];
            foreach ($group['values'] as $value) {
                $actual[$group['code']][$value['code']] = [$value['monthly_cents'], $value['setup_cents']];
            }
        }
        foreach ($groups as $code => $values) {
            expect($actual[$code])->toBe($values);
        }
        expect($actual['ip']['ip-2'] ?? $actual['ip']['ip-1'])->toBeArray();
        if ($slug === 'mc-hk-gia-cloud-basic') {
            expect(array_keys($actual['ip']))->toBe(['ip-1']);
        } elseif ($slug === 'mc-hk-cia-hpc-amd') {
            expect(array_keys($actual['ip']))->toBe(['ip-1', 'ip-2', 'ip-3', 'ip-4', 'ip-5'])
                ->and($actual['ip']['ip-5'])->toBe([1720, 0]);
        } else {
            expect(array_keys($actual['ip']))->toBe(['ip-2', 'ip-3', 'ip-4', 'ip-5', 'ip-6', 'ip-7', 'ip-8'])
                ->and($actual['ip']['ip-3'])->toBe([430, 0])
                ->and($actual['ip']['ip-8'])->toBe([2580, 0]);
        }
        expect($actual['antiddos'])->toBe($ddos);
        if (in_array($slug, ['mc-hk-cia-cloud-intel', 'mc-hk-cia-cloud-amd'], true)) {
            expect($actual['memory'])->not->toHaveKey('ram-8g');
        }
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
    $ignored = PricedOptionEngine::quote($catalog, ['period' => '1M'], 6900, 0);
    expect($ignored['recurring_cents'])->toBe(6900);
    expect(fn () => PricedOptionEngine::quote($catalog, ['period' => '1M', 'options' => ['bandwidth' => 'gia-100m']], 6900, 0))
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
