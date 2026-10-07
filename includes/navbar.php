<?php
// Expected variables from parent scope
/** @var string $page - Current page being viewed */
/** @var bool $isLoggedIn - Whether user is authenticated */
/** @var string|null $user_name - User's full name if logged in */

$headerCounts = ['messages' => 0, 'notifications' => 0];
if (!empty($isLoggedIn) && !empty($_SESSION['user_id']) && class_exists('Database')) {
    try {
        $headerCounts = getHeaderSocialCounts(Database::getInstance()->getConnection(), (int) $_SESSION['user_id']);
    } catch (Throwable $e) {
        $headerCounts = ['messages' => 0, 'notifications' => 0];
    }
}

$cartCount = 0;
if (!empty($isLoggedIn) && !empty($_SESSION['user_id']) && class_exists('Database')) {
    try {
        $cartStmt = Database::getInstance()->getConnection()->prepare(
            "SELECT COUNT(*) FROM cart_items ci
             JOIN products p ON p.id = ci.product_id AND p.status = 'active'
             WHERE ci.user_id = ?"
        );
        $cartStmt->execute([(int) $_SESSION['user_id']]);
        $cartCount = (int) $cartStmt->fetchColumn();
    } catch (Throwable $e) {
        $cartCount = 0;
    }
}

$primaryLinks = [
    ['page' => 'home', 'href' => 'index.php', 'icon' => 'fa-home', 'label' => 'Home'],
    ['page' => 'community', 'href' => 'index.php?page=community', 'icon' => 'fa-users', 'label' => 'Community'],
    ['page' => 'products', 'href' => 'index.php?page=products', 'icon' => 'fa-store', 'label' => 'Products'],
    ['page' => 'tournaments', 'href' => 'index.php?page=tournaments', 'icon' => 'fa-trophy', 'label' => 'Tournaments'],
];
if (!empty($isLoggedIn)) {
    $primaryLinks[] = ['page' => 'balance', 'href' => 'index.php?page=balance', 'icon' => 'fa-wallet', 'label' => 'Balance'];
    $primaryLinks[] = ['page' => 'p2p', 'href' => 'index.php?page=p2p', 'icon' => 'fa-right-left', 'label' => 'P2P'];
}

$mobileExtraLinks = [
    ['page' => 'how-it-works', 'href' => 'index.php?page=how-it-works', 'icon' => 'fa-circle-question', 'label' => 'How It Works'],
    ['page' => 'faq', 'href' => 'index.php?page=faq', 'icon' => 'fa-book-open', 'label' => 'FAQ'],
];

$avatarFile = $_SESSION['avatar'] ?? 'default.png';
$avatarPath = $avatarFile && $avatarFile !== 'default.png'
    ? (str_starts_with($avatarFile, 'assets/') ? htmlspecialchars($avatarFile) : 'assets/avatars/' . htmlspecialchars($avatarFile))
    : 'assets/avatars/default.png';
$displayName = htmlspecialchars(explode(' ', $user_name ?? 'User')[0] ?? 'User');
?>
<nav class="dream-navbar" id="mainNav" data-site-navigation>
    <div class="dream-navbar-shell">
        <div class="dream-navbar-left">
            <button type="button" class="dream-search-back" id="searchBackBtn" aria-label="Back">
                <i class="fas fa-arrow-left"></i>
            </button>

            <a href="index.php" class="dream-brand" aria-label="DreamBD home">
                <span class="dream-brand-mark">
                    <img src="assets/logo/logo.png" alt="DreamBD logo" class="dream-brand-logo">
                </span>
                <span class="dream-brand-copy">
                    <strong>DreamBD</strong>
                    <small>Social home</small>
                </span>
            </a>

            <div class="dream-search" id="searchBar">
                <form action="index.php" method="GET" class="dream-search-form">
                    <input type="hidden" name="page" value="search">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search DreamBD" id="searchInput" autocomplete="off">
                    <button type="button" class="dream-search-clear hidden" id="searchClearBtn" aria-label="Clear search">
                        <i class="fas fa-times"></i>
                    </button>
                </form>

                <div class="dream-search-suggestions hidden" id="navSearchSuggestions">
                    <div class="dream-search-suggestions-head">
                        <div class="dsh-left">
                            <i class="fas fa-bolt"></i>
                            <strong>Quick results</strong>
                        </div>
                        <span id="navSearchSuggestionsMeta">Search people and posts</span>
                    </div>
                    <div id="navSearchSuggestionsBody" class="dream-search-suggestions-body"></div>
                    <div class="dream-search-suggestions-foot hidden" id="navSearchSuggestionsFoot">
                        <a href="index.php?page=search" id="navSearchAllLink" data-no-ajax class="dream-search-all-results">
                            <span>Open full search results</span>
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="dream-navbar-center">
            <div class="dream-nav-links">
                <?php foreach ($primaryLinks as $link): ?>
                    <?php $isActive = ($page === $link['page']) || ($link['page'] === 'home' && $page === 'home'); ?>
                    <a href="<?php echo $link['href']; ?>"
                       class="dream-nav-link <?php echo $isActive ? 'is-active' : ''; ?>"
                       data-page="<?php echo htmlspecialchars($link['page']); ?>"
                       aria-label="<?php echo htmlspecialchars($link['label']); ?>">
                        <i class="fas <?php echo htmlspecialchars($link['icon']); ?>"></i>
                        <span><?php echo htmlspecialchars($link['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="dream-navbar-right">
            <button type="button" class="dream-icon-btn mobile-search-btn" id="mobileSearchToggle" aria-label="Search">
                <i class="fas fa-search"></i>
            </button>

            <button type="button" id="themeToggle" class="dream-icon-btn" aria-label="Toggle theme">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun hidden"></i>
            </button>

            <a href="index.php?page=cart" class="dream-icon-btn dream-counter-btn dream-cart-btn" data-page="cart" aria-label="Cart">
                <i class="fas fa-cart-shopping"></i>
                <?php if ($cartCount > 0): ?>
                    <span class="dream-counter-badge"><?php echo $cartCount > 9 ? '9+' : $cartCount; ?></span>
                <?php endif; ?>
            </a>

            <?php if ($isLoggedIn): ?>
                <a href="index.php?page=notifications" class="dream-icon-btn dream-counter-btn" data-page="notifications" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($headerCounts['notifications'] > 0): ?>
                        <span class="dream-counter-badge"><?php echo $headerCounts['notifications'] > 9 ? '9+' : (int) $headerCounts['notifications']; ?></span>
                    <?php endif; ?>
                </a>

                <a href="index.php?page=messages" class="dream-icon-btn dream-counter-btn" data-page="messages" aria-label="Messages">
                    <i class="fas fa-comment-dots"></i>
                    <?php if ($headerCounts['messages'] > 0): ?>
                        <span class="dream-counter-badge"><?php echo $headerCounts['messages'] > 9 ? '9+' : (int) $headerCounts['messages']; ?></span>
                    <?php endif; ?>
                </a>

                <div class="relative" id="userDropdown">
                    <button type="button" class="dream-profile-chip" id="userDropdownToggle" aria-label="Open profile menu">
                        <img src="<?php echo $avatarPath; ?>"
                             alt="Profile"
                             class="dream-profile-avatar"
                             onerror="this.onerror=null;this.src='assets/avatars/default.png'">
                        <span class="hidden lg:inline"><?php echo $displayName; ?></span>
                        <?php if (($_SESSION['role'] ?? 'user') === 'agent'): ?><span class="gp-agent-chip-badge" title="Agent"><i class="fas fa-crown"></i></span><?php endif; ?>
                        <i class="fas fa-chevron-down text-xs"></i>
                    </button>

                    <div class="dream-user-menu hidden opacity-0 scale-95" id="userDropdownMenu">
                        <div class="dream-user-menu-header">
                            <img src="<?php echo $avatarPath; ?>"
                                 alt="Profile"
                                 class="dream-user-menu-avatar"
                                 onerror="this.onerror=null;this.src='assets/avatars/default.png'">
                            <div>
                                <strong><?php echo htmlspecialchars($user_name ?? 'User'); ?></strong>
                                <span><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></span>
                                <?php if (($_SESSION['role'] ?? 'user') === 'agent'): ?><span class="gp-menu-agent-badge"><i class="fas fa-crown"></i> Agent</span><?php endif; ?>
                            </div>
                        </div>
                        <a href="index.php?page=profile" data-page="profile"><i class="fas fa-user"></i><span>My Profile</span></a>
                        <a href="index.php?page=profile&tab=orders" data-page="profile"><i class="fas fa-bag-shopping"></i><span>Orders</span></a>
                        <a href="index.php?page=profile&tab=sessions" data-page="profile"><i class="fas fa-shield-halved"></i><span>Sessions</span></a>
                        <?php if ($_SESSION['role'] === 'agent'): ?>
                            <a href="index.php?page=agent-dashboard" data-page="agent-dashboard"><i class="fas fa-crown"></i><span>Agent Dashboard</span></a>
                        <?php endif; ?>
                        <?php if (in_array($_SESSION['role'] ?? 'user', ['admin', 'moderator', 'super_admin'], true)): ?>
                            <a href="admin/index.php" data-no-ajax><i class="fas fa-gauge-high"></i><span>Admin Panel</span></a>
                        <?php endif; ?>
                        <button type="button" data-open-global-settings><i class="fas fa-sliders"></i><span>Settings</span></button>
                        <button type="button" data-open-logout-dialog class="dream-user-menu-logout"><i class="fas fa-right-from-bracket"></i><span>Logout</span></button>
                    </div>
                </div>
            <?php else: ?>
                <a href="index.php?page=login" class="dream-auth-link" data-page="login">Sign In</a>
                <a href="index.php?page=register" class="dream-auth-cta">Join Now</a>
            <?php endif; ?>

            <button type="button" class="dream-icon-btn" id="mobileMenuToggle" aria-label="Menu" aria-controls="mobileMenu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>

    <div class="dream-mobile-menu" id="mobileMenu">
        <div class="dream-mobile-menu-inner">
            <div class="dream-mobile-menu-head">
                <span class="dream-mobile-menu-title">Menu</span>
                <button type="button" class="dream-mobile-close" id="closeMenu" aria-label="Close menu">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php if ($isLoggedIn): ?>
                <div class="dream-mobile-profile">
                    <img src="<?php echo $avatarPath; ?>" alt="Profile" onerror="this.onerror=null;this.src='assets/avatars/default.png'">
                    <div>
                        <strong><?php echo htmlspecialchars($user_name ?? 'User'); ?></strong>
                        <span><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dream-mobile-links">
                <?php foreach ($primaryLinks as $link): ?>
                    <?php $isActive = ($page === $link['page']) || ($link['page'] === 'home' && $page === 'home'); ?>
                    <a href="<?php echo $link['href']; ?>" class="<?php echo $isActive ? 'is-active' : ''; ?>" data-page="<?php echo htmlspecialchars($link['page']); ?>">
                        <i class="fas <?php echo htmlspecialchars($link['icon']); ?>"></i>
                        <span><?php echo htmlspecialchars($link['label']); ?></span>
                    </a>
                <?php endforeach; ?>

                <?php foreach ($mobileExtraLinks as $link): ?>
                    <a href="<?php echo $link['href']; ?>" data-page="<?php echo htmlspecialchars($link['page']); ?>">
                        <i class="fas <?php echo htmlspecialchars($link['icon']); ?>"></i>
                        <span><?php echo htmlspecialchars($link['label']); ?></span>
                    </a>
                <?php endforeach; ?>

                <?php if ($isLoggedIn): ?>
                    <a href="index.php?page=profile" data-page="profile"><i class="fas fa-id-badge"></i><span>Profile</span></a>
                    <a href="index.php?page=messages" data-page="messages"><i class="fas fa-envelope"></i><span>Messages</span></a>
                    <a href="index.php?page=notifications" data-page="notifications"><i class="fas fa-bell"></i><span>Notifications</span></a>
                    <?php if (in_array($_SESSION['role'] ?? 'user', ['admin', 'moderator', 'super_admin'], true)): ?>
                        <a href="admin/index.php" data-no-ajax><i class="fas fa-gauge"></i><span>Admin Panel</span></a>
                    <?php endif; ?>
                    <button type="button" class="dream-mobile-logout" data-open-logout-dialog><i class="fas fa-right-from-bracket"></i><span>Logout</span></button>
                <?php else: ?>
                    <a href="index.php?page=login" data-page="login"><i class="fas fa-right-to-bracket"></i><span>Sign In</span></a>
                    <a href="index.php?page=register" data-page="register" class="<?php echo $page === 'register' ? 'is-active' : ''; ?>"><i class="fas fa-user-plus"></i><span>Create account</span></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</nav>
<div class="dream-mobile-overlay" id="mobileOverlay"></div>

<style>
.dream-nav-link i, .dream-nav-link span, .dream-brand-mark, .dream-brand-copy, .dream-icon-btn i {
  pointer-events: none;
}

#mobileMenuToggle {
  display: none !important;
}

@media (max-width: 768px) {
  #mobileMenuToggle {
    display: flex !important;
  }
}

@media (max-width: 769px) {
  #mobileMenuToggle {
    display: flex !important;
  }
}

.dream-navbar {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  background:
    linear-gradient(135deg, rgba(255, 255, 255, 0.94), rgba(244, 247, 255, 0.92)),
    radial-gradient(circle at top right, rgba(96, 165, 250, 0.16), transparent 24%);
  backdrop-filter: blur(18px);
  border-bottom: 1px solid rgba(226, 232, 240, 0.95);
  box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
}
.dark .dream-navbar {
  background:
    linear-gradient(135deg, rgba(15, 23, 42, 0.94), rgba(30, 41, 59, 0.92)),
    radial-gradient(circle at top right, rgba(96, 165, 250, 0.14), transparent 28%);
  border-bottom-color: rgba(51, 65, 85, 0.9);
}
.dream-navbar-shell {
  position: relative;
  z-index: 1002;
  max-width: 1560px;
  margin: 0 auto;
  padding: 0.55rem clamp(0.75rem, 2vw, 1.4rem);
  display: flex;
  align-items: center;
  gap: clamp(0.55rem, 1vw, 1rem);
}
.dream-navbar-left {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  flex-shrink: 0;
}
.dream-navbar-right {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  flex-shrink: 0;
  justify-content: flex-end;
  min-width: 0;
}
.dream-brand {
  display: inline-flex;
  align-items: center;
  gap: 0.8rem;
  color: #0f172a;
  min-width: 0;
}
.dream-brand:hover {
  color: #0f172a;
}
.dark .dream-brand {
  color: #f8fafc;
}
.dream-brand-mark {
  width: 40px;
  height: 40px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.18), rgba(139, 92, 246, 0.18), rgba(236, 72, 153, 0.18));
  box-shadow: 0 18px 36px rgba(124, 58, 237, 0.18);
  animation: dreamBrandPulse 6s ease-in-out infinite;
}
.dream-brand-logo {
  width: 28px;
  height: 28px;
  object-fit: contain;
}
.dream-brand-copy {
  display: grid;
  gap: 0.05rem;
  min-width: 0;
}
.dream-brand-copy strong {
  font-size: 0.95rem;
  line-height: 1;
}
.dream-brand-copy small {
  margin: 0;
  color: #64748b;
  line-height: 1;
  font-size: 0.75rem;
}
.dream-icon-btn,
.dream-auth-link,
.dream-profile-chip {
  border: 0;
  cursor: pointer;
  transition: all 0.2s ease;
}
.dream-icon-btn:hover,
.dream-profile-chip:hover,
.dream-auth-link:hover {
  transform: translateY(-2px);
}

.dream-icon-btn {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #eef2ff, #f8fafc);
  color: #334155;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.84);
}
.dark .dream-icon-btn {
  background: rgba(30, 41, 59, 0.96);
  color: #e2e8f0;
}
.dream-counter-btn {
  position: relative;
  overflow: visible !important;
}
.dream-counter-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  min-width: 20px;
  height: 20px;
  padding: 0 5px;
  border-radius: 999px;
  background: #ef4444;
  color: #fff;
  font-size: 0.65rem;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #fff;
  box-shadow: 0 1px 3px rgba(0,0,0,0.15);
  z-index: 10;
  line-height: 1;
}
.dark .dream-counter-badge {
  border-color: #0f172a;
}
.dream-profile-chip {
  padding: 0.25rem 0.5rem 0.25rem 0.25rem;
  border-radius: 100px;
  background: rgba(37, 99, 235, 0.08);
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-weight: 600;
  font-size: 0.84rem;
  color: #0f172a;
  transition: all 0.2s ease;
}
.dark .dream-profile-chip {
  background: rgba(30, 41, 59, 0.96);
  color: #f8fafc;
}
.dream-profile-avatar,
.dream-user-menu-avatar,
.dream-mobile-profile img {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  object-fit: cover;
}
.dream-user-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 10px);
  width: 260px;
  border-radius: 22px;
  border: 1px solid rgba(226, 232, 240, 0.95);
  background: rgba(255, 255, 255, 0.98);
  box-shadow: 0 24px 50px rgba(15, 23, 42, 0.14);
  padding: 0.7rem;
  transition: all 0.2s ease;
}
.dark .dream-user-menu {
  background: rgba(15, 23, 42, 0.98);
  border-color: rgba(51, 65, 85, 0.9);
}
.dream-user-menu-header {
  display: flex;
  gap: 0.8rem;
  align-items: center;
  padding: 0.45rem 0.45rem 0.8rem;
  margin-bottom: 0.45rem;
  border-bottom: 1px solid rgba(226, 232, 240, 0.85);
}
.dark .dream-user-menu-header {
  border-bottom-color: rgba(51, 65, 85, 0.85);
}
.dream-user-menu-header strong,
.dream-user-menu-header span {
  display: block;
}
.dream-user-menu-header span {
  color: #64748b;
  font-size: 0.82rem;
}
.dream-user-menu a,
.dream-user-menu button {
  width: 100%;
  padding: 0.82rem 0.85rem;
  border-radius: 14px;
  border: 0;
  background: transparent;
  display: flex;
  align-items: center;
  gap: 0.72rem;
  font-size: 0.92rem;
  color: #334155;
  cursor: pointer;
  text-align: left;
}
.dream-user-menu a:hover,
.dream-user-menu button:hover {
  background: #f8fafc;
  color: #0f172a;
}
.dark .dream-user-menu a,
.dark .dream-user-menu button {
  color: #e2e8f0;
}
.dark .dream-user-menu a:hover,
.dark .dream-user-menu button:hover {
  background: rgba(30, 41, 59, 0.94);
}
.dream-user-menu-logout {
  color: #dc2626 !important;
}
.dream-auth-link,
.dream-auth-cta {
  padding: 0.82rem 1rem;
  border-radius: 999px;
  font-weight: 700;
}
.dream-auth-link {
  background: transparent;
  color: #334155;
}
.dark .dream-auth-link {
  color: #e2e8f0;
}
.dream-auth-cta {
  background: linear-gradient(135deg, #2563eb 0%, #8b5cf6 56%, #ec4899 100%);
  color: #fff;
  box-shadow: 0 18px 30px rgba(124, 58, 237, 0.24);
  transition: transform 0.24s ease, box-shadow 0.24s ease;
}
.dream-auth-cta:hover {
  transform: translateY(-2px);
  box-shadow: 0 22px 38px rgba(124, 58, 237, 0.3);
}
/* Mobile search panel */
.mobile-search-btn {
  display: none !important;
}
.dream-search-form {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.95rem 1rem;
  border-radius: 24px;
  border: 1px solid rgba(191, 219, 254, 0.95);
  background: #fff;
  box-shadow: 0 20px 42px rgba(37, 99, 235, 0.12);
}
.dream-search-form input {
  flex: 1;
  border: 0;
  outline: none;
  background: transparent;
  color: #0f172a;
}
.dream-search-form button {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  border: 0;
  background: #eff6ff;
  color: #2563eb;
  cursor: pointer;
}
.dark .dream-search-form {
  background: #0f172a;
  border-color: rgba(51, 65, 85, 0.95);
}
.dark .dream-search-form input {
  color: #f8fafc;
}
.dark .dream-search-form button {
  background: rgba(30, 41, 59, 0.96);
  color: #93c5fd;
}
.dream-search-suggestions {
  border-radius: 24px;
  border: 1px solid rgba(191, 219, 254, 0.95);
  background:
    linear-gradient(135deg, rgba(255,255,255,0.98), rgba(248,250,252,0.96)),
    radial-gradient(circle at 20% 0%, rgba(96,165,250,0.08), transparent 40%),
    radial-gradient(circle at 80% 100%, rgba(124,58,237,0.06), transparent 40%);
  box-shadow: 0 28px 60px rgba(37, 99, 235, 0.16), inset 0 1px 0 rgba(255,255,255,0.8);
  overflow: hidden;
  animation: dreamSuggFadeIn .18s ease-out;
}
@keyframes dreamSuggFadeIn {
  from { opacity: 0; transform: translateY(-6px) scale(.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.dark .dream-search-suggestions {
  background:
    linear-gradient(180deg, rgba(15,23,42,0.98), rgba(30,41,59,0.96)),
    radial-gradient(circle at 20% 0%, rgba(96,165,250,0.1), transparent 40%),
    radial-gradient(circle at 80% 100%, rgba(124,58,237,0.08), transparent 40%);
  border-color: rgba(51, 65, 85, 0.95);
  box-shadow: 0 28px 60px rgba(0,0,0,.35);
}
.dream-search-suggestions-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: .75rem;
  padding: .85rem 1rem;
  border-bottom: 1px solid rgba(226, 232, 240, 0.92);
  background:
    linear-gradient(135deg, rgba(239,246,255,0.95), rgba(250,245,255,0.94)),
    radial-gradient(circle at 90% 10%, rgba(236,72,153,0.1), transparent 28%);
}
.dark .dream-search-suggestions-head {
  background:
    linear-gradient(135deg, rgba(30,41,59,0.98), rgba(15,23,42,0.94)),
    radial-gradient(circle at 92% 0%, rgba(124,58,237,0.15), transparent 30%);
  border-bottom-color: rgba(51, 65, 85, 0.82);
}
.dream-search-suggestions-head .dsh-left {
  display: flex;
  align-items: center;
  gap: .4rem;
}
.dream-search-suggestions-head .dsh-left i {
  font-size: .82rem;
  color: #7c3aed;
  background: linear-gradient(135deg, #8b5cf6, #6366f1);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
.dream-search-suggestions-head strong {
  color: #0f172a;
  font-size: .92rem;
}
.dream-search-suggestions-head span {
  color: #64748b;
  font-size: .78rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dark .dream-search-suggestions-head strong {
  color: #f8fafc;
}
.dark .dream-search-suggestions-head span {
  color: #94a3b8;
}
.dream-search-suggestions-body {
  padding: .6rem .7rem .7rem;
  display: grid;
  gap: .5rem;
}
.dream-search-section {
  display: grid;
  gap: .35rem;
}
.dream-search-section-title {
  display: flex;
  align-items: center;
  gap: .35rem;
  padding: .3rem .55rem .15rem;
  color: #7c3aed;
  font-size: .7rem;
  font-weight: 800;
  letter-spacing: .06em;
  text-transform: uppercase;
}
.dream-search-section-title i {
  font-size: .6rem;
  opacity: .7;
}
.sr-avatar-wrap {
  position: relative;
  flex-shrink: 0;
  width: 42px;
  height: 42px;
  border-radius: 14px;
  padding: 2px;
  background: linear-gradient(135deg, #c084fc, #60a5fa);
}
.dream-search-suggestion .sr-avatar-wrap img {
  width: 100%;
  height: 100%;
  border-radius: 12px;
  object-fit: cover;
  display: block;
  border: 2px solid #fff;
}
.sr-avatar-wrap .sr-online {
  position: absolute;
  bottom: -1px;
  right: -1px;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #22c55e;
  border: 2px solid #fff;
  display: none;
}
.dream-search-suggestion .sr-body .sr-note {
  color: #64748b;
  font-size: .78rem;
  line-height: 1.4;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dream-search-suggestion {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .65rem .7rem;
  border-radius: 16px;
  background: rgba(255,255,255,.86);
  border: 1px solid rgba(226,232,240,.9);
  color: #0f172a;
  text-decoration: none;
  transition: all .22s cubic-bezier(.34,1.56,.64,1);
  box-shadow: 0 4px 12px rgba(15,23,42,.03);
  position: relative;
  overflow: hidden;
}
.dream-search-suggestion::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: linear-gradient(135deg, rgba(37,99,235,0), rgba(139,92,246,0));
  pointer-events: none;
  transition: background .25s ease;
}
.dream-search-suggestion:hover {
  transform: translateY(-2px) scale(1.01);
  border-color: rgba(96,165,250,.34);
  box-shadow: 0 12px 28px rgba(37,99,235,.1), 0 4px 8px rgba(15,23,42,.04);
}
.dream-search-suggestion:hover::after {
  background: linear-gradient(135deg, rgba(37,99,235,.04), rgba(139,92,246,.06));
}
.dark .dream-search-suggestion {
  background: rgba(30,41,59,.92);
  border-color: rgba(71,85,105,.6);
  color: #f8fafc;
}
.dream-search-suggestion img,
.dream-search-post-icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  object-fit: cover;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, rgba(37,99,235,.12), rgba(139,92,246,.1));
  color: #7c3aed;
  font-size: .85rem;
  box-shadow: 0 4px 10px rgba(37,99,235,.08);
}
.dream-search-suggestion span,
.dream-search-suggestion strong,
.dream-search-suggestion em {
  display: block;
}
.dream-search-suggestion strong {
  font-size: .88rem;
  line-height: 1.3;
}
.dream-search-suggestion em {
  font-style: normal;
  color: #64748b;
  font-size: .78rem;
  line-height: 1.4;
}
.dark .dream-search-suggestion em {
  color: #94a3b8;
}
.dream-search-suggestion .sr-badge {
  display: inline-flex;
  align-items: center;
  gap: .25rem;
  padding: .12rem .5rem;
  border-radius: 999px;
  font-size: .6rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .03em;
  background: rgba(37,99,235,.1);
  color: #2563eb;
}
.dark .dream-search-suggestion .sr-badge {
  background: rgba(59,130,246,.15);
  color: #60a5fa;
}
.dream-search-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .6rem;
  padding: 2rem 1rem;
  color: #94a3b8;
  text-align: center;
}
.dream-search-empty i {
  font-size: 2.4rem;
  background: linear-gradient(135deg, #c084fc, #60a5fa);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  opacity: .5;
}
.dream-search-empty span {
  font-size: .85rem;
  max-width: 260px;
}
.dark .dream-search-empty {
  color: #64748b;
}
.dream-search-suggestions-foot {
  border-top: 1px solid rgba(226,232,240,.92);
}
.dark .dream-search-suggestions-foot {
  border-top-color: rgba(51,65,85,.82);
}
.dream-search-all-results {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .5rem;
  padding: .85rem;
  font-weight: 700;
  font-size: .85rem;
  color: #fff;
  text-decoration: none;
  background: linear-gradient(135deg, #7c3aed, #2563eb);
  transition: all .22s ease;
  position: relative;
  overflow: hidden;
}
.dream-search-all-results::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(255,255,255,.08), transparent);
  pointer-events: none;
}
.dream-search-all-results:hover {
  color: #fff;
  background: linear-gradient(135deg, #6d28d9, #1d4ed8);
}
.dream-search-all-results i {
  transition: transform .22s ease;
}
.dream-search-all-results:hover i {
  transform: translateX(4px);
}
.dream-mobile-menu {
  border-top: 1px solid rgba(226, 232, 240, 0.95);
  background: rgba(255, 255, 255, 0.98);
  position: relative;
  z-index: 1003;
  overflow: hidden;
}
.dark .dream-mobile-menu {
  background: rgba(15, 23, 42, 0.98);
  border-top-color: rgba(51, 65, 85, 0.95);
}
.dream-mobile-menu-inner {
  max-width: 1320px;
  margin: 0 auto;
  padding: 0.75rem 1rem 1rem;
  display: flex;
  flex-direction: column;
  min-height: 0;
  max-height: inherit;
}
.dream-mobile-menu-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex: 0 0 auto;
  padding-bottom: 0.6rem;
  margin-bottom: 0.4rem;
  border-bottom: 1px solid rgba(226, 232, 240, 0.85);
}
.dark .dream-mobile-menu-head {
  border-bottom-color: rgba(51, 65, 85, 0.85);
}
.dream-mobile-menu-title {
  font-weight: 700;
  font-size: 0.95rem;
  color: #0f172a;
}
.dark .dream-mobile-menu-title {
  color: #f1f5f9;
}
.dream-mobile-close {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(226, 232, 240, 0.9);
  color: #475569;
  cursor: pointer;
  transition: all 0.2s ease;
}
.dream-mobile-close:hover {
  background: linear-gradient(135deg, #ef4444, #dc2626);
  color: #fff;
  transform: rotate(90deg);
}
.dark .dream-mobile-close {
  background: rgba(51, 65, 85, 0.9);
  color: #cbd5e1;
}
.dark .dream-mobile-close:hover {
  background: linear-gradient(135deg, #ef4444, #b91c1c);
  color: #fff;
}
.dream-mobile-profile {
  display: flex;
  gap: 0.8rem;
  align-items: center;
  flex: 0 0 auto;
  padding: 0.2rem 0 1rem;
}
.dream-mobile-profile strong,
.dream-mobile-profile span {
  display: block;
}
.dream-mobile-profile span {
  color: #64748b;
  font-size: 0.82rem;
}
.dream-mobile-links {
  display: grid;
  gap: 0.45rem;
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding-right: 0.2rem;
  scrollbar-width: thin;
  scrollbar-color: rgba(148, 163, 184, 0.55) transparent;
}
.dream-mobile-links::-webkit-scrollbar {
  width: 6px;
}
.dream-mobile-links::-webkit-scrollbar-thumb {
  background: rgba(148, 163, 184, 0.55);
  border-radius: 999px;
}
.dream-mobile-links a,
.dream-mobile-links button {
  padding: 0.95rem 1rem;
  border-radius: 16px;
  display: flex;
  align-items: center;
  gap: 0.8rem;
  width: 100%;
  border: 0;
  text-align: left;
  cursor: pointer;
  color: #334155;
  background: #f8fafc;
}
.dark .dream-mobile-links a,
.dark .dream-mobile-links button {
  color: #e2e8f0;
  background: rgba(30, 41, 59, 0.95);
}
.dream-mobile-links a.is-active,
.dream-mobile-links a.active,
.dream-mobile-links button.is-active,
.dream-mobile-links button.active {
  color: #2563eb;
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(139, 92, 246, 0.14));
}
.dream-mobile-logout {
  color: #dc2626 !important;
}
@media (max-width: 1024px) {
  .dream-navbar-center {
    display: none !important;
  }
  .mobile-search-btn {
    display: flex !important;
  }
  .dream-navbar-shell {
    padding: 0.6rem 0.8rem;
    grid-template-columns: minmax(0, 1fr) auto;
  }
}
@media (max-width: 640px) {
  .dream-navbar-shell {
    padding: 0.5rem 0.6rem;
    gap: 0.35rem;
  }
  .dream-brand-copy {
    display: none;
  }
  .dream-search {
    width: clamp(140px, 26vw, 200px);
    min-width: 0;
  }
  .dream-profile-chip span,
  .dream-auth-link {
    display: none;
  }
}
@media (max-width: 420px) {
  .dream-navbar-shell {
    padding: 0.4rem 0.4rem;
    gap: 0.2rem;
  }
  .dream-navbar-right {
    gap: 0.15rem;
  }
  .dream-icon-btn {
    width: 34px;
    height: 34px;
  }
  .dream-cart-btn {
    display: none !important;
  }
  .dream-search {
    width: clamp(100px, 24vw, 150px);
  }
  .dream-navbar-left {
    gap: 0.3rem;
  }
  .dream-brand {
    gap: 0.35rem;
  }
  .dream-brand-mark img {
    width: 30px;
    height: 30px;
  }
  .dream-profile-avatar {
    width: 30px;
    height: 30px;
  }
  .dream-profile-chip {
    padding: 0.2rem;
  }
}
.dream-navbar-center {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
  gap: clamp(0.65rem, 1.2vw, 1.15rem);
  min-width: 0;
}

/* Facebook-style search pill */
.dream-search {
  position: relative;
  flex: 1 1 360px;
  max-width: 430px;
  min-width: 260px;
  z-index: 1010;
}

.dream-search .dream-search-form {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-height: 48px;
  padding: 0.58rem 1rem;
  border-radius: 999px;
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.94));
  border: 1px solid rgba(203, 213, 225, 0.86);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 14px 28px rgba(15, 23, 42, 0.06);
  transition: all 0.2s ease;
}

.dark .dream-search .dream-search-form {
  background: rgba(30, 41, 59, 0.85);
  border-color: transparent;
}

.dream-search .dream-search-form:focus-within {
  background: #fff;
  border-color: #2563eb;
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12), 0 22px 42px rgba(37, 99, 235, 0.14);
}

.dark .dream-search .dream-search-form:focus-within {
  background: rgba(15, 23, 42, 0.95);
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.dream-search .dream-search-form i {
  color: #94a3b8;
  font-size: 0.9rem;
  flex-shrink: 0;
}

.dream-search .dream-search-form input {
  flex: 1;
  border: 0;
  outline: none;
  background: transparent;
  color: #0f172a;
  font-size: 0.9rem;
  min-width: 0;
}

.dark .dream-search .dream-search-form input {
  color: #f1f5f9;
}

.dream-search .dream-search-form input::placeholder {
  color: #94a3b8;
}

/* Nav links in center - compact row layout */
.dream-nav-pills {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-shrink: 0;
  padding: 0.18rem;
  border-radius: 22px;
  background: rgba(255, 255, 255, 0.62);
  border: 1px solid rgba(226, 232, 240, 0.72);
}

.dream-nav-link {
  min-height: 48px;
  padding: 0.5rem 0.82rem;
  border-radius: 18px;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: #64748b;
  text-decoration: none;
  font-size: 0.82rem;
  font-weight: 600;
  transition: all 0.2s ease;
  white-space: nowrap;
}

.dream-nav-link i {
  font-size: 1rem;
}

.dream-nav-link:hover,
.dream-nav-link.is-active,
.dream-nav-link.active {
  color: #2563eb;
  background: linear-gradient(135deg, rgba(219, 234, 254, 0.96), rgba(237, 233, 254, 0.92));
  box-shadow: 0 12px 24px rgba(37, 99, 235, 0.12);
}

.dark .dream-nav-link {
  color: #cbd5e1;
}

.dark .dream-nav-link:hover,
.dark .dream-nav-link.is-active,
.dark .dream-nav-link.active {
  color: #93c5fd;
  background: rgba(59, 130, 246, 0.14);
}

/* Desktop suggestions dropdown - directly below the search input */
.dream-search-suggestions {
  position: absolute;
  top: calc(100% + 10px);
  left: 0;
  right: 0;
  transform: none;
  width: 100%;
  min-width: min(430px, calc(100vw - 2rem));
  margin-top: 0;
  border-radius: 22px;
  border: 1px solid rgba(191, 219, 254, 0.95);
  background: rgba(255, 255, 255, 0.98);
  box-shadow: 0 24px 56px rgba(37, 99, 235, 0.16);
  overflow: hidden;
  z-index: 1100;
}

.dark .dream-search-suggestions {
  background: rgba(15, 23, 42, 0.98);
  border-color: rgba(51, 65, 85, 0.95);
}

/* Dropdown caret/arrow */
.dream-search-suggestions::before {
  content: '';
  position: absolute;
  top: -6px;
  left: 1.6rem;
  transform: rotate(45deg);
  width: 12px;
  height: 12px;
  background: inherit;
  border: 1px solid rgba(191, 219, 254, 0.95);
  border-bottom: none;
  border-right: none;
}

.dark .dream-search-suggestions::before {
  border-color: rgba(51, 65, 85, 0.95);
}

.dream-search-suggestions-head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid rgba(226, 232, 240, 0.92);
}
.dark .dream-search-suggestions-head {
  border-bottom-color: rgba(51, 65, 85, 0.82);
}
.dream-search-suggestions-head strong {
  color: #0f172a;
  font-size: 0.95rem;
}
.dream-search-suggestions-head span {
  color: #64748b;
  font-size: 0.82rem;
}
.dark .dream-search-suggestions-head strong {
  color: #f8fafc;
}
.dark .dream-search-suggestions-head span {
  color: #94a3b8;
}
.dream-search-suggestions-body {
  padding: .6rem .7rem .7rem;
  display: grid;
  gap: .5rem;
  max-height: min(65vh, 460px);
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(148,163,184,.4) transparent;
}
.dream-search-suggestions-body::-webkit-scrollbar { width: 5px; }
.dream-search-suggestions-body::-webkit-scrollbar-thumb { background: rgba(148,163,184,.4); border-radius: 999px; }
.dream-search-section { display: grid; gap: .35rem; }
.dream-search-section-title {
  display: flex;
  align-items: center;
  gap: .35rem;
  padding: .25rem .55rem .1rem;
  color: #7c3aed;
  font-size: .7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.dream-search-section-title i { font-size: .6rem; opacity: .7; }
.dream-search-suggestion {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .6rem .7rem;
  border-radius: 14px;
  text-decoration: none;
  transition: all .18s cubic-bezier(.34,1.56,.64,1);
}
.dream-search-suggestion:hover {
  background: rgba(241,245,249,.94);
}
.dark .dream-search-suggestion:hover {
  background: rgba(30,41,59,.8);
}
.dream-search-suggestion img {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  object-fit: cover;
  background: #e2e8f0;
  flex-shrink: 0;
  box-shadow: 0 4px 10px rgba(15,23,42,.06);
}
.dream-search-suggestion .dream-search-suggestion-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, rgba(37,99,235,.12), rgba(139,92,246,.1));
  color: #7c3aed;
  flex-shrink: 0;
  box-shadow: 0 4px 10px rgba(37,99,235,.08);
}
.dark .dream-search-suggestion .dream-search-suggestion-icon {
  background: rgba(30,41,59,.9);
  color: #60a5fa;
}
.dream-search-suggestion .sr-body { min-width: 0; }
.dream-search-suggestion .sr-body strong {
  display: block;
  color: #0f172a;
  font-size: .85rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dark .dream-search-suggestion .sr-body strong { color: #f1f5f9; }
.dream-search-suggestion .sr-body span {
  display: block;
  color: #64748b;
  font-size: .78rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dream-search-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .5rem;
  padding: 1.5rem 1rem;
  color: #94a3b8;
  text-align: center;
}
.dream-search-empty i {
  font-size: 2.2rem;
  background: linear-gradient(135deg, #c084fc, #60a5fa);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  opacity: .45;
}
.dream-search-empty span { font-size: .82rem; max-width: 250px; }
.dream-search-all-results {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .5rem;
  padding: .85rem;
  border-top: 1px solid rgba(226,232,240,.92);
  color: #7c3aed;
  font-weight: 700;
  font-size: .85rem;
  text-decoration: none;
  transition: all .18s ease;
}
.dream-search-all-results:hover {
  color: #2563eb;
  background: rgba(37,99,235,.04);
}
.dark .dream-search-all-results {
  border-top-color: rgba(51,65,85,.82);
  color: #a78bfa;
}
.dark .dream-search-all-results:hover {
  color: #60a5fa;
  background: rgba(96,165,250,.06);
}

@keyframes dreamBrandPulse {
  0%, 100% { box-shadow: 0 18px 36px rgba(124, 58, 237, 0.18); }
  50% { box-shadow: 0 22px 42px rgba(236, 72, 153, 0.22); }
}

/* Final navbar reset: balanced logo, search, nav icons, and profile areas */
.dream-navbar {
  position: fixed;
  inset: 0 0 auto 0;
  z-index: 1000;
  background: rgba(255, 255, 255, 0.94);
  border-bottom: 1px solid rgba(226, 232, 240, 0.9);
  box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
}

.dark .dream-navbar {
  background: rgba(15, 23, 42, 0.94);
  border-bottom-color: rgba(51, 65, 85, 0.9);
}

.dream-navbar-shell {
  max-width: 1500px;
  min-height: 78px;
  display: grid;
  grid-template-columns: minmax(390px, 520px) minmax(0, 1fr) minmax(270px, auto);
  gap: clamp(0.65rem, 1.3vw, 1.2rem);
  align-items: center;
  padding: 0.62rem clamp(0.75rem, 1.7vw, 1.35rem);
}

.dream-navbar-left,
.dream-navbar-right,
.dream-brand {
  min-width: 0;
}

.dream-navbar-left {
  display: flex;
  align-items: center;
  gap: clamp(0.75rem, 1.1vw, 1rem);
}

.dream-navbar-center {
  display: flex;
  justify-content: center;
  align-items: center;
  min-width: 0;
}

.dream-search {
  position: relative;
  width: clamp(210px, 19vw, 300px);
  min-width: 0;
  max-width: none;
  flex: none;
}

.dream-search .dream-search-form {
  min-height: 46px;
  padding: 0.55rem 0.62rem 0.55rem 0.95rem;
}

.dream-search-clear {
  width: 30px !important;
  height: 30px !important;
  min-width: 30px;
  min-height: 30px;
  border: 0;
  border-radius: 999px !important;
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  color: #64748b;
  background: rgba(226, 232, 240, 0.9);
  cursor: pointer;
  transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.dream-search-clear i {
  color: currentColor !important;
  font-size: 0.82rem !important;
  line-height: 1;
}

.dream-search-clear:hover {
  color: #ffffff !important;
  background: linear-gradient(135deg, #ef4444, #dc2626) !important;
  transform: rotate(90deg) scale(1.04);
}

.dark .dream-search-clear {
  color: #cbd5e1;
  background: rgba(51, 65, 85, 0.95);
  box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.16);
}

.dark .dream-search-clear:hover {
  color: #ffffff !important;
  background: linear-gradient(135deg, #ef4444, #b91c1c) !important;
}

.dream-nav-links {
  display: flex;
  justify-content: center;
  gap: 0.28rem;
  max-width: 100%;
  overflow: visible;
}

.dream-nav-link {
  min-width: 76px;
  min-height: 48px;
  padding: 0.45rem 0.58rem;
  display: inline-flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  gap: 0.16rem;
  font-size: 0.72rem;
}

.dream-nav-link i {
  font-size: 1.08rem;
}

.dream-search-suggestions {
  top: calc(100% + 10px);
  left: 0;
  right: auto;
  width: min(460px, calc(100vw - 2rem));
  min-width: 100%;
  transform: none;
  border-radius: 24px;
  border: 1px solid rgba(191, 219, 254, 0.95);
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(248, 250, 252, 0.97)),
    radial-gradient(circle at top right, rgba(37, 99, 235, 0.12), transparent 32%);
  box-shadow: 0 28px 70px rgba(37, 99, 235, 0.18);
  overflow: hidden;
  backdrop-filter: blur(18px);
}

.dream-search-suggestions::before {
  left: 1.55rem;
  transform: rotate(45deg);
  background: rgba(255, 255, 255, 0.99);
}

.dream-search-suggestions-head {
  background:
    linear-gradient(135deg, rgba(239, 246, 255, 0.95), rgba(250, 245, 255, 0.94)),
    radial-gradient(circle at 90% 10%, rgba(236, 72, 153, 0.12), transparent 28%);
}

.dream-search-suggestions-body {
  padding: .6rem .7rem .7rem;
  scrollbar-width: thin;
  scrollbar-color: rgba(148,163,184,.4) transparent;
}
.dream-search-suggestion {
  border: 1px solid rgba(226,232,240,.9);
  background: rgba(255,255,255,.86);
  box-shadow: 0 6px 16px rgba(15,23,42,.04);
}
.dream-search-suggestion:hover {
  background: linear-gradient(135deg, rgba(219,234,254,.9), rgba(252,231,243,.78));
  border-color: rgba(96,165,250,.38);
  transform: translateY(-2px);
}
.dream-search-suggestion img,
.dream-search-suggestion-icon {
  box-shadow: 0 6px 14px rgba(37,99,235,.1);
}
.dream-search-suggestions-foot {
  border-top: 1px solid rgba(226,232,240,.92);
}
.dark .dream-search-suggestions-foot {
  border-top-color: rgba(51,65,85,.82);
}
.dream-search-all-results {
  margin: .2rem .85rem .75rem;
  border: 0;
  border-radius: 16px;
  background: linear-gradient(135deg, #7c3aed, #2563eb);
  color: #fff !important;
  box-shadow: 0 12px 28px rgba(37,99,235,.22);
  font-weight: 700;
  font-size: .85rem;
  padding: .8rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .5rem;
  text-decoration: none;
  transition: all .22s cubic-bezier(.34,1.56,.64,1);
}
.dream-search-all-results:hover {
  background: linear-gradient(135deg, #6d28d9, #1d4ed8);
  transform: translateY(-1px);
  box-shadow: 0 16px 32px rgba(37,99,235,.3);
  color: #fff !important;
}
.dark .dream-search-all-results {
  background: linear-gradient(135deg, #6d28d9, #1d4ed8);
  color: #fff !important;
}
.dark .dream-search-all-results:hover {
  background: linear-gradient(135deg, #5b21b6, #1e40af);
  color: #fff !important;
}

.dark .dream-search-suggestions {
  border-color: rgba(71,85,105,.75);
  background:
    linear-gradient(180deg, rgba(15,23,42,.98), rgba(30,41,59,.96)),
    radial-gradient(circle at top right, rgba(96,165,250,.12), transparent 32%);
  box-shadow: 0 28px 70px rgba(0,0,0,.35);
}
.dark .dream-search-suggestions::before {
  background: rgba(15,23,42,.98);
  border-color: rgba(71,85,105,.75);
}
.dark .dream-search-suggestions-head {
  background:
    linear-gradient(135deg, rgba(30,41,59,.98), rgba(15,23,42,.94)),
    radial-gradient(circle at 92% 0%, rgba(124,58,237,.18), transparent 30%);
}
.dark .dream-search-suggestion {
  border-color: rgba(71,85,105,.62);
  background: rgba(30,41,59,.82);
  box-shadow: 0 8px 20px rgba(0,0,0,.16);
}
.dark .dream-search-suggestion:hover {
  background: linear-gradient(135deg, rgba(30,64,175,.32), rgba(76,29,149,.26));
  border-color: rgba(96,165,250,.42);
}
.dark .dream-search-empty {
  color: #94a3b8;
}

.dream-search-panel-mobile.hidden,
.dream-search-suggestions.hidden,
.dream-search-suggestions-mobile.hidden {
  display: none !important;
}

.dream-search-panel-mobile:not(.hidden) {
  display: flex !important;
}

.dream-cart-btn {
  color: #ea580c;
  background: linear-gradient(135deg, #fff7ed, #ffedd5);
}

.dream-cart-btn:hover {
  color: #fff;
  background: linear-gradient(135deg, #f97316, #ec4899);
}

#themeToggle:hover {
  color: #fff;
  background: linear-gradient(135deg, #7c3aed, #0ea5e9);
}

.dream-counter-btn[aria-label="Notifications"]:hover {
  color: #fff;
  background: linear-gradient(135deg, #ef4444, #f97316);
}

.dream-counter-btn[aria-label="Messages"]:hover {
  color: #fff;
  background: linear-gradient(135deg, #0891b2, #2563eb);
}

.dream-icon-btn {
  position: relative;
  overflow: hidden;
  isolation: isolate;
  animation: dreamNavIconFloat 4.8s ease-in-out infinite;
}

.dream-icon-btn::before,
.dream-nav-link::before {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: linear-gradient(135deg, rgba(255,255,255,0.38), transparent 58%);
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}

.dream-icon-btn:hover::before,
.dream-nav-link:hover::before {
  opacity: 1;
}

.dream-icon-btn:hover,
.dream-nav-link:hover {
  transform: translateY(-3px) scale(1.03);
}

#themeToggle {
  color: #7c3aed;
  background: linear-gradient(135deg, #f5f3ff, #ede9fe);
}

.dream-counter-btn[aria-label="Notifications"] {
  color: #dc2626;
  background: linear-gradient(135deg, #fff1f2, #fee2e2);
}

.dream-counter-btn[aria-label="Messages"] {
  color: #0891b2;
  background: linear-gradient(135deg, #ecfeff, #cffafe);
}

.mobile-search-btn {
  color: #2563eb;
  background: linear-gradient(135deg, #eff6ff, #dbeafe);
}

#mobileMenuToggle {
  color: #334155;
  background: linear-gradient(135deg, #f8fafc, #e2e8f0);
}

.dream-nav-link[data-page="home"] i { color: #2563eb; }
.dream-nav-link[data-page="community"] i { color: #059669; }
.dream-nav-link[data-page="products"] i { color: #ea580c; }
.dream-nav-link[data-page="tournaments"] i { color: #7c3aed; }
.dream-nav-link[data-page="balance"] i { color: #0d9488; }
.dream-nav-link[data-page="p2p"] i { color: #8b5cf6; }

.dream-nav-link[data-page="home"].is-active,
.dream-nav-link[data-page="home"]:hover { background: linear-gradient(135deg, #dbeafe, #eff6ff); }
.dream-nav-link[data-page="community"].is-active,
.dream-nav-link[data-page="community"]:hover { background: linear-gradient(135deg, #dcfce7, #f0fdf4); }
.dream-nav-link[data-page="products"].is-active,
.dream-nav-link[data-page="products"]:hover { background: linear-gradient(135deg, #ffedd5, #fff7ed); }
.dream-nav-link[data-page="tournaments"].is-active,
.dream-nav-link[data-page="tournaments"]:hover { background: linear-gradient(135deg, #ede9fe, #faf5ff); }
.dream-nav-link[data-page="balance"].is-active,
.dream-nav-link[data-page="balance"]:hover { background: linear-gradient(135deg, #ccfbf1, #f0fdfa); }
.dream-nav-link[data-page="p2p"].is-active,
.dream-nav-link[data-page="p2p"]:hover { background: linear-gradient(135deg, #ede9fe, #faf5ff); }

@keyframes dreamNavIconFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-1.5px); }
}

/* ===== MOBILE SEARCH OVERLAY MODE ===== */
.dream-search-back {
  display: none;
  border: 0;
  background: none;
  color: #475569;
  font-size: 1.2rem;
  cursor: pointer;
  padding: 0.3rem 0.4rem 0.3rem 0;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: color .15s;
}
.dream-search-back:active { color: #2563eb; }

/* When navbar is in search mode */
.dream-navbar.is-mobile-searching .dream-search-back {
  display: flex;
}
.dream-navbar.is-mobile-searching .dream-brand,
.dream-navbar.is-mobile-searching .dream-navbar-center,
.dream-navbar.is-mobile-searching .dream-navbar-right {
  display: none !important;
}
.dream-navbar.is-mobile-searching .dream-navbar-shell {
  grid-template-columns: 1fr;
  gap: 0;
}
.dream-navbar.is-mobile-searching .dream-navbar-left {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  width: 100%;
}
.dream-navbar.is-mobile-searching .dream-search {
  display: flex !important;
  flex: 1;
  width: auto;
  max-width: none;
  min-width: 0;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-form {
  width: 100%;
  min-height: 40px;
  padding: 0.45rem 0.5rem 0.45rem 0.8rem;
  border-radius: 12px;
  border: 1px solid rgba(203,213,225,0.5);
  background: rgba(241,245,249,0.85);
  backdrop-filter: blur(6px);
  box-shadow: none;
  gap: 0.5rem;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-form i {
  font-size: 0.9rem;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-form input {
  font-size: 0.95rem;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-clear {
  width: 26px !important;
  height: 26px !important;
  min-width: 26px;
  min-height: 26px;
}
.dark .dream-navbar.is-mobile-searching .dream-search .dream-search-form {
  background: rgba(30,41,59,0.7);
  border-color: rgba(71,85,105,0.3);
}

/* Mobile suggestions panel - full screen overlay below navbar */
.dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions {
  position: fixed;
  top: var(--navbar-actual-height, 62px);
  left: 0;
  right: 0;
  bottom: 0;
  height: auto;
  width: auto;
  z-index: 999999;
  background: #fff;
  border: 0;
  border-radius: 0;
  box-shadow: none;
  margin-top: 0;
  display: flex !important;
  flex-direction: column;
  animation: mobSearchSlideUp .25s ease-out;
}
@keyframes mobSearchSlideUp {
  from { transform: translateY(16px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
.dark .dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions {
  background: #0f172a;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions .dream-search-suggestions-head {
  flex-shrink: 0;
  background:
    linear-gradient(135deg, #f8f9ff, #f5f3ff),
    radial-gradient(circle at 90% 10%, rgba(236,72,153,.08), transparent 28%);
  border-bottom: 1px solid rgba(226,232,240,.8);
  padding: 1rem 1.2rem;
}
.dark .dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions .dream-search-suggestions-head {
  background:
    linear-gradient(135deg, rgba(30,41,59,.98), rgba(15,23,42,.94)),
    radial-gradient(circle at 92% 0%, rgba(124,58,237,.14), transparent 30%);
  border-bottom-color: rgba(51,65,85,.5);
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions .dream-search-suggestions-body {
  flex: 1;
  overflow-y: auto;
  padding: .5rem 1rem 1rem;
  -webkit-overflow-scrolling: touch;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions .dream-search-empty {
  padding: 3rem 0;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions .dream-search-section-title {
  padding: .5rem .35rem .25rem;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions .dream-search-suggestions-foot {
  flex-shrink: 0;
  padding: 0 1rem 1rem;
}
.dream-navbar.is-mobile-searching .dream-search .dream-search-suggestions .dream-search-suggestions-foot .dream-search-all-results {
  margin: 0;
}

/* ===== RESPONSIVE BREAKPOINTS ===== */

/* Large desktop (>1440px) */
@media (min-width: 1441px) {
  .dream-navbar-shell {
    grid-template-columns: minmax(360px, 500px) 1fr minmax(270px, 500px);
  }
}

/* Desktop (1281px–1440px) */
@media (min-width: 1281px) and (max-width: 1440px) {
  .dream-navbar-shell {
    grid-template-columns: minmax(320px, 420px) 1fr minmax(250px, 420px);
  }
  .dream-search {
    width: clamp(180px, 17vw, 240px);
  }
  .dream-nav-link {
    min-width: 62px;
    padding-inline: 0.4rem;
    font-size: 0.66rem;
  }
}

/* Small desktop / large tablet (1025px–1280px) */
@media (min-width: 1025px) and (max-width: 1280px) {
  .dream-navbar-shell {
    grid-template-columns: minmax(280px, 360px) 1fr minmax(230px, 360px);
    gap: clamp(0.4rem, 1vw, 0.8rem);
  }
  .dream-search {
    width: clamp(160px, 15vw, 210px);
  }
  .dream-nav-link {
    min-width: 54px;
    padding: 0.35rem 0.3rem;
    font-size: 0.62rem;
    min-height: 42px;
  }
  .dream-nav-link span {
    font-size: 0.6rem;
  }
  .dream-nav-link i {
    font-size: 0.95rem;
  }
}

/* Tablet landscape / small tablet (769px–1024px) */
@media (min-width: 769px) and (max-width: 1024px) {
  .dream-navbar-shell {
    grid-template-columns: auto 1fr auto;
    min-height: 68px;
    padding: 0.5rem clamp(0.6rem, 1.5vw, 1rem);
  }
  .dream-navbar-center {
    display: flex !important;
    justify-content: center;
    min-width: 0;
    padding: 0 0.25rem;
  }
  .dream-navbar-left .dream-search {
    display: none;
  }
  .dream-nav-links {
    gap: 0.15rem;
  }
  .dream-nav-link {
    min-width: 44px;
    min-height: 40px;
    padding: 0.3rem 0.35rem;
    font-size: 0.6rem;
    flex-direction: column;
    border-radius: 12px;
  }
  .dream-nav-link span {
    font-size: 0.55rem;
    line-height: 1;
  }
  .dream-nav-link i {
    font-size: 0.9rem;
  }
  .mobile-search-btn {
    display: flex !important;
  }
  .dream-profile-chip span.lg\:inline {
    display: none;
  }
  .dream-brand-copy small {
    display: none;
  }
  .dream-brand-copy strong {
    font-size: 0.85rem;
  }
  .dream-brand-mark {
    width: 34px;
    height: 34px;
  }
  .dream-brand-logo {
    width: 24px;
    height: 24px;
  }
}

/* Phone (max 768px) */
@media (max-width: 769px) {
  .dream-navbar,
  .dark .dream-navbar {
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
  }
  .dream-navbar-shell {
    grid-template-columns: auto auto;
    justify-content: space-between;
    min-height: 60px;
    padding: 0.4rem 0.6rem;
    gap: 0.3rem;
  }
  .dream-navbar-center {
    display: none !important;
  }
  .dream-navbar-left .dream-search {
    display: none;
  }
  .mobile-search-btn {
    display: flex !important;
  }
  #mobileMenuToggle {
    display: flex !important;
  }
  .dream-brand-mark {
    width: 32px;
    height: 32px;
  }
  .dream-brand-logo {
    width: 22px;
    height: 22px;
  }
  .dream-brand-copy strong {
    font-size: 0.8rem;
  }
  .dream-brand-copy small {
    display: none;
  }
  .dream-navbar-right {
    gap: 0.15rem;
  }
  .dream-icon-btn {
    width: 34px;
    height: 34px;
    font-size: 0.85rem;
  }
  .dream-counter-badge {
    min-width: 18px;
    height: 18px;
    font-size: 0.55rem;
    top: -5px;
    right: -5px;
  }
  #userDropdown {
    display: none !important;
  }
  .dream-auth-cta {
    padding: 0.5rem 0.7rem;
    font-size: 0.75rem;
  }
  .dream-auth-link {
    display: none;
  }
  .dream-cart-btn {
    width: 34px;
    height: 34px;
  }
  #themeToggle {
    width: 34px;
    height: 34px;
  }
  .dream-user-menu {
    right: -10px;
    width: 240px;
  }
}

@media (max-width: 360px) {
  .dream-brand-copy {
    display: none;
  }
  .dream-navbar-shell {
    padding-inline: 0.35rem;
  }
}

/* Small phone (max 400px) */
@media (max-width: 400px) {
  .dream-navbar-shell {
    padding: 0.35rem 0.4rem;
    gap: 0.2rem;
  }
  .dream-brand-mark {
    width: 28px;
    height: 28px;
  }
  .dream-brand-logo {
    width: 20px;
    height: 20px;
  }
  .dream-brand-copy strong {
    font-size: 0.72rem;
  }
  .dream-icon-btn {
    width: 30px;
    height: 30px;
    font-size: 0.75rem;
  }
  .dream-navbar-right {
    gap: 0.1rem;
  }
  #themeToggle,
  .dream-cart-btn,
  .dream-counter-btn,
  .mobile-search-btn,
  #mobileMenuToggle {
    width: 30px !important;
    height: 30px !important;
    min-width: 30px;
    min-height: 30px;
  }
  .dream-counter-badge {
    min-width: 16px;
    height: 16px;
    font-size: 0.5rem;
    top: -4px;
    right: -4px;
  }
  .dream-auth-cta {
    padding: 0.4rem 0.55rem;
    font-size: 0.65rem;
  }
}

/* Mobile menu - hidden by default (display set by JS for animation) */
.dream-mobile-menu {
  display: none;
}

.dream-mobile-menu.is-open {
  display: block !important;
  animation: dreamMenuSlideDown 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.dream-mobile-menu.is-closing {
  animation: dreamMenuSlideUp 0.2s ease forwards;
}

@keyframes dreamMenuSlideDown {
  from {
    opacity: 0;
    transform: translateY(-16px) scaleY(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scaleY(1);
  }
}

@keyframes dreamMenuSlideUp {
  from {
    opacity: 1;
    transform: translateY(0) scaleY(1);
  }
  to {
    opacity: 0;
    transform: translateY(-12px) scaleY(0.94);
  }
}

/* Mobile menu blur overlay - hidden by default */
.dream-mobile-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 999;
  background: rgba(15, 23, 42, 0.35);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  pointer-events: auto;
}

.dream-mobile-overlay.is-visible {
  display: block !important;
  animation: dreamOverlayFadeIn 0.3s ease forwards;
}

.dream-mobile-overlay.is-closing {
  animation: dreamOverlayFadeOut 0.2s ease forwards;
}

@keyframes dreamOverlayFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes dreamOverlayFadeOut {
  from { opacity: 1; }
  to { opacity: 0; }
}

/* Colorful mobile menu link icons */
.dream-mobile-links a i,
.dream-mobile-links button i {
  width: 36px;
  height: 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  flex-shrink: 0;
  font-size: 0.95rem;
  color: #fff;
}
.dream-mobile-links a[data-page="home"] i { background: linear-gradient(135deg, #2563eb, #60a5fa); }
.dream-mobile-links a[data-page="community"] i { background: linear-gradient(135deg, #059669, #34d399); }
.dream-mobile-links a[data-page="products"] i { background: linear-gradient(135deg, #ea580c, #fb923c); }
.dream-mobile-links a[data-page="tournaments"] i { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
.dream-mobile-links a[data-page="balance"] i { background: linear-gradient(135deg, #0d9488, #2dd4bf); }
.dream-mobile-links a[data-page="p2p"] i { background: linear-gradient(135deg, #8b5cf6, #c084fc); }
.dream-mobile-links a[data-page="how-it-works"] i { background: linear-gradient(135deg, #0ea5e9, #38bdf8); }
.dream-mobile-links a[data-page="faq"] i { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
.dream-mobile-links a[data-page="cart"] i { background: linear-gradient(135deg, #ea580c, #fb923c); }
.dream-mobile-links a[data-page="agent-dashboard"] i { background: linear-gradient(135deg, #ca8a04, #facc15); }
.dream-mobile-links a[data-page="profile"] i { background: linear-gradient(135deg, #2563eb, #60a5fa); }
.dream-mobile-links a[data-page="messages"] i { background: linear-gradient(135deg, #0891b2, #22d3ee); }
.dream-mobile-links a[data-page="notifications"] i { background: linear-gradient(135deg, #dc2626, #f87171); }
.dream-mobile-links a[data-no-ajax] i { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
.dream-mobile-links .dream-mobile-logout i,
.dream-mobile-links button[data-open-logout-dialog] i { background: linear-gradient(135deg, #dc2626, #f87171); }
.dream-mobile-links a[data-page="login"] i { background: linear-gradient(135deg, #2563eb, #60a5fa); }
.dream-mobile-links a[data-page="register"] i { background: linear-gradient(135deg, #8b5cf6, #ec4899); }
.dream-mobile-links a:not([data-page]):not([data-no-ajax]) i { background: linear-gradient(135deg, #475569, #94a3b8); }
.dark .dream-mobile-links a i,
.dark .dream-mobile-links button i {
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.15);
}

/* Mobile dropdown menu responsiveness */
@media (max-width: 640px) {
  .dream-mobile-menu {
    position: fixed;
    inset: 0 0 auto 0;
    max-height: 85vh;
    height: min(85vh, 620px);
    overflow: hidden;
    border-radius: 0 0 24px 24px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2);
    z-index: 1003;
    transform-origin: top center;
  }
  .dream-mobile-menu-inner {
    padding: 0.75rem;
    height: 100%;
  }
  .dream-mobile-links {
    gap: 0.3rem;
  }
  .dream-mobile-links a,
  .dream-mobile-links button {
    padding: 0.75rem 0.85rem;
    min-height: 44px;
    font-size: 0.88rem;
  }
  .dream-mobile-profile {
    padding: 0 0 0.6rem;
  }
}
@media (min-width: 641px) and (max-width: 769px) {
  .dream-mobile-menu {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    border-radius: 0 0 20px 20px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
    z-index: 1003;
    max-height: 80vh;
    height: min(80vh, 620px);
    overflow: hidden;
  }
}

/* ===== END RESPONSIVE BREAKPOINTS ===== */

.dream-user-menu a i,
.dream-user-menu button i {
  width: 36px;
  height: 36px;
  display: inline-grid;
  place-items: center;
  border-radius: 12px;
  color: #fff;
  flex-shrink: 0;
}

.dream-user-menu a:nth-of-type(1) i { background: linear-gradient(135deg, #2563eb, #60a5fa); }
.dream-user-menu a:nth-of-type(2) i { background: linear-gradient(135deg, #f97316, #f59e0b); }
.dream-user-menu a:nth-of-type(3) i { background: linear-gradient(135deg, #0f766e, #2dd4bf); }
.dream-user-menu a:nth-of-type(4) i { background: linear-gradient(135deg, #7c3aed, #c084fc); }
.dream-user-menu button[data-open-global-settings] i { background: linear-gradient(135deg, #8b5cf6, #ec4899); }
.dream-user-menu button[data-open-logout-dialog] i { background: linear-gradient(135deg, #ef4444, #f97316); }

.dream-user-menu a,
.dream-user-menu button {
  font-weight: 750;
  transition: transform 0.22s ease, background 0.22s ease, color 0.22s ease, box-shadow 0.22s ease;
}

.dream-user-menu a:hover,
.dream-user-menu button:hover {
  background: linear-gradient(135deg, rgba(219, 234, 254, 0.78), rgba(237, 233, 254, 0.74));
  transform: translateX(3px);
  box-shadow: 0 12px 26px rgba(37, 99, 235, 0.1);
}

.dream-user-menu {
  transform-origin: top right;
  transition: opacity 0.22s ease, transform 0.22s ease, visibility 0.22s ease;
  will-change: transform, opacity;
}

.dream-user-menu:not(.hidden) {
  animation: dreamDropdownIn 0.24s ease both;
}

@keyframes dreamDropdownIn {
  from {
    opacity: 0;
    transform: translateY(10px) scale(0.96);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.dream-nav-pills {
  background: transparent !important;
  border: 0 !important;
  padding: 0 !important;
}

.dark #themeToggle {
  color: #c4b5fd;
  background: linear-gradient(135deg, rgba(76, 29, 149, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-counter-btn[aria-label="Notifications"] {
  color: #fca5a5;
  background: linear-gradient(135deg, rgba(127, 29, 29, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-counter-btn[aria-label="Messages"] {
  color: #67e8f9;
  background: linear-gradient(135deg, rgba(22, 78, 99, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-cart-btn {
  color: #fdba74;
  background: linear-gradient(135deg, rgba(154, 52, 18, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .mobile-search-btn {
  color: #93c5fd;
  background: linear-gradient(135deg, rgba(30, 64, 175, 0.42), rgba(30, 41, 59, 0.96));
}

.dark #mobileMenuToggle {
  color: #cbd5e1;
  background: linear-gradient(135deg, rgba(51, 65, 85, 0.7), rgba(30, 41, 59, 0.96));
}

.dark #themeToggle:hover,
.dark .dream-counter-btn[aria-label="Notifications"]:hover,
.dark .dream-counter-btn[aria-label="Messages"]:hover,
.dark .dream-cart-btn:hover,
.dark .mobile-search-btn:hover,
.dark #mobileMenuToggle:hover {
  color: #fff;
}

.dark .dream-nav-link[data-page="home"].is-active,
.dark .dream-nav-link[data-page="home"].active,
.dark .dream-nav-link[data-page="home"]:hover {
  color: #93c5fd;
  background: linear-gradient(135deg, rgba(30, 64, 175, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-nav-link[data-page="home"].is-active i,
.dark .dream-nav-link[data-page="home"].active i,
.dark .dream-nav-link[data-page="home"]:hover i { color: #93c5fd; }

.dark .dream-nav-link[data-page="community"].is-active,
.dark .dream-nav-link[data-page="community"].active,
.dark .dream-nav-link[data-page="community"]:hover {
  color: #86efac;
  background: linear-gradient(135deg, rgba(20, 83, 45, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-nav-link[data-page="community"].is-active i,
.dark .dream-nav-link[data-page="community"].active i,
.dark .dream-nav-link[data-page="community"]:hover i { color: #86efac; }

.dark .dream-nav-link[data-page="products"].is-active,
.dark .dream-nav-link[data-page="products"].active,
.dark .dream-nav-link[data-page="products"]:hover {
  color: #fdba74;
  background: linear-gradient(135deg, rgba(154, 52, 18, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-nav-link[data-page="products"].is-active i,
.dark .dream-nav-link[data-page="products"].active i,
.dark .dream-nav-link[data-page="products"]:hover i { color: #fdba74; }

.dark .dream-nav-link[data-page="tournaments"].is-active,
.dark .dream-nav-link[data-page="tournaments"].active,
.dark .dream-nav-link[data-page="tournaments"]:hover {
  color: #c4b5fd;
  background: linear-gradient(135deg, rgba(76, 29, 149, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-nav-link[data-page="tournaments"].is-active i,
.dark .dream-nav-link[data-page="tournaments"].active i,
.dark .dream-nav-link[data-page="tournaments"]:hover i { color: #c4b5fd; }

.dark .dream-nav-link[data-page="balance"].is-active,
.dark .dream-nav-link[data-page="balance"].active,
.dark .dream-nav-link[data-page="balance"]:hover {
  color: #5eead4;
  background: linear-gradient(135deg, rgba(15, 118, 110, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-nav-link[data-page="balance"].is-active i,
.dark .dream-nav-link[data-page="balance"].active i,
.dark .dream-nav-link[data-page="balance"]:hover i { color: #5eead4; }

.dark .dream-nav-link[data-page="p2p"].is-active,
.dark .dream-nav-link[data-page="p2p"].active,
.dark .dream-nav-link[data-page="p2p"]:hover {
  color: #c4b5fd;
  background: linear-gradient(135deg, rgba(76, 29, 149, 0.42), rgba(30, 41, 59, 0.96));
}

.dark .dream-nav-link[data-page="p2p"].is-active i,
.dark .dream-nav-link[data-page="p2p"].active i,
.dark .dream-nav-link[data-page="p2p"]:hover i { color: #c4b5fd; }

</style>
