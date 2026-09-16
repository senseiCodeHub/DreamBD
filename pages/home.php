<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

$viewerId = $_SESSION['user_id'] ?? null;
$db = Database::getInstance()->getConnection();
ensureSocialTables($db);

$homeFeedPosts = getTopReachPosts($db, $viewerId ? (int) $viewerId : null, 5);
$communityOverview = getCommunityOverview($db, $viewerId ? (int) $viewerId : null);
$homeSecurity = new Security();
$homeCsrfToken = $homeSecurity->generateCSRFToken();
$userDisplayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Guest';
$suggestedFriends = getHomePeopleSuggestions($db, $viewerId ? (int) $viewerId : null, 10);
$topPlayers = getHomeTopPlayers($db, 3);
$featuredTournament = getFeaturedTournamentSummary($db);
$homeSearchQuery = trim((string) ($_GET['q'] ?? ''));
$homeSearchResults = $homeSearchQuery !== ''
    ? getHomeSearchResults($db, $viewerId ? (int) $viewerId : null, $homeSearchQuery, 5, 4)
    : ['users' => [], 'posts' => [], 'counts' => ['all' => 0, 'people' => 0, 'posts' => 0]];

$staticHomePosts = [
    [
        'author' => 'DreamBD Admin',
        'avatar' => 'default.png',
        'time' => '2 hours ago',
        'privacy' => 'Public',
        'content' => 'Welcome to the refreshed DreamBD home. This is a static sample post for testing the homepage design before we connect everything with the database.',
        'likes' => 24,
        'comments' => 8,
        'shares' => 5,
    ],
];

$homeReactionOptions = [
    ['type' => 'like', 'label' => 'Like', 'emoji' => '👍'],
    ['type' => 'love', 'label' => 'Love', 'emoji' => '❤️'],
    ['type' => 'care', 'label' => 'Care', 'emoji' => '🥰'],
    ['type' => 'haha', 'label' => 'Haha', 'emoji' => '😆'],
    ['type' => 'wow', 'label' => 'Wow', 'emoji' => '😮'],
    ['type' => 'sad', 'label' => 'Sad', 'emoji' => '😢'],
    ['type' => 'angry', 'label' => 'Angry', 'emoji' => '😡'],
];

$staticTournamentShowcase = [
    [
        'title' => 'Dream Valor Cup',
        'subtitle' => '5v5 Competitive Bracket',
        'date' => 'May 28, 2026',
        'prize' => '$1,200 Prize Pool',
        'teams' => '32 Teams',
        'accent' => 'blue',
    ],
    [
        'title' => 'Weekend Clash',
        'subtitle' => 'Open Community Tournament',
        'date' => 'June 3, 2026',
        'prize' => '$850 Prize Pool',
        'teams' => '16 Teams',
        'accent' => 'purple',
    ],
];

$staticProductShowcase = [
    [
        'title' => 'Dream Pro Jersey',
        'subtitle' => 'Official team edition apparel',
        'price' => '$29',
        'image' => 'assets/images/apps/app1.jpg',
        'accent' => 'blue',
        'category' => 'Apparel',
        'badge' => 'New',
        'badge_color' => '#3b82f6',
        'rating' => '4.8',
        'sales' => 124,
    ],
    [
        'title' => 'Creator Stream Deck',
        'subtitle' => 'Hotkeys for tournament and content control',
        'price' => '$79',
        'image' => 'assets/images/apps/app2.jpg',
        'accent' => 'purple',
        'category' => 'Accessories',
        'badge' => null,
        'badge_color' => null,
        'rating' => '4.6',
        'sales' => 89,
    ],
];

$homeTournamentShowcase = $staticTournamentShowcase;
try {
    $stmt = $db->query("SELECT title, description, starts_at, status FROM tournaments ORDER BY COALESCE(starts_at, created_at) DESC LIMIT 2");
    $dbTournaments = $stmt->fetchAll() ?: [];
    if ($dbTournaments) {
        $accents = ['blue', 'purple'];
        $homeTournamentShowcase = array_map(static function ($item, $index) use ($accents) {
            return [
                'title' => $item['title'] ?: 'DreamBD Tournament',
                'subtitle' => $item['description'] ?: ucfirst((string) ($item['status'] ?? 'Upcoming')) . ' community event',
                'date' => !empty($item['starts_at']) ? date('M j, Y', strtotime((string) $item['starts_at'])) : 'Schedule coming soon',
                'prize' => ucfirst((string) ($item['status'] ?? 'Upcoming')),
                'teams' => 'Open registration',
                'accent' => $accents[$index % count($accents)],
            ];
        }, $dbTournaments, array_keys($dbTournaments));
    }
} catch (Throwable $e) {
    $homeTournamentShowcase = $staticTournamentShowcase;
}

$homeProductShowcase = $staticProductShowcase;
try {
    $dbProducts = getProducts($db, 4);
    if ($dbProducts) {
        $accents = ['blue', 'purple', 'green', 'orange'];
        $homeProductShowcase = array_map(static function ($item, $index) use ($accents) {
            $image = trim((string) ($item['image'] ?? ''));
            return [
                'title' => $item['name'] ?: 'DreamBD Product',
                'subtitle' => $item['short_desc'] ?: ($item['description'] ? mb_substr($item['description'], 0, 80) : 'Featured store item'),
                'price' => '$' . number_format((float) ($item['price'] ?? 0), 2),
                'image' => $image !== '' ? (str_starts_with($image, 'assets/') ? $image : 'assets/images/products/' . $image) : 'assets/images/apps/app1.jpg',
                'accent' => $accents[$index % count($accents)],
                'category' => $item['category'] ?? 'General',
                'badge' => $item['badge'] ?? null,
                'badge_color' => $item['badge_color'] ?? null,
                'rating' => $item['rating'] ?? null,
                'sales' => $item['sales'] ?? 0,
            ];
        }, $dbProducts, array_keys($dbProducts));
    }
} catch (Throwable $e) {
    $homeProductShowcase = $staticProductShowcase;
}

$homeSlides = [];
try {
    $stmt = $db->query("SELECT * FROM slider_content WHERE status = 'active' ORDER BY sort_order ASC, id ASC LIMIT 5");
    $homeSlides = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $homeSlides = [];
}

$leftMenuLinks = [
    ['icon' => 'fa-house', 'label' => 'Home feed', 'href' => 'index.php', 'dataPage' => 'home', 'tone' => 'blue'],
    ['icon' => 'fa-users', 'label' => 'Community', 'href' => 'index.php?page=community', 'dataPage' => 'community', 'tone' => 'green'],
    ['icon' => 'fa-comment-dots', 'label' => 'Messages', 'href' => $viewerId ? 'index.php?page=messages' : 'index.php?page=login', 'dataPage' => $viewerId ? 'messages' : 'login', 'tone' => 'pink'],
    ['icon' => 'fa-bell', 'label' => 'Notifications', 'href' => $viewerId ? 'index.php?page=notifications' : 'index.php?page=login', 'dataPage' => $viewerId ? 'notifications' : 'login', 'tone' => 'orange'],
    ['icon' => 'fa-user-group', 'label' => 'Friends', 'href' => $viewerId ? 'index.php?page=profile#friends' : 'index.php?page=register', 'dataPage' => $viewerId ? 'profile' : 'register', 'tone' => 'purple'],
    ['icon' => 'fa-circle-question', 'label' => 'How It Works', 'href' => 'index.php?page=how-it-works', 'dataPage' => 'how-it-works', 'tone' => 'cyan'],
];

$statHighlights = [
    ['label' => 'Members', 'value' => number_format((int) $communityOverview['members']), 'icon' => 'fa-users', 'tone' => 'blue'],
    ['label' => 'Posts', 'value' => number_format((int) $communityOverview['posts']), 'icon' => 'fa-newspaper', 'tone' => 'green'],
    ['label' => 'Public posts', 'value' => number_format((int) $communityOverview['public_posts']), 'icon' => 'fa-earth-asia', 'tone' => 'cyan'],
    ['label' => 'Friend visibility', 'value' => number_format((int) $communityOverview['friends_posts']), 'icon' => 'fa-user-check', 'tone' => 'purple'],
];
?>

<?php
// Check if slider is enabled
$sliderEnabled = true;
try {
    $stmt = $db->query("SELECT `value` FROM site_settings WHERE `key` = 'slider_enabled'");
    $v = $stmt->fetchColumn();
    $sliderEnabled = $v !== '0';
} catch (Throwable $e) {}

// Get slider_players for leaderboard type
$sliderPlayers = [];
try {
    $stmt = $db->query("SELECT * FROM slider_players WHERE is_active = 1 ORDER BY sort_order ASC, `rank` ASC LIMIT 5");
    $sliderPlayers = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {}

// Get slider_ads
$sliderAds = [];
try {
    $stmt = $db->query("SELECT * FROM slider_ads WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 5");
    $sliderAds = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {}
?>

<div class="home-page home-social-home" data-home-page data-csrf-token="<?php echo htmlspecialchars($homeCsrfToken); ?>" data-viewer-id="<?php echo (int) ($viewerId ?? 0); ?>">

    <?php if ($sliderEnabled && $homeSlides): ?>
    <section class="home-hero-slider home-hero-slider--social">
        <div class="home-hero-bg-shapes" aria-hidden="true">
            <div class="hero-orb hero-orb-1"></div>
            <div class="hero-orb hero-orb-2"></div>
            <div class="hero-orb hero-orb-3"></div>
        </div>
        <div class="home-slider-container">
            <?php foreach ($homeSlides as $slideIndex => $slide): ?>
                <?php
                $slideType = $slide['slider_type'] ?? 'features';
                $primaryHref = trim((string) ($slide['button1_href'] ?? 'index.php?page=community')) ?: 'index.php?page=community';
                $secondaryHref = trim((string) ($slide['button2_href'] ?? 'index.php?page=profile')) ?: 'index.php?page=profile';
                $primaryIcon = trim((string) ($slide['button1_icon'] ?? 'arrow-right')) ?: 'arrow-right';
                $secondaryIcon = trim((string) ($slide['button2_icon'] ?? 'sparkles')) ?: 'sparkles';
                $slideImage = trim((string) ($slide['image_path'] ?? $slide['image'] ?? $slide['background_image'] ?? ''));
                if ($slideImage !== '') {
                    $slideImageSrc = preg_match('/^https?:\/\//i', $slideImage) || str_starts_with($slideImage, 'assets/')
                        ? $slideImage
                        : 'assets/images/slider/' . ltrim($slideImage, '/');
                } else {
                    $slideImageSrc = '';
                }
                $bgGradient = htmlspecialchars($slide['bg_gradient'] ?? 'linear-gradient(135deg, #0f172a 0%, #1d4ed8 50%, #0ea5e9 100%)');
                $bgImage = trim((string) ($slide['bg_image'] ?? ''));
                ?>
                <div class="home-slide <?php echo $slideIndex === 0 ? 'active' : ''; ?> home-slide--<?php echo $slideType; ?>"
                     style="--slide-index: <?php echo $slideIndex + 1; ?>; --hero-gradient: <?php echo $bgGradient; ?>;">
                    <div class="home-slide-overlay"></div>
                    <?php if ($slideImageSrc !== ''): ?>
                    <div class="home-slide-background">
                        <img src="<?php echo htmlspecialchars($slideImageSrc); ?>" alt="<?php echo htmlspecialchars($slide['title'] ?? 'DreamBD slide'); ?>" loading="lazy">
                    </div>
                    <?php endif; ?>

                    <?php if ($slideType === 'tournament'): ?>
                    <div class="home-slide-bg-particles" aria-hidden="true">
                        <span class="particle"></span><span class="particle"></span><span class="particle"></span>
                        <span class="particle"></span><span class="particle"></span><span class="particle"></span>
                    </div>
                    <div class="container">
                        <div class="home-slide-content home-slide-content--centered">
                            <div class="home-tournament-slide">
                                <div class="home-tournament-badge">
                                    <i class="fas fa-trophy"></i>
                                    <span><?php echo htmlspecialchars($slide['badge'] ?? 'TOURNAMENT'); ?></span>
                                </div>
                                <h1 class="home-tournament-title"><?php echo htmlspecialchars($slide['title'] ?? 'Tournament'); ?></h1>
                                <p class="home-tournament-desc"><?php echo htmlspecialchars($slide['description'] ?? ''); ?></p>
                                <div class="home-tournament-meta">
                                    <?php
                                    $tournamentLink = trim((string) ($slide['link_url'] ?? ''));
                                    $tournamentLinkText = trim((string) ($slide['link_text'] ?? 'Join Tournament'));
                                    $prizeMoney = trim((string) ($slide['prize_money'] ?? ''));
                                    ?>
                                    <a href="<?php echo htmlspecialchars($tournamentLink ?: $primaryHref); ?>" class="home-tournament-cta" data-no-ajax>
                                        <i class="fas fa-gamepad"></i> <?php echo htmlspecialchars($tournamentLinkText ?: 'Join Tournament'); ?>
                                    </a>
                                    <?php if ($prizeMoney): ?>
                                    <div class="home-tournament-prize">
                                        <i class="fas fa-coins"></i>
                                        <span>$<?php echo htmlspecialchars($prizeMoney); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($slide['button2_href'])): ?>
                                <div class="home-slide-buttons home-tournament-alt">
                                    <a href="<?php echo htmlspecialchars($secondaryHref); ?>" class="btn btn-outline btn-lg home-slide-btn" data-no-ajax>
                                        <i class="fas fa-<?php echo htmlspecialchars($secondaryIcon); ?>"></i> <?php echo htmlspecialchars($slide['button2_text'] ?? 'Details'); ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php elseif ($slideType === 'leaderboard'): ?>
                    <div class="container">
                        <div class="home-slide-content">
                            <div class="home-leaderboard-text">
                                <div class="home-leaderboard-badge">
                                    <i class="fas fa-ranking-star"></i>
                                    <span><?php echo htmlspecialchars($slide['badge'] ?? 'LEADERBOARD'); ?></span>
                                </div>
                                <h1 class="home-slide-title"><?php echo htmlspecialchars($slide['title'] ?? 'Top Players'); ?></h1>
                                <p class="home-slide-description"><?php echo htmlspecialchars($slide['description'] ?? ''); ?></p>
                                <div class="home-slide-buttons">
                                    <a href="<?php echo htmlspecialchars($primaryHref); ?>" class="btn btn-primary btn-lg home-slide-btn" data-no-ajax>
                                        <i class="fas fa-<?php echo htmlspecialchars($primaryIcon); ?>"></i> <?php echo htmlspecialchars($slide['button1_text'] ?? 'View Full Rankings'); ?>
                                    </a>
                                </div>
                            </div>
                            <aside class="home-leaderboard-podium">
                                <?php if ($sliderPlayers): ?>
                                    <?php
                                    $podiumColors = ['#fbbf24', '#94a3b8', '#d97706'];
                                    $podiumIcons = ['fa-crown', 'fa-medal', 'fa-medal'];
                                    $top3 = array_slice($sliderPlayers, 0, 3);
                                    ?>
                                    <div class="home-podium">
                                        <?php foreach ($top3 as $i => $player): ?>
                                        <div class="home-podium-item home-podium-item--<?php echo $i + 1; ?>">
                                            <div class="home-podium-rank" style="background: <?php echo $podiumColors[$i] ?? '#94a3b8'; ?>;">
                                                <i class="fas <?php echo $podiumIcons[$i] ?? 'fa-medal'; ?>"></i>
                                            </div>
                                            <img src="assets/avatars/<?php echo htmlspecialchars($player['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                                            <strong><?php echo htmlspecialchars($player['name'] ?? 'Player'); ?></strong>
                                            <span><?php echo number_format((int) ($player['score'] ?? 0)); ?> pts</span>
                                            <?php if (!empty($player['highlight'])): ?>
                                            <em><?php echo htmlspecialchars($player['highlight']); ?></em>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($sliderPlayers) > 3): ?>
                                    <div class="home-podium-others">
                                        <?php foreach (array_slice($sliderPlayers, 3) as $p): ?>
                                        <div class="home-podium-other-item">
                                            <span>#<?php echo (int) $p['rank']; ?></span>
                                            <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                                            <span><?php echo number_format((int) ($p['score'] ?? 0)); ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php $leaderboardLink = trim((string) ($slide['link_url'] ?? $secondaryHref)); ?>
                                    <a href="<?php echo htmlspecialchars($leaderboardLink); ?>" class="home-leaderboard-link" data-no-ajax>
                                        <i class="fas fa-arrow-right"></i> <?php echo htmlspecialchars($slide['link_text'] ?: ($slide['button2_text'] ?? 'Full Leaderboard')); ?>
                                    </a>
                                <?php else: ?>
                                    <div class="home-leaderboard-empty">
                                        <i class="fas fa-trophy"></i>
                                        <h3><?php echo htmlspecialchars($slide['title'] ?? 'Leaderboard'); ?></h3>
                                        <p>Add players from the admin panel to display rankings here.</p>
                                        <a href="admin/player-manager.php" class="btn btn-outline btn-sm" data-no-ajax>
                                            <i class="fas fa-plus"></i> Add Players
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </aside>
                        </div>
                    </div>

                    <?php elseif ($slideType === 'ads'): ?>
                    <div class="container">
                        <div class="home-slide-content home-slide-content--centered">
                            <div class="home-ad-slide" style="--ad-bg: <?php echo $bgGradient; ?>;">
                                <div class="home-ad-backdrop"></div>
                                <?php if ($slideImageSrc): ?>
                                <div class="home-ad-image">
                                    <img src="<?php echo htmlspecialchars($slideImageSrc); ?>" alt="" onerror="this.style.display='none'">
                                </div>
                                <?php endif; ?>
                                <div class="home-ad-content">
                                    <div class="home-ad-badge">
                                        <i class="fas fa-bolt"></i>
                                        <span><?php echo htmlspecialchars($slide['badge'] ?? 'SPONSORED'); ?></span>
                                    </div>
                                    <h2 class="home-ad-title"><?php echo htmlspecialchars($slide['title'] ?? ''); ?></h2>
                                    <p class="home-ad-desc"><?php echo htmlspecialchars($slide['description'] ?? ''); ?></p>
                                    <?php
                                    $adLink = trim((string) ($slide['link_url'] ?? $primaryHref));
                                    $adLinkText = trim((string) ($slide['link_text'] ?? $slide['button1_text'] ?? 'Learn More'));
                                    ?>
                                    <a href="<?php echo htmlspecialchars($adLink); ?>" class="home-ad-cta" data-no-ajax>
                                        <span><?php echo htmlspecialchars($adLinkText); ?></span>
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php else: ?>
                    <?php
                    $accentColor = htmlspecialchars($slide['accent_color'] ?? '#3b82f6');
                    $textColor = htmlspecialchars($slide['text_color'] ?? '#ffffff');
                    $badgeIcon = htmlspecialchars($slide['badge_icon'] ?? 'fa-star');
                    $overlayOpacity = $slide['overlay_opacity'] ?? 0.6;
                    ?>
                    <div class="home-slide-features-bg" style="--accent: <?php echo $accentColor; ?>; --text: <?php echo $textColor; ?>;">
                        <div class="home-slide-shape home-slide-shape--1" style="background: <?php echo $accentColor; ?>33;"></div>
                        <div class="home-slide-shape home-slide-shape--2" style="background: <?php echo $accentColor; ?>22;"></div>
                        <div class="home-slide-grid-pattern"></div>
                    </div>
                    <div class="container">
                        <div class="home-slide-content home-slide-content--features" style="--accent: <?php echo $accentColor; ?>; --text: <?php echo $textColor; ?>;">
                            <div class="home-features-left">
                                <div class="home-features-badge" style="background: <?php echo $accentColor; ?>; color: #fff;">
                                    <i class="fas <?php echo $badgeIcon; ?>"></i>
                                    <span><?php echo htmlspecialchars($slide['badge'] ?? 'DreamBD'); ?></span>
                                </div>
                                <h1 class="home-features-title" style="color: <?php echo $textColor; ?>;"><?php echo htmlspecialchars($slide['title'] ?? 'Welcome to DreamBD'); ?></h1>
                                <p class="home-features-desc" style="color: <?php echo $textColor; ?>cc;"><?php echo htmlspecialchars($slide['description'] ?? ''); ?></p>
                                <div class="home-features-buttons">
                                    <a href="<?php echo htmlspecialchars($primaryHref); ?>" class="home-features-btn home-features-btn--primary" style="background: <?php echo $accentColor; ?>; color: #fff; box-shadow: 0 8px 30px <?php echo $accentColor; ?>66;" data-no-ajax>
                                        <i class="fas fa-<?php echo htmlspecialchars($primaryIcon); ?>"></i>
                                        <span><?php echo htmlspecialchars($slide['button1_text'] ?? 'Explore'); ?></span>
                                    </a>
                                    <a href="<?php echo htmlspecialchars($secondaryHref); ?>" class="home-features-btn home-features-btn--outline" style="border-color: <?php echo $textColor; ?>44; color: <?php echo $textColor; ?>;" data-no-ajax>
                                        <i class="fas fa-<?php echo htmlspecialchars($secondaryIcon); ?>"></i>
                                        <span><?php echo htmlspecialchars($slide['button2_text'] ?? 'Learn more'); ?></span>
                                    </a>
                                </div>
                            </div>
                            <aside class="home-features-right">
                                <div class="home-features-showcase">
                                    <div class="home-features-showcase-header">
                                        <span class="home-features-card-badge" style="background: <?php echo $accentColor; ?>;"><i class="fas <?php echo $badgeIcon; ?>"></i> Platform Features</span>
                                    </div>
                                    <div class="home-features-showcase-grid">
                                        <div class="home-features-showcase-item" style="background: <?php echo $accentColor; ?>12; border-color: <?php echo $accentColor; ?>22;">
                                            <i class="fas fa-bolt" style="color: <?php echo $accentColor; ?>;"></i>
                                            <strong style="color: <?php echo $textColor; ?>;">Lightning Fast</strong>
                                            <span style="color: <?php echo $textColor; ?>99;">Optimized for speed</span>
                                        </div>
                                        <div class="home-features-showcase-item" style="background: <?php echo $accentColor; ?>12; border-color: <?php echo $accentColor; ?>22;">
                                            <i class="fas fa-shield-alt" style="color: <?php echo $accentColor; ?>;"></i>
                                            <strong style="color: <?php echo $textColor; ?>;">Secure</strong>
                                            <span style="color: <?php echo $textColor; ?>99;">Protected & private</span>
                                        </div>
                                        <div class="home-features-showcase-item" style="background: <?php echo $accentColor; ?>12; border-color: <?php echo $accentColor; ?>22;">
                                            <i class="fas fa-palette" style="color: <?php echo $accentColor; ?>;"></i>
                                            <strong style="color: <?php echo $textColor; ?>;">Customizable</strong>
                                            <span style="color: <?php echo $textColor; ?>99;">Theme & preferences</span>
                                        </div>
                                        <div class="home-features-showcase-item" style="background: <?php echo $accentColor; ?>12; border-color: <?php echo $accentColor; ?>22;">
                                            <i class="fas fa-users-gear" style="color: <?php echo $accentColor; ?>;"></i>
                                            <strong style="color: <?php echo $textColor; ?>;">Community Driven</strong>
                                            <span style="color: <?php echo $textColor; ?>99;">Built by the people</span>
                                        </div>
                                    </div>
                                </div>
                            </aside>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="home-slider-controls">
            <button class="home-slider-prev" aria-label="Previous slide">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="home-slider-progress">
                <div class="home-slider-progress-bar"></div>
            </div>
            <button class="home-slider-next" aria-label="Next slide">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>

        <div class="home-slider-nav">
            <?php foreach ($homeSlides as $slideIndex => $slide): ?>
                <button class="home-slider-dot <?php echo $slideIndex === 0 ? 'active' : ''; ?>" data-slide="<?php echo $slideIndex; ?>" aria-label="Go to slide <?php echo $slideIndex + 1; ?>">
                    <span class="home-slider-dot-progress"></span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="home-facebook-layout" id="discover">
        <aside class="home-left-rail">
            <article class="home-rail-card home-rail-profile-card">
                <div class="home-rail-profile-top">
                    <div class="home-rail-avatar"><img src="assets/avatars/<?php echo htmlspecialchars($_SESSION['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'"></div>
                    <div>
                        <span class="home-rail-kicker">Welcome</span>
                        <h2><?php echo htmlspecialchars($viewerId ? $userDisplayName : 'Guest visitor'); ?></h2>
                        <p><?php echo $viewerId ? 'Pick up from your feed, inbox, and profile in one place.' : 'Explore DreamBD like a social network built around people and posts.'; ?></p>
                    </div>
                </div>
                <div class="home-rail-stats-inline">
                    <div><strong><?php echo number_format((int) $communityOverview['members']); ?></strong><span>Members</span></div>
                    <div><strong><?php echo number_format((int) $communityOverview['posts']); ?></strong><span>Posts</span></div>
                    <div><strong><?php echo number_format((int) $communityOverview['public_posts']); ?></strong><span>Public</span></div>
                </div>
            </article>

            <article class="home-rail-card">
                <div class="home-rail-card-header">
                    <span class="home-rail-kicker">Navigation</span>
                    <h3>Quick menu</h3>
                </div>
                <div class="home-left-menu">
                    <?php foreach ($leftMenuLinks as $menuLink): ?>
                        <a href="<?php echo htmlspecialchars($menuLink['href']); ?>" <?php echo !empty($menuLink['dataPage']) ? 'data-page="' . htmlspecialchars($menuLink['dataPage']) . '"' : 'data-no-ajax'; ?>>
                            <span class="home-menu-icon" data-tone="<?php echo htmlspecialchars($menuLink['tone']); ?>"><i class="fas <?php echo htmlspecialchars($menuLink['icon']); ?>"></i></span>
                            <span><?php echo htmlspecialchars($menuLink['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>

            <?php if ($homeSearchQuery !== ''): ?>
                <article class="home-rail-card">
                    <div class="home-rail-card-header">
                        <span class="home-rail-kicker">Search preview</span>
                        <h3><?php echo (int) ($homeSearchResults['counts']['all'] ?? 0); ?> matches</h3>
                    </div>
                    <p class="home-rail-copy">Results for "<?php echo htmlspecialchars($homeSearchQuery); ?>" across people and posts.</p>
                    <a href="index.php?page=search&q=<?php echo urlencode($homeSearchQuery); ?>" class="btn btn-outline btn-full" data-no-ajax>
                        <i class="fas fa-arrow-right"></i> Open full search
                    </a>
                </article>
            <?php endif; ?>
        </aside>

        <main class="home-center-feed" id="community">
            <?php if ($viewerId): ?>
            <section class="community-composer-card" id="composerCard">
                <div class="community-composer-top">
                    <img src="assets/avatars/<?= htmlspecialchars($_SESSION['avatar'] ?? 'default.png') ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                    <button type="button" class="composer-trigger-btn" id="composerTriggerBtn">What's on your mind, <?= htmlspecialchars(explode(' ', trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Friend'))[0]) ?>?</button>
                </div>
                <div class="community-composer-actions">
                    <button type="button" class="composer-action-btn" id="composerTriggerPhoto"><i class="fas fa-image" style="color:#45bd62"></i> Photo</button>
                    <button type="button" class="composer-action-btn" id="composerTriggerFeeling"><i class="fas fa-face-smile" style="color:#f7b928"></i> Feeling</button>
                </div>
            </section>
            
            <div class="create-post-overlay" id="createPostOverlay">
                <div class="create-post-modal">
                    <div class="create-post-header">
                        <h2>Create Post</h2>
                        <button type="button" class="create-post-close" id="createPostClose"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="create-post-body">
                        <div class="create-post-user">
                            <img src="assets/avatars/<?= htmlspecialchars($_SESSION['avatar'] ?? 'default.png') ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                            <div>
                                <strong><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></strong>
                                <span class="create-post-privacy">
                                    <i class="fas fa-globe-americas"></i> Public
                                </span>
                            </div>
                        </div>
                        <textarea class="create-post-textarea" id="createPostTextarea" placeholder="What's on your mind, <?= htmlspecialchars(explode(' ', trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Friend'))[0]) ?>?" maxlength="5000"></textarea>
                        <div class="create-post-photo-preview" id="createPostPhotoPreview" style="display:none">
                            <img id="createPostPhotoImg" src="" alt="">
                            <button type="button" class="create-post-photo-remove" id="createPostPhotoRemove"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="create-post-feeling-bar" id="createPostFeelingBar" style="display:none">
                            <i class="fas fa-face-smile"></i> Feeling <strong id="createPostFeelingText"></strong>
                            <button type="button" class="create-post-feeling-clear" id="createPostFeelingClear"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div class="create-post-footer">
                        <div class="create-post-footer-left">
                            <span class="create-post-footer-label">Add to your post</span>
                            <div class="create-post-footer-icons">
                                <button type="button" class="create-post-icon-btn" id="createPostPhotoBtn" title="Photo"><i class="fas fa-image" style="color:#45bd62"></i></button>
                                <button type="button" class="create-post-icon-btn" id="createPostFeelingBtn" title="Feeling"><i class="fas fa-face-smile" style="color:#f7b928"></i></button>
                            </div>
                        </div>
                        <button type="button" class="create-post-submit" id="createPostSubmit" disabled>Post</button>
                    </div>
                    <div class="create-post-feeling-picker" id="createPostFeelingPicker">
                        <div class="feeling-picker-header">How are you feeling?</div>
                        <div class="feeling-picker-grid">
                            <button class="feeling-option" data-feeling="Happy"><span class="feeling-emoji">😊</span><span class="feeling-label">Happy</span></button>
                            <button class="feeling-option" data-feeling="Sad"><span class="feeling-emoji">😢</span><span class="feeling-label">Sad</span></button>
                            <button class="feeling-option" data-feeling="Excited"><span class="feeling-emoji">🎉</span><span class="feeling-label">Excited</span></button>
                            <button class="feeling-option" data-feeling="Loved"><span class="feeling-emoji">❤️</span><span class="feeling-label">Loved</span></button>
                            <button class="feeling-option" data-feeling="Grateful"><span class="feeling-emoji">🙏</span><span class="feeling-label">Grateful</span></button>
                            <button class="feeling-option" data-feeling="Blessed"><span class="feeling-emoji">✨</span><span class="feeling-label">Blessed</span></button>
                            <button class="feeling-option" data-feeling="Angry"><span class="feeling-emoji">😡</span><span class="feeling-label">Angry</span></button>
                            <button class="feeling-option" data-feeling="Silly"><span class="feeling-emoji">🤪</span><span class="feeling-label">Silly</span></button>
                            <button class="feeling-option" data-feeling="Tired"><span class="feeling-emoji">😴</span><span class="feeling-label">Tired</span></button>
                            <button class="feeling-option" data-feeling="Cool"><span class="feeling-emoji">😎</span><span class="feeling-label">Cool</span></button>
                        </div>
                    </div>
                    <input type="file" id="createPostPhotoInput" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                </div>
            </div>
            <?php endif; ?>

            <div class="home-feed-header-bar">
                <div class="home-feed-header-copy">
                    <span class="home-rail-kicker">Main feed</span>
                    <h3>Posts from the community</h3>
                    <p class="home-feed-invite">Catch the latest stories, react like Facebook, follow friend activity, and jump into tournaments or products from one colorful DreamBD feed.</p>
                </div>
                <div class="home-feed-pills">
                    <span class="home-feed-pill"><i class="fas fa-user-check"></i> Friend-aware</span>
                    <span class="home-feed-pill"><i class="fas fa-bolt"></i> Fresh first</span>
                    <span class="home-feed-pill"><i class="fas fa-earth-asia"></i> Public discovery</span>
                </div>
            </div>

            <?php foreach ($homeFeedPosts as $post): ?>
                <article class="community-post-card" data-post-id="<?= (int) $post['id'] ?>">
                    <div class="community-post-header">
                        <div class="community-post-author">
                            <img src="assets/avatars/<?= htmlspecialchars($post['avatar'] ?? 'default.png') ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                            <div class="community-author-info">
                                <strong><?= htmlspecialchars($post['full_name'] ?: $post['username']) ?></strong>
                                <span class="community-post-time"><?= formatTimeAgo($post['created_at']) ?></span>
                            </div>
                        </div>
                        <div class="post-menu-container">
                            <button type="button" class="post-menu-trigger" title="More options"><i class="fas fa-ellipsis-h"></i></button>
                            <div class="post-dropdown">
                                <?php if ((int)$post['user_id'] === (int)$viewerId): ?>
                                <button class="post-dropdown-item" data-action="edit" data-post-id="<?= (int)$post['id'] ?>"><i class="fas fa-pen"></i> Edit post</button>
                                <button class="post-dropdown-item danger" data-action="delete" data-post-id="<?= (int)$post['id'] ?>"><i class="fas fa-trash-alt"></i> Delete post</button>
                                <?php else: ?>
                                <button class="post-dropdown-item" data-action="save" data-post-id="<?= (int)$post['id'] ?>"><i class="fas fa-bookmark"></i> Save post</button>
                                <div class="post-dropdown-divider"></div>
                                <button class="post-dropdown-item danger" data-action="report" data-post-id="<?= (int)$post['id'] ?>"><i class="fas fa-flag"></i> Report post</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="community-post-content">
                        <p><?= nl2br(htmlspecialchars($post['content'])) ?></p>
                    </div>
                    <?php if (!empty($post['image_path'])): ?>
                        <div class="community-post-image">
                            <img src="assets/posts/<?= htmlspecialchars($post['image_path']) ?>" alt="" loading="lazy">
                        </div>
                    <?php endif; ?>
                    
                    <div class="community-post-stats">
                        <div class="community-stat-left" title="View reactions">
                            <div class="community-reaction-icons">
                                <?= renderReactionSummaryHtml($post['reaction_summary'] ?? [], (int) ($post['like_count'] ?? 0)) ?>
                            </div>
                        </div>
                        <div class="community-stat-right">
                            <span class="community-comment-trigger" data-post-id="<?= (int) $post['id'] ?>"><?= (int) $post['comment_count'] ?> comments</span> • 
                            <span><?= (int) ($post['share_count'] ?? 0) ?> shares</span>
                        </div>
                    </div>

                    <div class="community-post-actions">
                        <div class="community-btn-action-wrap">
                            <button class="community-btn-action <?= ($post['viewer_reaction'] ?? null) ? 'active' : '' ?>" data-action="like" data-reaction="<?= htmlspecialchars($post['viewer_reaction'] ?? '') ?>">
                                <?php if ($post['viewer_reaction'] ?? null): ?>
                                    <span style="margin-right:8px"><?= getEmoji($post['viewer_reaction']) ?></span> <?= ucfirst($post['viewer_reaction']) ?>
                                <?php else: ?>
                                    <i class="far fa-thumbs-up"></i> Like
                                <?php endif; ?>
                            </button>
                            <div class="community-reaction-strip">
                                <?php foreach ($homeReactionOptions as $rxn): ?>
                                    <button class="community-reaction-btn" data-reaction="<?= htmlspecialchars($rxn['type']) ?>" title="<?= htmlspecialchars($rxn['label']) ?>"><?= $rxn['emoji'] ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="community-btn-action-wrap">
                            <button class="community-btn-action community-comment-trigger" data-action="comment" data-post-id="<?= (int) $post['id'] ?>"><i class="far fa-comment"></i> Comment</button>
                        </div>
                        <div class="community-btn-action-wrap">
                            <button class="community-btn-action" data-action="share"><i class="far fa-share-square"></i> Share</button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <section class="home-content-showcase">
                <article class="home-showcase-panel home-showcase-panel--tournaments">
                    <div class="home-showcase-panel-header">
                        <div>
                            <span class="home-rail-kicker">Tournaments</span>
                            <h3>Featured tournaments</h3>
                        </div>
                        <a href="index.php?page=tournaments" class="btn btn-outline btn-sm" data-page="tournaments">View all</a>
                    </div>
                    <div class="home-showcase-grid home-showcase-grid--tournaments">
                        <?php foreach ($homeTournamentShowcase as $tIdx => $item): ?>
                            <article class="home-tournament-card home-tournament-card--<?php echo htmlspecialchars($item['accent']); ?>" data-tournament-idx="<?php echo $tIdx; ?>">
                                <div class="home-tournament-card-top">
                                    <div class="home-tournament-card-icon">
                                        <i class="fas fa-trophy"></i>
                                    </div>
                                    <span class="home-tournament-card-status"><?php echo htmlspecialchars($item['prize']); ?></span>
                                </div>
                                <h4 class="home-tournament-card-title"><?php echo htmlspecialchars($item['title']); ?></h4>
                                <p class="home-tournament-card-desc"><?php echo htmlspecialchars($item['subtitle']); ?></p>
                                <div class="home-tournament-card-meta">
                                    <span><i class="fas fa-calendar-days"></i> <?php echo htmlspecialchars($item['date']); ?></span>
                                    <span><i class="fas fa-users"></i> <?php echo htmlspecialchars($item['teams']); ?></span>
                                </div>
                                <a href="index.php?page=tournaments" class="home-tournament-card-btn" data-page="tournaments">
                                    <span>View Details</span>
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="home-showcase-panel home-showcase-panel--products">
                    <div class="home-showcase-panel-header">
                        <div>
                            <span class="home-rail-kicker">Products</span>
                            <h3>Featured products</h3>
                        </div>
                        <a href="index.php?page=products" class="btn btn-outline btn-sm" data-page="products">Browse store</a>
                    </div>
                    <div class="home-showcase-grid home-showcase-grid--products">
                        <?php foreach ($homeProductShowcase as $pIdx => $item): ?>
                            <article class="home-product-card home-product-card--<?php echo htmlspecialchars($item['accent']); ?>" data-product-idx="<?php echo $pIdx; ?>">
                                <div class="home-product-card-media">
                                    <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" loading="lazy">
                                    <?php if (!empty($item['badge'])): ?>
                                    <span class="home-product-card-badge" style="background:<?php echo htmlspecialchars($item['badge_color'] ?? '#3b82f6'); ?>"><?php echo htmlspecialchars($item['badge']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="home-product-card-body">
                                    <span class="home-product-card-category"><?php echo htmlspecialchars($item['category'] ?? 'General'); ?></span>
                                    <h4 class="home-product-card-title"><?php echo htmlspecialchars($item['title']); ?></h4>
                                    <p class="home-product-card-desc"><?php echo htmlspecialchars($item['subtitle']); ?></p>
                                    <div class="home-product-card-footer">
                                        <span class="home-product-card-price"><?php echo htmlspecialchars($item['price']); ?></span>
                                        <div class="home-product-card-stats">
                                            <?php if (!empty($item['rating'])): ?>
                                            <span class="home-product-card-rating"><i class="fas fa-star"></i> <?php echo htmlspecialchars($item['rating']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['sales'])): ?>
                                            <span class="home-product-card-sales"><?php echo number_format((int) $item['sales']); ?> sold</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <a href="index.php?page=products" class="home-product-card-btn" data-page="products">
                                        <i class="fas fa-shopping-cart"></i> View Product
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>
        </main>

        <aside class="home-right-rail">
            <article class="home-rail-card">
                <div class="home-rail-card-header">
                    <span class="home-rail-kicker">Suggested friends</span>
                    <h3>People you may know</h3>
                </div>
                <div class="home-suggestions-list home-suggestions-list--rail" data-home-suggestions data-csrf-token="<?php echo htmlspecialchars($homeCsrfToken); ?>">
                    <?php if ($suggestedFriends): ?>
                        <?php $skey = 0; ?>
                        <?php foreach ($suggestedFriends as $suggested): ?>
                            <?php $skey++; ?>
                            <div class="home-suggestion-card-v2 <?php echo $skey > 5 ? 'home-suggestion-extra' : ''; ?>" data-suggestion-card="<?php echo (int) $suggested['id']; ?>">
                                <a href="index.php?page=profile&user=<?php echo (int) $suggested['id']; ?>" data-no-ajax class="home-suggestion-avatar-wrap">
                                    <img src="assets/avatars/<?php echo htmlspecialchars($suggested['avatar'] ?: 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                                </a>
                                <div class="home-suggestion-body">
                                    <a href="index.php?page=profile&user=<?php echo (int) $suggested['id']; ?>" data-no-ajax class="home-suggestion-name"><?php echo htmlspecialchars($suggested['full_name'] ?: $suggested['username']); ?></a>
                                    <span class="home-suggestion-mutual"><?php echo htmlspecialchars($suggested['suggestion_reason'] ?? ''); ?></span>
                                    <div class="home-suggestion-actions">
                                        <button class="home-btn home-btn-primary friend-toggle-btn" type="button" data-action="send_friend_request" data-target-user-id="<?php echo (int) $suggested['id']; ?>"><i class="fas fa-user-plus"></i> Add Friend</button>
                                    </div>
                                </div>
                                <button class="home-suggestion-dismiss" type="button" data-dismiss-user-id="<?php echo (int) $suggested['id']; ?>" title="Dismiss suggestion"><i class="fas fa-xmark"></i></button>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($suggestedFriends) > 5): ?>
                            <button type="button" class="home-suggestion-see-more" onclick="this.closest('[data-home-suggestions]').querySelectorAll('.home-suggestion-extra').forEach(function(el){el.classList.remove('home-suggestion-extra')});this.remove()">See more</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="home-suggestion-empty">
                            <i class="fas fa-user-group"></i>
                            <strong>No suggestions yet</strong>
                            <span>Your network suggestions will appear here as more people join.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="home-rail-card">
                <div class="home-rail-card-header">
                    <span class="home-rail-kicker">DreamBD stats</span>
                    <h3>Platform snapshot</h3>
                </div>
                <div class="home-rail-metric-list">
                    <?php foreach ($statHighlights as $stat): ?>
                        <div class="home-rail-metric">
                            <span class="home-rail-metric-icon" data-tone="<?php echo htmlspecialchars($stat['tone']); ?>"><i class="fas <?php echo htmlspecialchars($stat['icon']); ?>"></i></span>
                            <div class="home-rail-metric-body">
                                <strong class="home-stat-number" data-count="<?php echo (int) str_replace(',', '', $stat['value']); ?>">0</strong>
                                <span><?php echo htmlspecialchars($stat['label']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="home-rail-card home-rail-pulse-card">
                <div class="home-rail-card-header">
                    <span class="home-rail-kicker">DreamBD pulse</span>
                    <h3><?php echo $viewerId ? 'Your next moves' : 'Start your journey'; ?></h3>
                </div>
                <p class="home-rail-copy"><?php echo $viewerId ? 'Welcome back, ' . htmlspecialchars($userDisplayName) . '. Keep your feed, tournaments, and profile moving from here.' : 'Create a free account to post, react, comment, join tournaments, and discover people faster.'; ?></p>
                <div class="home-pulse-list">
                    <a href="index.php?page=community" data-page="community">
                        <i class="fas fa-fire"></i>
                        <span><strong><?php echo number_format((int) $communityOverview['posts']); ?> community posts</strong><em>Read, react, and join conversations.</em></span>
                    </a>
                    <a href="index.php?page=tournaments" data-page="tournaments">
                        <i class="fas fa-trophy"></i>
                        <span><strong><?php echo htmlspecialchars($featuredTournament['title']); ?></strong><em><?php echo htmlspecialchars($featuredTournament['status']); ?> · <?php echo htmlspecialchars($featuredTournament['display_time']); ?></em></span>
                    </a>
                    <a href="index.php?page=products" data-page="products">
                        <i class="fas fa-store"></i>
                        <span><strong>Featured store</strong><em>Browse products picked for DreamBD members.</em></span>
                    </a>
                    <a href="<?php echo $viewerId ? 'index.php?page=profile' : 'index.php?page=register'; ?>" <?php echo $viewerId ? 'data-page="profile"' : 'data-page="register"'; ?>>
                        <i class="fas fa-user-astronaut"></i>
                        <span><strong><?php echo $viewerId ? 'Polish your profile' : 'Join DreamBD'; ?></strong><em><?php echo $viewerId ? 'Update your public identity and friends.' : 'Unlock posting, comments, and reactions.'; ?></em></span>
                    </a>
                </div>
            </article>
        </aside>
    </section>
</div>

<?php include __DIR__ . '/../includes/post-modals.php'; ?>

<link rel="stylesheet" href="<?php echo dream_asset('assets/css/home.css'); ?>">
<script src="<?php echo dream_asset('assets/js/community.js'); ?>" defer></script>
<script src="<?php echo dream_asset('assets/js/home.js'); ?>" defer></script>
