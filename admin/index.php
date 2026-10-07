<?php
$pageTitle = 'Dashboard';
$pageHeading = 'Admin Dashboard';
$currentPage = 'dashboard';
$breadcrumbs = [
    ['label' => 'Dashboard']
];

require_once __DIR__ . '/layout/header.php';

$csrfToken = $security->generateCSRFToken();
$db = Database::getInstance()->getConnection();
$messages = $messages ?? [];
$errors = $errors ?? [];

// Get slider enabled setting
$sliderEnabled = '1';
try {
    $stmt = $db->query("SELECT `value` FROM site_settings WHERE `key` = 'slider_enabled'");
    $sliderEnabled = $stmt->fetchColumn() ?: '1';
} catch (Throwable $e) {}

// Handle slider toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_slider'])) {
    if ($security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $newVal = $_POST['slider_enabled'] === '1' ? '1' : '0';
        try {
            $stmt = $db->prepare("INSERT INTO site_settings (`key`, `value`) VALUES ('slider_enabled', ?) ON DUPLICATE KEY UPDATE `value` = ?");
            $stmt->execute([$newVal, $newVal]);
            $sliderEnabled = $newVal;
            $messages[] = $newVal === '1' ? 'Slider enabled' : 'Slider disabled';
        } catch (PDOException $e) { $errors[] = $e->getMessage(); }
    }
}

$dashboardStats = [
    ['label' => 'Users', 'value' => 0, 'icon' => 'fa-users', 'color' => 'text-blue-500'],
    ['label' => 'Posts', 'value' => 0, 'icon' => 'fa-newspaper', 'color' => 'text-emerald-500'],
    ['label' => 'Friendships', 'value' => 0, 'icon' => 'fa-user-group', 'color' => 'text-violet-500'],
    ['label' => 'Slides', 'value' => 0, 'icon' => 'fa-sliders-h', 'color' => 'text-purple-500'],
];

$recentUsers = [];
$recentPosts = [];
$sliderStats = ['total' => 0, 'active' => 0];

try {
    ensureSocialTables($db);

    $dashboardStats[0]['value'] = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $dashboardStats[1]['value'] = (int) $db->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    $dashboardStats[2]['value'] = (int) $db->query("SELECT COUNT(*) FROM friendships WHERE status = 'accepted'")->fetchColumn();
    $dashboardStats[3]['value'] = (int) $db->query("SELECT COUNT(*) FROM slider_content WHERE status = 'active'")->fetchColumn();
    if ($dashboardStats[3]['value'] === 0) {
        $dashboardStats[3]['value'] = (int) $db->query("SELECT COUNT(*) FROM slider_content")->fetchColumn();
    }

    $recentUsers = $db->query("
        SELECT id, username, full_name, email, avatar, role, registered_at
        FROM users
        ORDER BY registered_at DESC, id DESC
        LIMIT 6
    ")->fetchAll() ?: [];

    $recentPosts = $db->query("
        SELECT p.id, p.content, p.privacy, p.created_at, u.username, u.full_name
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        ORDER BY p.created_at DESC
        LIMIT 5
    ")->fetchAll() ?: [];

    try {
        $sliderStats['total'] = (int) $db->query("SELECT COUNT(*) FROM slider_content")->fetchColumn();
        $sliderStats['active'] = (int) $db->query("SELECT COUNT(*) FROM slider_content WHERE status = 'active'")->fetchColumn();
    } catch (Throwable $e) {
        $sliderStats = ['total' => 0, 'active' => 0];
    }
} catch (Throwable $e) {
    $errors[] = 'Unable to load admin dashboard data: ' . $e->getMessage();
}
?>

<div class="grid grid-cols-1 xl:grid-cols-4 gap-6 mb-8">
    <?php foreach ($dashboardStats as $stat): ?>
        <article class="stat-card bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($stat['label']); ?></span>
                    <h2 class="text-3xl font-black mt-2 text-gray-900 dark:text-white"><?php echo number_format((int) $stat['value']); ?></h2>
                </div>
                <span class="w-14 h-14 rounded-2xl bg-gray-100 dark:bg-gray-700 inline-flex items-center justify-center <?php echo htmlspecialchars($stat['color']); ?>">
                    <i class="fas <?php echo htmlspecialchars($stat['icon']); ?> text-xl"></i>
                </span>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
    <section class="xl:col-span-2 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6 slide-in">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
            <div>
                <span class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-500">Overview</span>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mt-2">Control the social homepage and community data</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">This dashboard now runs on the same admin framework as the rest of the panel, so navigation, permissions, and tools stay consistent.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="slider-editor.php" class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white transition-colors">
                    <i class="fas fa-sliders-h mr-2"></i>Manage slider
                </a>
                <a href="manage-db.php" class="px-4 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-200 transition-colors">
                    <i class="fas fa-database mr-2"></i>Database tools
                </a>
            </div>
        </div>

        <div class="grid md:grid-cols-3 gap-4">
            <article class="rounded-2xl border border-blue-100 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-900/20 p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm text-blue-600 dark:text-blue-300">Slider content</div>
                        <div class="text-2xl font-bold text-blue-900 dark:text-white mt-2"><?php echo number_format($sliderStats['active']); ?></div>
                        <p class="text-sm text-blue-800/80 dark:text-blue-100/70 mt-2"><?php echo number_format($sliderStats['total']); ?> total slides</p>
                    </div>
                    <div class="text-center">
                        <form method="POST" class="inline-block">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="toggle_slider" value="1">
                            <input type="hidden" name="slider_enabled" value="<?php echo $sliderEnabled === '1' ? '0' : '1'; ?>">
                            <button type="submit" class="relative inline-flex h-7 w-12 items-center rounded-full transition-colors duration-200 <?php echo $sliderEnabled === '1' ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'; ?>">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition-transform duration-200 <?php echo $sliderEnabled === '1' ? 'translate-x-6' : 'translate-x-1'; ?>"></span>
                            </button>
                        </form>
                        <div class="text-xs font-semibold mt-1 <?php echo $sliderEnabled === '1' ? 'text-green-600' : 'text-red-500'; ?>">
                            <i class="fas fa-<?php echo $sliderEnabled === '1' ? 'check-circle' : 'times-circle'; ?>"></i>
                            <?php echo $sliderEnabled === '1' ? 'Visible' : 'Hidden'; ?>
                        </div>
                        <p class="text-[11px] leading-snug text-amber-600 dark:text-amber-300/90 bg-amber-50 dark:bg-amber-900/25 border border-amber-200 dark:border-amber-800/50 rounded-lg px-2 py-1.5 mt-2 max-w-[15rem] mx-auto">
                            <i class="fas fa-triangle-exclamation mr-1"></i>
                            <strong>ON</strong> = the slider takes over the top of the home hero.
                            <strong>OFF</strong> = the standard hero shows instead. Slides still stay saved either way.
                        </p>
                    </div>
                </div>
            </article>

            <article class="rounded-2xl border border-emerald-100 dark:border-emerald-900/40 bg-emerald-50 dark:bg-emerald-900/20 p-5">
                <div class="text-sm text-emerald-600 dark:text-emerald-300">Community ready</div>
                <div class="text-2xl font-bold text-emerald-900 dark:text-white mt-2"><?php echo number_format($dashboardStats[1]['value']); ?></div>
                <p class="text-sm text-emerald-800/80 dark:text-emerald-100/70 mt-2">Posts are available for the home feed and community views.</p>
            </article>

            <article class="rounded-2xl border border-violet-100 dark:border-violet-900/40 bg-violet-50 dark:bg-violet-900/20 p-5">
                <div class="text-sm text-violet-600 dark:text-violet-300">Relationships</div>
                <div class="text-2xl font-bold text-violet-900 dark:text-white mt-2"><?php echo number_format($dashboardStats[2]['value']); ?></div>
                <p class="text-sm text-violet-800/80 dark:text-violet-100/70 mt-2">Accepted friendships shaping personalized feed visibility.</p>
            </article>
        </div>
    </section>

    <section class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6 slide-in">
        <div class="mb-5">
            <span class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-400">Quick actions</span>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mt-2">Admin shortcuts</h2>
        </div>
        <div class="space-y-2">
            <a href="slider-editor.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-sliders-h text-blue-500"></i> Slider Editor</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
            <a href="tournament-manager.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-trophy text-purple-500"></i> Tournaments</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
            <a href="player-manager.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-ranking-star text-amber-500"></i> Top Players</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
            <a href="ad-manager.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-ad text-green-500"></i> Ad Manager</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
            <a href="topup-manager.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-gamepad text-indigo-500"></i> Game Top-Up</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
            <div class="border-t border-gray-200 dark:border-gray-700 my-2"></div>
            <a href="user-manager.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-users-cog text-blue-500"></i> User manager</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
            <a href="system-status.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-server text-emerald-500"></i> System status</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
            <a href="analytics.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-chart-line text-violet-500"></i> Analytics</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
            <a href="settings.php" class="flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-gray-50 hover:bg-gray-100 dark:bg-gray-700/70 dark:hover:bg-gray-700 transition-colors">
                <span class="flex items-center gap-3"><i class="fas fa-cog text-amber-500"></i> Settings</span>
                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            </a>
        </div>
    </section>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <section class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Recent users</h2>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            <?php if ($recentUsers): ?>
                <?php foreach ($recentUsers as $recentUser): ?>
                    <div class="px-6 py-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <img src="../assets/avatars/<?php echo htmlspecialchars($recentUser['avatar'] ?: 'default.png'); ?>" alt="<?php echo htmlspecialchars($recentUser['full_name'] ?: $recentUser['username']); ?>" class="w-11 h-11 rounded-xl object-cover" onerror="this.src='../assets/avatars/default.png'">
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-900 dark:text-white truncate"><?php echo htmlspecialchars($recentUser['full_name'] ?: $recentUser['username']); ?></div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate"><?php echo htmlspecialchars($recentUser['email']); ?></div>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold <?php echo ($recentUser['role'] ?? 'user') === 'super_admin' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : (($recentUser['role'] ?? 'user') === 'admin' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'); ?>">
                                <?php echo strtoupper(htmlspecialchars($recentUser['role'] ?? 'user')); ?>
                            </span>
                            <div class="text-xs text-gray-400 mt-2"><?php echo !empty($recentUser['registered_at']) ? date('M j, Y', strtotime($recentUser['registered_at'])) : 'Unknown'; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="px-6 py-8 text-sm text-gray-500 dark:text-gray-400">No users found yet.</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Recent posts</h2>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            <?php if ($recentPosts): ?>
                <?php foreach ($recentPosts as $recentPost): ?>
                    <div class="px-6 py-4">
                        <div class="flex items-center justify-between gap-4 mb-2">
                            <strong class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($recentPost['full_name'] ?: $recentPost['username']); ?></strong>
                            <span class="text-xs uppercase tracking-wide text-gray-400"><?php echo htmlspecialchars($recentPost['privacy']); ?></span>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-300 leading-6">
                            <?php echo htmlspecialchars(mb_strimwidth(trim((string) $recentPost['content']), 0, 160, '...')); ?>
                        </p>
                        <div class="text-xs text-gray-400 mt-3"><?php echo !empty($recentPost['created_at']) ? date('M j, Y g:i A', strtotime($recentPost['created_at'])) : 'Unknown'; ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="px-6 py-8 text-sm text-gray-500 dark:text-gray-400">No posts have been created yet.</div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
