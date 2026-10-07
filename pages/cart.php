<?php
require_once __DIR__ . '/../database/config.php';

$viewerId = $_SESSION['user_id'] ?? null;
$isLoggedIn = !empty($viewerId);

$balance = 0.0;
$items = [];
$purchases = [];
$topups = [];

if ($isLoggedIn) {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$viewerId]);
    $balance = (float)($stmt->fetchColumn() ?: 0);

    try {
        $stmt = $db->prepare("
            SELECT ci.id AS cart_id, ci.created_at AS added_at,
                   p.id, p.name, p.price, p.image, p.pages, p.category,
                   p.author_id, p.file_path, p.file_type, p.status,
                   a.full_name AS author_name, a.username AS author_username,
                   EXISTS(SELECT 1 FROM product_purchases pp
                          WHERE pp.user_id = ci.user_id AND pp.product_id = ci.product_id
                            AND pp.status = 'completed') AS owned
            FROM cart_items ci
            JOIN products p ON p.id = ci.product_id
            LEFT JOIN users a ON a.id = p.author_id
            WHERE ci.user_id = ?
            ORDER BY ci.created_at DESC
        ");
        $stmt->execute([$viewerId]);
        $items = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $items = [];
    }

    try {
        $stmt = $db->prepare("
            SELECT pp.id, pp.amount, pp.status, pp.created_at, pp.product_id,
                   p.name AS product_name, p.image, p.file_path, p.file_type
            FROM product_purchases pp
            LEFT JOIN products p ON p.id = pp.product_id
            WHERE pp.user_id = ?
            ORDER BY pp.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$viewerId]);
        $purchases = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $purchases = [];
    }

    try {
        $stmt = $db->prepare("
            SELECT id, game_name, package_name, amount, status, player_uid, created_at
            FROM topup_orders
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$viewerId]);
        $topups = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $topups = [];
    }
}

$subtotal = 0.0;
foreach ($items as $it) {
    if (!$it['owned'] && $it['status'] === 'active') $subtotal += (float)$it['price'];
}
$subtotal = round($subtotal, 2);
$canCheckout = $subtotal > 0 && $balance >= $subtotal;
$activeTab = ($_GET['tab'] ?? 'cart') === 'orders' ? 'orders' : 'cart';

function cart_img($image) {
    if (!$image) return '';
    if (preg_match('#^(https?:)?//#i', $image) || str_starts_with($image, 'assets/')) return htmlspecialchars($image);
    return 'assets/products/' . htmlspecialchars(ltrim($image, '/'));
}
?>
<link rel="stylesheet" href="<?php echo dream_asset('assets/css/cart.css'); ?>">

<div class="cart-wrap">
    <header class="cart-hero">
        <span class="cart-kicker"><i class="fas fa-cart-shopping"></i> Cart &amp; Orders</span>
        <h1>Your Cart</h1>
        <p>Review your guides, then check out using your DreamBD balance. Purchases unlock instantly.</p>
        <?php if ($isLoggedIn): ?>
        <div class="cart-balance-chip">
            <i class="fas fa-wallet"></i>
            <span>Balance</span>
            <strong>৳<?php echo number_format($balance, 2); ?></strong>
        </div>
        <?php endif; ?>
    </header>

    <nav class="cart-tabs">
        <a href="index.php?page=cart&tab=cart" class="cart-tab <?php echo $activeTab === 'cart' ? 'is-active' : ''; ?>">
            <i class="fas fa-basket-shopping"></i> Cart
            <span class="cart-tab-count"><?php echo count($items); ?></span>
        </a>
        <a href="index.php?page=cart&tab=orders" class="cart-tab <?php echo $activeTab === 'orders' ? 'is-active' : ''; ?>">
            <i class="fas fa-receipt"></i> My Orders
            <span class="cart-tab-count"><?php echo count($purchases) + count($topups); ?></span>
        </a>
    </nav>

    <?php if (!$isLoggedIn): ?>
        <div class="cart-empty">
            <div class="cart-empty-icon"><i class="fas fa-lock"></i></div>
            <h2>Please log in</h2>
            <p>You need to be logged in to view your cart and orders.</p>
            <a class="cart-btn cart-btn--primary" href="index.php?page=login" data-page="login"><i class="fas fa-right-to-bracket"></i> Log in</a>
        </div>

    <?php elseif ($activeTab === 'cart'): ?>
        <?php if (!$items): ?>
        <div class="cart-empty">
            <div class="cart-empty-icon"><i class="fas fa-basket-shopping"></i></div>
            <h2>Your cart is empty</h2>
            <p>Browse premium PDF guides and add the ones you want.</p>
            <a class="cart-btn cart-btn--primary" href="index.php?page=products" data-page="products"><i class="fas fa-store"></i> Browse Products</a>
        </div>
        <?php else: ?>
        <div class="cart-layout">
            <div class="cart-list">
                <?php foreach ($items as $it): ?>
                <article class="cart-item <?php echo $it['owned'] ? 'is-owned' : ''; ?><?php echo $it['status'] !== 'active' ? ' is-off' : ''; ?>" data-pid="<?php echo (int)$it['id']; ?>">
                    <div class="cart-item-thumb">
                        <?php if ($it['image']): ?>
                            <img src="<?php echo cart_img($it['image']); ?>" alt="" onerror="this.remove()">
                        <?php else: ?>
                            <span class="cart-item-fallback"><i class="fas fa-file-pdf"></i></span>
                        <?php endif; ?>
                    </div>
                    <div class="cart-item-body">
                        <div class="cart-item-meta">
                            <span class="cart-item-cat"><i class="fas <?php echo $it['category'] === 'topup' ? 'fa-gamepad' : 'fa-file-pdf'; ?>"></i> <?php echo htmlspecialchars(ucfirst($it['category'])); ?></span>
                            <?php if ($it['pages']): ?><span class="cart-item-pages"><?php echo (int)$it['pages']; ?> pages</span><?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($it['name']); ?></h3>
                        <p class="cart-item-author">by <?php echo htmlspecialchars($it['author_name'] ?: ($it['author_username'] ?? 'DreamBD')); ?></p>
                        <?php if ($it['owned']): ?>
                            <span class="cart-item-flag cart-item-flag--owned"><i class="fas fa-circle-check"></i> Already owned</span>
                        <?php elseif ($it['status'] !== 'active'): ?>
                            <span class="cart-item-flag cart-item-flag--off"><i class="fas fa-circle-exclamation"></i> Unavailable</span>
                        <?php endif; ?>
                    </div>
                    <div class="cart-item-side">
                        <div class="cart-item-price">৳<?php echo number_format((float)$it['price'], 2); ?></div>
                        <?php if ($it['owned']): ?>
                            <a class="cart-item-read" href="index.php?page=products" data-page="products"><i class="fas fa-book-open"></i> Read</a>
                        <?php else: ?>
                            <button type="button" class="cart-item-remove" data-pid="<?php echo (int)$it['id']; ?>" aria-label="Remove">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>

                <div class="cart-list-actions">
                    <button type="button" class="cart-link-btn" id="cartClearBtn"><i class="fas fa-xmark"></i> Clear cart</button>
                    <a class="cart-link-btn" href="index.php?page=products" data-page="products"><i class="fas fa-plus"></i> Add more guides</a>
                </div>
            </div>

            <aside class="cart-summary">
                <h2><i class="fas fa-clipboard-list"></i> Order Summary</h2>
                <div class="cart-sum-row"><span>Items</span><strong><?php echo count($items); ?></strong></div>
                <div class="cart-sum-row"><span>Subtotal</span><strong>৳<?php echo number_format($subtotal, 2); ?></strong></div>
                <div class="cart-sum-row"><span>Your balance</span><strong class="<?php echo $balance >= $subtotal ? '' : 'is-short'; ?>">৳<?php echo number_format($balance, 2); ?></strong></div>
                <div class="cart-sum-total">
                    <span>Total</span>
                    <strong>৳<?php echo number_format($subtotal, 2); ?></strong>
                </div>

                <?php if ($subtotal > 0 && $balance < $subtotal): ?>
                <div class="cart-sum-warn">
                    <i class="fas fa-triangle-exclamation"></i>
                    Short by ৳<?php echo number_format($subtotal - $balance, 2); ?>.
                    <a href="index.php?page=balance" data-page="balance">Add funds</a>
                </div>
                <?php endif; ?>

                <button type="button" class="cart-btn cart-btn--primary cart-btn--block" id="cartCheckoutBtn" <?php echo $canCheckout ? '' : 'disabled'; ?>>
                    <i class="fas fa-lock"></i> Checkout
                </button>
                <p class="cart-sum-note"><i class="fas fa-bolt"></i> Instant access after payment. Charged from your balance.</p>
            </aside>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <?php if (!$purchases && !$topups): ?>
        <div class="cart-empty">
            <div class="cart-empty-icon"><i class="fas fa-receipt"></i></div>
            <h2>No orders yet</h2>
            <p>Your guide purchases and game top-ups will show up here.</p>
            <a class="cart-btn cart-btn--primary" href="index.php?page=products" data-page="products"><i class="fas fa-store"></i> Browse Products</a>
        </div>
        <?php else: ?>

        <?php if ($purchases): ?>
        <section class="cart-orders">
            <h2 class="cart-orders-title"><i class="fas fa-file-pdf"></i> Guide Purchases</h2>
            <?php foreach ($purchases as $o): ?>
            <div class="cart-order-row">
                <div class="cart-order-main">
                    <strong><?php echo htmlspecialchars($o['product_name'] ?? 'Deleted product'); ?></strong>
                    <span><?php echo date('M j, Y g:i A', strtotime($o['created_at'])); ?> &middot; #<?php echo (int)$o['id']; ?></span>
                </div>
                <div class="cart-order-amount">৳<?php echo number_format((float)$o['amount'], 2); ?></div>
                <span class="cart-order-status is-<?php echo htmlspecialchars($o['status']); ?>"><?php echo ucfirst($o['status']); ?></span>
                <?php if ($o['status'] === 'completed'): ?>
                    <a class="cart-order-action" href="index.php?page=products" data-page="products"><i class="fas fa-book-open"></i> Read</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($topups): ?>
        <section class="cart-orders">
            <h2 class="cart-orders-title"><i class="fas fa-gamepad"></i> Game Top-Ups</h2>
            <?php foreach ($topups as $t): ?>
            <div class="cart-order-row">
                <div class="cart-order-main">
                    <strong><?php echo htmlspecialchars($t['game_name'] . ' — ' . $t['package_name']); ?></strong>
                    <span><?php echo date('M j, Y g:i A', strtotime($t['created_at'])); ?> &middot; UID <?php echo htmlspecialchars($t['player_uid']); ?></span>
                </div>
                <div class="cart-order-amount">৳<?php echo number_format((float)$t['amount'], 2); ?></div>
                <span class="cart-order-status is-<?php echo htmlspecialchars($t['status']); ?>"><?php echo ucfirst($t['status']); ?></span>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    const HANDLER = 'handlers/cart_handler.php';
    const CSRF = document.body.dataset.csrfToken || '';

    async function call(payload) {
        const fd = new FormData();
        Object.keys(payload).forEach(k => fd.append(k, payload[k]));
        fd.append('csrf_token', CSRF);
        const res = await fetch(HANDLER, { method: 'POST', body: fd });
        return res.json();
    }

    function reload() { window.location.reload(); }

    document.querySelectorAll('.cart-item-remove').forEach(btn => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            const data = await call({ action: 'remove', product_id: btn.dataset.pid });
            if (data.success) reload(); else { btn.disabled = false; alert(data.message); }
        });
    });

    const clearBtn = document.getElementById('cartClearBtn');
    if (clearBtn) clearBtn.addEventListener('click', async () => {
        if (!confirm('Remove everything from your cart?')) return;
        const data = await call({ action: 'clear' });
        if (data.success) reload(); else alert(data.message);
    });

    const checkoutBtn = document.getElementById('cartCheckoutBtn');
    if (checkoutBtn) checkoutBtn.addEventListener('click', async () => {
        if (checkoutBtn.dataset.busy) return;
        checkoutBtn.dataset.busy = '1';
        checkoutBtn.disabled = true;
        const original = checkoutBtn.innerHTML;
        checkoutBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        try {
            const data = await call({ action: 'checkout' });
            if (data.success) {
                checkoutBtn.innerHTML = '<i class="fas fa-check"></i> Done';
                reload();
            } else {
                alert(data.message);
                checkoutBtn.innerHTML = original;
                checkoutBtn.disabled = false;
                delete checkoutBtn.dataset.busy;
            }
        } catch (e) {
            alert('Network error. Please try again.');
            checkoutBtn.innerHTML = original;
            checkoutBtn.disabled = false;
            delete checkoutBtn.dataset.busy;
        }
    });
})();
</script>
