<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Order\Controller;

use Symfony\Component\HttpFoundation\Response;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/order', 'get_products', [], static::class);
        $app->get('/order/service', 'get_orders', [], static::class);
        $app->get('/order/:id', 'get_configure_product', ['id' => '[0-9]+'], static::class);
        $app->get('/order/:slug', 'get_configure_product_by_slug', ['slug' => '[a-z0-9-]+'], static::class);
        $app->get('/order/service/manage/:id', 'get_order', ['id' => '[0-9]+'], static::class);
    }

    public function get_products(\Box_App $app): string|Response
    {
        $requested = $this->requestedProduct($app);
        if ($requested === null) {
            return $app->render('mod_order_index');
        }

        $product = $this->findActiveProduct($requested);
        if ($product === null) {
            return $this->renderMissing($app, $requested);
        }

        return $app->render('mod_order_index', ['product' => $product]);
    }

    public function get_configure_product_by_slug(\Box_App $app, $slug): string|Response
    {
        $product = $this->findActiveProduct((string) $slug);

        return $product === null ? $this->renderMissing($app, (string) $slug) : $this->renderConfigured($app, $product);
    }

    public function get_configure_product(\Box_App $app, $id): string|Response
    {
        $product = $this->findActiveProduct((string) $id);

        return $product === null ? $this->renderMissing($app, (string) $id) : $this->renderConfigured($app, $product);
    }

    private function requestedProduct(\Box_App $app): ?string
    {
        $value = trim((string) $app->getRequest()->query->get('product', ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveProduct(string $value): ?array
    {
        $criteria = ctype_digit($value) ? ['id' => (int) $value] : ['slug' => $value];

        try {
            $product = $this->di['api_guest']->product_get($criteria);
        } catch (\FOSSBilling\InformationException) {
            return null;
        }

        return is_array($product) && $product !== [] ? $product : null;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function renderConfigured(\Box_App $app, array $product): string
    {
        $api = $this->di['api_guest'];
        $tpl = 'mod_service' . $product['type'] . '_order';
        if ($api->system_template_exists(['file' => $tpl . '.html.twig'])) {
            return $app->render($tpl, ['product' => $product]);
        }

        return $app->render('mod_order_product', ['product' => $product]);
    }

    private function renderMissing(\Box_App $app, string $requested): Response
    {
        return $app->renderResponse('mod_order_missing', ['requested' => $requested], 404);
    }

    public function get_orders(\Box_App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_order_list');
    }

    public function get_order(\Box_App $app, $id): string
    {
        $api = $this->di['api_client'];
        $data = [
            'id' => $id,
        ];
        $order = $api->order_get($data);

        return $app->render('mod_order_manage', ['order' => $order]);
    }
}
