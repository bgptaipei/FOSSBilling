<?php

declare(strict_types=1);

use Box\Mod\Invoice\Api\Admin;
use Box\Mod\Invoice\Service;
use Box\Mod\Invoice\ServiceSubscription;
use Box\Mod\Invoice\ServiceTransaction;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

test('prepare refuses a new invoice for a non-USD client', function (): void {
    $api = apiEndpoint(new Admin());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->currency = 'CNY';

    $db = Mockery::mock('\Box_Database');
    $db->shouldReceive('getExistingModelById')->once()->andReturn($client);
    $service = Mockery::mock(Service::class);
    $service->shouldNotReceive('prepareInvoice');

    $di = container();
    $di['db'] = $db;
    $api->setDi($di);
    $api->setService($service);

    expect(fn () => $api->prepare(['client_id' => 2]))
        ->toThrow(FOSSBilling\InformationException::class, '新发票只使用美元（USD）。');
});

test('transaction update repeats a historical currency and rejects a relabel', function (): void {
    $api = apiEndpoint(new Admin());
    $transaction = new Model_Transaction();
    $transaction->loadBean(new Tests\Helpers\DummyBean());
    $transaction->currency = 'CNY';

    $db = Mockery::mock('\Box_Database');
    $db->shouldReceive('getExistingModelById')->andReturn($transaction);
    $transactions = Mockery::mock(ServiceTransaction::class);
    $transactions->shouldReceive('update')
        ->once()
        ->withArgs(fn ($model, array $data): bool => $data['currency'] === 'CNY' && $data['note'] === 'checked')
        ->andReturn(true);

    $di = container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactions]));
    $api->setDi($di);

    expect($api->transaction_update(['id' => 7, 'currency' => 'CNY', 'note' => 'checked']))->toBeTrue();
    expect(fn () => $api->transaction_update(['id' => 7, 'currency' => 'USD']))
        ->toThrow(FOSSBilling\InformationException::class, '历史记录的币种保持原样，不能改成其他币种。');
});

test('subscription update keeps the stored currency when the field is omitted', function (): void {
    $api = apiEndpoint(new Admin());
    $subscription = new Model_Subscription();
    $subscription->loadBean(new Tests\Helpers\DummyBean());
    $subscription->currency = 'CNY';

    $db = Mockery::mock('\Box_Database');
    $db->shouldReceive('getExistingModelById')->once()->andReturn($subscription);
    $subscriptions = Mockery::mock(ServiceSubscription::class);
    $subscriptions->shouldReceive('update')
        ->once()
        ->withArgs(fn ($model, array $data): bool => !array_key_exists('currency', $data) && $data['amount'] === '10')
        ->andReturn(true);

    $di = container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(moduleService(['invoice:subscription' => $subscriptions]));
    $api->setDi($di);

    expect($api->subscription_update(['id' => 3, 'amount' => '10']))->toBeTrue();
});
