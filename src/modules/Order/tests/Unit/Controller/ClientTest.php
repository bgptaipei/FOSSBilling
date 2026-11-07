<?php

declare(strict_types=1);

use Box\Mod\Order\Controller\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Tests\Helpers\container;

function orderClient(array $query, object $api, Box_App $app): Client
{
    $controller = new Client();
    $di = container();
    $di['api_guest'] = $api;
    $controller->setDi($di);
    $app->shouldReceive('getRequest')->andReturn(Request::create('/order', 'GET', $query));

    return $controller;
}

test('catalog stays in place for the order index and non-product queries', function (array $query): void {
    $api = Mockery::mock();
    $api->shouldNotReceive('product_get');
    $app = Mockery::mock(Box_App::class);
    $app->shouldReceive('render')->once()->with('mod_order_index')->andReturn('catalog');
    $controller = orderClient($query, $api, $app);

    expect($controller->get_products($app))->toBe('catalog');
})->with([
    'unfiltered' => [[]],
    'empty product' => [['product' => '']],
    'category query' => [['category' => '3']],
    'catalog filters' => [['type' => 'virtual', 'currency' => 'USD']],
]);

test('query product id renders configuration for that product', function (): void {
    $product = ['id' => 53, 'slug' => 'mc-hk-cia-hpc-amd', 'type' => 'custom', 'title' => 'HK'];
    $api = Mockery::mock();
    $api->shouldReceive('product_get')->once()->with(['id' => 53])->andReturn($product);
    $app = Mockery::mock(Box_App::class);
    $app->shouldReceive('render')->once()->with('mod_order_index', ['product' => $product])->andReturn('config');
    $controller = orderClient(['product' => '53'], $api, $app);

    expect($controller->get_products($app))->toBe('config');
});

test('unknown query product is a 404 and does not render configuration', function (): void {
    $api = Mockery::mock();
    $api->shouldReceive('product_get')->once()->with(['id' => 999])->andThrow(new FOSSBilling\InformationException('Product not found'));
    $app = Mockery::mock(Box_App::class);
    $app->shouldReceive('renderResponse')
        ->once()
        ->with('mod_order_missing', ['requested' => '999'], 404)
        ->andReturn(new Response('missing', 404));
    $controller = orderClient(['product' => '999'], $api, $app);

    $response = $controller->get_products($app);
    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(404);
});

test('route product id and slug render the product template', function (string $method, string $value, array $criteria): void {
    $product = ['id' => 2, 'slug' => 'hk-1c1g', 'type' => 'custom', 'title' => 'HK 1C'];
    $api = Mockery::mock();
    $api->shouldReceive('product_get')->once()->with($criteria)->andReturn($product);
    $api->shouldReceive('system_template_exists')->once()->andReturn(false);
    $app = Mockery::mock(Box_App::class);
    $app->shouldReceive('getRequest')->andReturn(Request::create('/order'));
    $app->shouldReceive('render')->once()->with('mod_order_product', ['product' => $product])->andReturn('config');
    $controller = new Client();
    $di = container();
    $di['api_guest'] = $api;
    $controller->setDi($di);

    expect($controller->{$method}($app, $value))->toBe('config');
})->with([
    'id' => ['get_configure_product', '2', ['id' => 2]],
    'slug' => ['get_configure_product_by_slug', 'hk-1c1g', ['slug' => 'hk-1c1g']],
]);

test('unknown route product id and slug are 404s', function (string $method, string $value): void {
    $api = Mockery::mock();
    $api->shouldReceive('product_get')->once()->andThrow(new FOSSBilling\InformationException('Product not found'));
    $app = Mockery::mock(Box_App::class);
    $app->shouldReceive('renderResponse')
        ->once()
        ->with('mod_order_missing', ['requested' => $value], 404)
        ->andReturn(new Response('missing', 404));
    $controller = new Client();
    $di = container();
    $di['api_guest'] = $api;
    $controller->setDi($di);

    $response = $controller->{$method}($app, $value);
    expect($response->getStatusCode())->toBe(404);
})->with([
    'id' => ['get_configure_product', '1'],
    'slug' => ['get_configure_product_by_slug', 'missing-plan'],
]);

test('order content uses the routed product and does not fetch an unknown product', function (): void {
    $content = (string) file_get_contents(dirname(__DIR__, 4) . '/Orderbutton/templates/client/mod_orderbutton_content.html.twig');
    $configuration = (string) file_get_contents(dirname(__DIR__, 4) . '/Orderbutton/templates/client/mod_orderbutton_product_configuration.html.twig');

    expect($content)->toContain('product is defined and product')
        ->and($content)->not->toContain('{% if request.product %}')
        ->and($configuration)->not->toContain('guest.product_get');
});
