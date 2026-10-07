<?php
$pageTitle = 'Game Top-Up Manager';
$pageHeading = 'Game Top-Up Manager';
$currentPage = 'topup';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => 'index.php'],
    ['label' => 'Game Top-Up']
];
require_once __DIR__ . '/layout/header.php';
$db = Database::getInstance()->getConnection();

$games = $db->query("SELECT * FROM game_topup_games ORDER BY sort_order ASC, name ASC")->fetchAll() ?: [];
$gamesById = [];
$packageCounts = [];
foreach ($games as $game) {
    $gamesById[(int)$game['id']] = $game;
}

try {
    $pkgStmt = $db->query("SELECT game_id, COUNT(*) AS cnt FROM game_topup_packages GROUP BY game_id");
    foreach ($pkgStmt->fetchAll() as $row) $packageCounts[(int)$row['game_id']] = (int)$row['cnt'];
} catch (Throwable $e) {}

$packages = $db->query("SELECT p.*, g.name AS game_name FROM game_topup_packages p JOIN game_topup_games g ON g.id=p.game_id ORDER BY p.game_id, p.sort_order ASC, p.price ASC")->fetchAll() ?: [];

$orders = [];
$orderCounts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'cancelled' => 0, 'all' => 0];
try {
    $orders = $db->query("SELECT o.*, u.username, u.full_name AS user_name,
                                 h.full_name AS handler_name
                          FROM topup_orders o
                          LEFT JOIN users u ON u.id = o.user_id
                          LEFT JOIN users h ON h.id = o.handled_by
                          ORDER BY o.created_at DESC
                          LIMIT 200")->fetchAll() ?: [];
    foreach ($orders as $o) {
        $st = in_array($o['status'], ['pending','processing','completed','cancelled'], true) ? $o['status'] : 'pending';
        $orderCounts[$st]++;
        $orderCounts['all']++;
    }
    foreach (['pending','processing','completed','cancelled','all'] as $k) {
        if ($orderCounts[$k] === 0) {
            $stmt = $db->query("SELECT COUNT(*) FROM topup_orders");
            $orderCounts[$k] = (int)$stmt->fetchColumn();
        }
    }
} catch (Throwable $e) {}

$tab = $_GET['tab'] ?? 'games';
$activeGameId = (int)($_GET['game'] ?? 0);
$orderFilter = $_GET['of'] ?? '';
$orderGameFilter = (int)($_GET['gf'] ?? 0);
$validTabs = ['games', 'packages', 'orders'];
if (!in_array($tab, $validTabs, true)) $tab = 'games';
$orderStatusFilter = in_array($orderFilter, ['', 'pending', 'processing', 'completed', 'cancelled'], true) ? $orderFilter : '';
if ($orderGameFilter && !isset($gamesById[$orderGameFilter])) $orderGameFilter = 0;
?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8 slide-in">
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-gamepad text-2xl text-purple-600 dark:text-purple-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo count($games); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Games</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-boxes-stacked text-2xl text-blue-600 dark:text-blue-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo count($packages); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Packages</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-clock text-2xl text-yellow-600 dark:text-yellow-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo (int)$orderCounts['pending']; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Pending Orders</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-circle-check text-2xl text-green-600 dark:text-green-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo (int)$orderCounts['completed']; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Completed</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-red-100 dark:bg-red-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-ban text-2xl text-red-600 dark:text-red-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo (int)$orderCounts['cancelled']; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Cancelled</p>
    </div>
</div>

<div class="flex flex-wrap gap-2 mb-8">
    <a href="topup-manager.php?tab=games" class="px-4 py-2 rounded-xl font-medium transition <?php echo $tab === 'games' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>"><i class="fas fa-gamepad mr-2"></i>Games</a>
    <a href="topup-manager.php?tab=packages" class="px-4 py-2 rounded-xl font-medium transition <?php echo $tab === 'packages' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>"><i class="fas fa-boxes-stacked mr-2"></i>Packages</a>
    <a href="topup-manager.php?tab=orders" class="px-4 py-2 rounded-xl font-medium transition <?php echo $tab === 'orders' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>"><i class="fas fa-truck-fast mr-2"></i>Orders <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?php echo $orderCounts['pending'] > 0 ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-200 text-gray-600'; ?>"><?php echo $orderCounts['pending']; ?></span></a>
</div>

<?php if ($tab === 'games'): ?>
<div class="grid grid-cols-1 lg:grid-cols-5 gap-8">
    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in" style="height:fit-content">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6"><i class="fas fa-plus text-green-500 mr-2"></i><span id="gameFormTitle">Add Game</span></h2>
        <form id="gameForm" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save_game">
            <input type="hidden" name="id" id="gameId" value="">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Game Name *</label>
                <input type="text" name="name" id="gName" required maxlength="120" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="e.g. Free Fire">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Slug</label>
                <input type="text" name="slug" id="gSlug" maxlength="80" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="freefire (or leave empty)">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Font Awesome Icon</label>
                <input type="text" name="icon" id="gIcon" value="fa-gamepad" maxlength="60" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="fa-crosshairs">
                <p class="text-xs text-gray-400 mt-1">Fallback icon used when no logo image is uploaded.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Game Logo <small class="text-gray-400 font-normal">(JPG/PNG/WebP · max 5MB)</small></label>
                <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-3 hover:border-purple-400 transition">
                    <input type="file" name="logo" id="gLogo" accept="image/*"
                        class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300">
                    <div id="currentLogo" class="mt-3 hidden">
                        <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Current logo:</div>
                        <div class="flex items-center gap-3">
                            <img id="currentLogoPreview" src="" alt="" class="w-14 h-14 object-contain rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900">
                            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 cursor-pointer">
                                <input type="checkbox" name="remove_logo" id="gRemoveLogo" value="1"> Remove logo
                            </label>
                        </div>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-1">Shown on the products page game cards & top-up panel. Falls back to the icon above.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Gradient (CSS)</label>
                <input type="text" name="gradient" id="gGradient" maxlength="255" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="linear-gradient(135deg, #ff6b35, #f7931e)">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Shadow Color</label>
                <input type="text" name="shadow_color" id="gShadowColor" maxlength="50" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="rgba(255,107,53,0.3)">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                <input type="text" name="description" id="gDescription" maxlength="255" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="Diamond top-up. Instant delivery.">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sort Order</label>
                    <input type="number" name="sort_order" id="gSortOrder" value="0" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select name="status" id="gStatus" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors font-medium"><i class="fas fa-save mr-2"></i><span id="gameSubmitText">Save Game</span></button>
                <button type="button" id="gameCancelBtn" onclick="resetGameForm()" class="px-6 py-2.5 bg-gray-300 dark:bg-gray-600 text-gray-800 dark:text-white rounded-lg hover:bg-gray-400 transition hidden">Cancel</button>
            </div>
        </form>
    </div>

    <div class="lg:col-span-3 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6"><i class="fas fa-list text-purple-500 mr-2"></i>Games (<?php echo count($games); ?>)</h2>
        <?php if (!$games): ?>
        <div class="py-16 text-center text-gray-500"><i class="fas fa-gamepad text-6xl opacity-30 mb-4"></i><p>No games yet. Add your first game!</p></div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-xs uppercase text-gray-500">
                        <th class="py-3 text-left font-semibold">Game</th>
                        <th class="py-3 text-center font-semibold">Packages</th>
                        <th class="py-3 text-center font-semibold">Orders</th>
                        <th class="py-3 text-center font-semibold">Status</th>
                        <th class="py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($games as $game): ?>
                    <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                        <td class="py-3">
                            <div class="flex items-center gap-3">
                                <?php if (!empty($game['logo'])): ?>
                                <span class="w-10 h-10 rounded-xl flex items-center justify-center overflow-hidden bg-gray-100 dark:bg-gray-700" style="background:<?php echo htmlspecialchars($game['gradient'] ?: '#8b5cf6'); ?>">
                                    <img src="../<?php echo htmlspecialchars($game['logo']); ?>" alt="" class="w-full h-full object-contain" onerror="this.remove()">
                                </span>
                                <?php else: ?>
                                <span class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm" style="background:<?php echo htmlspecialchars($game['gradient'] ?: '#8b5cf6'); ?>"><i class="fas <?php echo htmlspecialchars($game['icon'] ?: 'fa-gamepad'); ?>"></i></span>
                                <?php endif; ?>
                                <div>
                                    <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($game['name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($game['description'] ?: ''); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 text-center"><a href="topup-manager.php?tab=packages&game=<?php echo $game['id']; ?>" class="text-purple-600 hover:underline"><?php echo (int)($packageCounts[$game['id']] ?? 0); ?></a></td>
                        <td class="py-3 text-center text-gray-700 dark:text-gray-300"><a href="topup-manager.php?tab=orders&gf=<?php echo (int)$game['id']; ?>" class="text-blue-600 hover:underline">view</a></td>
                        <td class="py-3 text-center">
                            <button type="button" onclick="toggleGame(<?php echo $game['id']; ?>, '<?php echo $game['status'] === 'active' ? 'inactive' : 'active'; ?>')" class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?php echo $game['status'] === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'; ?>"><?php echo ucfirst($game['status']); ?></button>
                        </td>
                        <td class="py-3">
                            <div class="flex justify-end gap-1">
                                <button type="button" onclick="editGame(<?php echo $game['id']; ?>)" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg" title="Edit"><i class="fas fa-edit"></i></button>
                                <button type="button" onclick="deleteGame(<?php echo $game['id']; ?>)" class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg" title="Delete"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($tab === 'packages'): ?>
<div class="grid grid-cols-1 lg:grid-cols-5 gap-8">
    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in" style="height:fit-content">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6"><i class="fas fa-plus text-green-500 mr-2"></i><span id="pkgFormTitle">Add Package</span></h2>
        <form id="pkgForm" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save_package">
            <input type="hidden" name="id" id="pkgId" value="">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Game *</label>
                <select name="game_id" id="pkgGameId" required class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                    <?php foreach ($games as $game): ?>
                    <option value="<?php echo $game['id']; ?>"><?php echo htmlspecialchars($game['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Package Name *</label>
                <input type="text" name="name" id="pkgName" required maxlength="120" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="e.g. 100 Diamonds">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Price (৳) *</label>
                <input type="number" name="price" id="pkgPrice" required min="0" step="0.01" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="120">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sort Order</label>
                    <input type="number" name="sort_order" id="pkgSortOrder" value="0" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select name="status" id="pkgStatus" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Badge Text</label>
                    <input type="text" name="badge" id="pkgBadge" maxlength="50" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="Popular">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Badge Color</label>
                    <div class="flex gap-2">
                        <input type="color" name="badge_color" id="pkgBadgeColor" value="#3b82f6" class="w-10 h-9 rounded-lg cursor-pointer block border border-gray-300 dark:border-gray-500">
                        <input type="text" id="pkgBadgeColorText" value="#3b82f6" class="flex-1 px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-500 rounded-lg font-mono text-sm text-gray-800 dark:text-white">
                    </div>
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors font-medium"><i class="fas fa-save mr-2"></i><span id="pkgSubmitText">Save Package</span></button>
                <button type="button" id="pkgCancelBtn" onclick="resetPkgForm()" class="px-6 py-2.5 bg-gray-300 dark:bg-gray-600 text-gray-800 dark:text-white rounded-lg hover:bg-gray-400 transition hidden">Cancel</button>
            </div>
        </form>
    </div>

    <div class="lg:col-span-3 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6 gap-4">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white"><i class="fas fa-boxes-stacked text-blue-500 mr-2"></i>Packages (<?php echo count($packages); ?>)</h2>
            <select id="pkgFilterGame" class="px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                <option value="">All Games</option>
                <?php foreach ($games as $game): ?>
                <option value="<?php echo $game['id']; ?>" <?php echo $activeGameId === (int)$game['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($game['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!$packages): ?>
        <div class="py-16 text-center text-gray-500"><i class="fas fa-box-open text-6xl opacity-30 mb-4"></i><p>No packages yet.</p></div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-xs uppercase text-gray-500">
                        <th class="py-3 text-left font-semibold">Package</th>
                        <th class="py-3 text-left font-semibold">Game</th>
                        <th class="py-3 text-right font-semibold">Price</th>
                        <th class="py-3 text-center font-semibold">Badge</th>
                        <th class="py-3 text-center font-semibold">Status</th>
                        <th class="py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody id="pkgTbody">
                    <?php foreach ($packages as $pkg): ?>
                    <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition pkg-row" data-game="<?php echo $pkg['game_id']; ?>">
                        <td class="py-3"><span class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($pkg['name']); ?></span><?php echo $pkg['badge'] ? ' <span class="ml-1 text-xs px-2 py-0.5 rounded-full" style="background:'.htmlspecialchars($pkg['badge_color'] ?: '#3b82f6').'22;color:'.htmlspecialchars($pkg['badge_color'] ?: '#3b82f6').'">'.htmlspecialchars($pkg['badge']).'</span>' : ''; ?></td>
                        <td class="py-3 text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($pkg['game_name']); ?></td>
                        <td class="py-3 text-right font-semibold text-gray-800 dark:text-white">৳<?php echo number_format($pkg['price'], 2); ?></td>
                        <td class="py-3 text-center"><?php echo $pkg['badge'] ? '<i class="fas fa-tag text-amber-500"></i>' : '<span class="text-gray-300">—</span>'; ?></td>
                        <td class="py-3 text-center">
                            <button type="button" onclick="togglePkg(<?php echo $pkg['id']; ?>, '<?php echo $pkg['status'] === 'active' ? 'inactive' : 'active'; ?>')" class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?php echo $pkg['status'] === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'; ?>"><?php echo ucfirst($pkg['status']); ?></button>
                        </td>
                        <td class="py-3">
                            <div class="flex justify-end gap-1">
                                <button type="button" onclick="editPkg(<?php echo $pkg['id']; ?>)" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg" title="Edit"><i class="fas fa-edit"></i></button>
                                <button type="button" onclick="deletePkg(<?php echo $pkg['id']; ?>)" class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg" title="Delete"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<!-- ORDERS TAB -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white"><i class="fas fa-truck-fast text-green-500 mr-2"></i>Top-Up Orders (<?php echo $orderCounts['all']; ?>)</h2>
        <div class="flex flex-wrap gap-2">
            <?php foreach (['' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $fkey => $flabel): ?>
            <a href="topup-manager.php?tab=orders&of=<?php echo $fkey; ?><?php echo $orderGameFilter ? '&gf=' . $orderGameFilter : ''; ?>" class="px-3 py-1.5 rounded-full text-xs font-medium <?php echo $orderStatusFilter === $fkey ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'; ?>"><?php echo $flabel; ?></a>
            <?php endforeach; ?>
            <select onchange="if(this.value){window.location.href='topup-manager.php?tab=orders<?php echo $orderStatusFilter !== '' ? '&of=' . $orderStatusFilter : ''; ?>&gf='+this.value}else{window.location.href='topup-manager.php?tab=orders<?php echo $orderStatusFilter !== '' ? '&of=' . $orderStatusFilter : ''; ?>'}" class="px-3 py-1.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-0 cursor-pointer">
                <option value="">All games</option>
                <?php foreach ($games as $gf): ?>
                <option value="<?php echo (int)$gf['id']; ?>" <?php echo $orderGameFilter === (int)$gf['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($gf['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!$orders): ?>
    <div class="py-16 text-center text-gray-500"><i class="fas fa-inbox text-6xl opacity-30 mb-4"></i><p>No orders yet.</p></div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700 text-xs uppercase text-gray-500">
                    <th class="py-3 text-left font-semibold">Order</th>
                    <th class="py-3 text-left font-semibold">Buyer</th>
                    <th class="py-3 text-left font-semibold">Package</th>
                    <th class="py-3 text-left font-semibold">Game ID</th>
                    <th class="py-3 text-right font-semibold">Amount</th>
                    <th class="py-3 text-left font-semibold">Status</th>
                    <th class="py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order):
                    if ($orderStatusFilter !== '' && $order['status'] !== $orderStatusFilter) continue;
                    if ($orderGameFilter && (int)($order['game_id'] ?? 0) !== $orderGameFilter) continue;
                ?>
                <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                    <td class="py-3">
                        <div class="font-semibold text-gray-800 dark:text-white">#<?php echo $order['id']; ?></div>
                        <div class="text-xs text-gray-500"><?php echo date('M j, g:i A', strtotime($order['created_at'])); ?></div>
                    </td>
                    <td class="py-3"><div class="text-gray-800 dark:text-white"><?php echo htmlspecialchars($order['user_name'] ?? $order['username'] ?? 'User #' . $order['user_id']); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($order['contact']); ?></div></td>
                    <td class="py-3"><div class="text-gray-800 dark:text-white"><?php echo htmlspecialchars($order['package_name']); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($order['game_name']); ?></div></td>
                    <td class="py-3"><div class="font-mono text-gray-800 dark:text-white"><?php echo htmlspecialchars($order['player_uid']); ?></div><?php echo $order['player_zone'] ? '<div class="text-xs text-gray-500">Zone: ' . htmlspecialchars($order['player_zone']) . '</div>' : ''; ?></td>
                    <td class="py-3 text-right font-semibold text-gray-800 dark:text-white">৳<?php echo number_format($order['amount'], 2); ?></td>
                    <td class="py-3">
                        <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium
                            <?php
                                $oc = ['pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'processing' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400', 'completed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'cancelled' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'];
                                echo $oc[$order['status']] ?? $oc['pending'];
                            ?>"><?php echo ucfirst($order['status']); ?></span>
                    </td>
                    <td class="py-3">
                        <div class="flex justify-end gap-1">
                            <select onchange="updateOrder(this, <?php echo $order['id']; ?>)" class="px-2 py-1.5 text-xs bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" data-cur="<?php echo $order['status']; ?>">
                                <option value="pending" <?php echo $order['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo $order['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="completed" <?php echo $order['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <?php if (in_array($order['status'], ['pending', 'processing'], true)): ?>
                                <option value="cancelled">Cancel + Refund</option>
                                <?php endif; ?>
                            </select>
                            <button type="button" onclick="deleteOrder(<?php echo $order['id']; ?>)" class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg" title="Delete"><i class="fas fa-trash"></i></button>
                        </div>
                        <?php if ($order['note']): ?><div class="text-xs text-gray-500 mt-1 text-right"><i class="fas fa-sticky-note mr-1"></i><?php echo htmlspecialchars($order['note']); ?></div><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
const TOPUP_HANDLER = '../handlers/topup_handler.php';
const csrf = '<?php echo $csrfToken; ?>';
const games = <?php echo json_encode(array_values($games), JSON_UNESCAPED_UNICODE); ?>;
const packages = <?php echo json_encode(array_values($packages), JSON_UNESCAPED_UNICODE); ?>;

async function api(fd) {
    const res = await fetch(TOPUP_HANDLER, { method: 'POST', body: fd });
    return res.json();
}
function toast(msg, ok) {
    if (ok) { alert(msg); } else { alert('Error: ' + msg); }
}
async function act(formData, reload = true) {
    const data = await api(formData);
    if (data.success) {
        toast(data.message, true);
        if (reload) window.location.reload();
    } else {
        toast(data.message, false);
    }
    return data;
}

/* ---- Games ---- */
const gameBtn = document.getElementById('gameForm');
if (gameBtn) gameBtn.addEventListener('submit', async function(e) {
    e.preventDefault();
    await act(new FormData(this));
});

window.editGame = function(id) {
    const g = games.find(x => x.id === id);
    if (!g) return;
    document.getElementById('gameId').value = g.id;
    document.getElementById('gName').value = g.name || '';
    document.getElementById('gSlug').value = g.slug || '';
    document.getElementById('gIcon').value = g.icon || 'fa-gamepad';
    document.getElementById('gGradient').value = g.gradient || '';
    document.getElementById('gShadowColor').value = g.shadow_color || '';
    document.getElementById('gDescription').value = g.description || '';
    document.getElementById('gSortOrder').value = g.sort_order || 0;
    document.getElementById('gStatus').value = g.status || 'active';
    const logoBox = document.getElementById('currentLogo');
    const logoImg = document.getElementById('currentLogoPreview');
    const rmLogo = document.getElementById('gRemoveLogo');
    if (g.logo) {
        logoBox.classList.remove('hidden');
        logoImg.src = '../' + g.logo;
        rmLogo.checked = false;
    } else {
        logoBox.classList.add('hidden');
        rmLogo.checked = false;
    }
    document.getElementById('gameFormTitle').textContent = 'Edit Game (#' + g.id + ')';
    document.getElementById('gameSubmitText').textContent = 'Update Game';
    document.getElementById('gameCancelBtn').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

window.resetGameForm = function() {
    document.getElementById('gameForm').reset();
    document.getElementById('gameId').value = '';
    document.getElementById('currentLogo').classList.add('hidden');
    document.getElementById('gRemoveLogo').checked = false;
    document.getElementById('gameFormTitle').textContent = 'Add Game';
    document.getElementById('gameSubmitText').textContent = 'Save Game';
    document.getElementById('gameCancelBtn').classList.add('hidden');
};

window.deleteGame = function(id) {
    if (!confirm('Delete this game and all its packages?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_game');
    fd.append('id', id);
    fd.append('csrf_token', csrf);
    act(fd);
};

window.toggleGame = function(id, status) {
    const fd = new FormData();
    fd.append('action', 'toggle_game');
    fd.append('id', id);
    fd.append('status', status);
    fd.append('csrf_token', csrf);
    act(fd);
};

/* ---- Packages ---- */
const pkgForm = document.getElementById('pkgForm');
if (pkgForm) pkgForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    await act(new FormData(this));
});

const pkgBadgeColor = document.getElementById('pkgBadgeColor');
const pkgBadgeColorText = document.getElementById('pkgBadgeColorText');
if (pkgBadgeColor) {
    pkgBadgeColor.addEventListener('input', () => pkgBadgeColorText.value = pkgBadgeColor.value);
    pkgBadgeColorText.addEventListener('input', () => { pkgBadgeColor.value = pkgBadgeColorText.value; });
}

window.editPkg = function(id) {
    const p = packages.find(x => x.id === id);
    if (!p) return;
    document.getElementById('pkgId').value = p.id;
    document.getElementById('pkgGameId').value = p.game_id;
    document.getElementById('pkgName').value = p.name || '';
    document.getElementById('pkgPrice').value = p.price;
    document.getElementById('pkgSortOrder').value = p.sort_order || 0;
    document.getElementById('pkgStatus').value = p.status || 'active';
    document.getElementById('pkgBadge').value = p.badge || '';
    document.getElementById('pkgBadgeColor').value = p.badge_color || '#3b82f6';
    document.getElementById('pkgBadgeColorText').value = p.badge_color || '#3b82f6';
    document.getElementById('pkgFormTitle').textContent = 'Edit Package (#' + p.id + ')';
    document.getElementById('pkgSubmitText').textContent = 'Update Package';
    document.getElementById('pkgCancelBtn').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

window.resetPkgForm = function() {
    document.getElementById('pkgForm').reset();
    document.getElementById('pkgId').value = '';
    document.getElementById('pkgFormTitle').textContent = 'Add Package';
    document.getElementById('pkgSubmitText').textContent = 'Save Package';
    document.getElementById('pkgCancelBtn').classList.add('hidden');
};

window.deletePkg = function(id) {
    if (!confirm('Delete this package?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_package');
    fd.append('id', id);
    fd.append('csrf_token', csrf);
    act(fd);
};

window.togglePkg = function(id, status) {
    const fd = new FormData();
    fd.append('action', 'toggle_package');
    fd.append('id', id);
    fd.append('status', status);
    fd.append('csrf_token', csrf);
    act(fd);
};

/* Package game filter */
const pkgFilterGame = document.getElementById('pkgFilterGame');
if (pkgFilterGame) {
    pkgFilterGame.addEventListener('change', function() {
        const g = this.value;
        window.location.href = 'topup-manager.php?tab=packages' + (g ? '&game=' + g : '');
    });
}

/* ---- Orders ---- */
window.updateOrder = async function(sel, orderId) {
    const cur = sel.dataset.cur;
    const status = sel.value;
    if (status === cur) return;
    const msg = status === 'cancelled'
        ? 'Cancel this order? The buyer will be refunded the full amount.'
        : 'Mark order #' + orderId + ' as ' + status + '?';
    if (!confirm(msg)) { sel.value = cur; return; }
    const note = status === 'cancelled' || status === 'completed'
        ? prompt(status === 'cancelled' ? 'Add a cancellation note (optional):' : 'Add a delivery note / game account credited (optional):', '')
        : '';
    const fd = new FormData();
    fd.append('action', 'update_order_status');
    fd.append('order_id', orderId);
    fd.append('status', status);
    fd.append('note', note || '');
    fd.append('csrf_token', csrf);
    const data = await api(fd);
    if (data.success) { toast(data.message, true); window.location.reload(); }
    else { toast(data.message, false); sel.value = cur; }
};

window.deleteOrder = function(orderId) {
    if (!confirm('Delete order #' + orderId + '? This does NOT refund the buyer.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_order');
    fd.append('id', orderId);
    fd.append('csrf_token', csrf);
    act(fd);
};
</script>
<?php require_once __DIR__ . '/layout/footer.php'; ?>