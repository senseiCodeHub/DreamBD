<?php
require_once __DIR__ . '/../includes/session.php';
dream_start_session();
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$security = new Security();

header('Content-Type: application/json');

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? 'user';
$adminRoles = ['admin', 'moderator', 'super_admin'];
$isAdmin = in_array($userRole, $adminRoles, true);

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$db = Database::getInstance()->getConnection();

/* ── PUBLIC: buy a top-up package ── */
if ($action === 'buy_topup') {
    if (!$auth->isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Please login first.']);
        exit;
    }
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh the page.']);
        exit;
    }

    $packageId = (int)($_POST['package_id'] ?? 0);
    $playerUid = trim($_POST['player_uid'] ?? '');
    $playerZone = trim($_POST['player_zone'] ?? '');
    $contact = trim($_POST['contact'] ?? '');

    if (!$packageId) {
        echo json_encode(['success' => false, 'message' => 'Invalid package.']);
        exit;
    }
    if ($playerUid === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter your game ID / UID.']);
        exit;
    }

    try {
        $stmt = $db->prepare("SELECT p.id AS package_id, p.name AS package_name, p.price,
                                     g.id AS game_id, g.name AS game_name
                              FROM game_topup_packages p
                              JOIN game_topup_games g ON g.id = p.game_id
                              WHERE p.id = ? AND p.status = 'active' AND g.status = 'active'");
        $stmt->execute([$packageId]);
        $pkg = $stmt->fetch();
        if (!$pkg) {
            echo json_encode(['success' => false, 'message' => 'Package not found.']);
            exit;
        }

        $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $balance = (float)($stmt->fetchColumn() ?: 0);
        $amount = (float)$pkg['price'];

        if ($balance < $amount) {
            echo json_encode(['success' => false, 'message' => 'Insufficient balance. Please add funds first.']);
            exit;
        }

        $db->beginTransaction();

        $stmt = $db->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$amount, $userId]);

        $stmt = $db->prepare("INSERT INTO topup_orders
            (user_id, game_id, package_id, game_name, package_name, amount, player_uid, player_zone, contact, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$userId, $pkg['game_id'], $pkg['package_id'], $pkg['game_name'], $pkg['package_name'], $amount, $playerUid, $playerZone, $contact]);
        $orderId = (int)$db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose)
                              VALUES (?, 'topup_purchase', ?, ?, ?, ?, 'topup_purchase')");
        $stmt->execute([$userId, $amount, $balance, $balance - $amount, 'Top-up: ' . $pkg['game_name'] . ' — ' . $pkg['package_name'] . ' (#' . $orderId . ')']);

        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Top-up order placed! Our team will deliver ' . $pkg['package_name'] . ' to your account soon.', 'order_id' => $orderId]);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log("Top-up purchase error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    }
    exit;
}

/* ── Everything below requires admin/moderator ── */
if (!$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

if ($action !== 'get_game' && !$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh.']);
    exit;
}

/* ── Game CRUD ── */
if ($action === 'save_game') {
    $gameId = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $icon = trim($_POST['icon'] ?? 'fa-gamepad');
    $gradient = trim($_POST['gradient'] ?? '');
    $shadowColor = trim($_POST['shadow_color'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Game name is required.']);
        exit;
    }
    if ($slug === '') {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    }
    if ($gradient === '' && $shadowColor === '') {
        $palette = ['#ff6b35','#e53935','#7b1fa2','#1e88e5','#ff4655','#f59e0b'];
        $c = $palette[$gameId % count($palette)];
        $gradient = 'linear-gradient(135deg, ' . $c . ', ' . $c . 'cc)';
        $shadowColor = 'rgba(99,102,241,0.3)';
    }

    try {
        $logoPath = null;   // null = no change; '' = remove
        $oldLogo = null;
        if ($gameId > 0) {
            $stmt = $db->prepare("SELECT logo FROM game_topup_games WHERE id = ?");
            $stmt->execute([$gameId]);
            $oldLogo = $stmt->fetchColumn() ?: null;
        }

        if (isset($_FILES['logo']) && is_uploaded_file($_FILES['logo']['tmp_name']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $img = $_FILES['logo'];
            $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($img['tmp_name']);
            if (!isset($allowedTypes[$detectedMime])) {
                echo json_encode(['success' => false, 'message' => 'Logo must be a JPG, PNG, GIF or WebP image.']);
                exit;
            }
            if ($img['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Logo too large (max 5MB).']);
                exit;
            }
            $uploadDir = __DIR__ . '/../assets/games/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $basename = 'game_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$detectedMime];
            if (!move_uploaded_file($img['tmp_name'], $uploadDir . $basename)) {
                echo json_encode(['success' => false, 'message' => 'Could not save the logo file.']);
                exit;
            }
            $logoPath = 'assets/games/' . $basename;
        } elseif (!empty($_POST['remove_logo'])) {
            $logoPath = '';
        }

        $removeOldLogo = function ($path) {
            if (!$path || !str_starts_with($path, 'assets/games/')) return;
            $abs = __DIR__ . '/../' . ltrim($path, '/');
            if (is_file($abs)) @unlink($abs);
        };

        if ($gameId > 0) {
            if ($logoPath === null) {
                $stmt = $db->prepare("UPDATE game_topup_games SET name=?, slug=?, icon=?, gradient=?, shadow_color=?, description=?, sort_order=?, status=? WHERE id=?");
                $stmt->execute([$name, $slug, $icon, $gradient, $shadowColor, $description, $sortOrder, $status, $gameId]);
            } else {
                $stmt = $db->prepare("UPDATE game_topup_games SET name=?, slug=?, icon=?, logo=?, gradient=?, shadow_color=?, description=?, sort_order=?, status=? WHERE id=?");
                $stmt->execute([$name, $slug, $icon, $logoPath !== '' ? $logoPath : null, $gradient, $shadowColor, $description, $sortOrder, $status, $gameId]);
                if ($logoPath !== null) $removeOldLogo($oldLogo);
            }
            echo json_encode(['success' => true, 'message' => 'Game updated.']);
        } else {
            $stmt = $db->prepare("INSERT INTO game_topup_games (name, slug, icon, logo, gradient, shadow_color, description, sort_order, status) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$name, $slug, $icon, $logoPath ?: null, $gradient, $shadowColor, $description, $sortOrder, $status]);
            if ($logoPath === '') $logoPath = null;
            echo json_encode(['success' => true, 'message' => 'Game added.', 'id' => (int)$db->lastInsertId()]);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_game') {
    $gameId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM game_topup_games WHERE id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        echo json_encode(['success' => false, 'message' => 'Game not found.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $game]);
    exit;
}

if ($action === 'delete_game') {
    $gameId = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT logo FROM game_topup_games WHERE id = ?");
    $stmt->execute([$gameId]);
    $oldLogo = $stmt->fetchColumn() ?: null;
    $stmt = $db->prepare("DELETE FROM game_topup_games WHERE id = ?");
    $stmt->execute([$gameId]);
    $stmt = $db->prepare("DELETE FROM game_topup_packages WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ($oldLogo && str_starts_with($oldLogo, 'assets/games/')) {
        $abs = __DIR__ . '/../' . ltrim($oldLogo, '/');
        if (is_file($abs)) @unlink($abs);
    }
    echo json_encode(['success' => true, 'message' => 'Game and its packages deleted.']);
    exit;
}

if ($action === 'toggle_game') {
    $gameId = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
    $stmt = $db->prepare("UPDATE game_topup_games SET status = ? WHERE id = ?");
    $stmt->execute([$status, $gameId]);
    echo json_encode(['success' => true, 'message' => $status === 'active' ? 'Game published.' : 'Game hidden.']);
    exit;
}

/* ── Package CRUD ── */
if ($action === 'save_package') {
    $pkgId = (int)($_POST['id'] ?? 0);
    $gameId = (int)($_POST['game_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $badge = trim($_POST['badge'] ?? '');
    $badgeColor = trim($_POST['badge_color'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

    if (!$gameId || $name === '' || $price < 0) {
        echo json_encode(['success' => false, 'message' => 'Game, package name, and a valid price are required.']);
        exit;
    }

    try {
        if ($pkgId > 0) {
            $stmt = $db->prepare("UPDATE game_topup_packages SET game_id=?, name=?, price=?, badge=?, badge_color=?, sort_order=?, status=? WHERE id=?");
            $stmt->execute([$gameId, $name, $price, $badge, $badgeColor, $sortOrder, $status, $pkgId]);
            echo json_encode(['success' => true, 'message' => 'Package updated.']);
        } else {
            $stmt = $db->prepare("INSERT INTO game_topup_packages (game_id, name, price, badge, badge_color, sort_order, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$gameId, $name, $price, $badge, $badgeColor, $sortOrder, $status]);
            echo json_encode(['success' => true, 'message' => 'Package added.', 'id' => (int)$db->lastInsertId()]);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_package') {
    $pkgId = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM game_topup_packages WHERE id = ?");
    $stmt->execute([$pkgId]);
    echo json_encode(['success' => true, 'message' => 'Package deleted.']);
    exit;
}

if ($action === 'toggle_package') {
    $pkgId = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
    $stmt = $db->prepare("UPDATE game_topup_packages SET status = ? WHERE id = ?");
    $stmt->execute([$status, $pkgId]);
    echo json_encode(['success' => true, 'message' => $status === 'active' ? 'Package published.' : 'Package hidden.']);
    exit;
}

/* ── Order management ── */
if ($action === 'update_order_status') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');
    if (!in_array($status, ['pending', 'processing', 'completed', 'cancelled'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM topup_orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit;
        }

        $prevStatus = $order['status'];
        $stmt = $db->prepare("UPDATE topup_orders SET status=?, note=?, handled_by=? WHERE id=?");
        $stmt->execute([$status, $note, $userId, $orderId]);

        // Refund the buyer once when moving from pending/processing to cancelled
        if ($status === 'cancelled' && in_array($prevStatus, ['pending', 'processing'], true)) {
            $amount = (float)$order['amount'];
            $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
            $stmt->execute([$order['user_id']]);
            $buyerBalanceBefore = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $order['user_id']]);

            $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose)
                                  VALUES (?, 'topup_refund', ?, ?, ?, ?, 'topup_refund')");
            $stmt->execute([$order['user_id'], $amount, $buyerBalanceBefore, $buyerBalanceBefore + $amount, 'Refund: ' . $order['package_name'] . ' (#' . $orderId . ')']);
        }

        if ($order['user_id']) {
            $msgText = $status === 'completed'
                ? 'Your ' . $order['package_name'] . ' top-up for ' . $order['game_name'] . ' has been delivered!'
                : ($status === 'processing'
                    ? 'Your ' . $order['package_name'] . ' top-up is being processed.'
                    : ($status === 'cancelled'
                        ? 'Your ' . $order['package_name'] . ' top-up was cancelled' . ($status === 'cancelled' && in_array($prevStatus, ['pending', 'processing'], true) ? ' and the balance has been refunded.' : '.')
                        : 'Your top-up order status changed to pending.'));
            createNotification($db, (int)$order['user_id'], $userId, 'topup', $msgText, $orderId);
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Order marked as ' . ucfirst($status) . '.']);
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log("Top-up order update error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    }
    exit;
}

if ($action === 'delete_order') {
    $orderId = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM topup_orders WHERE id = ?");
    $stmt->execute([$orderId]);
    echo json_encode(['success' => true, 'message' => 'Order deleted.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);