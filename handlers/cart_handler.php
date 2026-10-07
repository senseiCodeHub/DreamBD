<?php
require_once __DIR__ . '/../includes/session.php';
dream_start_session();
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/security.php';

$auth = new Auth();
$security = new Security();

header('Content-Type: application/json');

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

function cart_res(array $data): void {
    echo json_encode($data);
    exit;
}

if (!$userId) {
    cart_res(['success' => false, 'message' => 'Please login first.', 'login' => true]);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!$security->validateCSRFToken($csrfToken)) {
    cart_res(['success' => false, 'message' => 'Security token invalid. Please refresh.']);
}

$db = Database::getInstance()->getConnection();

function cart_count(PDO $db, int $userId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cart_items ci
                          JOIN products p ON p.id = ci.product_id AND p.status = 'active'
                          WHERE ci.user_id = ?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

if ($action === 'count') {
    cart_res(['success' => true, 'count' => cart_count($db, $userId)]);
}

if ($action === 'add') {
    $productId = (int)($_POST['product_id'] ?? 0);
    if (!$productId) cart_res(['success' => false, 'message' => 'Invalid product.']);

    $stmt = $db->prepare("SELECT id, price, status FROM products WHERE id = ? AND status = 'active'");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) cart_res(['success' => false, 'message' => 'Product not available.']);

    $stmt = $db->prepare("SELECT id FROM product_purchases WHERE user_id = ? AND product_id = ? AND status = 'completed'");
    $stmt->execute([$userId, $productId]);
    if ($stmt->fetch()) cart_res(['success' => false, 'message' => 'You already own this product.']);

    try {
        $stmt = $db->prepare("INSERT IGNORE INTO cart_items (user_id, product_id) VALUES (?, ?)");
        $stmt->execute([$userId, $productId]);
        $already = $stmt->rowCount() === 0;
        cart_res([
            'success' => true,
            'message' => $already ? 'Already in your cart.' : 'Added to cart.',
            'count' => cart_count($db, $userId),
            'already' => $already,
        ]);
    } catch (Throwable $e) {
        cart_res(['success' => false, 'message' => 'Unable to update cart.']);
    }
}

if ($action === 'remove') {
    $productId = (int)($_POST['product_id'] ?? 0);
    try {
        $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$userId, $productId]);
        cart_res(['success' => true, 'message' => 'Removed from cart.', 'count' => cart_count($db, $userId)]);
    } catch (Throwable $e) {
        cart_res(['success' => false, 'message' => 'Unable to update cart.']);
    }
}

if ($action === 'clear') {
    try {
        $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$userId]);
        cart_res(['success' => true, 'message' => 'Cart cleared.', 'count' => 0]);
    } catch (Throwable $e) {
        cart_res(['success' => false, 'message' => 'Unable to clear cart.']);
    }
}

if ($action === 'checkout') {
    try {
        $stmt = $db->prepare("SELECT ci.product_id, p.name, p.price, p.author_id, p.file_path, p.file_type
                              FROM cart_items ci
                              JOIN products p ON p.id = ci.product_id
                              WHERE ci.user_id = ? AND p.status = 'active'
                              ORDER BY ci.id");
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll();

        if (!$items) cart_res(['success' => false, 'message' => 'Your cart is empty.']);

        $ownedIds = [];
        $placeholders = implode(',', array_fill(0, count($items), '?'));
        $ids = array_map(fn($i) => (int)$i['product_id'], $items);
        $params = array_merge([$userId], $ids);
        $stmt = $db->prepare("SELECT product_id FROM product_purchases WHERE user_id = ? AND product_id IN ($placeholders) AND status = 'completed'");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) $ownedIds[] = (int)$row['product_id'];
        if ($ownedIds) {
            $in = implode(',', $ownedIds);
            $db->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id IN ($in)")->execute([$userId]);
            cart_res(['success' => false, 'message' => 'Some items were already owned and were removed. Please review your cart.']);
        }

        $total = 0.0;
        foreach ($items as $it) $total += (float)$it['price'];
        $total = round($total, 2);

        $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $balance = (float)($stmt->fetchColumn() ?: 0);

        if ($balance < $total) {
            cart_res(['success' => false, 'message' => 'Insufficient balance (৳' . number_format($total, 2) . ' needed). Please add funds first.', 'need' => $total, 'balance' => $balance]);
        }

        $db->beginTransaction();

        $stmt = $db->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$total, $userId]);

        $buyerAfter = $balance - $total;
        $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'product_purchase', ?, ?, ?, ?, 'product_purchase')");
        $stmt->execute([$userId, $total, $balance, $buyerAfter, 'Cart checkout (' . count($items) . ' item' . (count($items) > 1 ? 's' : '') . ')']);

        $bought = 0;
        foreach ($items as $it) {
            $price = (float)$it['price'];
            $authorId = (int)($it['author_id'] ?? 0);
            $authorEarning = round($price * 0.7, 2);

            if ($authorId) {
                $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
                $stmt->execute([$authorId]);
                $authorBefore = (float)($stmt->fetchColumn() ?: 0);

                $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$authorEarning, $authorId]);

                $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'credit', ?, ?, ?, ?, 'product_sale')");
                $stmt->execute([$authorId, $authorEarning, $authorBefore, ($authorBefore + $authorEarning), 'Sale: ' . $it['name']]);
            }

            $stmt = $db->prepare("INSERT INTO product_purchases (user_id, product_id, amount, status, author_earnings) VALUES (?, ?, ?, 'completed', ?)");
            $stmt->execute([$userId, (int)$it['product_id'], $price, $authorEarning]);

            $stmt = $db->prepare("UPDATE products SET sales = sales + 1 WHERE id = ?");
            $stmt->execute([(int)$it['product_id']]);

            if ($authorId && $authorId !== $userId) {
                $stmt = $db->prepare("INSERT INTO notifications (user_id, actor_id, type, entity_id, message) VALUES (?, ?, 'product_sale', ?, ?)");
                $stmt->execute([$authorId, $userId, (int)$it['product_id'], 'Your guide "' . $it['name'] . '" was sold!']);
            }
            $bought++;
        }

        $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$userId]);

        $db->commit();

        cart_res([
            'success' => true,
            'message' => 'Checkout complete! ' . $bought . ' item' . ($bought > 1 ? 's' : '') . ' purchased. You can now read your guides.',
            'count' => 0,
            'total' => $total,
        ]);
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log("Cart checkout error: " . $e->getMessage());
        cart_res(['success' => false, 'message' => 'Server error. Please try again.']);
    }
}

cart_res(['success' => false, 'message' => 'Invalid action.']);
