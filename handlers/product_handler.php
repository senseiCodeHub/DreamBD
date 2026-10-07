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
$userRole = $_SESSION['role'] ?? 'user';

$adminRoles = ['admin', 'moderator', 'super_admin'];
$sellerRoles = ['merchant', 'seller'];

function dream_is_admin($role, $adminRoles) {
    return in_array($role, $adminRoles, true);
}

function dream_is_seller($role, $sellerRoles) {
    return in_array($role, $sellerRoles, true);
}

function dream_can_manage_products($role, $adminRoles, $sellerRoles) {
    return dream_is_admin($role, $adminRoles) || dream_is_seller($role, $sellerRoles);
}

function dream_generate_pdf_preview($sourcePath, $previewDir, $maxPages = 3) {
    if (!is_file($sourcePath)) return 0;
    if (!is_dir($previewDir) && !@mkdir($previewDir, 0775, true)) return 0;
    if (!class_exists('setasign\\Fpdi\\Fpdi')) return 0;
    try {
        $pdf = new setasign\Fpdi\Fpdi();
        $pageCount = $pdf->setSourceFile($sourcePath);
        if ($pageCount < 1) return 0;
        $n = min((int)$maxPages, (int)$pageCount);
        for ($i = 1; $i <= $n; $i++) {
            $templateId = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($templateId);
            if (!$size) continue;
            $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orient, [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
        }
        $previewPath = $previewDir . 'preview_' . basename($sourcePath);
        $pdf->Output($previewPath, 'F');
        return is_file($previewPath) ? $n : 0;
    } catch (Throwable $e) {
        error_log("PDF preview generation error: " . $e->getMessage());
        return 0;
    }
}

function dream_reader_token($productId, $userId, $expires) {
    return hash_hmac('sha256', $productId . '|' . $userId . '|' . $expires, DatabaseConfig::getJwtSecret());
}

function dream_pdf_page_count($filePath) {
    if (!is_file($filePath)) return 0;
    if (!class_exists('setasign\\Fpdi\\Fpdi')) return 0;
    try {
        $pdf = new setasign\Fpdi\Fpdi();
        $count = (int)$pdf->setSourceFile($filePath);
        return $count > 0 ? $count : 0;
    } catch (Throwable $e) {
        error_log('PDF page count error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Returns the real page count of a product PDF, correcting the stored value
 * if the file disagrees with it (manual/estimated page counts are common).
 */
function dream_product_pages(PDO $db, $product) {
    $stored = max(0, (int)$product['pages']);
    $path = __DIR__ . '/../' . ltrim((string)$product['file_path'], '/');
    $real = dream_pdf_page_count($path);
    if ($real > 0) {
        if ($real !== $stored) {
            try {
                $stmt = $db->prepare('UPDATE products SET pages = ? WHERE id = ?');
                $stmt->execute([$real, $product['id']]);
            } catch (Throwable $e) {
                error_log('Could not correct products.pages: ' . $e->getMessage());
            }
        }
        return $real;
    }
    return $stored > 0 ? $stored : 1;
}

/**
 * Buyer (completed purchase), the product's own author, or an admin can read.
 */
function dream_can_read_product(PDO $db, $userId, $userRole, $adminRoles, $product) {
    if ((int)$product['author_id'] === (int)$userId) return true;
    if (dream_is_admin($userRole, $adminRoles)) return true;
    $stmt = $db->prepare("SELECT id FROM product_purchases WHERE user_id = ? AND product_id = ? AND status = 'completed' LIMIT 1");
    $stmt->execute([(int)$userId, (int)$product['id']]);
    return (bool)$stmt->fetch();
}

function dream_resize_uploaded_image($srcPath, $maxWidth = 1200, $maxHeight = 1200, $quality = 85) {
    if (!function_exists('getimagesize')) return false;
    $imageInfo = @getimagesize($srcPath);
    if ($imageInfo === false) return false;

    list($origWidth, $origHeight, $type) = $imageInfo;
    if ($origWidth <= $maxWidth && $origHeight <= $maxHeight) return true;

    $loaders = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_GIF  => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    $savers = [
        IMAGETYPE_JPEG => 'imagejpeg',
        IMAGETYPE_PNG  => 'imagepng',
        IMAGETYPE_GIF  => 'imagegif',
        IMAGETYPE_WEBP => 'imagewebp',
    ];
    if (!isset($loaders[$type])) return false;
    if (!function_exists($loaders[$type]) || !function_exists($savers[$type])) {
        // GD not available on this PHP build — keep the original image instead of crashing.
        return true;
    }

    $src = @$loaders[$type]($srcPath);
    if (!$src) return false;

    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
    $newWidth = (int)round($origWidth * $ratio);
    $newHeight = (int)round($origHeight * $ratio);

    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF])) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

    $saved = false;
    switch ($type) {
        case IMAGETYPE_JPEG: $saved = imagejpeg($dst, $srcPath, $quality); break;
        case IMAGETYPE_PNG:  $saved = imagepng($dst, $srcPath, 9); break;
        case IMAGETYPE_GIF:  $saved = imagegif($dst, $srcPath); break;
        case IMAGETYPE_WEBP: $saved = imagewebp($dst, $srcPath, $quality); break;
    }
    imagedestroy($src);
    imagedestroy($dst);
    return $saved;
}

if (defined('DREAM_PRODUCT_FUNCTIONS_ONLY')) {
    return;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'preview_pdf') {
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!$productId) {
        header('HTTP/1.1 400 Bad Request');
        exit('Invalid product');
    }
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, name, preview_file, preview_pages FROM products WHERE id = ? AND status != 'inactive'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || !$product['preview_file']) {
            header('HTTP/1.1 404 Not Found');
            exit('Free preview not available for this product.');
        }
        $fullPath = __DIR__ . '/../' . ltrim($product['preview_file'], '/');
        if (!is_file($fullPath)) {
            header('HTTP/1.1 404 Not Found');
            exit('Preview file missing.');
        }
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $product['name']) . '-preview.pdf';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($fullPath));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Accept-Ranges: bytes');
        readfile($fullPath);
        exit;
    } catch (Throwable $e) {
        header('HTTP/1.1 500 Server Error');
        exit('Server error');
    }
}

if ($action === 'download_pdf') {
    if (!$auth->isLoggedIn()) {
        header('HTTP/1.1 401 Unauthorized');
        exit('Not logged in');
    }
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!$productId) {
        header('HTTP/1.1 400 Bad Request');
        exit('Invalid product');
    }
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT p.id, p.name, p.file_path, p.file_type, p.author_id FROM products p WHERE p.id = ? AND p.status != 'inactive'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || !$product['file_path'] || $product['file_type'] !== 'pdf') {
            header('HTTP/1.1 404 Not Found');
            exit('PDF not available');
        }
        $isStaff = false;
        if ($userId === (int)$product['author_id']) $isStaff = true;
        if (dream_is_admin($userRole, $adminRoles)) $isStaff = true;
        if (!$isStaff) {
            $stmt = $db->prepare("SELECT id FROM product_purchases WHERE user_id = ? AND product_id = ? AND status = 'completed' LIMIT 1");
            $stmt->execute([$userId, $productId]);
            if ($stmt->fetch()) {
                header('HTTP/1.1 403 Forbidden');
                exit('Downloading is disabled. Please use the in-site reader.');
            }
            header('HTTP/1.1 403 Forbidden');
            exit('Purchase required to download');
        }
        $fullPath = __DIR__ . '/../' . ltrim($product['file_path'], '/');
        if (!is_file($fullPath)) {
            header('HTTP/1.1 404 Not Found');
            exit('File missing');
        }
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $product['name']) . '.pdf';
        $isDownload = !empty($_GET['dl']);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($fullPath));
        header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Accept-Ranges: bytes');
        readfile($fullPath);
        exit;
    } catch (Throwable $e) {
        header('HTTP/1.1 500 Server Error');
        exit('Server error');
    }
}

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Please login first.']);
    exit;
}

/**
 * VIEW-ONLY READER -----------------------------------------------
 * read_meta  : returns total page count + a signed, expiring token.
 * view_page  : streams ONE page of the PDF as an inline document.
 * The token is bound to (product, user, expiry) so links cannot be shared.
 */
if ($action === 'read_meta') {
    $productId = (int)($_REQUEST['product_id'] ?? 0);
    if (!$productId) {
        echo json_encode(['success' => false, 'message' => 'Invalid product.']);
        exit;
    }
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, name, file_path, file_type, author_id, pages FROM products WHERE id = ? AND status != 'inactive'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || !$product['file_path'] || $product['file_type'] !== 'pdf') {
            echo json_encode(['success' => false, 'message' => 'This guide has no readable file yet.']);
            exit;
        }
        if (!dream_can_read_product($db, $userId, $userRole, $adminRoles, $product)) {
            echo json_encode(['success' => false, 'requires_purchase' => true, 'message' => 'You need to buy this guide before you can read it.']);
            exit;
        }
        $pages = dream_product_pages($db, $product);
        $expires = time() + 3600;
        echo json_encode([
            'success' => true,
            'name'    => $product['name'],
            'pages'   => $pages,
            'expires' => $expires,
            'token'   => dream_reader_token($productId, $userId, $expires),
            'can_download' => ((int)$product['author_id'] === (int)$userId || dream_is_admin($userRole, $adminRoles)),
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('read_meta error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
        exit;
    }
}

if ($action === 'view_page') {
    $productId = (int)($_GET['product_id'] ?? 0);
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $expires   = (int)($_GET['expires'] ?? 0);
    $token     = (string)($_GET['token'] ?? '');

    $fail = function ($code, $msg) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        exit($msg);
    };

    if (!$productId || $expires < time() || !hash_equals(dream_reader_token($productId, $userId, $expires), $token)) {
        $fail(403, 'This reading link is invalid or has expired. Re-open the guide to continue.');
    }
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, name, file_path, file_type, author_id, pages FROM products WHERE id = ? AND status != 'inactive'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || !$product['file_path'] || $product['file_type'] !== 'pdf') {
            $fail(404, 'PDF not available');
        }
        if (!dream_can_read_product($db, $userId, $userRole, $adminRoles, $product)) {
            $fail(403, 'Purchase required');
        }
        $totalPages = dream_product_pages($db, $product);
        if ($page > $totalPages) {
            $fail(404, 'Page not found');
        }
        $fullPath = __DIR__ . '/../' . ltrim($product['file_path'], '/');
        if (!is_file($fullPath)) {
            $fail(404, 'File missing');
        }
        if (!class_exists('setasign\\Fpdi\\Fpdi')) {
            $fail(500, 'Reader not available');
        }
        $reader = new setasign\Fpdi\Fpdi();
        $reader->setSourceFile($fullPath);
        $templateId = $reader->importPage($page);
        $size = $reader->getTemplateSize($templateId);
        if (!$size) {
            $fail(500, 'Could not render page');
        }
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $reader->AddPage($orientation, [$size['width'], $size['height']]);
        $reader->useTemplate($templateId);

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $product['name']) . '-p' . $page . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $safeName . '"');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        $reader->Output('I', $safeName);
        exit;
    } catch (Throwable $e) {
        error_log('view_page error: ' . $e->getMessage());
        $fail(500, 'Server error');
    }
}

if ($action === 'buy_product') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!$security->validateCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh the page.']);
        exit;
    }

    if (!$productId) {
        echo json_encode(['success' => false, 'message' => 'Invalid product.']);
        exit;
    }

    try {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT id, name, price, author_id FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM product_purchases WHERE user_id = ? AND product_id = ? AND status = 'completed'");
        $stmt->execute([$userId, $productId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => true, 'message' => 'You already own this product! Redirecting...', 'redirect' => true]);
            exit;
        }

        $price = (float)$product['price'];
        $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $balance = (float)($stmt->fetchColumn() ?: 0);

        if ($balance < $price) {
            echo json_encode(['success' => false, 'message' => 'Insufficient balance. Please add funds first.']);
            exit;
        }

        $authorEarning = round($price * 0.7, 2);

        $db->beginTransaction();

        $stmt = $db->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$price, $userId]);

        if ($product['author_id']) {
            $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
            $stmt->execute([$product['author_id']]);
            $authorBalanceBefore = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$authorEarning, $product['author_id']]);

            $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'credit', ?, ?, ?, ?, 'product_sale')");
            $stmt->execute([$product['author_id'], $authorEarning, $authorBalanceBefore, ($authorBalanceBefore + $authorEarning), 'Sale: ' . $product['name']]);
        }

        $stmt = $db->prepare("INSERT INTO product_purchases (user_id, product_id, amount, status, author_earnings) VALUES (?, ?, ?, 'completed', ?)");
        $stmt->execute([$userId, $productId, $price, $authorEarning]);

        $stmt = $db->prepare("UPDATE products SET sales = sales + 1 WHERE id = ?");
        $stmt->execute([$productId]);

        $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'product_purchase', ?, ?, ?, ?, 'product_purchase')");
        $stmt->execute([$userId, $price, ($balance), ($balance - $price), 'Purchased: ' . $product['name']]);

        if ($product['author_id']) {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, actor_id, type, entity_id, message) VALUES (?, ?, 'product_sale', ?, ?)");
            $stmt->execute([$product['author_id'], $userId, $productId, 'Your guide "' . $product['name'] . '" was sold!']);
        }

        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Purchase successful! You can now read the guide.']);

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Product purchase error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    }
    exit;
}

if (!dream_can_manage_products($userRole, $adminRoles, $sellerRoles)) {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if ($action !== 'get_product' && !$security->validateCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh.']);
    exit;
}

$db = Database::getInstance()->getConnection();

if ($action === 'get_product') {
    $productId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$productId) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        exit;
    }
    try {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
            exit;
        }
        if (!dream_is_admin($userRole, $adminRoles) && (int)$product['author_id'] !== $userId) {
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $product]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_product') {
    $productId = (int)($_POST['id'] ?? 0);
    if (!$productId) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        exit;
    }
    try {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Not found.']);
            exit;
        }
        if (!dream_is_admin($userRole, $adminRoles) && (int)$product['author_id'] !== $userId) {
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
            exit;
        }
        if (!empty($product['file_path'])) {
            $fp = __DIR__ . '/../' . ltrim($product['file_path'], '/');
            if (is_file($fp)) @unlink($fp);
        }
        if (!empty($product['preview_file'])) {
            $pv = __DIR__ . '/../' . ltrim($product['preview_file'], '/');
            if (is_file($pv)) @unlink($pv);
        }
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        echo json_encode(['success' => true, 'message' => 'Product deleted.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'refund_purchase') {
    if (!dream_is_admin($userRole, $adminRoles)) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }
    $purchaseId = (int)($_POST['id'] ?? 0);
    if (!$purchaseId) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        exit;
    }
    try {
        $stmt = $db->prepare("SELECT pp.*, p.name AS product_name, p.author_id, p.sales
                              FROM product_purchases pp
                              LEFT JOIN products p ON p.id = pp.product_id
                              WHERE pp.id = ? LIMIT 1");
        $stmt->execute([$purchaseId]);
        $purchase = $stmt->fetch();
        if (!$purchase) {
            echo json_encode(['success' => false, 'message' => 'Purchase not found.']);
            exit;
        }
        if ($purchase['status'] !== 'completed') {
            echo json_encode(['success' => false, 'message' => 'Only completed purchases can be refunded.']);
            exit;
        }

        $amount = (float)$purchase['amount'];
        $authorEarning = (float)($purchase['author_earnings'] ?? 0);
        $buyerId = (int)$purchase['user_id'];
        $authorId = (int)($purchase['author_id'] ?? 0);

        $db->beginTransaction();

        $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$buyerId]);
        $buyerBefore = (float)($stmt->fetchColumn() ?: 0);

        $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$amount, $buyerId]);

        $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'refund', ?, ?, ?, ?, 'product_refund')");
        $stmt->execute([$buyerId, $amount, $buyerBefore, ($buyerBefore + $amount), 'Refund: ' . ($purchase['product_name'] ?? 'product')]);

        if ($authorId && $authorEarning > 0) {
            $stmt = $db->prepare("UPDATE users SET balance = GREATEST(balance - ?, 0) WHERE id = ?");
            $stmt->execute([$authorEarning, $authorId]);
        }

        if (!empty($purchase['product_id'])) {
            $stmt = $db->prepare("UPDATE products SET sales = GREATEST(sales - 1, 0) WHERE id = ?");
            $stmt->execute([(int)$purchase['product_id']]);
        }

        $stmt = $db->prepare("UPDATE product_purchases SET status = 'refunded' WHERE id = ?");
        $stmt->execute([$purchaseId]);

        $stmt = $db->prepare("INSERT INTO notifications (user_id, actor_id, type, entity_id, message) VALUES (?, ?, 'product_refund', ?, ?)");
        $stmt->execute([$buyerId, $userId, (int)($purchase['product_id'] ?? 0), 'Your payment for "' . ($purchase['product_name'] ?? 'a product') . '" was refunded.']);

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Refund completed. ' . number_format($amount, 2) . ' returned to the buyer.']);
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log("Product refund error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    }
    exit;
}

if ($action === 'add_product' || $action === 'update_product') {
    $productId = $action === 'update_product' ? (int)($_POST['id'] ?? 0) : 0;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $shortDesc = trim($_POST['short_desc'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $category = trim($_POST['category'] ?? 'pdf');
    $stock = (int)($_POST['stock'] ?? 0);
    $status = trim($_POST['status'] ?? 'active');
    $badge = trim($_POST['badge'] ?? '');
    $badgeColor = trim($_POST['badge_color'] ?? '');
    $pages = (int)($_POST['pages'] ?? 0);
    $previewText = trim($_POST['preview_text'] ?? '');
    $contentLong = trim($_POST['content_long'] ?? '');
    $rating = (float)($_POST['rating'] ?? 0);
    $sales = (int)($_POST['sales'] ?? 0);

    if (!in_array($status, ['active','inactive','pending'], true)) $status = 'active';
    if (!in_array($category, ['pdf','topup','physical','digital','other'], true)) $category = 'pdf';
    if (!dream_is_admin($userRole, $adminRoles) && $status === 'active') {
        $status = 'pending';
    }

    $errors = [];
    if ($name === '') $errors[] = 'Product name is required.';
    if ($price < 0) $errors[] = 'Price cannot be negative.';
    if ($stock < 0) $errors[] = 'Stock cannot be negative.';
    if ($pages < 0) $errors[] = 'Pages cannot be negative.';
    if (strlen($name) > 150) $errors[] = 'Product name is too long.';
    if ($rating < 0 || $rating > 5) $rating = 0;

    $existingProduct = null;
    if ($action === 'update_product') {
        if (!$productId) $errors[] = 'Invalid product ID.';
        else {
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $existingProduct = $stmt->fetch();
            if (!$existingProduct) $errors[] = 'Product not found.';
            elseif (!dream_is_admin($userRole, $adminRoles) && (int)$existingProduct['author_id'] !== $userId) {
                $errors[] = 'Permission denied.';
            }
        }
    }
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    $imagePath = $existingProduct['image'] ?? null;
    if (isset($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $img = $_FILES['image'];
        if ($img['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Image upload error (code ' . $img['error'] . ')']);
            exit;
        }
        $allowedTypes = ['image/jpeg','image/png','image/gif','image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($img['tmp_name']);
        if (!in_array($detectedMime, $allowedTypes, true)) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, or WEBP images allowed.']);
            exit;
        }
        if ($img['size'] > 8 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Image too large (max 8MB).']);
            exit;
        }
        $uploadDir = __DIR__ . '/../assets/products/';
        $ext = 'jpg';
        switch ($detectedMime) {
            case 'image/png': $ext = 'png'; break;
            case 'image/gif': $ext = 'gif'; break;
            case 'image/webp': $ext = 'webp'; break;
            default: $ext = 'jpg';
        }
        $basename = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $uploadDir . $basename;
        if (!move_uploaded_file($img['tmp_name'], $dest)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save image.']);
            exit;
        }
        dream_resize_uploaded_image($dest);
        if ($imagePath && $existingProduct) {
            $old = __DIR__ . '/../' . ltrim($imagePath, '/');
            if (is_file($old)) @unlink($old);
        }
        $imagePath = 'assets/products/' . $basename;
    }

    $filePath = $existingProduct['file_path'] ?? null;
    $fileType = $existingProduct['file_type'] ?? null;
    if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File upload error (code ' . $file['error'] . ')']);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        $allowedPdf = ['application/pdf', 'application/x-pdf', 'application/octet-stream'];
        if (!in_array($detectedMime, $allowedPdf, true)) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                echo json_encode(['success' => false, 'message' => 'Only PDF files allowed for upload.']);
                exit;
            }
        }
        if ($file['size'] > 50 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'PDF too large (max 50MB).']);
            exit;
        }
        $uploadDir = __DIR__ . '/../assets/pdfs/';
        $basename = 'guide_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $dest = $uploadDir . $basename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save PDF.']);
            exit;
        }
        if ($filePath && $existingProduct) {
            $old = __DIR__ . '/../' . ltrim($filePath, '/');
            if (is_file($old)) @unlink($old);
        }
        $filePath = 'assets/pdfs/' . $basename;
        $fileType = 'pdf';

        $previewFile = null;
        $previewPages = 0;
        try {
            $previewDir = __DIR__ . '/../assets/pdfs/preview/';
            $previewPages = dream_generate_pdf_preview($dest, $previewDir);
            if ($previewPages > 0) {
                $previewFile = 'assets/pdfs/preview/preview_' . $basename;
                if ($existingProduct && !empty($existingProduct['preview_file'])) {
                    $oldPreview = __DIR__ . '/../' . ltrim($existingProduct['preview_file'], '/');
                    if (is_file($oldPreview)) @unlink($oldPreview);
                }
            }
        } catch (Throwable $e) {
            $previewFile = null;
            $previewPages = 0;
        }
    } else {
        $previewFile = $existingProduct['preview_file'] ?? null;
        $previewPages = (int)($existingProduct['preview_pages'] ?? 0);
    }

    $statusNote = '';
    if ($status === 'active' && (empty($filePath) || $fileType !== 'pdf')) {
        $status = 'pending';
        $statusNote = ' No PDF attached, so the product was saved as Pending. Upload the file to publish it.';
    }

    try {
        if ($action === 'add_product') {
            $authorId = $userId;
            $stmt = $db->prepare("INSERT INTO products (
                name, description, short_desc, price, image, file_path, file_type,
                badge, badge_color, rating, sales, pages, category, stock, status,
                author_id, preview_text, content_long, preview_file, preview_pages
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $name, $description, $shortDesc, $price, $imagePath, $filePath, $fileType,
                $badge, $badgeColor, $rating, $sales, $pages, $category, $stock, $status,
                $authorId, $previewText, $contentLong, $previewFile, $previewPages
            ]);
            $msg = dream_is_admin($userRole, $adminRoles)
                ? 'Product created successfully.'
                : 'Product submitted for admin approval.';
            echo json_encode(['success' => true, 'message' => $msg . $statusNote, 'id' => (int)$db->lastInsertId()]);
        } else {
            $fields = "name=?, description=?, short_desc=?, price=?, image=?, file_path=?, file_type=?,
                       badge=?, badge_color=?, rating=?, sales=?, pages=?, category=?, stock=?, status=?,
                       preview_text=?, content_long=?, preview_file=?, preview_pages=?";
            $params = [
                $name, $description, $shortDesc, $price, $imagePath, $filePath, $fileType,
                $badge, $badgeColor, $rating, $sales, $pages, $category, $stock, $status,
                $previewText, $contentLong, $previewFile, $previewPages,
                $productId
            ];
            $stmt = $db->prepare("UPDATE products SET $fields WHERE id=?");
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Product updated.' . $statusNote]);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
