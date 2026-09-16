<?php
// index.php
require_once __DIR__ . '/includes/session.php';
dream_start_session();

require_once __DIR__ . '/database/config.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';

$auth = new Auth();
$security = new Security();

$isLoggedIn = $auth->isLoggedIn();
$user_name = $isLoggedIn ? ($_SESSION['full_name'] ?? $_SESSION['username'] ?? null) : null;

// Refresh session role from DB on every request (catches admin approvals)
if ($isLoggedIn && !empty($_SESSION['user_id'])) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row && $row['role'] !== ($_SESSION['role'] ?? '')) {
            $_SESSION['role'] = $row['role'];
        }
    } catch (Throwable $e) {}
}

$page = $_GET['page'] ?? 'home';
$allowed_pages = ['home', 'community', 'products', 'tournaments', 'tournament-room', 'how-it-works', 'cart', 'login', 'register', 'rules', 'faq', 'profile', 'messages', 'notifications', 'search', 'agent-dashboard', 'balance', 'p2p', 'admin', 'agent_submit_results', 'verify', 'reset_password', 'clubs', 'player-market', 'seller-upload'];
$page = in_array($page, $allowed_pages) ? $page : 'home';

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Check if user is logged in and trying to access login/register
if ($isLoggedIn && ($page === 'login' || $page === 'register')) {
    if ($is_ajax) {
        echo json_encode(['redirect' => 'index.php?page=profile', 'status' => 'redirect']);
        exit;
    } else {
        header('Location: index.php?page=profile');
        exit();
    }
}

// Check if user is not logged in and trying to access protected page
if (!$isLoggedIn && in_array($page, ['profile', 'messages', 'notifications', 'tournament-room', 'seller-upload'], true)) {
    if ($is_ajax) {
        echo json_encode(['redirect' => 'index.php?page=login', 'status' => 'redirect']);
        exit;
    } else {
        header('Location: index.php?page=login');
        exit();
    }
}

// Handle AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] === 'nav_search') {
    header('Content-Type: application/json');
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query === '') {
        echo json_encode(['users' => [], 'posts' => [], 'counts' => ['all' => 0, 'people' => 0, 'posts' => 0]]);
        exit;
    }

    try {
        $results = getNavbarSearchResults(Database::getInstance()->getConnection(), $isLoggedIn ? (int) ($_SESSION['user_id'] ?? 0) : null, $query, 4, 3);
        echo json_encode($results);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['users' => [], 'posts' => [], 'counts' => ['all' => 0, 'people' => 0, 'posts' => 0]]);
    }
    exit;
}

if ($is_ajax && isset($_GET['ajax'])) {
    ob_start();
    try {
        include "pages/{$page}.php";
        $content = ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unable to load page.']);
        exit;
    }
    echo json_encode(['title' => "DreamBD - {$page}", 'content' => $content, 'page' => $page]);
    exit;
}

// Normal page load - NO ob_start() here
$csrf_token = $security->generateCSRFToken();

// Theme logic
$theme = $_COOKIE['dreambd-theme'] ?? 'light';
if (!in_array($theme, ['light', 'dark'])) {
    $theme = 'light';
}
$themeAttr = htmlspecialchars($theme, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $themeAttr; ?>" data-theme="<?php echo $themeAttr; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DreamBD - <?php echo ucfirst($page); ?></title>
    
    <!-- Tailwind CSS (Play CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: {} }
        }
    </script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Hind+Siliguri:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Google reCAPTCHA v2 -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo dream_asset('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo dream_asset('assets/css/navbar.css'); ?>">
    <link rel="stylesheet" href="<?php echo dream_asset('assets/css/home.css'); ?>">
    <link rel="stylesheet" href="<?php echo dream_asset('assets/css/profile.css'); ?>">
    <link rel="stylesheet" href="<?php echo dream_asset('assets/css/social-pages.css'); ?>">
    <link rel="stylesheet" href="<?php echo dream_asset('assets/css/animations.css'); ?>">
    <link rel="stylesheet" href="<?php echo dream_asset('assets/css/community.css'); ?>">

    <style>
        body { opacity: 0; }
        body.css-ready { opacity: 1; transition: opacity 0.15s ease; }
        html, body { height: 100vh; overflow: hidden; }
        .main-content { height: 100vh; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .main-content::-webkit-scrollbar { width: 6px; }
        .main-content::-webkit-scrollbar-track { background: transparent; }
        .main-content::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.3); border-radius: 3px; }
        .main-content::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.5); }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('css-ready');
        });
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300" id="body" data-csrf-token="<?php echo htmlspecialchars($csrf_token); ?>" data-logged-in="<?php echo $isLoggedIn ? '1' : '0'; ?>">
    
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>
    
    <!-- Main Content (scrollable area below fixed navbar) -->
    <main id="mainContent" class="main-content min-h-screen">
        <div id="pageSkeleton" class="page-skeleton" aria-hidden="true" hidden>
            <div class="skeleton-container">
                <div class="sk-hero">
                    <div class="sk-banner"></div>
                    <div class="sk-hero-content">
                        <div class="sk-avatar sk-avatar-lg"></div>
                        <div class="sk-hero-text">
                            <div class="sk-line sk-line-lg"></div>
                            <div class="sk-line sk-line-sm"></div>
                        </div>
                    </div>
                </div>
                <div class="sk-tabs">
                    <div class="sk-tab"></div>
                    <div class="sk-tab"></div>
                    <div class="sk-tab"></div>
                    <div class="sk-tab"></div>
                </div>
                <div class="sk-layout-split">
                    <div class="sk-sidebar">
                        <div class="sk-sidebar-item"></div>
                        <div class="sk-sidebar-item"></div>
                        <div class="sk-sidebar-item"></div>
                        <div class="sk-sidebar-item"></div>
                    </div>
                    <div class="sk-main-area">
                        <div class="sk-chat-header"><div class="sk-line sk-line-md"></div></div>
                        <div class="sk-chat-bubble sk-chat-bubble-left"></div>
                        <div class="sk-chat-bubble sk-chat-bubble-right"></div>
                        <div class="sk-chat-bubble sk-chat-bubble-left"></div>
                        <div class="sk-chat-input"><div class="sk-line sk-line-xl"></div></div>
                    </div>
                </div>
                <div class="sk-stat-grid">
                    <div class="sk-stat-card"><div class="sk-line sk-line-sm"></div><div class="sk-line sk-line-lg sk-mt-2"></div><div class="sk-line sk-line-xs sk-mt-1"></div></div>
                    <div class="sk-stat-card"><div class="sk-line sk-line-sm"></div><div class="sk-line sk-line-lg sk-mt-2"></div><div class="sk-line sk-line-xs sk-mt-1"></div></div>
                    <div class="sk-stat-card"><div class="sk-line sk-line-sm"></div><div class="sk-line sk-line-lg sk-mt-2"></div><div class="sk-line sk-line-xs sk-mt-1"></div></div>
                </div>
                <div class="sk-form-card">
                    <div class="sk-form-header">
                        <div class="sk-avatar sk-avatar-md sk-mx-auto"></div>
                        <div class="sk-line sk-line-lg sk-mx-auto sk-mt-3"></div>
                        <div class="sk-line sk-line-sm sk-mx-auto sk-mt-2"></div>
                    </div>
                    <div class="sk-form-body">
                        <div class="sk-form-field"></div><div class="sk-form-field"></div>
                        <div class="sk-form-field sk-form-field--recaptcha"></div>
                        <div class="sk-form-field sk-form-field--half"><div class="sk-line sk-line-sm" style="width:45%"></div><div class="sk-line sk-line-sm" style="width:40%"></div></div>
                        <div class="sk-form-field sk-form-field--btn"></div>
                    </div>
                    <div class="sk-form-footer"><div class="sk-divider"></div><div class="sk-line sk-line-md sk-mx-auto"></div></div>
                </div>
                <div class="sk-notif-list">
                    <div class="sk-notif-item"><div class="sk-avatar sk-avatar-sm"></div><div class="sk-notif-text"><div class="sk-line sk-line-md"></div><div class="sk-line sk-line-xs"></div></div></div>
                    <div class="sk-notif-item"><div class="sk-avatar sk-avatar-sm"></div><div class="sk-notif-text"><div class="sk-line sk-line-md"></div><div class="sk-line sk-line-xs"></div></div></div>
                    <div class="sk-notif-item"><div class="sk-avatar sk-avatar-sm"></div><div class="sk-notif-text"><div class="sk-line sk-line-md"></div><div class="sk-line sk-line-xs"></div></div></div>
                </div>
                <div class="sk-feed">
                    <div class="sk-post"><div class="sk-post-head"><div class="sk-avatar"></div><div class="sk-post-head-text"><div class="sk-line sk-line-md"></div><div class="sk-line sk-line-xs"></div></div></div><div class="sk-post-body"><div class="sk-line sk-line-xl"></div><div class="sk-line sk-line-lg"></div><div class="sk-line sk-line-md"></div></div><div class="sk-post-thumb"></div><div class="sk-post-actions"><div class="sk-action"></div><div class="sk-action"></div><div class="sk-action"></div></div></div>
                    <div class="sk-post"><div class="sk-post-head"><div class="sk-avatar"></div><div class="sk-post-head-text"><div class="sk-line sk-line-md"></div><div class="sk-line sk-line-xs"></div></div></div><div class="sk-post-body"><div class="sk-line sk-line-xl"></div><div class="sk-line sk-line-lg"></div><div class="sk-line sk-line-sm"></div></div><div class="sk-post-actions"><div class="sk-action"></div><div class="sk-action"></div><div class="sk-action"></div></div></div>
                    <div class="sk-post"><div class="sk-post-head"><div class="sk-avatar"></div><div class="sk-post-head-text"><div class="sk-line sk-line-md"></div><div class="sk-line sk-line-sm"></div></div></div><div class="sk-post-body"><div class="sk-line sk-line-xl"></div><div class="sk-line sk-line-md"></div><div class="sk-line sk-line-sm"></div></div><div class="sk-post-thumb"></div><div class="sk-post-actions"><div class="sk-action"></div><div class="sk-action"></div><div class="sk-action"></div></div></div>
                </div>
            </div>
        </div>
        <div id="pageContent">
            <?php include "pages/{$page}.php"; ?>
        </div>

        <!-- Footer inside main for scroll containment -->
        <footer class="footer dream-footer" id="mainFooter">
            <div class="footer-wave" aria-hidden="true"><svg viewBox="0 0 1440 80" preserveAspectRatio="none"><path d="M0,40 C360,120 1080,-40 1440,40 L1440,0 L0,0 Z" fill="currentColor"/></svg></div>
            <div class="footer-deco footer-deco-1" aria-hidden="true"></div>
            <div class="footer-deco footer-deco-2" aria-hidden="true"></div>
            <div class="container">
                <div class="footer-content">
                    <section class="footer-section footer-brand-section">
                        <a href="index.php?page=home" class="footer-logo" data-page="home"><img src="assets/logo/logo.png" alt="DreamBD" onerror="this.style.display='none'"><span>DreamBD</span></a>
                        <p class="footer-description">A colorful social home for community posts, products, tournaments, friends, and real-time conversations.</p>
                        <div class="social-links">
                            <a href="#" class="social-link social-facebook" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" class="social-link social-discord" aria-label="Discord"><i class="fab fa-discord"></i></a>
                            <a href="#" class="social-link social-youtube" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                            <a href="#" class="social-link social-instagram" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                            <a href="#" class="social-link social-github" aria-label="GitHub"><i class="fab fa-github"></i></a>
                        </div>
                    </section>
                    <section class="footer-section">
                        <h3 class="footer-title">Explore</h3>
                        <ul class="footer-links">
                            <li><a href="index.php?page=home" data-page="home"><i class="fas fa-house"></i> Home</a></li>
                            <li><a href="index.php?page=community" data-page="community"><i class="fas fa-users"></i> Community</a></li>
                            <li><a href="index.php?page=products" data-page="products"><i class="fas fa-store"></i> Products</a></li>
                            <li><a href="index.php?page=tournaments" data-page="tournaments"><i class="fas fa-trophy"></i> Tournaments</a></li>
                            <li><a href="index.php?page=how-it-works" data-page="how-it-works"><i class="fas fa-circle-info"></i> How it works</a></li>
                        </ul>
                    </section>
                    <section class="footer-section">
                        <h3 class="footer-title">Support</h3>
                        <ul class="footer-links">
                            <li><a href="index.php?page=rules" data-page="rules"><i class="fas fa-book"></i> Rules</a></li>
                            <li><a href="index.php?page=faq" data-page="faq"><i class="fas fa-question-circle"></i> FAQ</a></li>
                            <li><a href="index.php?page=cart" data-page="cart"><i class="fas fa-cart-shopping"></i> Cart</a></li>
                            <li><a href="index.php?page=contact" data-page="contact"><i class="fas fa-envelope"></i> Contact</a></li>
                        </ul>
                    </section>

                </div>
                <div class="footer-bottom">
                    <p class="footer-copy">Created with <i class="fas fa-heart" style="color:#ef4444;font-size:11px;"></i> by <a href="https://me.robicodes.xyz/" target="_blank" rel="noopener noreferrer" style="color:var(--p2p-accent, #8b5cf6);font-weight:700;text-decoration:none;">Robiul Islam</a></p>
                    <div class="footer-bottom-links">
                        <a href="https://me.robicodes.xyz/" target="_blank" rel="noopener noreferrer" aria-label="Website"><i class="fas fa-globe"></i></a>
                        <a href="https://github.com/dev3ROBI" target="_blank" rel="noopener noreferrer" aria-label="GitHub"><i class="fab fa-github"></i></a>
                        <a href="https://www.facebook.com/iam.robi69/" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    </div>
                </div>
            </div>
        </footer>
    </main>

    <!-- Modal Root (fixed-position, outside mainContent to avoid stacking issues) -->
    <div id="modalRoot"></div>

    <!-- Scroll Progress Bar -->
    <div class="scroll-progress" id="scrollProgress" role="progressbar" aria-label="Page scroll progress"></div>

    <!-- Back to Top Button -->
    <button class="back-to-top" id="backToTop" aria-label="Back to top" data-scroll-progress>
        <svg class="progress-ring" viewBox="0 0 48 48" aria-hidden="true">
            <circle class="progress-ring-bg" cx="24" cy="24" r="20" fill="none"/>
            <circle class="progress-ring-fill" cx="24" cy="24" r="20" fill="none" stroke-linecap="round"/>
        </svg>
        <i class="fas fa-chevron-up" aria-hidden="true"></i>
    </button>
    
    <!-- Modal Overlay -->
    <div class="modal-overlay hidden fixed inset-0 bg-black/50" id="modalOverlay"></div>

    <!-- Post View Modal -->
    <div class="home-post-modal-backdrop hidden" id="homePostModalBackdrop" aria-hidden="true">
        <section class="home-post-modal" role="dialog" aria-modal="true" aria-labelledby="homePostModalTitle">
            <header class="home-post-modal-header">
                <h2 id="homePostModalTitle">Post details</h2>
                <button class="home-post-modal-close" type="button" aria-label="Close post details">
                    <i class="fas fa-times"></i>
                </button>
            </header>
            <div class="home-post-modal-body">
                <article class="home-post-modal-card">
                    <div class="home-post-modal-author">
                        <img src="assets/avatars/default.png" alt="" data-modal-avatar onerror="this.src='assets/avatars/default.png'">
                        <div>
                            <strong data-modal-author>DreamBD</strong>
                            <span><span data-modal-time>Just now</span> · <i class="fas fa-earth-asia"></i> <span data-modal-privacy>Public</span></span>
                        </div>
                    </div>
                    <p data-modal-content></p>
                    <div class="home-post-modal-image hidden" data-modal-image-wrap>
                        <img src="" alt="Post image" data-modal-image>
                    </div>
                    <div class="home-post-modal-stats">
                        <span><span class="home-modal-reaction-bubbles">👍 ❤️ 😮</span> <strong data-modal-likes>0</strong></span>
                        <span><strong data-modal-comments>0</strong> comments · <strong data-modal-shares>0</strong> shares</span>
                    </div>
                </article>
                <div class="home-post-modal-comments" data-modal-comments-list></div>
            </div>
            <form class="home-post-modal-composer" data-modal-comment-form>
                <input type="hidden" name="post_id" value="">
                <input type="hidden" name="parent_comment_id" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <img src="assets/avatars/<?php echo htmlspecialchars($_SESSION['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                <div class="home-post-modal-input-wrap">
                    <input type="text" name="comment" maxlength="300" placeholder="Write a comment...">
                    <div class="home-post-modal-tools">
                        <button type="button" aria-label="Add emoji"><i class="far fa-smile"></i></button>
                        <button type="button" aria-label="Add photo"><i class="fas fa-camera"></i></button>
                        <button type="button" aria-label="Add GIF"><i class="fas fa-file-image"></i></button>
                    </div>
                </div>
                <button class="home-post-modal-send" type="submit" aria-label="Post comment">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </section>
    </div>

    <!-- Settings & Logout Dialogs -->
    <div class="site-dialog-backdrop" id="siteDialogBackdrop" hidden></div>
    
    <div class="site-dialog" id="globalSettingsDialog" role="dialog" aria-modal="true" aria-labelledby="globalSettingsTitle" hidden>
        <div class="site-dialog-panel settings-dialog-panel">
            <div class="site-dialog-header">
                <div>
                    <span class="site-dialog-kicker">DreamBD</span>
                    <h2 id="globalSettingsTitle"><i class="fas fa-sliders"></i> Settings & Shortcuts</h2>
                    <p class="site-dialog-subtitle">Personalize navigation, appearance, and quick access from one colorful panel.</p>
                </div>
                <button type="button" class="site-dialog-close" data-close-site-dialog aria-label="Close settings">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="site-settings-grid">
                <section class="site-settings-card">
                    <span class="site-settings-card-icon"><i class="fas fa-user-gear"></i></span>
                    <span class="site-settings-card-tag">Daily essentials</span>
                    <h3>Account</h3>
                    <p>Manage your profile, messages, notifications, and orders.</p>
                    <div class="site-settings-links">
                        <a href="index.php?page=profile" data-no-ajax><i class="fas fa-user"></i><span>Profile</span></a>
                        <a href="index.php?page=messages" data-no-ajax><i class="fas fa-comments"></i><span>Messages</span></a>
                        <a href="index.php?page=notifications" data-no-ajax><i class="fas fa-bell"></i><span>Notifications</span></a>
                        <a href="index.php?page=cart" data-page="cart"><i class="fas fa-cart-shopping"></i><span>Cart & Orders</span></a>
                    </div>
                </section>
                <section class="site-settings-card">
                    <span class="site-settings-card-icon"><i class="fas fa-compass"></i></span>
                    <span class="site-settings-card-tag">Main sections</span>
                    <h3>Explore</h3>
                    <p>Jump to the main DreamBD sections quickly.</p>
                    <div class="site-settings-links">
                        <a href="index.php?page=home" data-page="home"><i class="fas fa-house"></i><span>Home</span></a>
                        <a href="index.php?page=community" data-page="community"><i class="fas fa-users"></i><span>Community</span></a>
                        <a href="index.php?page=products" data-page="products"><i class="fas fa-shopping-bag"></i><span>Products</span></a>
                        <a href="index.php?page=tournaments" data-page="tournaments"><i class="fas fa-trophy"></i><span>Tournaments</span></a>
                    </div>
                </section>
                <section class="site-settings-card">
                    <span class="site-settings-card-icon"><i class="fas fa-bolt"></i></span>
                    <span class="site-settings-card-tag">Fast shortcuts</span>
                    <h3>Quick actions</h3>
                    <p>Start common social actions without extra clicks.</p>
                    <div class="site-settings-links">
                        <a href="index.php?page=community" data-page="community"><i class="fas fa-pen-to-square"></i><span>Share a post</span></a>
                        <a href="index.php?page=messages" data-no-ajax><i class="fas fa-paper-plane"></i><span>Open inbox</span></a>
                        <a href="index.php?page=profile" data-no-ajax><i class="fas fa-user-group"></i><span>Manage friends</span></a>
                        <a href="index.php?page=notifications" data-no-ajax><i class="fas fa-heart"></i><span>Review activity</span></a>
                    </div>
                </section>
                <section class="site-settings-card">
                    <span class="site-settings-card-icon"><i class="fas fa-life-ring"></i></span>
                    <span class="site-settings-card-tag">Helpful reads</span>
                    <h3>Help & Guide</h3>
                    <p>Read platform rules, guide, and common answers.</p>
                    <div class="site-settings-links">
                        <a href="index.php?page=how-it-works" data-page="how-it-works"><i class="fas fa-cogs"></i><span>How It Works</span></a>
                        <a href="index.php?page=rules" data-page="rules"><i class="fas fa-book"></i><span>Rules</span></a>
                        <a href="index.php?page=faq" data-page="faq"><i class="fas fa-question-circle"></i><span>FAQ</span></a>
                    </div>
                </section>
                <section class="site-settings-card">
                    <span class="site-settings-card-icon"><i class="fas fa-palette"></i></span>
                    <span class="site-settings-card-tag">Look and feel</span>
                    <h3>Appearance</h3>
                    <p>Choose the look that feels best for you.</p>
                    <div class="site-theme-options">
                        <button type="button" class="site-theme-btn" data-theme-choice="light"><i class="fas fa-sun"></i> Light</button>
                        <button type="button" class="site-theme-btn" data-theme-choice="dark"><i class="fas fa-moon"></i> Dark</button>
                        <button type="button" class="site-theme-btn" data-theme-choice="auto"><i class="fas fa-circle-half-stroke"></i> Auto</button>
                    </div>
                    <div class="site-settings-note">You can still change detailed preferences from your profile settings page.</div>
                </section>
                <section class="site-settings-card site-settings-card-wide">
                    <span class="site-settings-card-icon"><i class="fas fa-shield-heart"></i></span>
                    <span class="site-settings-card-tag">Safety</span>
                    <h3>Session & privacy</h3>
                    <p>Need a clean exit on a shared device? Open the logout confirmation from here or from the mobile menu any time.</p>
                    <div class="site-settings-links">
                        <button type="button" class="site-settings-action" data-open-logout-dialog><i class="fas fa-right-from-bracket"></i><span>Open logout confirmation</span></button>
                        <a href="index.php?page=profile&tab=sessions" data-no-ajax><i class="fas fa-shield-halved"></i><span>Review active sessions</span></a>
                    </div>
                </section>
            </div>
        </div>
    </div>
    
    <div class="site-dialog" id="logoutConfirmDialog" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" hidden>
        <div class="site-dialog-panel logout-dialog-panel">
            <div class="site-dialog-header">
                <div>
                    <h2 id="logoutConfirmTitle"><i class="fas fa-right-from-bracket"></i> Log out?</h2>
                </div>
                <button type="button" class="site-dialog-close" data-close-site-dialog aria-label="Close logout dialog">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="site-dialog-copy logout-dialog-copy">Are you sure you want to log out of this device?</div>
            <div class="site-dialog-actions">
                <button type="button" class="btn btn-outline" data-close-site-dialog>Cancel</button>
                <a href="logout.php" class="btn btn-primary logout-confirm-btn" data-no-ajax>
                    <i class="fas fa-sign-out-alt"></i> Log out
                </a>
            </div>
        </div>
    </div>
    
    <!-- Forgot Password Success Overlay -->
    <div class="fixed inset-0 items-center justify-center" id="forgotSuccessOverlay" style="display:none;background:rgba(0,0,0,0.5);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:1001">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 max-w-sm w-full mx-4 text-center" onclick="event.stopPropagation()" style="animation:authModalContentIn 0.35s cubic-bezier(0.34,1.56,0.64,1) both">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full mb-4">
                <i class="fas fa-check-circle text-3xl text-green-500"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Password Reset!</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Your password has been reset successfully. Sign in with your new password.</p>
            <a href="index.php?page=login" class="inline-block w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 text-white hover:text-white font-medium text-sm rounded-lg transition-colors">Go to Login</a>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="auth-modal hidden fixed inset-0 items-center justify-center" id="forgotPasswordModal" style="display:none">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 max-w-sm w-full mx-4 relative">
            <button class="absolute top-3 right-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors" data-modal-close="forgotPassword" aria-label="Close modal">
                <i class="fas fa-times"></i>
            </button>
            
            <div class="text-center mb-5">
                <div class="inline-flex items-center justify-center w-11 h-11 bg-blue-600 rounded-full mb-3">
                    <i class="fas fa-key text-base text-white"></i>
                </div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Reset Password</h2>
                <p id="forgotDesc" class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter your email to receive an OTP</p>
            </div>
            
            <form method="POST" action="handlers/reset_handler.php" id="forgotPasswordForm" data-ajax-form="true" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="email" id="forgotEmailHidden" value="">
                
                <!-- Step 1: Email -->
                <div id="forgotStepEmail">
                    <div class="mb-4">
                        <label for="reset_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email address</label>
                        <input type="email" id="reset_email" name="email_visible" required autocomplete="email"
                               class="block w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                               placeholder="you@example.com">
                    </div>
                    <button type="submit" class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        <span class="btn-text">Send OTP</span>
                        <span class="btn-loader hidden"><i class="fas fa-spinner fa-spin"></i> Sending...</span>
                    </button>
                </div>
                
                <!-- Step 2: OTP Only (hidden initially) -->
                <div id="forgotStepOtp" style="display:none">
                    <div class="mb-4">
                        <label for="reset_otp" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">OTP Code</label>
                        <input type="text" id="reset_otp" name="otp" required maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                               class="block w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors text-center tracking-widest text-lg"
                               placeholder="000000">
                    </div>
                    <button type="submit" class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        <span class="btn-text">Verify OTP</span>
                        <span class="btn-loader hidden"><i class="fas fa-spinner fa-spin"></i> Verifying...</span>
                    </button>
                    <p class="text-center mt-3 text-xs text-gray-500 dark:text-gray-400">
                        <button type="button" id="forgotBackBtn" class="text-blue-600 hover:underline text-sm">Back to email</button>
                    </p>
                </div>
                
                <!-- Step 3: New Password (hidden initially) -->
                <div id="forgotStepPassword" style="display:none">
                    <div class="mb-4">
                        <label for="reset_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New password</label>
                        <input type="password" id="reset_password" name="password" required minlength="8" autocomplete="new-password"
                               class="block w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                               placeholder="At least 8 characters">
                    </div>
                    <div class="mb-4">
                        <label for="reset_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirm password</label>
                        <input type="password" id="reset_confirm" name="confirm_password" required minlength="8" autocomplete="new-password"
                               class="block w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                               placeholder="Repeat new password">
                    </div>
                    <button type="submit" class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        <span class="btn-text">Reset Password</span>
                        <span class="btn-loader hidden"><i class="fas fa-spinner fa-spin"></i> Resetting...</span>
                    </button>
                    <p class="text-center mt-3">
                        <button type="button" id="forgotBackToOtpBtn" class="text-sm text-blue-600 hover:underline">Back to OTP</button>
                    </p>
                </div>
                
                <p class="text-center text-sm text-gray-500 dark:text-gray-400 mt-4">
                    Remember your password?
                    <a href="index.php?page=login" class="text-blue-600 hover:underline font-medium">Sign in</a>
                </p>
            </form>
        </div>
    </div>
    
    <!-- JavaScript Files -->
    <script src="<?php echo dream_asset('assets/js/main.js'); ?>" defer></script>
    <script src="<?php echo dream_asset('assets/js/navbar.js'); ?>" defer></script>
    <script src="<?php echo dream_asset('assets/js/ajax.js'); ?>" defer></script>
    <script src="<?php echo dream_asset('assets/js/auth-handler.js'); ?>" defer></script>
    <script src="<?php echo dream_asset('assets/js/home.js'); ?>" defer></script>
    <script src="<?php echo dream_asset('assets/js/profile.js'); ?>" defer></script>
    <script src="<?php echo dream_asset('assets/js/social-pages.js'); ?>" defer></script>
    <script src="<?php echo dream_asset('assets/js/social-feed.js'); ?>" defer></script>
    
    <script>
        // Initialize app when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            const setNavbarActiveState = (pageName) => {
                document.querySelectorAll('#mainNav [data-page]').forEach((link) => {
                    const isActive = link.getAttribute('data-page') === pageName;
                    link.classList.toggle('active', isActive);
                    link.classList.toggle('is-active', isActive);
                    if (isActive) {
                        link.setAttribute('aria-current', 'page');
                    } else {
                        link.removeAttribute('aria-current');
                    }
                });
            };

            if (window.DreamBDApp) {
                window.DreamBDApp.init();
            }
            
            // Dialog handling is managed by navbar.js via document delegation
            
            // Theme choice buttons in settings
            document.querySelectorAll('[data-theme-choice]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const theme = btn.getAttribute('data-theme-choice');
                    const html = document.documentElement;
                    if (theme === 'dark') {
                        html.classList.add('dark');
                        html.setAttribute('data-theme', 'dark');
                    } else if (theme === 'light') {
                        html.classList.remove('dark');
                        html.setAttribute('data-theme', 'light');
                    } else {
                        // Auto - check system preference
                        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                            html.classList.add('dark');
                            html.setAttribute('data-theme', 'dark');
                        } else {
                            html.classList.remove('dark');
                            html.setAttribute('data-theme', 'light');
                        }
                    }
                    document.cookie = `dreambd-theme=${theme}; path=/; max-age=31536000`;
                });
            });

            setNavbarActiveState('<?php echo htmlspecialchars($page); ?>');
            document.addEventListener('pageChanged', (event) => {
                setNavbarActiveState(event.detail?.page || 'home');
            });

            // ============ Footer: Back to Top with Scroll Progress (on main element) ============
            var scrollContainer = document.getElementById('mainContent');
            const backToTopBtn = document.getElementById('backToTop');
            if (backToTopBtn && scrollContainer) {
                const ringFill = backToTopBtn.querySelector('.progress-ring-fill');
                const circumference = 2 * Math.PI * 20;
                if (ringFill) ringFill.style.strokeDasharray = circumference;

                const handleScroll = () => {
                    const scrollTop = scrollContainer.scrollTop;
                    const scrollHeight = scrollContainer.scrollHeight - scrollContainer.clientHeight;
                    const progress = scrollHeight > 0 ? scrollTop / scrollHeight : 0;

                    if (scrollTop > 400) {
                        backToTopBtn.classList.add('visible');
                    } else {
                        backToTopBtn.classList.remove('visible');
                    }

                    if (ringFill) {
                        const offset = circumference - progress * circumference;
                        ringFill.style.strokeDashoffset = offset;
                    }
                };

                scrollContainer.addEventListener('scroll', handleScroll, { passive: true });

                backToTopBtn.addEventListener('click', () => {
                    backToTopBtn.classList.add('clicked');
                    scrollContainer.scrollTo({ top: 0, behavior: 'smooth' });
                    setTimeout(() => backToTopBtn.classList.remove('clicked'), 500);
                });
            }

            // ============ Footer: Animate sections on scroll ============
            const footerObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('footer-section-visible');
                        footerObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('.dream-footer .footer-section').forEach((section, index) => {
                section.style.transitionDelay = `${index * 0.1}s`;
                footerObserver.observe(section);
            });
        });
    </script>
</body>
</html>
