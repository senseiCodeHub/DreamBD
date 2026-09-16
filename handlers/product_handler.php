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

function dream_resize_uploaded_image($srcPath, $maxWidth = 1200, $maxHeight = 1200, $quality = 85) {
    $imageInfo = @getimagesize($srcPath);
    if ($imageInfo === false) return false;

    list($origWidth, $origHeight, $type) = $imageInfo;
    if ($origWidth <= $maxWidth && $origHeight <= $maxHeight) return true;

    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcPath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcPath); break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($srcPath); break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($srcPath); break;
        default: return false;
    }
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
        $isOwner = false;
        if ($userId === (int)$product['author_id']) $isOwner = true;
        if (dream_is_admin($userRole, $adminRoles)) $isOwner = true;
        if (!$isOwner) {
            $stmt = $db->prepare("SELECT id FROM product_purchases WHERE user_id = ? AND product_id = ? AND status = 'completed' LIMIT 1");
            $stmt->execute([$userId, $productId]);
            $isOwner = (bool)$stmt->fetch();
        }
        if (!$isOwner) {
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
            echo json_encode(['success' => true, 'message' => $msg, 'id' => (int)$db->lastInsertId()]);
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
            echo json_encode(['success' => true, 'message' => 'Product updated.']);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
