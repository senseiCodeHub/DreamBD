<?php
// admin/layout/header.php - Enhanced Admin Header & Sidebar
// Include this at the top of every admin page
require_once __DIR__ . '/../../includes/session.php';
dream_start_session();
require_once __DIR__ . '/../../database/config.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$security = new Security();

if (!$auth->isLoggedIn()) {
    header('Location: ../index.php?page=login');
    exit();
}

$userRole = $_SESSION['role'] ?? 'user';
$userId = (int) ($_SESSION['user_id'] ?? 0);

// Also check admin_users table for elevated roles
if (!in_array($userRole, ['admin', 'moderator', 'super_admin'], true)) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT role FROM admin_users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $adminRow = $stmt->fetch();
        if ($adminRow) {
            $userRole = $adminRow['role'];
            $_SESSION['role'] = $userRole;
        }
    } catch (Throwable $e) {
        // admin_users table might not exist yet
    }
}

// Auto-setup: if no admins exist, promote current user to super_admin
if (!in_array($userRole, ['admin', 'moderator', 'super_admin'], true)) {
    try {
        $db = Database::getInstance()->getConnection();
        $existing = $db->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
        if ((int) $existing === 0) {
            // Ensure admin_users table exists
            $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                role ENUM('super_admin', 'moderator') DEFAULT 'super_admin',
                permissions JSON DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            $stmt = $db->prepare("INSERT IGNORE INTO admin_users (user_id, role) VALUES (?, 'super_admin')");
            $stmt->execute([$userId]);
            $userRole = 'super_admin';
            $_SESSION['role'] = 'super_admin';
        }
    } catch (Throwable $e) {
        // Table creation failed
    }
}

if (!in_array($userRole, ['admin', 'moderator', 'super_admin'], true)) {
    die('<div class="min-h-screen flex items-center justify-center bg-gray-900 text-red-500 font-mono">
        <div class="text-center"><h1 class="text-4xl mb-4">ACCESS DENIED</h1></div>
    </div>');
}

$db = Database::getInstance()->getConnection();
$csrfToken = $security->generateCSRFToken();
$userDisplayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$userEmail = $_SESSION['email'] ?? '';
$userAvatar = $_SESSION['avatar'] ?? null;

// Get unread notifications count
$notificationCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $notificationCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist
}
?>

<!DOCTYPE html>
<html lang="en" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' | DREAMBD Admin' : 'DREAMBD Admin Panel'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'dream-primary': '#3B82F6',
                        'dream-secondary': '#10B981',
                        'dream-danger': '#EF4444',
                        'dream-warning': '#F59E0B',
                        'dream-dark': '#1F2937',
                        'dream-darker': '#111827',
                    }
                }
            }
        }
    </script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        .live-clock { font-variant-numeric: tabular-nums; }
        .sidebar { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 40; }
        .sidebar.collapsed { width: 70px; }
        .sidebar.collapsed .sidebar-text { display: none; }
        .sidebar.collapsed .logo-text { display: none; }
        .sidebar.collapsed .dropdown-arrow { display: none; }
        .main-content { transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .slide-in { animation: slideIn 0.3s ease-out; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }

        /* Color Palette Styles */
        .color-swatch { outline: none; }
        .color-swatch.active { transform: scale(1.15); border-color: #3b82f6 !important; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #3b82f6; }
        .color-swatch.light-border { border-color: #d1d5db; }
        .icon-swatch.active { background: #eff6ff; border-color: #3b82f6; color: #2563eb; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,0.15); }
        .dark .icon-swatch.active { background: rgba(59,130,246,0.15); border-color: #3b82f6; color: #93c5fd; }

        /* Custom Range Slider */
        input[type="range"]::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 16px; height: 16px; border-radius: 50%; background: white; border: 2px solid #3b82f6; cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        input[type="range"]::-moz-range-thumb { width: 16px; height: 16px; border-radius: 50%; background: white; border: 2px solid #3b82f6; cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        input[type="range"]:focus { outline: none; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark ::-webkit-scrollbar-thumb { background: #475569; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #64748b; }

        /* Fix content under navbar - 64px matches navbar height */
        .main-content { padding-top: 64px; }
        #topNavbar { z-index: 30; position: fixed; left: 256px; right: 0; top: 0; }
        .sidebar.collapsed ~ #topNavbar { left: 70px !important; }
    </style>
    <link rel="stylesheet" href="../<?php echo dream_asset('assets/css/admin-ui.css'); ?>">
</head>
<body class="bg-slate-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen">

    <!-- Sidebar -->
    <aside class="sidebar adm-sidebar fixed left-0 top-0 h-full w-64 overflow-y-auto" id="sidebar">
        <!-- Logo -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <a href="index.php" class="flex items-center gap-3">
                    <span class="adm-brand-tile w-10 h-10 rounded-xl flex items-center justify-center shrink-0">
                        <i class="fas fa-terminal text-white text-lg"></i>
                    </span>
                    <span class="flex flex-col leading-tight">
                        <span class="logo-text adm-brand-text text-[17px] font-extrabold tracking-tight">DREAMBD</span>
                        <span class="logo-text text-[10px] font-bold uppercase tracking-[0.18em] text-gray-400 dark:text-gray-500">Admin Panel</span>
                    </span>
                </a>
                <button onclick="toggleSidebar()" class="adm-icon-btn !w-8 !h-8 shrink-0" title="Collapse sidebar">
                    <i class="fas fa-chevron-left text-xs" id="sidebarToggleIcon"></i>
                </button>
            </div>
        </div>

        <!-- User Info -->
        <div class="px-4 pt-4">
            <div class="adm-user-card p-3">
                <div class="flex items-center gap-3">
                    <div class="relative shrink-0">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-violet-500 to-blue-500 flex items-center justify-center text-white font-bold text-sm ring-2 ring-white dark:ring-gray-800">
                            <?php echo strtoupper(substr($userDisplayName, 0, 1)); ?>
                        </div>
                        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-gray-800 rounded-full"></span>
                    </div>
                    <div class="sidebar-text flex-1 min-w-0">
                        <div class="text-sm font-bold text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="text-[11px] text-gray-500 dark:text-gray-400 truncate"><?php echo htmlspecialchars($userEmail); ?></div>
                    </div>
                </div>
                <div class="mt-2.5 sidebar-text">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider <?php echo in_array($userRole, ['super_admin', 'admin'], true) ? 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'; ?>">
                        <i class="fas fa-shield-halved"></i>
                        <?php echo htmlspecialchars(str_replace('_', ' ', $userRole)); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-4 px-3 pb-24">
            <?php
            $admNavGroups = [
                ['label' => 'Overview', 'items' => [
                    ['index.php', 'dashboard', 'fa-gauge-high', 'Dashboard'],
                    ['analytics.php', 'analytics', 'fa-chart-line', 'Analytics'],
                    ['system-status.php', 'system', 'fa-server', 'System Status'],
                ]],
                ['label' => 'Commerce', 'items' => [
                    ['product-manager.php', 'products', 'fa-box', 'Products'],
                    ['product-orders.php', 'product-orders', 'fa-receipt', 'Product Orders'],
                    ['topup-manager.php', 'topup', 'fa-gamepad', 'Game Top-Up'],
                    ['payment-requests.php', 'payments', 'fa-credit-card', 'Payments'],
                ]],
                ['label' => 'Content', 'items' => [
                    ['slider-editor.php', 'slider', 'fa-sliders', 'Slider Editor'],
                    ['community-hero.php', 'community-hero', 'fa-panorama', 'Hero Banner'],
                    ['ad-manager.php', 'ads', 'fa-rectangle-ad', 'Ad Manager'],
                    ['tournament-manager.php', 'tournaments', 'fa-trophy', 'Tournaments'],
                    ['player-manager.php', 'players', 'fa-ranking-star', 'Top Players'],
                ]],
                ['label' => 'Community', 'items' => [
                    ['user-manager.php', 'users', 'fa-users-gear', 'User Manager'],
                    ['p2p-reports.php', 'p2p-reports', 'fa-flag', 'P2P Reports'],
                    ['post-reports.php', 'post-reports', 'fa-shield-halved', 'Post Reports'],
                ]],
                ['label' => 'System', 'items' => [
                    ['manage-db.php', 'database', 'fa-database', 'Database'],
                    ['app-control.php', 'app-control', 'fa-mobile-screen', 'App Control'],
                    ['settings.php', 'settings', 'fa-gear', 'Settings'],
                    ['search.php', 'search', 'fa-magnifying-glass', 'Search'],
                ]],
            ];
            foreach ($admNavGroups as $group): ?>
            <div class="adm-nav-label sidebar-text mt-5 mb-2 px-3 first:mt-0"><?php echo $group['label']; ?></div>
            <?php foreach ($group['items'] as $nav): $navActive = ($currentPage ?? '') === $nav[1]; ?>
            <a href="<?php echo $nav[0]; ?>" class="nav-item flex items-center gap-3 px-2.5 py-2 mb-0.5 <?php echo $navActive ? 'active' : ''; ?>">
                <span class="nav-ico"><i class="fas <?php echo $nav[2]; ?>"></i></span>
                <span class="sidebar-text font-semibold"><?php echo $nav[3]; ?></span>
            </a>
            <?php endforeach; ?>
            <?php endforeach; ?>

            <div class="border-t border-gray-200 dark:border-gray-700 my-4 mx-1"></div>
            <div class="adm-nav-label sidebar-text mb-2 px-3">Account</div>

            <a href="../index.php" class="nav-item flex items-center gap-3 px-2.5 py-2 mb-0.5">
                <span class="nav-ico"><i class="fas fa-arrow-up-right-from-square"></i></span>
                <span class="sidebar-text font-semibold">Back to Site</span>
            </a>
            <a href="../index.php?page=profile" class="nav-item flex items-center gap-3 px-2.5 py-2 mb-0.5">
                <span class="nav-ico"><i class="fas fa-user"></i></span>
                <span class="sidebar-text font-semibold">My Profile</span>
            </a>
            <a href="../logout.php" class="nav-item nav-danger flex items-center gap-3 px-2.5 py-2">
                <span class="nav-ico"><i class="fas fa-right-from-bracket"></i></span>
                <span class="sidebar-text font-semibold">Logout</span>
            </a>
        </nav>
    </aside>

    <!-- Mobile overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

    <!-- Main Content Wrapper -->
    <div class="ml-64 main-content" id="mainContent">

        <!-- Top Navbar -->
        <nav class="adm-topbar fixed top-0 right-0 left-64 z-30 transition-all duration-300" id="topNavbar">
            <div class="px-6 h-16 flex items-center justify-between gap-4">
                <!-- Left: mobile menu + title -->
                <div class="flex items-center gap-3 min-w-0">
                    <button onclick="toggleMobileSidebar()" class="adm-icon-btn lg:hidden shrink-0" id="mobileMenuBtn" title="Menu">
                        <i class="fas fa-bars text-sm"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="adm-page-title text-[19px] font-extrabold text-gray-900 dark:text-white truncate leading-tight">
                            <?php echo $pageHeading ?? 'Dashboard'; ?>
                        </h1>
                        <?php if (isset($pageSubheading)): ?>
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo $pageSubheading; ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right side -->
                <div class="flex items-center gap-2.5 shrink-0">
                    <!-- Live Clock -->
                    <div class="hidden md:flex adm-chip !gap-2.5" title="Server time">
                        <i class="fas fa-clock text-violet-500 text-sm"></i>
                        <div class="text-right leading-none">
                            <div class="text-[13px] font-bold font-mono text-gray-800 dark:text-white live-clock" id="liveClock">--:--:--</div>
                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5" id="liveDate">----/--/--</div>
                        </div>
                    </div>

                    <!-- Dark Mode Toggle -->
                    <button onclick="toggleDarkMode()" class="adm-icon-btn" title="Toggle Dark Mode">
                        <i class="fas fa-moon text-sm" id="darkModeIcon"></i>
                    </button>

                    <!-- Notifications -->
                    <div class="relative">
                        <button onclick="toggleNotifications(event)" class="adm-icon-btn relative" title="Notifications">
                            <i class="fas fa-bell text-sm"></i>
                            <?php if ($notificationCount > 0): ?>
                            <span class="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 bg-rose-500 text-white text-[10px] rounded-full flex items-center justify-center font-bold ring-2 ring-white dark:ring-gray-900">
                                <?php echo min($notificationCount, 9); ?><?php echo $notificationCount > 9 ? '+' : ''; ?>
                            </span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu absolute right-0 mt-2 w-72 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 p-4 hidden fade-in z-50" id="notifDropdown">
                            <div class="text-xs font-extrabold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Notifications</div>
                            <?php if ($notificationCount > 0): ?>
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-violet-50 dark:bg-violet-900/25 border border-violet-100 dark:border-violet-800/50">
                                <span class="w-9 h-9 rounded-xl bg-violet-600 text-white flex items-center justify-center text-sm shrink-0"><i class="fas fa-bell"></i></span>
                                <div class="min-w-0">
                                    <div class="text-sm font-bold text-gray-800 dark:text-white"><?php echo (int) $notificationCount; ?> unread notification<?php echo $notificationCount > 1 ? 's' : ''; ?></div>
                                    <a href="../index.php" class="text-xs font-semibold text-violet-600 dark:text-violet-300 hover:underline">Open site to view →</a>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="text-sm text-gray-500 dark:text-gray-400 py-2 flex items-center gap-2">
                                <i class="fas fa-check-circle text-emerald-500"></i> You're all caught up.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- User Dropdown -->
                    <div class="relative">
                        <button onclick="toggleUserMenu(event)" class="adm-chip !h-[38px] !pl-1.5 !pr-3">
                            <span class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500 to-blue-500 flex items-center justify-center text-white text-xs font-bold">
                                <?php echo strtoupper(substr($userDisplayName, 0, 1)); ?>
                            </span>
                            <span class="hidden sm:block text-sm font-bold text-gray-700 dark:text-gray-200 max-w-[90px] truncate"><?php echo htmlspecialchars($userDisplayName); ?></span>
                            <i class="fas fa-chevron-down text-[10px] text-gray-400 dropdown-arrow"></i>
                        </button>

                        <!-- Dropdown Menu -->
                        <div class="dropdown-menu absolute right-0 mt-2 w-52 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 py-2 hidden fade-in z-50 overflow-hidden" id="userDropdown">
                            <div class="px-4 py-2.5 border-b border-gray-100 dark:border-gray-700">
                                <div class="text-sm font-bold text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($userDisplayName); ?></div>
                                <div class="text-[11px] text-gray-400 truncate"><?php echo htmlspecialchars($userEmail); ?></div>
                            </div>
                            <a href="../index.php?page=profile" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <i class="fas fa-user w-4 text-center text-gray-400"></i>Profile
                            </a>
                            <a href="index.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <i class="fas fa-gauge-high w-4 text-center text-gray-400"></i>Dashboard
                            </a>
                            <a href="settings.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <i class="fas fa-gear w-4 text-center text-gray-400"></i>Settings
                            </a>
                            <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors">
                                <i class="fas fa-right-from-bracket w-4 text-center"></i>Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <div class="pt-8 px-6 py-8">
            <!-- Breadcrumb -->
            <?php if (isset($breadcrumbs)): ?>
            <nav class="adm-crumb mb-5 flex items-center gap-2 text-[13px]" aria-label="Breadcrumb">
                <ol class="flex items-center gap-2">
                    <li><a href="index.php">Home</a></li>
                    <?php foreach ($breadcrumbs as $i => $crumb): ?>
                    <li class="text-gray-300 dark:text-gray-600"><i class="fas fa-chevron-right text-[9px]"></i></li>
                    <li class="<?php echo $i === count($breadcrumbs) - 1 ? 'text-gray-700 dark:text-gray-200 font-bold' : ''; ?>">
                        <?php if (isset($crumb['url']) && $i < count($breadcrumbs) - 1): ?>
                        <a href="<?php echo $crumb['url']; ?>"><?php echo $crumb['label']; ?></a>
                        <?php else: ?>
                        <?php echo $crumb['label']; ?>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <?php endif; ?>

            <!-- Global CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <!-- Alerts / Messages -->
            <?php if (isset($messages)): foreach ($messages as $msg): ?>
            <div class="mb-4 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-400 fade-in text-sm font-semibold flex items-center gap-2.5">
                <i class="fas fa-check-circle"></i><?php echo htmlspecialchars($msg); ?>
            </div>
            <?php endforeach; endif; ?>

            <?php if (isset($errors)): foreach ($errors as $err): ?>
            <div class="mb-4 p-4 rounded-2xl bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-400 fade-in text-sm font-semibold flex items-center gap-2.5">
                <i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($err); ?>
            </div>
            <?php endforeach; endif; ?>
