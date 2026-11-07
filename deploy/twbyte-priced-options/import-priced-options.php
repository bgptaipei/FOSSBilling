<?php

declare(strict_types=1);

/**
 * Idempotent importer for verified priced dropdown options.
 *
 * Usage:
 *   php import-priced-options.php --config=/var/www/fossbilling/config.php --data=/path/hk7-priced-options.json dry
 *   php import-priced-options.php --config=/var/www/fossbilling/config.php --data=/path/hk7-priced-options.json apply
 *
 * The script only deletes and reinserts product_option_group / product_option_value
 * rows for slugs present in the verified product list. It does not update product
 * prices, orders, invoices, currencies, or payment gateways.
 */
if ($argc < 4) {
    fwrite(STDERR, "usage: php import-priced-options.php --config=config.php --data=hk7-priced-options.json dry|apply\n");
    exit(1);
}

$mode = $argv[$argc - 1];
if (!in_array($mode, ['dry', 'apply'], true)) {
    fwrite(STDERR, "last argument must be dry or apply\n");
    exit(1);
}

$configPath = null;
$dataPath = null;
foreach (array_slice($argv, 1, -1) as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, 9);
    } elseif (str_starts_with($arg, '--data=')) {
        $dataPath = substr($arg, 7);
    }
}
if ($configPath === null || $dataPath === null || !is_file($configPath) || !is_file($dataPath)) {
    fwrite(STDERR, "config and data files are required\n");
    exit(1);
}

/** @var array<string, mixed> $config */
$config = include $configPath;
$dbConfig = $config['db'] ?? null;
if (!is_array($dbConfig)) {
    fwrite(STDERR, "config db section is missing\n");
    exit(1);
}

$document = json_decode((string) file_get_contents($dataPath), true);
if (!is_array($document) || !is_array($document['products'] ?? null)) {
    fwrite(STDERR, "data file has no products\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $dbConfig['host'] ?? 'localhost',
    (string) ($dbConfig['port'] ?? 3306),
    $dbConfig['name'] ?? ''
);
$pdo = new PDO($dsn, (string) ($dbConfig['user'] ?? ''), (string) ($dbConfig['password'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$missing = [];
$groups = 0;
$values = 0;
$pdo->beginTransaction();

try {
    foreach ($document['products'] as $product) {
        if (!is_array($product) || !($product['verified'] ?? false) || !str_starts_with((string) ($product['slug'] ?? ''), 'mc-')) {
            throw new RuntimeException('refusing a product that is not a verified mc- slug');
        }
        $slug = (string) $product['slug'];
        $productId = $pdo->prepare('SELECT id FROM product WHERE slug = ?');
        $productId->execute([$slug]);
        $id = $productId->fetchColumn();
        if ($id === false) {
            $missing[] = $slug;

            continue;
        }

        $pdo->prepare('DELETE v FROM product_option_value v INNER JOIN product_option_group g ON g.id = v.group_id WHERE g.product_id = ?')->execute([(int) $id]);
        $pdo->prepare('DELETE FROM product_option_group WHERE product_id = ?')->execute([(int) $id]);

        $insertGroup = $pdo->prepare('INSERT INTO product_option_group (product_id, code, label, required, min_count, max_count, currency, periods, verified, active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $insertValue = $pdo->prepare('INSERT INTO product_option_value (group_id, code, label, monthly_price_cents, setup_price_cents, is_default, active, stock_control, quantity_in_stock, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        foreach ($product['groups'] as $group) {
            $insertGroup->execute([
                (int) $id,
                $group['code'],
                $group['label'],
                $group['required'] ? 1 : 0,
                (int) $group['min_count'],
                (int) $group['max_count'],
                $group['currency'],
                implode(',', $group['periods']),
                $group['verified'] ? 1 : 0,
                ($group['active'] ?? true) ? 1 : 0,
                (int) $group['sort_order'],
            ]);
            $groupId = (int) $pdo->lastInsertId();
            ++$groups;
            foreach ($group['values'] as $value) {
                $insertValue->execute([
                    $groupId,
                    $value['code'],
                    $value['label'],
                    (int) $value['monthly_cents'],
                    (int) $value['setup_cents'],
                    $value['is_default'] ? 1 : 0,
                    ($value['active'] ?? true) ? 1 : 0,
                    ($value['stock_control'] ?? false) ? 1 : 0,
                    (int) ($value['quantity_in_stock'] ?? 0),
                    (int) $value['sort_order'],
                ]);
                ++$values;
            }
        }
    }

    if ($missing !== []) {
        $pdo->rollBack();
        fwrite(STDERR, 'missing product slugs: ' . implode(', ', $missing) . "\n");
        exit(2);
    }
    if ($mode !== 'apply') {
        $pdo->rollBack();
        echo json_encode(['mode' => 'dry', 'groups' => $groups, 'values' => $values], JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }
    $pdo->commit();
    echo json_encode(['mode' => 'apply', 'groups' => $groups, 'values' => $values], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
