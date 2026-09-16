<?php
$db = Database::getInstance()->getConnection();
$viewerId = $_SESSION['user_id'] ?? null;
$viewerRole = $_SESSION['role'] ?? 'user';
$isSellerOrAdmin = in_array($viewerRole, ['merchant','seller','admin','moderator','super_admin'], true);
$userBalance = 0;
$purchasedProductIds = [];
if ($viewerId) {
    $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$viewerId]);
    $userBalance = (float)($stmt->fetchColumn() ?: 0);
    $stmt = $db->prepare("SELECT product_id FROM product_purchases WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$viewerId]);
    $purchasedProductIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Authors from DB (users who have active products in 'pdf' category)
$authors = $db->query("
    SELECT u.id, u.full_name AS name, u.avatar, COALESCE(u.bio, 'Author') AS title,
           COUNT(p.id) AS products,
           ROUND(AVG(p.rating), 1) AS rating,
           SUM(p.sales) AS sales
    FROM users u
    INNER JOIN products p ON p.author_id = u.id AND p.status = 'active' AND p.category = 'pdf'
    GROUP BY u.id
    ORDER BY sales DESC
")->fetchAll(PDO::FETCH_ASSOC);

// PDF products from DB
$pdfProducts = $db->query("
    SELECT id, author_id, name, description, short_desc, price, image, file_path, file_type,
           badge, badge_color, rating, sales, pages,
           preview_text AS preview, preview_file, preview_pages
    FROM products
    WHERE status = 'active' AND category = 'pdf'
    ORDER BY sales DESC
")->fetchAll(PDO::FETCH_ASSOC);
// Cast numeric types so JSON encodes properly
$pdfProducts = array_map(function($p) {
    $p['id'] = (int)$p['id'];
    $p['author_id'] = (int)$p['author_id'];
    $p['price'] = (float)$p['price'];
    $p['rating'] = (float)$p['rating'];
    $p['sales'] = (int)$p['sales'];
    $p['pages'] = (int)$p['pages'];
    $p['preview_pages'] = (int)$p['preview_pages'];
    return $p;
}, $pdfProducts);
$authors = array_map(function($a) {
    $a['id'] = (int)$a['id'];
    $a['products'] = (int)$a['products'];
    $a['rating'] = (float)$a['rating'];
    $a['sales'] = (int)$a['sales'];
    return $a;
}, $authors);

// Game Top-Up catalog from DB (managed by admin / moderator)
$games = [];
try {
    $gameRows = $db->query("SELECT * FROM game_topup_games WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $pkgRows = $db->query("SELECT * FROM game_topup_packages WHERE status = 'active' ORDER BY sort_order ASC, price ASC")->fetchAll(PDO::FETCH_ASSOC);
    $packagesByGame = [];
    foreach ($pkgRows as $pkg) $packagesByGame[(int)$pkg['game_id']][] = $pkg;
    foreach ($gameRows as $game) {
        $gid = (int)$game['id'];
        $games[] = [
            'id' => $gid,
            'slug' => $game['slug'],
            'name' => $game['name'],
            'icon' => $game['icon'],
            'gradient' => $game['gradient'],
            'shadow' => $game['shadow_color'],
            'description' => $game['description'],
            'packages' => array_map(function($p) {
                return [
                    'id' => (int)$p['id'],
                    'name' => $p['name'],
                    'price' => (float)$p['price'],
                    'badge' => $p['badge'] ?? '',
                    'badge_color' => $p['badge_color'] ?? ''
                ];
            }, $packagesByGame[$gid] ?? []),
        ];
    }
} catch (Throwable $e) {
    $games = [];
}
?>
<div class="dp-wrap">

    <!-- ═══ HERO ═══ -->
    <section class="dp-hero">
        <div class="dp-hero-bg"></div>
        <div class="dp-hero-particles" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span>
            <span></span><span></span>
        </div>
        <div class="dp-hero-inner">
            <span class="dp-kicker"><i class="fas fa-store"></i> DreamBD Store</span>
            <h1>Learn, Play &amp; Top-Up</h1>
            <p>Premium game guides by top authors &amp; instant game top-ups — all in one place.</p>
            <div class="dp-hero-actions">
                <a href="#pdf-section" class="dp-hero-btn dp-hero-btn--primary">
                    <i class="fas fa-file-pdf"></i> Browse Guides
                </a>
                <a href="#topup-section" class="dp-hero-btn dp-hero-btn--outline">
                    <i class="fas fa-gamepad"></i> Top-Up Now
                </a>
            </div>
            <?php if ($isSellerOrAdmin): ?>
            <a href="index.php?page=seller-upload" class="inline-flex items-center gap-2 mt-5 px-5 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-2xl font-semibold shadow-xl hover:scale-[1.02] transition">
                <i class="fas fa-cloud-upload"></i>
                Upload Your Product / Guide
                <span class="bg-white/20 px-2.5 py-0.5 rounded-full text-xs font-bold">Earn 70%</span>
            </a>
            <?php endif; ?>
            <div class="dp-hero-stats">
                <div class="dp-hero-stat"><strong><?php echo count($pdfProducts) + count($games); ?>+</strong><span>Products</span></div>
                <div class="dp-hero-stat"><strong><?php echo count($authors); ?></strong><span>Authors</span></div>
                <div class="dp-hero-stat"><strong>4.8</strong><span><i class="fas fa-star" style="color:#f59e0b"></i> Rating</span></div>
            </div>
        </div>
    </section>

    <!-- ═══ SECTION NAV ═══ -->
    <nav class="dp-nav">
        <a href="#pdf-section" class="dp-nav-link dp-nav-link--pdf">
            <span class="dp-nav-icon"><i class="fas fa-file-pdf"></i></span>
            <span class="dp-nav-label">PDF Guides</span>
            <span class="dp-nav-count"><?php echo count($pdfProducts); ?></span>
        </a>
        <a href="#topup-section" class="dp-nav-link dp-nav-link--topup">
            <span class="dp-nav-icon"><i class="fas fa-gamepad"></i></span>
            <span class="dp-nav-label">Game Top-Up</span>
            <span class="dp-nav-count"><?php echo count($games); ?></span>
        </a>
    </nav>

    <!-- ═══ PDF GUIDES SECTION ═══ -->
    <section class="dp-section" id="pdf-section">
        <div class="dp-section-hd">
            <div>
                <span class="dp-section-tag"><i class="fas fa-file-pdf"></i> PDF Guides</span>
                <h2>Learn from the Best</h2>
                <p>Premium game guides written by top players &amp; coaches. Preview free, buy to unlock full content.</p>
            </div>
            <div class="dp-section-hd-stats">
                <div class="dp-hd-stat"><strong><?php echo count($authors); ?></strong> Authors</div>
                <div class="dp-hd-stat"><strong><?php echo count($pdfProducts); ?></strong> Guides</div>
            </div>
        </div>

        <!-- Authors Row -->
        <div class="dp-authors-row">
            <?php foreach ($authors as $author): ?>
            <div class="dp-author-card">
                <div class="dp-author-avatar">
                    <img src="assets/avatars/<?php echo htmlspecialchars($author['avatar']); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                </div>
                <div class="dp-author-info">
                    <strong><?php echo htmlspecialchars($author['name']); ?></strong>
                    <span><?php echo htmlspecialchars($author['title']); ?></span>
                </div>
                <div class="dp-author-meta">
                    <span><i class="fas fa-book"></i> <?php echo $author['products']; ?></span>
                    <span><i class="fas fa-star" style="color:#f59e0b"></i> <?php echo $author['rating']; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Product Grid -->
        <div class="dp-pdf-grid">
            <?php foreach ($pdfProducts as $product):
                $author = current(array_filter($authors, fn($a) => $a['id'] === $product['author_id']));
                $isPurchased = in_array($product['id'], $purchasedProductIds);
                $authorEarning = round($product['price'] * 0.7);
            ?>
            <article class="dp-pdf-card" data-product-id="<?php echo $product['id']; ?>">
                <?php if ($product['badge']): ?>
                    <span class="dp-pdf-badge" style="--badge: <?php echo $product['badge_color']; ?>"><?php echo $product['badge']; ?></span>
                <?php endif; ?>
                <div class="dp-pdf-media">
                    <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" onerror="this.src='https://picsum.photos/seed/fallback/400/300'">
                    <div class="dp-pdf-media-overlay">
                        <span class="dp-pdf-pages"><i class="fas fa-file-lines"></i> <?php echo $product['pages']; ?> pages</span>
                    </div>
                </div>
                <div class="dp-pdf-body">
                    <div class="dp-pdf-author">
                        <img src="assets/avatars/<?php echo htmlspecialchars($author['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                        <span><?php echo htmlspecialchars($author['name'] ?? 'Unknown'); ?></span>
                    </div>
                    <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                    <p><?php echo htmlspecialchars($product['short_desc']); ?></p>
                    <div class="dp-pdf-rating">
                        <span class="dp-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?php echo $i <= floor($product['rating']) ? 'filled' : ''; ?>"></i>
                            <?php endfor; ?>
                        </span>
                        <span class="dp-rating-val"><?php echo $product['rating']; ?></span>
                        <span class="dp-sales">(<?php echo number_format($product['sales']); ?> sold)</span>
                    </div>
                    <div class="dp-pdf-footer">
                        <div class="dp-pdf-price">
                            <strong>৳<?php echo number_format($product['price']); ?></strong>
                            <small>Author earns ৳<?php echo number_format($authorEarning); ?></small>
                        </div>
                        <div class="dp-pdf-actions">
                            <button type="button" class="dp-btn-preview" data-preview-id="<?php echo $product['id']; ?>"><i class="fas fa-eye"></i> Preview</button>
                            <?php if ($isPurchased): ?>
                                <a href="handlers/product_handler.php?action=download_pdf&amp;product_id=<?php echo $product['id']; ?>&amp;dl=1" target="_blank" class="dp-btn-preview" style="background:#3b82f6;color:#fff"><i class="fas fa-download"></i> Download</a>
                                <button type="button" class="dp-btn-read" data-read-id="<?php echo $product['id']; ?>"><i class="fas fa-book-open"></i> Read</button>
                            <?php else: ?>
                                <button type="button" class="dp-btn-buy" data-buy-id="<?php echo $product['id']; ?>" data-price="<?php echo $product['price']; ?>" data-name="<?php echo htmlspecialchars($product['name']); ?>"><i class="fas fa-cart-plus"></i> Buy</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ═══ GAME TOP-UP SECTION ═══ -->
    <section class="dp-section" id="topup-section">
        <div class="dp-section-hd">
            <div>
                <span class="dp-section-tag" style="--tag-color: #8b5cf6; --tag-bg: rgba(139,92,246,0.12);"><i class="fas fa-gamepad"></i> Game Top-Up</span>
                <h2>Instant Game Top-Ups</h2>
                <p>Select your game to view available diamond, UC &amp; currency packages. Instant delivery.</p>
            </div>
            <div class="dp-section-hd-stats">
                <div class="dp-hd-stat"><strong><?php echo count($games); ?></strong> Games</div>
                <div class="dp-hd-stat"><strong>Instant</strong> Delivery</div>
            </div>
        </div>

        <div class="dp-games-grid">
            <?php foreach ($games as $game): ?>
            <button type="button" class="dp-game-card" data-game-id="<?php echo $game['id']; ?>" style="--g-grad: <?php echo $game['gradient']; ?>; --g-shadow: <?php echo $game['shadow']; ?>">
                <div class="dp-game-card-bg"></div>
                <div class="dp-game-icon"><i class="fas <?php echo $game['icon']; ?>"></i></div>
                <h3><?php echo $game['name']; ?></h3>
                <p><?php echo $game['description']; ?></p>
                <span class="dp-game-cta">View Packages <i class="fas fa-arrow-right"></i></span>
            </button>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ═══ FEATURES ═══ -->
    <section class="dp-features">
        <div class="dp-features-grid">
            <div class="dp-feat-card" style="--fc: #3b82f6;">
                <div class="dp-feat-icon"><i class="fas fa-bolt"></i></div>
                <h4>Instant Delivery</h4>
                <p>Get access immediately after purchase.</p>
            </div>
            <div class="dp-feat-card" style="--fc: #10b981;">
                <div class="dp-feat-icon"><i class="fas fa-shield-halved"></i></div>
                <h4>Secure Payments</h4>
                <p>Pay with your DreamBD balance securely.</p>
            </div>
            <div class="dp-feat-card" style="--fc: #8b5cf6;">
                <div class="dp-feat-icon"><i class="fas fa-headset"></i></div>
                <h4>24/7 Support</h4>
                <p>We are here to help anytime.</p>
            </div>
            <div class="dp-feat-card" style="--fc: #f59e0b;">
                <div class="dp-feat-icon"><i class="fas fa-hand-holding-dollar"></i></div>
                <h4>Authors Get Paid</h4>
                <p>70% revenue goes to guide creators.</p>
            </div>
        </div>
    </section>

</div>

<!-- ══ PREVIEW MODAL ══ -->
<div class="dp-modal-overlay" id="dpPreviewOverlay">
    <div class="dp-modal dp-modal--wide" id="dpPreviewModal">
        <button class="dp-modal-x" id="dpPreviewClose"><i class="fas fa-times"></i></button>
        <div class="dp-preview-layout">
            <div class="dp-preview-cover">
                <img id="previewCover" src="" alt="">
                <div class="dp-preview-cover-info">
                    <span id="previewPages"></span>
                    <span id="previewPrice"></span>
                </div>
            </div>
            <div class="dp-preview-content">
                <span class="dp-kicker" style="margin-bottom:0.5rem;"><i class="fas fa-file-pdf"></i> Preview</span>
                <h2 id="previewTitle"></h2>
                <div class="dp-preview-author" id="previewAuthor"></div>
                <div class="dp-preview-divider"></div>
                <div id="previewFreeBadge"></div>
                <div class="dp-preview-frame-wrap" id="previewFrameWrap" style="display:none;">
                    <iframe id="previewFrame" src="" style="width:100%;height:420px;border:1px solid rgba(148,163,184,0.3);border-radius:0.75rem;background:#fff;" title="Free preview"></iframe>
                    <p class="dp-read-note" style="margin-top:0.6rem"><i class="fas fa-info-circle"></i> This is a <strong>free preview</strong> — only the first few pages. Buy to read the full guide.</p>
                </div>
                <p id="previewText"></p>
                <div class="dp-preview-divider"></div>
                <div class="dp-preview-actions" id="previewActions"></div>
            </div>
        </div>
    </div>
</div>

<!-- ══ READ MODAL ══ -->
<div class="dp-modal-overlay" id="dpReadOverlay">
    <div class="dp-modal dp-modal--full" id="dpReadModal">
        <div class="dp-read-hd">
            <div>
                <span class="dp-kicker" style="margin:0;"><i class="fas fa-book-open"></i> Reading</span>
                <h2 id="readTitle"></h2>
                <div id="readAuthor" class="dp-preview-author"></div>
            </div>
            <button class="dp-modal-x" id="dpReadClose"><i class="fas fa-times"></i></button>
        </div>
        <div class="dp-read-body" id="readBody"></div>
    </div>
</div>

<!-- ══ BUY MODAL ══ -->
<div class="dp-modal-overlay" id="dpBuyOverlay">
    <div class="dp-modal dp-modal--sm" id="dpBuyModal">
        <button class="dp-modal-x" id="dpBuyClose"><i class="fas fa-times"></i></button>
        <div class="dp-buy-content">
            <div class="dp-buy-icon"><i class="fas fa-shopping-cart"></i></div>
            <h3>Confirm Purchase</h3>
            <div class="dp-buy-detail">
                <span id="buyProductName"></span>
                <strong id="buyProductPrice"></strong>
            </div>
            <div class="dp-buy-balance">
                <span>Your Balance</span>
                <strong id="buyBalance">৳0</strong>
            </div>
            <div class="dp-buy-author-note">
                <i class="fas fa-hand-holding-dollar" style="color: var(--secondary);"></i>
                <span id="buyAuthorEarning">Author earns 70%</span>
            </div>
            <button type="button" class="dp-btn-buy-confirm" id="dpBuyConfirm"><i class="fas fa-check-circle"></i> Confirm Purchase</button>
            <button type="button" class="dp-btn-buy-cancel" id="dpBuyCancel">Cancel</button>
            <div class="dp-buy-error" id="dpBuyError"></div>
        </div>
    </div>
</div>

<!-- ══ TOAST ══ -->
<div class="dp-toast" id="dpToast">
    <div class="dp-toast-inner">
        <i class="fas fa-check-circle" style="color: #10b981;"></i>
        <span id="dpToastMsg">Purchase successful!</span>
    </div>
</div>

<!-- ══ TOP-UP MODAL (dynamic) ══ -->
<div class="dp-modal-overlay" id="dpTopupOverlay">
    <div class="dp-modal" id="dpTopupModal">
        <div class="dp-tp-hd">
            <div class="dp-tp-hd-left">
                <span class="dp-tp-icon" id="tpIcon"><i class="fas fa-gamepad"></i></span>
                <div>
                    <h3 id="tpTitle">Game</h3>
                    <p id="tpDesc">Select a package</p>
                </div>
            </div>
            <button class="dp-modal-x" id="tpClose"><i class="fas fa-times"></i></button>
        </div>
        <div class="dp-tp-body" id="tpBody"></div>
        <div class="dp-tp-ft"><p><i class="fas fa-shield-halved" style="color:#10b981"></i> Secure &bull; Instant &bull; 24/7 Support</p></div>
    </div>
</div>

<!-- ══ TOP-UP PACKAGE TEMPLATE ══ -->
<template id="dpTopupTemplate">
    <div class="dp-pkg-card">
        <span class="dp-pkg-id" data-pkg-id=""></span>
        <div class="dp-pkg-info">
            <strong class="dp-pkg-name"></strong>
            <span class="dp-pkg-badge"></span>
        </div>
        <div class="dp-pkg-right">
            <strong class="dp-pkg-price"></strong>
            <button class="dp-pkg-btn"><i class="fas fa-cart-plus"></i> Buy</button>
        </div>
    </div>
</template>

<!-- ══ TOP-UP PURCHASE MODAL ══ -->
<div class="dp-modal-overlay" id="dpTopupBuyOverlay">
    <div class="dp-modal" id="dpTopupBuyModal">
        <button class="dp-modal-x" id="tpBuyClose"><i class="fas fa-times"></i></button>
        <div class="dp-tp-buy">
            <div class="dp-buy-icon" style="background:var(--g-grad, linear-gradient(135deg,#8b5cf6,#6366f1))"><i class="fas fa-gamepad"></i></div>
            <h3 id="tpBuyTitle">Buy Top-Up</h3>
            <div class="dp-buy-detail">
                <span id="tpBuyPkgName"></span>
                <strong id="tpBuyPrice"></strong>
            </div>
            <div class="dp-buy-balance">
                <span>Your Balance</span>
                <strong id="tpBuyBalance">৳0</strong>
            </div>
            <div class="dp-tp-buy-fields">
                <div class="dp-tp-field">
                    <label for="tpPlayerUid">Game ID / UID *</label>
                    <input type="text" id="tpPlayerUid" maxlength="120" placeholder="Your in-game ID">
                </div>
                <div class="dp-tp-field">
                    <label for="tpPlayerZone">Server / Zone</label>
                    <input type="text" id="tpPlayerZone" maxlength="60" placeholder="e.g. BD Server / 5020">
                </div>
                <div class="dp-tp-field">
                    <label for="tpContact">Contact (WhatsApp / bKash number)</label>
                    <input type="text" id="tpContact" maxlength="120" placeholder="For delivery support">
                </div>
            </div>
            <button type="button" class="dp-btn-buy-confirm" id="tpBuyConfirm"><i class="fas fa-check-circle"></i> Place Order</button>
            <button type="button" class="dp-btn-buy-cancel" id="tpBuyCancel">Cancel</button>
            <div class="dp-buy-error" id="tpBuyError"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const loggedIn = document.body.dataset.loggedIn === '1';
    const viewerBalance = <?php echo $userBalance; ?>;
    const purchasedIds = <?php echo json_encode($purchasedProductIds); ?>;
    const pdfData = <?php echo json_encode($pdfProducts, JSON_UNESCAPED_UNICODE); ?>;
    const authorsData = <?php echo json_encode($authors, JSON_UNESCAPED_UNICODE); ?>;
    const gamesData = <?php echo json_encode($games, JSON_UNESCAPED_UNICODE); ?>;

    const $ = function(id) { return document.getElementById(id); };
    const qa = function(sel) { return document.querySelectorAll(sel); };
    const q = function(sel) { return document.querySelector(sel); };

    function getAuthor(id) { return authorsData.find(function(a){ return a.id === id; }); }
    function getProduct(id) { return pdfData.find(function(p){ return p.id === id; }); }

    /* ── Toast ── */
    function showToast(msg) {
        var t = $('dpToast');
        $('dpToastMsg').textContent = msg;
        t.classList.add('active');
        setTimeout(function(){ t.classList.remove('active'); }, 3000);
    }

    /* ── Modal ── */
    function openModal(id) { $(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
    function closeModal(id) { $(id).classList.remove('active'); document.body.style.overflow = ''; }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            qa('.dp-modal-overlay.active').forEach(function(el){ el.classList.remove('active'); });
            document.body.style.overflow = '';
        }
    });

    qa('.dp-modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) { overlay.classList.remove('active'); document.body.style.overflow = ''; }
        });
    });

    /* ── Section nav highlight on scroll ── */
    var navLinks = qa('.dp-nav-link');
    var sections = [ $('pdf-section'), $('topup-section') ];
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                var id = entry.target.id;
                navLinks.forEach(function(link) {
                    link.classList.toggle('active', link.getAttribute('href') === '#' + id);
                });
            }
        });
    }, { threshold: 0.3 });
    sections.forEach(function(s){ if(s) observer.observe(s); });

    /* ===== PDF PREVIEW ===== */
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-preview-id]');
        if (!btn) return;
        var pid = parseInt(btn.dataset.previewId);
        var p = getProduct(pid);
        if (!p) return;
        var author = getAuthor(p.author_id);
        $('previewCover').src = p.image;
        $('previewCover').onerror = function(){ this.src='https://picsum.photos/seed/fallback/400/300'; };
        $('previewPages').innerHTML = '<i class="fas fa-file-lines"></i> ' + p.pages + ' pages';
        $('previewPrice').innerHTML = '<i class="fas fa-tag"></i> ৳' + p.price.toLocaleString('en-BD');
        $('previewTitle').textContent = p.name;
        $('previewAuthor').innerHTML = author ? '<img src="assets/avatars/' + author.avatar + '" onerror="this.src=\'assets/avatars/default.png\'"> <span>By <strong>' + author.name + '</strong> &middot; ' + author.title + '</span>' : '';
        var owned = purchasedIds.indexOf(pid) !== -1;
        /* Free preview: show first N pages inline when available */
        var hasPreview = !!p.preview_file;
        var frameWrap = $('previewFrameWrap');
        if (hasPreview) {
            $('previewFrame').src = 'handlers/product_handler.php?action=preview_pdf&product_id=' + pid + '&_=' + Date.now();
            frameWrap.style.display = 'block';
            $('previewFreeBadge').innerHTML =
                '<span class="dp-free-preview-badge"><i class="fas fa-book-open"></i> FREE PREVIEW — ' + p.preview_pages + ' page' + (p.preview_pages > 1 ? 's' : '') + ' of ' + p.pages + '</span>';
        } else {
            if (frameWrap) frameWrap.style.display = 'none';
            $('previewFreeBadge').innerHTML = '';
        }
        $('previewText').textContent = p.preview;
        $('previewActions').innerHTML = owned
            ? '<div style="display:flex;gap:0.75rem;flex-wrap:wrap">' +
                '<a href="handlers/product_handler.php?action=download_pdf&amp;product_id=' + pid + '&amp;dl=1" target="_blank" class="dp-btn-preview dp-btn--lg" style="background:#3b82f6;color:#fff;text-decoration:none"><i class="fas fa-download"></i> Download PDF</a>' +
                '<button type="button" class="dp-btn-read dp-btn--lg" data-read-id="' + pid + '"><i class="fas fa-book-open"></i> Read Now</button>' +
              '</div>'
            : '<button type="button" class="dp-btn-buy dp-btn--lg" data-buy-id="' + pid + '" data-price="' + p.price + '" data-name="' + p.name.replace(/"/g,'&quot;') + '"><i class="fas fa-cart-plus"></i> Buy Now — ৳' + p.price.toLocaleString('en-BD') + '</button>';
        openModal('dpPreviewOverlay');
    });

    /* ===== READ MODAL ===== */
    function openReadModal(pid) {
        var p = getProduct(pid);
        if (!p) return;
        var author = getAuthor(p.author_id);
        $('readTitle').textContent = p.name;
        var actionsHtml = '<a href="handlers/product_handler.php?action=download_pdf&amp;product_id=' + p.id + '&amp;dl=1" target="_blank" class="inline-flex items-center gap-2 ml-3 px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-xl text-sm font-semibold shadow transition" style="text-decoration:none"><i class="fas fa-download"></i> Download</a>';
        $('readAuthor').innerHTML = (author ? '<img src="assets/avatars/' + author.avatar + '" onerror="this.src=\'assets/avatars/default.png\'"> <span>By <strong>' + author.name + '</strong></span>' : '') + actionsHtml;
        var pdfUrl = 'handlers/product_handler.php?action=download_pdf&product_id=' + p.id + '&_=' + Date.now();
        $('readBody').innerHTML =
            '<div style="height:70vh;border-radius:1rem;overflow:hidden;background:#e5e7eb;position:relative">' +
              '<iframe src="' + pdfUrl + '" style="width:100%;height:100%;border:0" title="' + p.name.replace(/"/g,'&quot;') + '"></iframe>' +
              '<p class="dp-read-note" style="margin-top:1rem">Having trouble viewing? <a href="' + pdfUrl.replace(/&_=\d+/,'&dl=1') + '" target="_blank" style="color:#3b82f6;text-decoration:underline">Open the PDF in a new tab</a>.</p>' +
            '</div>';
        openModal('dpReadOverlay');
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-read-id]');
        if (btn && !btn.closest('#dpReadOverlay')) openReadModal(parseInt(btn.dataset.readId));
    });

    $('dpReadClose').addEventListener('click', function(){ closeModal('dpReadOverlay'); });

    /* ===== BUY MODAL ===== */
    var currentBuyPid = null;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-buy-id]');
        if (!btn || btn.closest('#dpBuyOverlay')) return;
        var pid = parseInt(btn.dataset.buyId);
        var price = btn.dataset.price;
        var name = btn.dataset.name;
        if (!loggedIn) { showToast('Please login to purchase'); return; }
        currentBuyPid = pid;
        $('buyProductName').textContent = name;
        $('buyProductPrice').textContent = '৳' + parseInt(price).toLocaleString('en-BD');
        $('buyBalance').textContent = '৳' + viewerBalance.toLocaleString('en-BD', {minimumFractionDigits:2});
        var earning = Math.round(parseInt(price) * 0.7);
        $('buyAuthorEarning').textContent = 'Author earns ৳' + earning.toLocaleString('en-BD') + ' (70%)';
        $('dpBuyError').style.display = 'none';
        var confirmBtn = $('dpBuyConfirm');
        if (viewerBalance < parseInt(price)) {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-exclamation-circle"></i> Insufficient Balance';
            $('dpBuyError').textContent = 'Please add money to your balance first.';
            $('dpBuyError').style.display = 'block';
        } else {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Purchase';
        }
        openModal('dpBuyOverlay');
    });

    $('dpBuyClose').addEventListener('click', function(){ closeModal('dpBuyOverlay'); });
    $('dpBuyCancel').addEventListener('click', function(){ closeModal('dpBuyOverlay'); });

    $('dpBuyConfirm').addEventListener('click', function() {
        if (!currentBuyPid || this.disabled) return;
        var fd = new FormData();
        fd.append('action', 'buy_product');
        fd.append('product_id', currentBuyPid);
        fd.append('csrf_token', document.body.dataset.csrfToken || '');
        fetch('handlers/product_handler.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.success) {
                    closeModal('dpBuyOverlay');
                    showToast(data.message || 'Purchase successful!');
                    purchasedIds.push(currentBuyPid);
                    qa('[data-product-id="' + currentBuyPid + '"]').forEach(function(card){
                        var acts = q('.dp-pdf-actions', card);
                        if (acts) acts.innerHTML =
                            '<button type="button" class="dp-btn-preview" data-preview-id="' + currentBuyPid + '"><i class="fas fa-eye"></i> Preview</button>' +
                            '<a href="handlers/product_handler.php?action=download_pdf&amp;product_id=' + currentBuyPid + '&amp;dl=1" target="_blank" class="dp-btn-preview" style="background:#3b82f6;color:#fff;text-decoration:none"><i class="fas fa-download"></i> Download</a>' +
                            '<button type="button" class="dp-btn-read" data-read-id="' + currentBuyPid + '"><i class="fas fa-book-open"></i> Read</button>';
                    });
                    var pa = $('previewActions');
                    if (pa.querySelector('[data-buy-id="' + currentBuyPid + '"]')) {
                        pa.innerHTML = '<div style="display:flex;gap:0.75rem;flex-wrap:wrap">' +
                            '<a href="handlers/product_handler.php?action=download_pdf&amp;product_id=' + currentBuyPid + '&amp;dl=1" target="_blank" class="dp-btn-preview dp-btn--lg" style="background:#3b82f6;color:#fff;text-decoration:none"><i class="fas fa-download"></i> Download PDF</a>' +
                            '<button type="button" class="dp-btn-read dp-btn--lg" data-read-id="' + currentBuyPid + '"><i class="fas fa-book-open"></i> Read Now</button>' +
                          '</div>';
                    }
                    currentBuyPid = null;
                } else {
                    $('dpBuyError').textContent = data.message || 'Purchase failed.';
                    $('dpBuyError').style.display = 'block';
                }
            })
            .catch(function(){ $('dpBuyError').textContent = 'Network error.'; $('dpBuyError').style.display = 'block'; });
    });

    /* ===== PREVIEW CLOSE ===== */
    $('dpPreviewClose').addEventListener('click', function(){ closeModal('dpPreviewOverlay'); });

    /* ===== GAME TOP-UP ===== */
    var activeGameId = null;
    qa('.dp-game-card').forEach(function(card) {
        card.addEventListener('click', function() {
            var gid = this.dataset.gameId;
            var game = gamesData.find(function(g){ return g.id === gid; });
            if (!game) return;
            activeGameId = gid;
            $('tpTitle').textContent = game.name;
            $('tpDesc').textContent = game.description;
            var icon = $('tpIcon');
            icon.innerHTML = '<i class="fas ' + game.icon + '"></i>';
            icon.style.background = game.gradient;
            $('dpTopupModal').style.setProperty('--g-grad', game.gradient);
            var body = $('tpBody');
            body.innerHTML = '';
            game.packages.forEach(function(pkg) {
                var clone = $('dpTopupTemplate').content.cloneNode(true);
                clone.querySelector('.dp-pkg-id').dataset.pkgId = pkg.id;
                clone.querySelector('.dp-pkg-name').textContent = pkg.name;
                var badge = clone.querySelector('.dp-pkg-badge');
                if (pkg.badge) { badge.textContent = pkg.badge; } else { badge.style.display = 'none'; }
                clone.querySelector('.dp-pkg-price').textContent = '৳' + pkg.price.toLocaleString('en-BD');
                body.appendChild(clone);
            });
            openModal('dpTopupOverlay');
        });
    });

    /* Top-up package Buy */
    var currentTopupPkg = null;
    $('dpTopupOverlay').addEventListener('click', function(e) {
        var btn = e.target.closest('.dp-pkg-btn');
        if (!btn) return;
        if (!loggedIn) { showToast('Please login to purchase'); return; }
        var card = btn.closest('.dp-pkg-card');
        var game = gamesData.find(function(g){ return g.id === activeGameId; });
        var pkgIdEl = q('.dp-pkg-id', card);
        var name = q('.dp-pkg-name', card).textContent;
        var price = q('.dp-pkg-price', card).textContent.replace(/[^\d]/g, '');
        var gameName = $('tpTitle').textContent;
        currentTopupPkg = { id: (pkgIdEl ? pkgIdEl.dataset.pkgId : 0), name: name, price: parseFloat(price), game: gameName };
        $('tpBuyPkgName').textContent = gameName + ' — ' + name;
        $('tpBuyPrice').textContent = '৳' + currentTopupPkg.price.toLocaleString('en-BD');
        $('tpBuyBalance').textContent = '৳' + viewerBalance.toLocaleString('en-BD', {minimumFractionDigits:2});
        $('dpTopupBuyModal').style.setProperty('--g-grad', game ? game.gradient : 'linear-gradient(135deg,#8b5cf6,#6366f1)');
        $('tpBuyError').style.display = 'none';
        $('tpPlayerUid').value = '';
        $('tpPlayerZone').value = '';
        $('tpContact').value = '';
        var confirmBtn = $('tpBuyConfirm');
        if (viewerBalance < currentTopupPkg.price) {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-exclamation-circle"></i> Insufficient Balance';
            $('tpBuyError').textContent = 'Please add money to your balance first.';
            $('tpBuyError').style.display = 'block';
        } else {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
        }
        openModal('dpTopupBuyOverlay');
    });

    $('tpBuyClose').addEventListener('click', function(){ closeModal('dpTopupBuyOverlay'); });
    $('tpBuyCancel').addEventListener('click', function(){ closeModal('dpTopupBuyOverlay'); });

    $('tpBuyConfirm').addEventListener('click', function() {
        if (!currentTopupPkg || this.disabled) return;
        var uid = $('tpPlayerUid').value.trim();
        if (!uid) { $('tpBuyError').textContent = 'Please enter your game ID / UID.'; $('tpBuyError').style.display = 'block'; return; }
        var fd = new FormData();
        fd.append('action', 'buy_topup');
        fd.append('package_id', currentTopupPkg.id);
        fd.append('player_uid', uid);
        fd.append('player_zone', $('tpPlayerZone').value.trim());
        fd.append('contact', $('tpContact').value.trim());
        fd.append('csrf_token', document.body.dataset.csrfToken || '');
        var btn = this;
        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing...';
        fetch('handlers/topup_handler.php', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false;
                btn.innerHTML = orig;
                if (data.success) {
                    closeModal('dpTopupBuyOverlay');
                    closeModal('dpTopupOverlay');
                    showToast(data.message || 'Order placed!');
                } else {
                    $('tpBuyError').textContent = data.message || 'Order failed.';
                    $('tpBuyError').style.display = 'block';
                }
            })
            .catch(function(){ btn.disabled = false; btn.innerHTML = orig; $('tpBuyError').textContent = 'Network error.'; $('tpBuyError').style.display = 'block'; });
    });

    $('tpClose').addEventListener('click', function(){ closeModal('dpTopupOverlay'); });
});
</script>
