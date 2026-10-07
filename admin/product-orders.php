<?php
$pageTitle = 'Product Orders';
$pageHeading = 'Product Orders';
$currentPage = 'product-orders';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => 'index.php'],
    ['label' => 'Product Orders']
];
require_once __DIR__ . '/layout/header.php';
$db = Database::getInstance()->getConnection();
$csrfToken = $security->generateCSRFToken();

$statusFilter = $_GET['of'] ?? '';
if (!in_array($statusFilter, ['', 'completed', 'refunded'], true)) $statusFilter = '';
$search = trim($_GET['q'] ?? '');
$perPage = 20;
$pageNum = max(1, (int)($_GET['p'] ?? 1));

$where = [];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'pp.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR p.name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stats = ['orders' => 0, 'sales' => 0, 'refunded' => 0, 'author_paid' => 0, 'platform' => 0];
try {
    $stmt = $db->query("SELECT
        COUNT(*) AS orders,
        COALESCE(SUM(CASE WHEN status='completed' THEN amount END), 0) AS sales,
        COALESCE(SUM(CASE WHEN status='refunded'  THEN amount END), 0) AS refunded,
        COALESCE(SUM(CASE WHEN status='completed' THEN author_earnings END), 0) AS author_paid
        FROM product_purchases");
    $stats = $stmt->fetch() ?: $stats;
    $stats['platform'] = (float)$stats['sales'] - (float)$stats['author_paid'];
} catch (Throwable $e) {}

$totalRows = 0;
$orders = [];
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM product_purchases pp
        LEFT JOIN products p ON p.id = pp.product_id
        LEFT JOIN users u ON u.id = pp.user_id
        $whereSql");
    $stmt->execute($params);
    $totalRows = (int)$stmt->fetchColumn();

    $offset = ($pageNum - 1) * $perPage;
    $stmt = $db->prepare("SELECT pp.*, p.name AS product_name, p.author_id, p.category,
                                 u.username, u.full_name,
                                 a.username AS author_username, a.full_name AS author_name
                          FROM product_purchases pp
                          LEFT JOIN products p ON p.id = pp.product_id
                          LEFT JOIN users u ON u.id = pp.user_id
                          LEFT JOIN users a ON a.id = p.author_id
                          $whereSql
                          ORDER BY pp.created_at DESC
                          LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $orders = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($pageNum > $totalPages) $pageNum = $totalPages;

function po_qs(array $extra = []): string {
    $q = array_merge(['tab' => 'orders'], $_GET, $extra);
    unset($q['tab']);
    return 'product-orders.php?' . http_build_query($q);
}
?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8 slide-in">
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-receipt text-2xl text-blue-600 dark:text-blue-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo (int)$stats['orders']; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Total Orders</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-sack-dollar text-2xl text-green-600 dark:text-green-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white">৳<?php echo number_format((float)$stats['sales'], 2); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Gross Sales</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-red-100 dark:bg-red-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-rotate-left text-2xl text-red-600 dark:text-red-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white">৳<?php echo number_format((float)$stats['refunded'], 2); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Refunded</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-hand-holding-dollar text-2xl text-purple-600 dark:text-purple-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white">৳<?php echo number_format((float)$stats['author_paid'], 2); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Author Payouts (70%)</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="p-3 bg-amber-100 dark:bg-amber-900/30 rounded-xl inline-flex mb-3"><i class="fas fa-building-columns text-2xl text-amber-600 dark:text-amber-400"></i></div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white">৳<?php echo number_format((float)$stats['platform'], 2); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Platform Share (30%)</p>
    </div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white"><i class="fas fa-cart-shopping text-blue-500 mr-2"></i>Sales Ledger <span class="text-sm font-normal text-gray-500">(<?php echo $totalRows; ?>)</span></h2>
        <div class="flex flex-wrap items-center gap-2">
            <?php foreach (['' => 'All', 'completed' => 'Completed', 'refunded' => 'Refunded'] as $fkey => $flabel): ?>
            <a href="product-orders.php?of=<?php echo $fkey; ?><?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?>" class="px-3 py-1.5 rounded-full text-xs font-medium <?php echo $statusFilter === $fkey ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'; ?>"><?php echo $flabel; ?></a>
            <?php endforeach; ?>
            <form method="get" action="product-orders.php" class="flex items-center gap-2">
                <?php if ($statusFilter !== ''): ?><input type="hidden" name="of" value="<?php echo htmlspecialchars($statusFilter); ?>"><?php endif; ?>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search buyer or product..."
                       class="px-3 py-1.5 text-xs rounded-lg bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-white w-44">
                <button type="submit" class="px-3 py-1.5 text-xs rounded-lg bg-gray-800 dark:bg-gray-600 text-white"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </div>

    <?php if (!$orders): ?>
    <div class="py-16 text-center text-gray-500 dark:text-gray-400">
        <i class="fas fa-inbox text-6xl opacity-30 mb-4"></i>
        <p>No purchases found.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700 text-xs uppercase text-gray-500 dark:text-gray-400">
                    <th class="py-3 text-left font-semibold">Order</th>
                    <th class="py-3 text-left font-semibold">Buyer</th>
                    <th class="py-3 text-left font-semibold">Product</th>
                    <th class="py-3 text-left font-semibold">Author</th>
                    <th class="py-3 text-right font-semibold">Paid</th>
                    <th class="py-3 text-right font-semibold">Author 70%</th>
                    <th class="py-3 text-left font-semibold">Status</th>
                    <th class="py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                    <td class="py-3">
                        <div class="font-semibold text-gray-800 dark:text-white">#<?php echo (int)$o['id']; ?></div>
                        <div class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($o['created_at'])); ?></div>
                    </td>
                    <td class="py-3">
                        <div class="text-gray-800 dark:text-white"><?php echo htmlspecialchars($o['full_name'] ?: ($o['username'] ?? 'User #' . $o['user_id'])); ?></div>
                        <div class="text-xs text-gray-500">ID: <?php echo (int)$o['user_id']; ?></div>
                    </td>
                    <td class="py-3">
                        <div class="text-gray-800 dark:text-white"><?php echo htmlspecialchars($o['product_name'] ?? 'Deleted product'); ?></div>
                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($o['category'] ?? '—'); ?></div>
                    </td>
                    <td class="py-3 text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($o['author_name'] ?: ($o['author_username'] ?? '—')); ?></td>
                    <td class="py-3 text-right font-semibold text-gray-800 dark:text-white">৳<?php echo number_format((float)$o['amount'], 2); ?></td>
                    <td class="py-3 text-right text-gray-600 dark:text-gray-300">৳<?php echo number_format((float)($o['author_earnings'] ?? 0), 2); ?></td>
                    <td class="py-3">
                        <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?php echo $o['status'] === 'completed' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'; ?>">
                            <?php echo ucfirst($o['status']); ?>
                        </span>
                    </td>
                    <td class="py-3 text-right">
                        <?php if ($o['status'] === 'completed'): ?>
                        <button type="button" onclick="refundPurchase(<?php echo (int)$o['id']; ?>)" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50">
                            <i class="fas fa-rotate-left mr-1"></i>Refund
                        </button>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-100 dark:border-gray-700">
        <span class="text-xs text-gray-500">Page <?php echo $pageNum; ?> of <?php echo $totalPages; ?></span>
        <div class="flex gap-2">
            <?php if ($pageNum > 1): ?>
            <a href="<?php echo po_qs(['p' => $pageNum - 1]); ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300"><i class="fas fa-chevron-left"></i> Prev</a>
            <?php endif; ?>
            <?php if ($pageNum < $totalPages): ?>
            <a href="<?php echo po_qs(['p' => $pageNum + 1]); ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">Next <i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
const PRODUCT_HANDLER = '../handlers/product_handler.php';
const csrf = '<?php echo $csrfToken; ?>';

function toast(msg, ok) { alert(ok ? msg : ('Error: ' + msg)); }

async function refundPurchase(id) {
    if (!confirm('Refund purchase #' + id + '?\n\n- Full amount returns to the buyer\n- Author 70% earning is deducted\n- Access to the guide is revoked')) return;
    const fd = new FormData();
    fd.append('action', 'refund_purchase');
    fd.append('id', id);
    fd.append('csrf_token', csrf);
    try {
        const res = await fetch(PRODUCT_HANDLER, { method: 'POST', body: fd });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) window.location.reload();
    } catch (e) {
        toast('Network error.', false);
    }
}
</script>
<?php require_once __DIR__ . '/layout/footer.php'; ?>
