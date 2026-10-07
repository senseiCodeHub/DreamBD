<?php
$db = Database::getInstance()->getConnection();
$viewerId = $_SESSION['user_id'] ?? null;
$viewerRole = $_SESSION['role'] ?? 'user';
$isSellerOrAdmin = in_array($viewerRole, ['merchant','seller','admin','moderator','super_admin'], true);
$userBalance = 0;
$purchasedProductIds = [];
$cartProductIds = [];
if ($viewerId) {
    $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$viewerId]);
    $userBalance = (float)($stmt->fetchColumn() ?: 0);
    $stmt = $db->prepare("SELECT product_id FROM product_purchases WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$viewerId]);
    $purchasedProductIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $db->prepare("SELECT product_id FROM cart_items WHERE user_id = ?");
    $stmt->execute([$viewerId]);
    $cartProductIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Designed CSS cover per game (no image processing needed — pure gradient + typography art)
if (!function_exists('dream_cover')) {
    function dream_cover(string $name): array
    {
        $n = mb_strtolower($name);
        $map = [
            'free fire'       => ['FREE FIRE', '#fb7185', '#e11d48', 'fa-crosshairs'],
            'pubg'            => ['PUBG MOBILE', '#fbbf24', '#d97706', 'fa-person-rifle'],
            'mobile legends'  => ['MOBILE LEGENDS', '#818cf8', '#4f46e5', 'fa-shield-halved'],
            'mlbb'            => ['MOBILE LEGENDS', '#818cf8', '#4f46e5', 'fa-shield-halved'],
            'valorant'        => ['VALORANT', '#f43f5e', '#9333ea', 'fa-bullseye'],
            'call of duty'    => ['COD MOBILE', '#38bdf8', '#1d4ed8', 'fa-gun'],
            'cod'             => ['COD MOBILE', '#38bdf8', '#1d4ed8', 'fa-gun'],
            'clash'           => ['CLASH OF CLANS', '#4ade80', '#16a34a', 'fa-chess-rook'],
            'genshin'         => ['GENSHIN IMPACT', '#a78bfa', '#6366f1', 'fa-wand-magic-sparkles'],
            'fortnite'        => ['FORTNITE', '#22d3ee', '#0e7490', 'fa-parachute-box'],
            'efootball'       => ['eFOOTBALL', '#34d399', '#047857', 'fa-futbol'],
            'fifa'            => ['eFOOTBALL', '#34d399', '#047857', 'fa-futbol'],
        ];
        foreach ($map as $key => $v) {
            if (str_contains($n, $key)) {
                return ['label' => $v[0], 'a' => $v[1], 'b' => $v[2], 'icon' => $v[3]];
            }
        }
        $palette = [
            ['#60a5fa', '#1d4ed8', 'fa-bolt'],
            ['#f472b6', '#be123c', 'fa-star'],
            ['#fcd34d', '#d97706', 'fa-gem'],
            ['#6ee7b7', '#047857', 'fa-crown'],
            ['#c4b5fd', '#6d28d9', 'fa-wand-sparkles'],
        ];
        $p = $palette[abs(crc32($n)) % count($palette)];
        return ['label' => mb_strtoupper(mb_substr(trim($name), 0, 20)), 'a' => $p[0], 'b' => $p[1], 'icon' => $p[2]];
    }
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
    $p['cover'] = dream_cover($p['name']);
    $p['has_image'] = is_string($p['image']) && str_starts_with($p['image'], 'assets/');
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

// Filter chips derived from real data (badges + price) — no DB change needed
$chips = [['key' => 'all', 'label' => 'All guides', 'icon' => 'fa-border-all']];
$seenBadges = [];
foreach ($pdfProducts as $p) {
    $b = trim((string)($p['badge'] ?? ''));
    if ($b === '' || isset($seenBadges[mb_strtolower($b)])) continue;
    $seenBadges[mb_strtolower($b)] = true;
    $chips[] = ['key' => mb_strtolower($b), 'label' => $b, 'icon' => 'fa-award'];
}
$chips[] = ['key' => 'under250', 'label' => 'Under ৳250', 'icon' => 'fa-tags'];

// Sanitize admin-set badge colours before they land in a style attribute
if (!function_exists('dream_badge_color')) {
    function dream_badge_color($value)
    {
        $v = trim((string)$value);
        if ($v !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,\s%]+\)|linear-gradient\([0-9a-zdeg.,\s%#]+\))$/i', $v)) {
            return $v;
        }
        return '#3b82f6';
    }
}
?>
<div class="dp-wrap">

    <!-- ═══ HERO ═══ -->
    <section class="dp-hero">
        <span class="dp-hero-glow dp-hero-glow--1" aria-hidden="true"></span>
        <span class="dp-hero-glow dp-hero-glow--2" aria-hidden="true"></span>
        <div class="dp-hero-inner">
            <span class="dp-kicker"><span class="dp-kicker-dot"></span> DreamBD Store</span>
            <h1>Pro guides &amp; <span class="dp-grad-text">instant top-ups</span></h1>
            <p>Preview free, buy once, read in-site — and top up your game in seconds.</p>
            <div class="dp-hero-actions">
                <a href="#pdf-section" class="dp-hero-btn dp-hero-btn--primary">
                    <i class="fas fa-file-pdf"></i> Browse Guides
                </a>
                <a href="#topup-section" class="dp-hero-btn dp-hero-btn--ghost">
                    <i class="fas fa-gamepad"></i> Top-Up Now
                </a>
                <?php if ($isSellerOrAdmin): ?>
                <a href="index.php?page=seller-upload" class="dp-hero-btn dp-hero-btn--ghost" data-page="seller-upload">
                    <i class="fas fa-cloud-upload"></i> Sell a guide
                </a>
                <?php endif; ?>
            </div>
            <div class="dp-hero-stats">
                <a href="#pdf-section" class="dp-stat">
                    <span class="dp-stat-icon dp-stat-icon--blue"><i class="fas fa-layer-group"></i></span>
                    <strong><?php echo count($pdfProducts); ?>+</strong>
                    <span>Guides</span>
                </a>
                <a href="#pdf-section" class="dp-stat">
                    <span class="dp-stat-icon dp-stat-icon--violet"><i class="fas fa-feather-pointed"></i></span>
                    <strong><?php echo count($authors); ?></strong>
                    <span>Authors</span>
                </a>
                <a href="#topup-section" class="dp-stat">
                    <span class="dp-stat-icon dp-stat-icon--amber"><i class="fas fa-gamepad"></i></span>
                    <strong><?php echo count($games); ?></strong>
                    <span>Games</span>
                </a>
                <a href="index.php?page=balance" class="dp-stat" data-page="balance">
                    <span class="dp-stat-icon dp-stat-icon--green"><i class="fas fa-wallet"></i></span>
                    <strong>৳<?php echo number_format($userBalance, 0); ?></strong>
                    <span>Balance</span>
                </a>
            </div>
        </div>
    </section>

    <?php if (!empty($purchasedProductIds)): ?>
    <div class="dp-library">
        <i class="fas fa-library"></i>
        <span>You own <strong><?php echo count($purchasedProductIds); ?></strong> guide<?php echo count($purchasedProductIds) > 1 ? 's' : '' ?>.</span>
        <a href="index.php?page=cart&tab=orders" data-page="cart">Open my library</a>
    </div>
    <?php endif; ?>

    <!-- ═══ PDF GUIDES SECTION ═══ -->
    <section class="dp-section" id="pdf-section">
        <div class="dp-sec-hd">
            <h2 class="dp-sec-title"><i class="fas fa-file-pdf"></i> PDF Guides</h2>
            <div class="dp-sec-tools">
                <div class="dp-search">
                    <i class="fas fa-search"></i>
                    <input type="search" id="dpSearch" placeholder="Search guides, authors..." autocomplete="off" aria-label="Search guides">
                </div>
                <div class="dp-sort" id="dpSortWrap">
                    <button type="button" class="dp-sort-btn" id="dpSortBtn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="dp-sort-label" id="dpSortLabel">Most popular</span>
                        <i class="fas fa-chevron-down dp-sort-caret" aria-hidden="true"></i>
                    </button>
                    <div class="dp-sort-menu" id="dpSortMenu" role="listbox" aria-label="Sort guides" hidden>
                        <button type="button" role="option" class="dp-sort-opt is-on" data-value="popular" aria-selected="true"><i class="fas fa-fire" aria-hidden="true"></i><span>Most popular</span><i class="fas fa-check dp-sort-tick" aria-hidden="true"></i></button>
                        <button type="button" role="option" class="dp-sort-opt" data-value="newest" aria-selected="false"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i><span>Newest</span><i class="fas fa-check dp-sort-tick" aria-hidden="true"></i></button>
                        <button type="button" role="option" class="dp-sort-opt" data-value="rating" aria-selected="false"><i class="fas fa-star" aria-hidden="true"></i><span>Top rated</span><i class="fas fa-check dp-sort-tick" aria-hidden="true"></i></button>
                        <button type="button" role="option" class="dp-sort-opt dp-sort-opt--sep" data-value="price-asc" aria-selected="false"><i class="fas fa-arrow-up-short-wide" aria-hidden="true"></i><span>Price: low to high</span><i class="fas fa-check dp-sort-tick" aria-hidden="true"></i></button>
                        <button type="button" role="option" class="dp-sort-opt" data-value="price-desc" aria-selected="false"><i class="fas fa-arrow-down-wide-short" aria-hidden="true"></i><span>Price: high to low</span><i class="fas fa-check dp-sort-tick" aria-hidden="true"></i></button>
                    </div>
                    <select id="dpSort" class="dp-sort-native" aria-label="Sort guides" tabindex="-1" aria-hidden="true">
                        <option value="popular">Most popular</option>
                        <option value="newest">Newest</option>
                        <option value="rating">Top rated</option>
                        <option value="price-asc">Price: low to high</option>
                        <option value="price-desc">Price: high to low</option>
                    </select>
                </div>
                <span class="dp-count" id="dpResultCount" aria-live="polite"><?php echo count($pdfProducts); ?> guides</span>
            </div>
        </div>

        <div class="dp-chips" id="dpChips" role="group" aria-label="Filter guides">
            <?php foreach ($chips as $ci => $chip): ?>
            <button type="button" class="dp-chip<?php echo $ci === 0 ? ' is-active' : ''; ?>" data-chip="<?php echo htmlspecialchars($chip['key'], ENT_QUOTES); ?>" aria-pressed="<?php echo $ci === 0 ? 'true' : 'false'; ?>">
                <i class="fas <?php echo htmlspecialchars($chip['icon'], ENT_QUOTES); ?>"></i> <?php echo htmlspecialchars($chip['label']); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php if (count($authors) > 1): ?>
        <div class="dp-authors" id="dpAuthors" role="group" aria-label="Filter by author">
            <?php foreach ($authors as $author): ?>
            <button type="button" class="dp-author-chip" data-author="<?php echo (int)$author['id']; ?>" aria-pressed="false">
                <img src="assets/avatars/<?php echo htmlspecialchars($author['avatar']); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                <span><?php echo htmlspecialchars($author['name']); ?></span>
                <em><i class="fas fa-star"></i> <?php echo (float)$author['rating']; ?></em>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Product Grid -->
        <div class="dp-pdf-grid">
            <?php foreach ($pdfProducts as $product):
                $author = current(array_filter($authors, fn($a) => $a['id'] === $product['author_id']));
                $isPurchased = in_array($product['id'], $purchasedProductIds);
            ?>
            <article class="dp-pdf-card <?php echo $isPurchased ? 'is-owned' : ''; ?>" data-product-id="<?php echo $product['id']; ?>"
                     data-name="<?php echo htmlspecialchars(mb_strtolower($product['name'] . ' ' . $product['short_desc'] . ' ' . ($author['name'] ?? ''))); ?>"
                     data-price="<?php echo (float)$product['price']; ?>"
                     data-rating="<?php echo (float)$product['rating']; ?>"
                     data-sales="<?php echo (int)$product['sales']; ?>"
                     data-id="<?php echo (int)$product['id']; ?>"
                     data-author="<?php echo (int)$product['author_id']; ?>"
                     data-badge="<?php echo htmlspecialchars(mb_strtolower((string)$product['badge']), ENT_QUOTES); ?>">
                <div class="dp-media" style="--cv-a: <?php echo htmlspecialchars($product['cover']['a']); ?>; --cv-b: <?php echo htmlspecialchars($product['cover']['b']); ?>;">
                    <div class="dp-cover" aria-hidden="true">
                        <span class="dp-cover-pattern"></span>
                        <span class="dp-cover-icon"><i class="fas <?php echo htmlspecialchars($product['cover']['icon']); ?>"></i></span>
                        <strong class="dp-cover-game"><?php echo htmlspecialchars($product['cover']['label']); ?></strong>
                        <span class="dp-cover-kind">Guide</span>
                    </div>
                    <?php if ($product['has_image']): ?>
                    <img class="dp-media-img" src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" onerror="this.remove()">
                    <?php endif; ?>
                    <div class="dp-media-top">
                        <?php if ($isPurchased): ?>
                            <span class="dp-badge dp-badge--green"><i class="fas fa-circle-check"></i> Owned</span>
                        <?php elseif ($product['badge']): ?>
                            <span class="dp-badge" style="--badge: <?php echo htmlspecialchars(dream_badge_color($product['badge_color']), ENT_QUOTES); ?>"><?php echo htmlspecialchars($product['badge']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dp-media-bottom">
                        <span class="dp-media-chip"><i class="fas fa-file-lines"></i> <?php echo (int)$product['pages']; ?> pages</span>
                        <span class="dp-media-chip dp-media-chip--glass"><i class="fas fa-eye"></i> Free preview</span>
                    </div>
                </div>
                <div class="dp-card-body">
                    <div class="dp-card-author">
                        <img src="assets/avatars/<?php echo htmlspecialchars($author['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                        <span><?php echo htmlspecialchars($author['name'] ?? 'Unknown'); ?></span>
                        <?php if ($author): ?><i class="fas fa-circle-check dp-verified" title="Verified author"></i><?php endif; ?>
                    </div>
                    <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                    <p class="dp-card-desc"><?php echo htmlspecialchars($product['short_desc']); ?></p>
                    <div class="dp-card-meta">
                        <span class="dp-meta-rating"><i class="fas fa-star"></i> <?php echo (float)$product['rating']; ?></span>
                        <span>(<?php echo number_format($product['sales']); ?> sold)</span>
                    </div>
                    <div class="dp-card-foot">
                        <strong class="dp-price">৳<?php echo number_format($product['price']); ?></strong>
                        <div class="dp-card-actions">
                            <button type="button" class="dp-btn dp-btn--ghost" data-preview-id="<?php echo $product['id']; ?>"><i class="fas fa-eye"></i> Preview</button>
                            <?php if ($isPurchased): ?>
                                <button type="button" class="dp-btn dp-btn--primary" data-read-id="<?php echo $product['id']; ?>"><i class="fas fa-book-open"></i> Read</button>
                            <?php else: ?>
                                <button type="button" class="dp-btn dp-btn--ghost<?php echo in_array($product['id'], $cartProductIds) ? ' is-added' : ''; ?>" data-add-cart="<?php echo $product['id']; ?>">
                                    <i class="fas <?php echo in_array($product['id'], $cartProductIds) ? 'fa-check' : 'fa-basket-shopping'; ?>"></i> <span><?php echo in_array($product['id'], $cartProductIds) ? 'Added' : 'Cart'; ?></span>
                                </button>
                                <button type="button" class="dp-btn dp-btn--primary" data-buy-id="<?php echo $product['id']; ?>" data-price="<?php echo $product['price']; ?>" data-name="<?php echo htmlspecialchars($product['name']); ?>"><i class="fas fa-cart-plus"></i> Buy</button>
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
        <div class="dp-sec-hd">
            <h2 class="dp-sec-title"><i class="fas fa-gamepad"></i> Game Top-Up</h2>
            <div class="dp-sec-tools">
                <span class="dp-sec-pill"><i class="fas fa-bolt"></i> Instant delivery</span>
                <span class="dp-sec-pill dp-sec-pill--balance"><span>Balance</span><strong>৳<?php echo number_format($userBalance, 2); ?></strong></span>
            </div>
        </div>

        <div class="dp-games-grid" id="tpGameGrid">
            <?php foreach ($games as $game): ?>
            <button type="button" class="dp-game-card" data-game-id="<?php echo (int)$game['id']; ?>" style="--g-grad: <?php echo htmlspecialchars($game['gradient']); ?>; --g-shadow: <?php echo htmlspecialchars($game['shadow']); ?>">
                <span class="dp-game-tint" aria-hidden="true"></span>
                <span class="dp-game-icon"><i class="fas <?php echo htmlspecialchars($game['icon']); ?>"></i></span>
                <h3><?php echo htmlspecialchars($game['name']); ?></h3>
                <p><?php echo htmlspecialchars($game['description']); ?></p>
                <span class="dp-game-cta"><span class="dp-game-cta-idle">View Packages <i class="fas fa-arrow-right"></i></span><span class="dp-game-cta-on"><i class="fas fa-check"></i> Selected</span></span>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- ═══ TOP-UP STEPPER ═══ -->
        <div class="dp-tp-stepper" id="tpStepper" hidden>
            <ol>
                <li class="is-on" data-step="1"><span>1</span> Game</li>
                <li data-step="2"><span>2</span> Package</li>
                <li data-step="3"><span>3</span> Details</li>
            </ol>
        </div>

        <div class="dp-tp-panel" id="tpPanel" hidden>

            <!-- Step 2: package picker -->
            <section class="dp-tp-block" id="tpPackagesBlock">
                <header class="dp-tp-block-hd">
                    <span class="dp-tp-icon" id="tpIcon"><i class="fas fa-gamepad"></i></span>
                    <div>
                        <h3 id="tpTitle">Game</h3>
                        <p id="tpDesc">Select a package</p>
                    </div>
                    <button type="button" class="dp-tp-change" id="tpChangeGame"><i class="fas fa-arrow-left"></i> Change game</button>
                </header>
                <div class="dp-pkg-grid" id="tpPkgGrid"></div>
            </section>

            <!-- Step 3: order details -->
            <section class="dp-tp-block" id="tpDetailsBlock" hidden>
                <header class="dp-tp-block-hd">
                    <span class="dp-tp-icon dp-tp-icon--sm" id="tpDetailIcon"><i class="fas fa-pen-to-square"></i></span>
                    <div>
                        <h3>Delivery details</h3>
                        <p>We deliver to the ID you give below.</p>
                    </div>
                    <button type="button" class="dp-tp-change" id="tpChangePkg"><i class="fas fa-arrow-left"></i> Change package</button>
                </header>

                <div class="dp-tp-form">
                    <div class="dp-tp-fields">
                        <div class="dp-tp-field">
                            <label for="tpPlayerUid">Game ID / UID <em>*</em></label>
                            <input type="text" id="tpPlayerUid" maxlength="120" placeholder="Your in-game ID" autocomplete="off">
                        </div>
                        <div class="dp-tp-field">
                            <label for="tpPlayerZone">Server / Zone</label>
                            <input type="text" id="tpPlayerZone" maxlength="60" placeholder="e.g. BD Server / 5020" autocomplete="off">
                        </div>
                        <div class="dp-tp-field">
                            <label for="tpContact">Contact (WhatsApp / bKash)</label>
                            <input type="text" id="tpContact" maxlength="120" placeholder="For delivery support" autocomplete="off">
                        </div>
                    </div>

                    <aside class="dp-tp-summary">
                        <div class="dp-tp-sum-row"><span>Package</span><strong id="tpSumName">—</strong></div>
                        <div class="dp-tp-sum-row"><span>Price</span><strong id="tpSumPrice">৳0</strong></div>
                        <div class="dp-tp-sum-row"><span>Your balance</span><strong id="tpSumBalance">৳0</strong></div>
                        <div class="dp-tp-sum-row dp-tp-sum-row--total"><span>Payable now</span><strong id="tpSumPayable">৳0</strong></div>
                        <button type="button" class="dp-btn-buy-confirm" id="tpBuyConfirm"><i class="fas fa-check-circle"></i> Place Order</button>
                        <div class="dp-buy-error" id="tpBuyError"></div>
                        <p class="dp-tp-secure"><i class="fas fa-shield-halved"></i> Secure &middot; Paid from your DreamBD balance</p>
                    </aside>
                </div>
            </section>

            <!-- Step 4: success -->
            <section class="dp-tp-block dp-tp-done" id="tpDoneBlock" hidden>
                <div class="dp-tp-done-icon"><i class="fas fa-check"></i></div>
                <h3>Order placed</h3>
                <p id="tpDoneMsg">Your top-up order has been received.</p>
                <div class="dp-tp-done-actions">
                    <a class="dp-btn dp-btn--primary" href="index.php?page=cart&tab=orders" data-page="cart"><i class="fas fa-receipt"></i> My orders</a>
                    <button type="button" class="dp-btn dp-btn--ghost" id="tpAgain"><i class="fas fa-rotate-left"></i> Top-up again</button>
                </div>
            </section>
        </div>
    </section>

    <!-- ═══ FEATURES / WHY ═══ -->
    <section class="dp-features" id="why-section">
        <div class="dp-sec-hd">
            <h2 class="dp-sec-title"><i class="fas fa-gem"></i> Why DreamBD</h2>
        </div>
        <div class="dp-features-grid">
            <div class="dp-feat-card" style="--fc: #3b82f6;">
                <div class="dp-feat-icon"><i class="fas fa-bolt"></i></div>
                <h4>Instant Delivery</h4>
                <p>Guides unlock and top-ups dispatch immediately after payment.</p>
            </div>
            <div class="dp-feat-card" style="--fc: #10b981;">
                <div class="dp-feat-icon"><i class="fas fa-shield-halved"></i></div>
                <h4>Secure Payments</h4>
                <p>Every taka moves through your protected DreamBD balance.</p>
            </div>
            <div class="dp-feat-card" style="--fc: #8b5cf6;">
                <div class="dp-feat-icon"><i class="fas fa-lock"></i></div>
                <h4>View-Only Reader</h4>
                <p>Read guides in-site with download &amp; print disabled.</p>
            </div>
            <div class="dp-feat-card" style="--fc: #f59e0b;">
                <div class="dp-feat-icon"><i class="fas fa-hand-holding-dollar"></i></div>
                <h4>Authors Get 70%</h4>
                <p>Creators keep the lion's share of every sale they make.</p>
            </div>
        </div>
        <div class="dp-cta-strip">
            <div class="dp-cta-strip-copy">
                <span class="dp-cta-strip-icon"><i class="fas fa-cloud-upload"></i></span>
                <div>
                    <h4>Have a guide to sell?</h4>
                    <p>Upload it once and earn <strong>70%</strong> of every sale — forever.</p>
                </div>
            </div>
            <a href="index.php?page=seller-upload" class="dp-cta-strip-btn" data-page="seller-upload">
                <?php if ($isSellerOrAdmin): ?>Start selling <i class="fas fa-arrow-right"></i>
                <?php else: ?><i class="fas fa-right-to-bracket"></i> Become a seller<?php endif; ?>
            </a>
        </div>
    </section>

</div>

<link rel="stylesheet" href="<?php echo dream_asset('assets/css/products.css'); ?>">

<!-- ══ MOBILE ACTION BAR ══ -->
<div class="dp-mobile-bar" id="dpMobileBar">
    <div class="dp-mobile-bar-info">
        <span>Balance</span>
        <strong>৳<?php echo number_format($userBalance, 2); ?></strong>
    </div>
    <div class="dp-mobile-bar-actions">
        <a class="dp-bar-btn dp-bar-btn--ghost" href="index.php?page=balance" data-page="balance"><i class="fas fa-plus"></i> Add funds</a>
        <a class="dp-bar-btn dp-bar-btn--solid" href="index.php?page=cart" data-page="cart"><i class="fas fa-cart-shopping"></i> Cart</a>
    </div>
</div>

<!-- ══ PREVIEW MODAL ══ -->
<div class="dp-modal-overlay" id="dpPreviewOverlay">
    <div class="dp-modal dp-modal--wide" id="dpPreviewModal">
        <button class="dp-modal-x" id="dpPreviewClose"><i class="fas fa-times"></i></button>
        <div class="dp-preview-layout">
            <div class="dp-preview-cover" id="previewCover">
                <div class="dp-cover" aria-hidden="true">
                    <span class="dp-cover-pattern"></span>
                    <span class="dp-cover-icon" id="previewCoverIcon"><i class="fas fa-star"></i></span>
                    <strong class="dp-cover-game" id="previewCoverGame">GUIDE</strong>
                    <span class="dp-cover-kind">Guide</span>
                </div>
                <img id="previewCoverImg" src="" alt="" style="display:none" onerror="this.style.display='none'">
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

<!-- ══ READ MODAL (view-only, paginated) ══ -->
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

        <div class="dp-reader-bar">
            <div class="dp-reader-pages">
                <button type="button" id="readerPrev" aria-label="Previous page" disabled><i class="fas fa-chevron-left"></i></button>
                <span class="dp-reader-count"><b id="readerPage">1</b><i>/</i><span id="readerTotal">1</span></span>
                <button type="button" id="readerNext" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="dp-reader-status"><i class="fas fa-lock"></i> View only &mdash; download &amp; print disabled</div>
            <a class="dp-reader-dl" id="readerDownload" href="#" target="_blank" rel="noopener" style="display:none"><i class="fas fa-download"></i> Author copy</a>
        </div>

        <div class="dp-read-body" id="readBody">
            <div class="dp-reader-loading" id="readerLoading"><i class="fas fa-circle-notch fa-spin"></i> Opening guide&hellip;</div>
            <iframe id="readerFrame" src="" title="Guide reader" style="display:none"></iframe>
            <div class="dp-reader-error" id="readerError" style="display:none"></div>
        </div>
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

<!-- ══ TOP-UP legacy modals removed: the stepper above replaces them ══ -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const loggedIn = document.body.dataset.loggedIn === '1';
    var viewerBalance = <?php echo (float)$userBalance; ?>;
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

    /* ===== PDF PREVIEW ===== */
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-preview-id]');
        if (!btn) return;
        var pid = parseInt(btn.dataset.previewId);
        var p = getProduct(pid);
        if (!p) return;
        var author = getAuthor(p.author_id);
        var coverEl = $('previewCover');
        var cv = p.cover || { a: '#60a5fa', b: '#1d4ed8', label: 'GUIDE', icon: 'fa-star' };
        coverEl.style.setProperty('--cv-a', cv.a);
        coverEl.style.setProperty('--cv-b', cv.b);
        $('previewCoverIcon').innerHTML = '<i class="fas ' + cv.icon + '"></i>';
        $('previewCoverGame').textContent = cv.label;
        var coverImg = $('previewCoverImg');
        if (p.has_image) {
            coverImg.src = p.image;
            coverImg.style.display = '';
        } else {
            coverImg.style.display = 'none';
            coverImg.src = '';
        }
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
            ? '<div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center">' +
                '<button type="button" class="dp-btn dp-btn--primary dp-btn--lg" data-read-id="' + pid + '"><i class="fas fa-book-open"></i> Read Now</button>' +
                '<span class="dp-viewonly-note"><i class="fas fa-lock"></i> View only</span>' +
              '</div>'
            : '<button type="button" class="dp-btn dp-btn--primary dp-btn--lg" data-buy-id="' + pid + '" data-price="' + p.price + '" data-name="' + p.name.replace(/"/g,'&quot;') + '"><i class="fas fa-cart-plus"></i> Buy Now — ৳' + p.price.toLocaleString('en-BD') + '</button>';
        openModal('dpPreviewOverlay');
    });

    /* ===== READ MODAL (view-only, paginated) ===== */
    var readerState = { pid: 0, page: 1, total: 1, expires: 0, token: '' };

    function readerUrl() {
        return 'handlers/product_handler.php?action=view_page'
            + '&product_id=' + readerState.pid
            + '&page=' + readerState.page
            + '&expires=' + readerState.expires
            + '&token=' + encodeURIComponent(readerState.token)
            + '&_=' + Date.now()
            + '#toolbar=0&navpanes=0&zoom=page-width';
    }

    function renderReaderPage() {
        $('readerPage').textContent = readerState.page;
        $('readerTotal').textContent = readerState.total;
        $('readerPrev').disabled = readerState.page <= 1;
        $('readerNext').disabled = readerState.page >= readerState.total;
        $('readerError').style.display = 'none';
        var frame = $('readerFrame');
        frame.style.display = 'block';
        showReaderLoading();
        frame.src = readerUrl();
        clearTimeout(readerLoadTimer);
        readerLoadTimer = setTimeout(hideReaderLoading, 7000);
    }

    function showReaderLoading() {
        $('readerLoading').style.display = 'flex';
    }

    function hideReaderLoading() {
        clearTimeout(readerLoadTimer);
        $('readerLoading').style.display = 'none';
    }

    var readerLoadTimer = null;
    $('readerFrame').addEventListener('load', hideReaderLoading);

    function readerFail(message) {
        hideReaderLoading();
        $('readerFrame').style.display = 'none';
        $('readerError').style.display = 'block';
        $('readerError').innerHTML = '<i class="fas fa-triangle-exclamation"></i><p>' + message + '</p>' +
            (message.indexOf('buy') !== -1 || message.indexOf('Buy') !== -1
                ? '<button type="button" class="dp-btn dp-btn--primary dp-btn--lg" data-buy-id="' + readerState.pid + '"><i class="fas fa-cart-plus"></i> Buy this guide</button>'
                : '');
    }

    function openReadModal(pid) {
        var p = getProduct(pid);
        if (!p) return;
        if (!loggedIn) { showToast('Please login to read this guide'); return; }
        var author = getAuthor(p.author_id);
        $('readTitle').textContent = p.name;
        $('readAuthor').innerHTML = author ? '<img src="assets/avatars/' + author.avatar + '" onerror="this.src=\'assets/avatars/default.png\'"> <span>By <strong>' + author.name + '</strong></span>' : '';
        readerState.pid = pid;
        readerState.page = 1;
        readerState.total = 1;
        readerState.token = '';
        $('readerDownload').style.display = 'none';
        $('readerLoading').style.display = 'flex';
        $('readerError').style.display = 'none';
        $('readerFrame').style.display = 'none';
        $('readerFrame').src = '';
        openModal('dpReadOverlay');

        var fd = new FormData();
        fd.append('action', 'read_meta');
        fd.append('product_id', pid);
        fetch('handlers/product_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { readerFail(data.message || 'Could not open this guide.'); return; }
                readerState.total = Math.max(1, parseInt(data.pages, 10) || 1);
                readerState.expires = parseInt(data.expires, 10) || 0;
                readerState.token = data.token || '';
                if (data.can_download) {
                    $('readerDownload').style.display = '';
                    $('readerDownload').href = 'handlers/product_handler.php?action=download_pdf&product_id=' + pid + '&dl=1';
                }
                renderReaderPage();
            })
            .catch(function () { readerFail('Network error while opening the guide. Please try again.'); });
    }

    $('readerPrev').addEventListener('click', function () {
        if (readerState.page > 1) { readerState.page--; renderReaderPage(); }
    });
    $('readerNext').addEventListener('click', function () {
        if (readerState.page < readerState.total) { readerState.page++; renderReaderPage(); }
    });
    document.addEventListener('keydown', function (e) {
        if (!$('dpReadOverlay').classList.contains('active')) return;
        if (e.key === 'ArrowLeft' && !$('readerPrev').disabled) { readerState.page--; renderReaderPage(); }
        if (e.key === 'ArrowRight' && !$('readerNext').disabled) { readerState.page++; renderReaderPage(); }
    });

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
                        card.classList.add('is-owned');
                        var acts = q('.dp-card-actions', card);
                        if (acts) acts.innerHTML =
                            '<button type="button" class="dp-btn dp-btn--ghost" data-preview-id="' + currentBuyPid + '"><i class="fas fa-eye"></i> Preview</button>' +
                            '<button type="button" class="dp-btn dp-btn--primary" data-read-id="' + currentBuyPid + '"><i class="fas fa-book-open"></i> Read</button>';
                    });
                    var pa = $('previewActions');
                    if (pa.querySelector('[data-buy-id="' + currentBuyPid + '"]')) {
                        pa.innerHTML = '<div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center">' +
                            '<button type="button" class="dp-btn dp-btn--primary dp-btn--lg" data-read-id="' + currentBuyPid + '"><i class="fas fa-book-open"></i> Read Now</button>' +
                            '<span class="dp-viewonly-note"><i class="fas fa-lock"></i> View only</span>' +
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

    /* ===== GAME TOP-UP (inline stepper: game → package → details → done) ===== */
    var tpState = { game: null, pkg: null, price: 0, name: '' };

    function tpStep(step) {
        $('tpStepper').hidden = false;
        $('tpPanel').hidden = false;
        qa('#tpStepper li').forEach(function (li) {
            var n = parseInt(li.dataset.step, 10);
            li.classList.toggle('is-on', n <= step);
            li.classList.toggle('is-now', n === step);
        });
        $('tpPackagesBlock').hidden = (step !== 2);
        $('tpDetailsBlock').hidden = (step !== 3);
        $('tpDoneBlock').hidden = (step !== 4);
    }

    function tpMoney(v) {
        return '৳' + Number(v || 0).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function tpRenderPackages() {
        var g = tpState.game;
        $('tpTitle').textContent = g.name;
        $('tpDesc').textContent = g.description || 'Select a package';
        $('tpIcon').innerHTML = '<i class="fas ' + g.icon + '"></i>';
        $('tpIcon').style.background = g.gradient;
        $('tpDetailIcon').style.background = g.gradient;
        var grid = $('tpPkgGrid');
        grid.innerHTML = '';
        if (!g.packages || !g.packages.length) {
            grid.innerHTML = '<p class="dp-tp-empty"><i class="fas fa-circle-info"></i> No packages available for this game right now.</p>';
            return;
        }
        g.packages.forEach(function (pkg) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'dp-pkg';
            el.dataset.pkgId = pkg.id;
            el.dataset.pkgName = pkg.name;
            el.dataset.pkgPrice = pkg.price;
            el.innerHTML =
                '<span class="dp-pkg-body"><strong>' + pkg.name + '</strong>' +
                (pkg.badge ? '<em style="--badge:' + (pkg.badge_color || '#8b5cf6') + '">' + pkg.badge + '</em>' : '') +
                '</span>' +
                '<span class="dp-pkg-side"><strong class="dp-pkg-price">' + tpMoney(pkg.price) + '</strong>' +
                '<span class="dp-pkg-go">Select <i class="fas fa-arrow-right"></i></span></span>';
            grid.appendChild(el);
        });
    }

    function tpUpdateSummary() {
        $('tpSumName').textContent = tpState.name || '—';
        $('tpSumPrice').textContent = tpMoney(tpState.price);
        $('tpSumBalance').textContent = tpMoney(viewerBalance);
        $('tpSumPayable').textContent = tpMoney(tpState.price);

        var confirmBtn = $('tpBuyConfirm');
        var err = $('tpBuyError');
        if (!loggedIn) {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-right-to-bracket"></i> Login to place order';
            err.textContent = 'Please login to your DreamBD account to buy this top-up.';
            err.style.display = 'block';
            delete confirmBtn.dataset.short;
        } else if (viewerBalance < tpState.price) {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-wallet"></i> Add funds to continue';
            err.textContent = 'You need ' + tpMoney(tpState.price) + ' but have ' + tpMoney(viewerBalance) + '. Please add funds first.';
            err.style.display = 'block';
            confirmBtn.dataset.short = '1';
        } else {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order — ' + tpMoney(tpState.price);
            err.style.display = 'none';
            delete confirmBtn.dataset.short;
        }
    }

    qa('.dp-game-card').forEach(function (card) {
        card.addEventListener('click', function () {
            var gid = parseInt(this.dataset.gameId, 10);
            var game = gamesData.find(function (g) { return parseInt(g.id, 10) === gid; });
            if (!game) return;
            qa('.dp-game-card').forEach(function (c) { c.classList.remove('is-selected'); });
            card.classList.add('is-selected');
            tpState.game = game;
            tpState.pkg = null;
            tpRenderPackages();
            tpStep(2);
            $('tpPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    $('tpPkgGrid').addEventListener('click', function (e) {
        var btn = e.target.closest('.dp-pkg');
        if (!btn) return;
        Array.prototype.forEach.call(this.querySelectorAll('.dp-pkg'), function (p) { p.classList.remove('is-selected'); });
        btn.classList.add('is-selected');
        tpState.pkg = btn.dataset.pkgId;
        tpState.name = btn.dataset.pkgName;
        tpState.price = parseFloat(btn.dataset.pkgPrice) || 0;
        tpUpdateSummary();
        $('tpBuyError').style.display = 'none';
        $('tpPlayerUid').value = '';
        $('tpPlayerZone').value = '';
        $('tpContact').value = '';
        tpStep(3);
        $('tpDetailsBlock').scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function () { $('tpPlayerUid').focus({ preventScroll: true }); }, 420);
    });

    $('tpChangeGame').addEventListener('click', function () {
        tpStep(0);
        $('tpPanel').hidden = true;
        $('tpStepper').hidden = true;
        qa('.dp-game-card').forEach(function (c) { c.classList.remove('is-selected'); });
        $('tpGameGrid').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    $('tpChangePkg').addEventListener('click', function () {
        tpStep(2);
        $('tpPackagesBlock').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    $('tpAgain').addEventListener('click', function () {
        tpState.pkg = null;
        tpState.name = '';
        tpState.price = 0;
        tpStep(2);
        $('tpPackagesBlock').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    $('tpBuyConfirm').addEventListener('click', function () {
        if (!tpState.pkg) return;
        if (!loggedIn) { showToast('Please login to place a top-up order'); return; }
        if (this.disabled) {
            if (this.dataset.short === '1') {
                showToast('Insufficient balance — please add funds first');
            }
            return;
        }
        var uid = $('tpPlayerUid').value.trim();
        if (!uid) {
            $('tpBuyError').textContent = 'Please enter your game ID / UID.';
            $('tpBuyError').style.display = 'block';
            $('tpPlayerUid').focus();
            return;
        }
        var fd = new FormData();
        fd.append('action', 'buy_topup');
        fd.append('package_id', tpState.pkg);
        fd.append('player_uid', uid);
        fd.append('player_zone', $('tpPlayerZone').value.trim());
        fd.append('contact', $('tpContact').value.trim());
        fd.append('csrf_token', document.body.dataset.csrfToken || '');
        var btn = this;
        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing order...';
        fetch('handlers/topup_handler.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (data.success) {
                    $('tpDoneMsg').textContent = data.message || 'Your top-up order has been received.';
                    tpStep(4);
                    viewerBalance = Math.max(0, viewerBalance - tpState.price);
                    $('tpSumBalance').textContent = tpMoney(viewerBalance);
                    showToast(data.message || 'Order placed!');
                    $('tpDoneBlock').scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    btn.innerHTML = orig;
                    $('tpBuyError').textContent = data.message || 'Order failed.';
                    $('tpBuyError').style.display = 'block';
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = orig;
                $('tpBuyError').textContent = 'Network error.';
                $('tpBuyError').style.display = 'block';
            });
    });

    /* ===== TOOLBAR: search + chips + authors + sort ===== */
    var grid = q('.dp-pdf-grid');
    var searchInput = $('dpSearch');
    var sortSelect = $('dpSort');
    var resultCount = $('dpResultCount');
    var chipsWrap = $('dpChips');
    var authorsWrap = $('dpAuthors');
    var totalCards = grid ? grid.querySelectorAll('.dp-pdf-card').length : 0;
    var emptyBox = null;
    var originalOrder = grid ? Array.prototype.slice.call(grid.querySelectorAll('.dp-pdf-card')) : [];
    var activeChip = 'all';
    var activeAuthor = null;

    function ensureEmptyBox() {
        if (emptyBox || !grid) return;
        emptyBox = document.createElement('div');
        emptyBox.className = 'dp-empty';
        emptyBox.style.display = 'none';
        emptyBox.innerHTML = '<span class="dp-empty-icon"><i class="fas fa-magnifying-glass"></i></span>' +
            '<h4>No guides found</h4>' +
            '<p>Try a different keyword, or clear your filters.</p>' +
            '<button type="button" class="dp-empty-reset" id="dpEmptyReset"><i class="fas fa-rotate-left"></i> Reset filters</button>';
        grid.appendChild(emptyBox);
        emptyBox.addEventListener('click', function (e) {
            if (!e.target.closest('#dpEmptyReset')) return;
            if (searchInput) searchInput.value = '';
            activeChip = 'all';
            activeAuthor = null;
            if (chipsWrap) {
                Array.prototype.forEach.call(chipsWrap.querySelectorAll('.dp-chip'), function (c) {
                    var on = c.dataset.chip === 'all';
                    c.classList.toggle('is-active', on);
                    c.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
            }
            if (authorsWrap) {
                Array.prototype.forEach.call(authorsWrap.querySelectorAll('.dp-author-chip'), function (c) {
                    c.classList.remove('is-active');
                    c.setAttribute('aria-pressed', 'false');
                });
            }
            applyToolbar();
        });
    }

    function chipMatch(card) {
        if (activeChip === 'all') return true;
        if (activeChip === 'under250') return parseFloat(card.dataset.price || '0') < 250;
        return (card.dataset.badge || '') === activeChip;
    }

    function authorMatch(card) {
        return !activeAuthor || parseInt(card.dataset.author, 10) === activeAuthor;
    }

    function applyToolbar() {
        if (!grid) return;
        ensureEmptyBox();
        var term = (searchInput ? searchInput.value : '').trim().toLowerCase();
        var mode = sortSelect ? sortSelect.value : 'popular';
        var cards = originalOrder.slice();

        cards.sort(function(a, b) {
            if (mode === 'price-asc') return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
            if (mode === 'price-desc') return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
            if (mode === 'rating') return parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating);
            if (mode === 'newest') return parseInt(b.dataset.id, 10) - parseInt(a.dataset.id, 10);
            return parseInt(b.dataset.sales, 10) - parseInt(a.dataset.sales, 10);
        });
        cards.forEach(function(c) { grid.appendChild(c); });
        if (emptyBox) grid.appendChild(emptyBox);

        var visible = 0;
        cards.forEach(function(c) {
            var match = (!term || (c.dataset.name || '').indexOf(term) !== -1) && chipMatch(c) && authorMatch(c);
            c.classList.toggle('is-filtered', !match);
            if (match) visible++;
        });

        if (emptyBox) emptyBox.style.display = (visible === 0) ? '' : 'none';
        if (resultCount) resultCount.textContent = visible === totalCards
            ? totalCards + ' guides'
            : visible + ' of ' + totalCards + ' guides';
    }

    if (searchInput) searchInput.addEventListener('input', applyToolbar);
    if (sortSelect) sortSelect.addEventListener('change', applyToolbar);

    var sortBtn = $('dpSortBtn');
    var sortMenu = $('dpSortMenu');
    var sortLabel = $('dpSortLabel');
    if (sortBtn && sortMenu && sortSelect) {
        var sortOpts = Array.prototype.slice.call(sortMenu.querySelectorAll('.dp-sort-opt'));
        var syncSortUi = function () {
            sortOpts.forEach(function (o) {
                var on = o.dataset.value === sortSelect.value;
                o.classList.toggle('is-on', on);
                o.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on && sortLabel) sortLabel.textContent = (o.querySelector('span') || o).textContent;
            });
        };
        var sortOutside = function (e) {
            if (!e.target.closest('#dpSortWrap')) sortClose();
        };
        function sortClose() {
            sortMenu.hidden = true;
            sortBtn.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', sortOutside, true);
        }
        var sortOpen = function () {
            sortMenu.hidden = false;
            sortBtn.setAttribute('aria-expanded', 'true');
            document.addEventListener('click', sortOutside, true);
        };
        sortBtn.addEventListener('click', function () {
            if (sortMenu.hidden) sortOpen(); else sortClose();
        });
        sortMenu.addEventListener('click', function (e) {
            var o = e.target.closest('.dp-sort-opt');
            if (!o) return;
            sortSelect.value = o.dataset.value;
            syncSortUi();
            sortSelect.dispatchEvent(new Event('change', { bubbles: true }));
            sortClose();
            sortBtn.focus();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !sortMenu.hidden) { sortClose(); sortBtn.focus(); }
        });
        syncSortUi();
    }

    if (chipsWrap) {
        chipsWrap.addEventListener('click', function (e) {
            var chip = e.target.closest('.dp-chip');
            if (!chip) return;
            activeChip = chip.dataset.chip || 'all';
            Array.prototype.forEach.call(chipsWrap.querySelectorAll('.dp-chip'), function (c) {
                var on = c === chip;
                c.classList.toggle('is-active', on);
                c.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            applyToolbar();
        });
    }
    if (authorsWrap) {
        authorsWrap.addEventListener('click', function (e) {
            var chip = e.target.closest('.dp-author-chip');
            if (!chip) return;
            var id = parseInt(chip.dataset.author, 10);
            activeAuthor = (activeAuthor === id) ? null : id;
            Array.prototype.forEach.call(authorsWrap.querySelectorAll('.dp-author-chip'), function (c) {
                var on = activeAuthor !== null && parseInt(c.dataset.author, 10) === activeAuthor;
                c.classList.toggle('is-active', on);
                c.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            applyToolbar();
        });
    }
    applyToolbar();

    /* ===== ADD TO CART ===== */
    function updateCartBadge(count) {
        var badge = document.querySelector('.dream-cart-btn .dream-counter-badge');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'dream-counter-badge';
                var btn = document.querySelector('.dream-cart-btn');
                if (btn) btn.appendChild(badge);
            }
            badge.textContent = count > 9 ? '9+' : String(count);
        } else if (badge) {
            badge.remove();
        }
    }

    function renderCartBtn(btn, added) {
        btn.classList.toggle('is-added', added);
        btn.innerHTML = added
            ? '<i class="fas fa-check"></i> <span>Added</span>'
            : '<i class="fas fa-basket-shopping"></i> <span>Cart</span>';
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-add-cart]');
        if (!btn) return;
        var pid = btn.dataset.addCart;
        if (!loggedIn) { showToast('Please login to add items to your cart'); return; }
        if (btn.disabled) return;
        var removing = btn.classList.contains('is-added');
        btn.disabled = true;
        btn.innerHTML = removing
            ? '<i class="fas fa-spinner fa-spin"></i> <span>...</span>'
            : '<i class="fas fa-spinner fa-spin"></i> <span>Adding</span>';
        var fd = new FormData();
        fd.append('action', removing ? 'remove' : 'add');
        fd.append('product_id', pid);
        fd.append('csrf_token', document.body.dataset.csrfToken || '');
        fetch('handlers/cart_handler.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false;
                if (data.success) {
                    renderCartBtn(btn, !removing);
                    if (typeof data.count === 'number') updateCartBadge(data.count);
                    showToast(data.message || (removing ? 'Removed from cart' : 'Added to cart'));
                } else {
                    renderCartBtn(btn, removing);
                    showToast(data.message || 'Could not update cart');
                }
            })
            .catch(function(){
                btn.disabled = false;
                renderCartBtn(btn, removing);
                showToast('Network error. Please try again.');
            });
    });
});
</script>
