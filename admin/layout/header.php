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
        .sidebar { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 40; }
        .sidebar.collapsed { width: 70px; }
        .sidebar.collapsed .sidebar-text { display: none; }
        .sidebar.collapsed .logo-text { display: none; }
        .sidebar.collapsed .dropdown-arrow { display: none; }
        .main-content { transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .nav-item { transition: all 0.2s ease; }
        .nav-item:hover { transform: translateX(4px); }
        .nav-item.active { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(16, 185, 129, 0.1)); border-right: 3px solid #3B82F6; }
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
        .dropdown-menu { transition: all 0.2s ease; }
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
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen">

<script>
    // Dark mode initialization is handled in footer.php after DOM is ready
</script>

    <!-- Sidebar -->
    <div class="sidebar fixed left-0 top-0 h-full w-64 bg-white dark:bg-gray-800 shadow-2xl z-40 overflow-y-auto" id="sidebar">
        <!-- Logo -->
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                        <i class="fas fa-terminal text-white text-xl"></i>
                    </div>
                    <span class="logo-text text-xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">DREAMBD</span>
                </div>
                <button onclick="toggleSidebar()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
                    <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
                </button>
            </div>
        </div>

        <!-- User Info -->
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-green-400 to-blue-500 flex items-center justify-center text-white font-bold">
                    <?php echo strtoupper(substr($userDisplayName, 0, 1)); ?>
                </div>
                <div class="sidebar-text flex-1 min-w-0">
                    <div class="text-sm font-semibold text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo htmlspecialchars($userEmail); ?></div>
                </div>
            </div>
            <div class="mt-3 sidebar-text">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $userRole === 'super_admin' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400'; ?>">
                    <i class="fas fa-shield-alt mr-1"></i>
                    <?php echo strtoupper($userRole); ?>
                </span>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-4 px-3 pb-20">
            <div class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2 sidebar-text px-3">Main</div>

            <a href="index.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'dashboard' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-tachometer-alt w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Dashboard</span>
            </a>

            <a href="manage-db.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'database' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-database w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Database</span>
            </a>

            <a href="slider-editor.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'slider' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-sliders-h w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Slider Editor</span>
            </a>

            <a href="tournament-manager.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'tournaments' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-trophy w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Tournaments</span>
            </a>

            <a href="player-manager.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'players' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-ranking-star w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Top Players</span>
            </a>

            <a href="ad-manager.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'ads' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-ad w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Ad Manager</span>
            </a>

            <a href="product-manager.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'products' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-box-stacked w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Products</span>
            </a>

            <a href="topup-manager.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'topup' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-gamepad w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Game Top-Up</span>
            </a>

            <a href="user-manager.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'users' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-users-cog w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">User Manager</span>
            </a>

            <a href="system-status.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'system' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-server w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">System Status</span>
            </a>

            <a href="payment-requests.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'payments' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-credit-card w-5 text-center"></i>
                <span class="sidebar-text font-medium">Payments</span>
            </a>
            <a href="p2p-reports.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'p2p-reports' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-flag w-5 text-center"></i>
                <span class="sidebar-text font-medium">P2P Reports</span>
            </a>

            <a href="post-reports.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'post-reports' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-shield-alt w-5 text-center"></i>
                <span class="sidebar-text font-medium">Post Reports</span>
            </a>

            <a href="app-control.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'app-control' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-mobile-alt w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">App Control</span>
            </a>

            <a href="analytics.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'analytics' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-chart-line w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Analytics</span>
            </a>

            <a href="search.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 <?php echo ($currentPage ?? '') === 'search' ? 'active text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                <i class="fas fa-search w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Search</span>
            </a>

            <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
            <div class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2 sidebar-text px-3">Account</div>

            <a href="../index.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <i class="fas fa-home w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Back to Site</span>
            </a>

            <a href="../index.php?page=profile" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg mb-1 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <i class="fas fa-user w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">My Profile</span>
            </a>

            <a href="../logout.php" class="nav-item flex items-center space-x-3 px-3 py-3 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">
                <i class="fas fa-sign-out-alt w-6 text-center text-lg"></i>
                <span class="sidebar-text font-medium">Logout</span>
            </a>
        </nav>
    </div>

    <!-- Main Content Wrapper -->
    <div class="ml-64 main-content" id="mainContent">

        <!-- Top Navbar -->
        <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 fixed top-0 right-0 left-64 z-30 transition-all duration-300" id="topNavbar">
            <div class="px-6 py-3">
                <div class="flex items-center justify-between">
                    <!-- Page Title -->
                    <div class="flex items-center space-x-4">
                        <h1 class="text-xl font-bold text-gray-800 dark:text-white">
                            <?php echo $pageHeading ?? 'Dashboard'; ?>
                        </h1>
                        <?php if (isset($pageSubheading)): ?>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            <?php echo $pageSubheading; ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Right Side -->
                    <div class="flex items-center space-x-4">
                        <!-- Live Clock -->
                        <div class="hidden md:flex items-center space-x-2 bg-gray-100 dark:bg-gray-700 px-4 py-2 rounded-lg">
                            <i class="fas fa-clock text-blue-500"></i>
                            <div class="text-right">
                                <div class="text-sm font-mono font-bold text-gray-800 dark:text-white live-clock" id="liveClock">--:--:--</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400" id="liveDate">----/--/--</div>
                            </div>
                        </div>

                        <!-- Dark Mode Toggle -->
                        <button onclick="toggleDarkMode()" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors" title="Toggle Dark Mode">
                            <i class="fas fa-moon text-gray-600 dark:text-yellow-400" id="darkModeIcon"></i>
                        </button>

                        <!-- Notifications -->
                        <button onclick="toggleNotifications()" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors relative">
                            <i class="fas fa-bell text-gray-600 dark:text-gray-300"></i>
                            <?php if ($notificationCount > 0): ?>
                            <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold">
                                <?php echo min($notificationCount, 9); ?><?php echo $notificationCount > 9 ? '+' : ''; ?>
                            </span>
                            <?php endif; ?>
                        </button>

                        <!-- User Dropdown -->
                        <div class="relative">
                            <button onclick="toggleUserMenu()" class="flex items-center space-x-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg px-3 py-2 transition-colors">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-sm font-bold">
                                    <?php echo strtoupper(substr($userDisplayName, 0, 1)); ?>
                                </div>
                                <i class="fas fa-chevron-down text-gray-400 text-xs dropdown-arrow"></i>
                            </button>

                            <!-- Dropdown Menu -->
                            <div class="dropdown-menu absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 py-2 hidden fade-in" id="userDropdown">
                                <a href="../index.php?page=profile" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <i class="fas fa-user mr-2"></i>Profile
                                </a>
                                <a href="index.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                                </a>
                                <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <i class="fas fa-cog mr-2"></i>Settings
                                </a>
                                <div class="border-t border-gray-200 dark:border-gray-700 my-1"></div>
                                <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <div class="pt-8 px-6 py-8">
            <!-- Breadcrumb -->
            <?php if (isset($breadcrumbs)): ?>
            <nav class="mb-6" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2 text-sm text-gray-500 dark:text-gray-400">
                    <li><a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Home</a></li>
                    <?php foreach ($breadcrumbs as $i => $crumb): ?>
                    <li><i class="fas fa-chevron-right text-xs"></i></li>
                    <li class="<?php echo $i === count($breadcrumbs) - 1 ? 'text-gray-800 dark:text-white font-medium' : ''; ?>">
                        <?php if (isset($crumb['url']) && $i < count($breadcrumbs) - 1): ?>
                        <a href="<?php echo $crumb['url']; ?>" class="hover:text-blue-600 dark:hover:text-blue-400"><?php echo $crumb['label']; ?></a>
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
            <div class="mb-4 p-4 rounded-xl bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 fade-in">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($msg); ?>
            </div>
            <?php endforeach; endif; ?>

            <?php if (isset($errors)): foreach ($errors as $err): ?>
            <div class="mb-4 p-4 rounded-xl bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 fade-in">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($err); ?>
            </div>
            <?php endforeach; endif; ?>
