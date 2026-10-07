<?php
$pageTitle = 'Product Manager';
$pageHeading = 'Product Manager';
$currentPage = 'products';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => 'index.php'],
    ['label' => 'Product Manager']
];
require_once __DIR__ . '/layout/header.php';
$db = Database::getInstance()->getConnection();

$isAdmin = in_array($userRole, ['admin','moderator','super_admin'], true);

$allProducts = [];
try {
    $sql = "SELECT p.*, u.full_name AS author_name, u.username AS author_username
            FROM products p
            LEFT JOIN users u ON u.id = p.author_id
            ORDER BY p.created_at DESC";
    $allProducts = $db->query($sql)->fetchAll();
} catch (PDOException $e) {
    $errors[] = $e->getMessage();
}

$statusCounts = ['total' => count($allProducts)];
foreach ($allProducts as $p) {
    $statusCounts[$p['status']] = ($statusCounts[$p['status']] ?? 0) + 1;
}
?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 slide-in">
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
                <i class="fas fa-box text-2xl text-blue-600 dark:text-blue-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $statusCounts['total']; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Total Products</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-xl">
                <i class="fas fa-circle-check text-2xl text-green-600 dark:text-green-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $statusCounts['active'] ?? 0; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Active</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl">
                <i class="fas fa-hourglass-half text-2xl text-yellow-600 dark:text-yellow-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $statusCounts['pending'] ?? 0; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Pending Review</p>
    </div>
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
                <i class="fas fa-download text-2xl text-purple-600 dark:text-purple-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo array_reduce($allProducts, fn($s,$p)=>$s+($p['sales']??0), 0); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Total Sales</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-8 mb-8">
    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">
            <i class="fas fa-plus text-green-500 mr-2"></i><span id="formTitle">Add New Product</span>
        </h2>
        <form id="productForm" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" id="formAction" value="add_product">
            <input type="hidden" name="id" id="productId" value="">

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Product Name *</label>
                <input type="text" name="name" id="fName" required maxlength="150"
                    class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Price (৳) *</label>
                    <input type="number" name="price" id="fPrice" required min="0" step="0.01"
                        class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Category</label>
                    <select name="category" id="fCategory"
                        class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                        <option value="pdf">PDF Guide</option>
                        <option value="digital">Digital Product</option>
                        <option value="physical">Physical</option>
                        <option value="topup">Top-Up</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Stock</label>
                    <input type="number" name="stock" id="fStock" min="0" value="0"
                        class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Pages (for PDF)</label>
                    <input type="number" name="pages" id="fPages" min="0" value="0"
                        class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Short Description</label>
                <input type="text" name="short_desc" id="fShortDesc" maxlength="255"
                    class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Full Description</label>
                <textarea name="description" id="fDescription" rows="3"
                    class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white"></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Preview Text (shown before purchase)</label>
                <textarea name="preview_text" id="fPreviewText" rows="3"
                    class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Badge Text</label>
                    <input type="text" name="badge" id="fBadge" maxlength="50"
                        class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="e.g. Best Seller">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Badge Color</label>
                    <div class="flex gap-2">
                        <input type="color" name="badge_color" id="fBadgeColor" value="#ef4444"
                            class="w-10 h-9 rounded-lg cursor-pointer block border border-gray-300 dark:border-gray-500">
                        <input type="text" id="fBadgeColorText" value="#ef4444"
                            class="flex-1 px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-500 rounded-lg font-mono text-sm text-gray-800 dark:text-white">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rating (0-5)</label>
                    <input type="number" name="rating" id="fRating" min="0" max="5" step="0.1" value="0"
                        class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sales (admin only)</label>
                    <input type="number" name="sales" id="fSales" min="0" value="0" <?php echo !$isAdmin ? 'readonly' : ''; ?>
                        class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Cover Image
                        <small class="text-gray-400">(max 8MB)</small>
                    </label>
                    <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-3 hover:border-blue-400 transition">
                        <input type="file" name="image" id="fImage" accept="image/*"
                            class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-blue-900/30 dark:file:text-blue-300">
                    </div>
                    <div id="currentImage" class="mt-2 hidden">
                        <div class="text-xs text-gray-500 mb-1">Current image:</div>
                        <img id="currentImagePreview" src="" alt="" class="w-full h-24 object-cover rounded-lg border">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">PDF File
                        <small class="text-gray-400">(max 50MB)</small>
                    </label>
                    <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-3 hover:border-purple-400 transition">
                        <input type="file" name="file" id="fFile" accept="application/pdf"
                            class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300">
                    </div>
                    <div id="currentFile" class="mt-2 hidden text-xs text-gray-500">
                        <i class="fas fa-file-pdf text-red-500 mr-1"></i><span id="currentFileName"></span>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status<?php echo !$isAdmin ? ' <small class="text-yellow-500">(submissions require admin approval)</small>' : ''; ?></label>
                <select name="status" id="fStatus" <?php echo !$isAdmin ? 'disabled' : ''; ?>
                    class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                    <option value="active">Active (visible)</option>
                    <option value="pending">Pending Review</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" id="submitBtn"
                    class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors font-medium">
                    <i class="fas fa-save mr-2"></i><span id="submitBtnText">Create Product</span>
                </button>
                <button type="button" id="cancelBtn" onclick="resetForm()"
                    class="px-6 py-2.5 bg-gray-300 dark:bg-gray-600 text-gray-800 dark:text-white rounded-lg hover:bg-gray-400 transition hidden">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    <div class="lg:col-span-3 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6 gap-4">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white">
                <i class="fas fa-list text-blue-500 mr-2"></i>Products (<?php echo count($allProducts); ?>)
            </h2>
            <div class="flex gap-2">
                <div class="relative flex-1 md:w-64">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" id="searchProducts" placeholder="Search products..."
                        class="w-full pl-9 pr-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                </div>
                <select id="filterStatus"
                    class="px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <?php if (!$allProducts): ?>
        <div class="py-16 text-center text-gray-500">
            <i class="fas fa-box-open text-6xl opacity-30 mb-4"></i>
            <p>No products yet. Create your first one!</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto -mx-6 px-6">
            <table class="w-full text-sm" id="productsTable">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-xs uppercase text-gray-500">
                        <th class="py-3 text-left font-semibold pl-1">Product</th>
                        <th class="py-3 text-left font-semibold">Category</th>
                        <th class="py-3 text-right font-semibold">Price</th>
                        <th class="py-3 text-center font-semibold">Sales</th>
                        <th class="py-3 text-center font-semibold">Status</th>
                        <th class="py-3 text-right font-semibold pr-1">Actions</th>
                    </tr>
                </thead>
                <tbody id="productsTbody">
                    <?php foreach ($allProducts as $p): ?>
                    <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition product-row"
                        data-search="<?php echo htmlspecialchars(strtolower($p['name'] . ' ' . ($p['author_name'] ?? '') . ' ' . ($p['short_desc'] ?? ''))); ?>"
                        data-status="<?php echo htmlspecialchars($p['status']); ?>">
                        <td class="py-3 pl-1">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-lg bg-gray-100 dark:bg-gray-700 overflow-hidden flex-shrink-0">
                                    <?php if ($p['image']): ?>
                                        <img src="<?php echo htmlspecialchars($p['image']); ?>" alt="" class="w-full h-full object-cover" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-gray-400"><i class="fas fa-image"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-800 dark:text-white truncate max-w-xs"><?php echo htmlspecialchars($p['name']); ?></div>
                                    <div class="text-xs text-gray-500 truncate max-w-xs">
                                        by <?php echo htmlspecialchars($p['author_name'] ?? $p['author_username'] ?? 'System'); ?>
                                        <?php if ($p['file_type'] === 'pdf'): ?>
                                            <span class="ml-2 text-red-500"><i class="fas fa-file-pdf"></i> PDF</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                <?php echo htmlspecialchars(ucfirst($p['category'])); ?>
                            </span>
                        </td>
                        <td class="py-3 text-right font-semibold text-gray-800 dark:text-white">৳<?php echo number_format($p['price'], 2); ?></td>
                        <td class="py-3 text-center text-gray-700 dark:text-gray-300"><?php echo number_format($p['sales'] ?? 0); ?></td>
                        <td class="py-3 text-center">
                            <?php
                                $statusStyles = [
                                    'active' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                    'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
                                    'inactive' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'
                                ];
                                $style = $statusStyles[$p['status']] ?? $statusStyles['inactive'];
                            ?>
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?php echo $style; ?>">
                                <?php echo htmlspecialchars(ucfirst($p['status'])); ?>
                            </span>
                        </td>
                        <td class="py-3 pr-1">
                            <div class="flex justify-end gap-1">
                                <?php if ($p['file_type'] === 'pdf' && $isAdmin): ?>
                                    <a href="../handlers/product_handler.php?action=download_pdf&product_id=<?php echo $p['id']; ?>" target="_blank"
                                        class="p-2 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg" title="View PDF">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($isAdmin || (int)$p['author_id'] === $userId): ?>
                                <button type="button" onclick="editProduct(<?php echo $p['id']; ?>)"
                                    class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this product? Files will be permanently removed.');" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" formaction="../handlers/product_handler.php"
                                        class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="noProducts" class="hidden py-10 text-center text-gray-500">
            <i class="fas fa-search text-3xl mb-2 opacity-50"></i>
            <p>No products match your filters.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const productData = <?php
    $safe = [];
    foreach ($allProducts as $p) $safe[$p['id']] = [
        'id'=>(int)$p['id'],'name'=>$p['name'],'description'=>$p['description'],'short_desc'=>$p['short_desc'],
        'price'=>(float)$p['price'],'category'=>$p['category'],'stock'=>(int)$p['stock'],'pages'=>(int)$p['pages'],
        'badge'=>$p['badge'],'badge_color'=>$p['badge_color'],'rating'=>(float)$p['rating'],'sales'=>(int)($p['sales']??0),
        'preview_text'=>$p['preview_text'],'content_long'=>$p['content_long'],'status'=>$p['status'],
        'image'=>$p['image'],'file_path'=>$p['file_path'],'file_type'=>$p['file_type'],'author_id'=>(int)$p['author_id']
    ];
    echo json_encode($safe, JSON_UNESCAPED_UNICODE);
?>;
const HANDLER_URL = '../handlers/product_handler.php';
const csrfToken = '<?php echo $csrfToken; ?>';

const $ = (id) => document.getElementById(id);
const badgeColor = $('fBadgeColor');
const badgeColorText = $('fBadgeColorText');
badgeColor.addEventListener('input', () => badgeColorText.value = badgeColor.value);
badgeColorText.addEventListener('input', () => { badgeColor.value = badgeColorText.value; });

$('productForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const btn = $('submitBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
    fetch(HANDLER_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = original;
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert(data.message || 'Save failed.');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = original;
            alert('Network error: ' + err);
        });
});

function resetForm() {
    const f = $('productForm');
    f.reset();
    $('formAction').value = 'add_product';
    $('productId').value = '';
    $('formTitle').textContent = 'Add New Product';
    $('submitBtnText').textContent = 'Create Product';
    $('cancelBtn').classList.add('hidden');
    $('currentImage').classList.add('hidden');
    $('currentFile').classList.add('hidden');
    $('fStatus').disabled = <?php echo $isAdmin ? 'false' : 'true'; ?>;
    $('fStatus').value = '<?php echo $isAdmin ? 'active' : 'pending'; ?>';
    $('fBadgeColor').value = '#ef4444';
    $('fBadgeColorText').value = '#ef4444';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function editProduct(id) {
    const p = productData[id];
    if (!p) return;
    if (!<?php echo $isAdmin ? 'true' : 'false'; ?> && p.author_id !== <?php echo $userId; ?>) {
        alert('Permission denied');
        return;
    }
    $('formAction').value = 'update_product';
    $('productId').value = p.id;
    $('formTitle').textContent = 'Edit Product (#' + p.id + ')';
    $('submitBtnText').textContent = 'Update Product';
    $('cancelBtn').classList.remove('hidden');
    $('fName').value = p.name || '';
    $('fDescription').value = p.description || '';
    $('fShortDesc').value = p.short_desc || '';
    $('fPrice').value = p.price;
    $('fCategory').value = p.category || 'pdf';
    $('fStock').value = p.stock;
    $('fPages').value = p.pages;
    $('fBadge').value = p.badge || '';
    $('fBadgeColor').value = p.badge_color || '#ef4444';
    $('fBadgeColorText').value = p.badge_color || '#ef4444';
    $('fRating').value = p.rating;
    $('fSales').value = p.sales;
    $('fPreviewText').value = p.preview_text || '';
    $('fStatus').disabled = <?php echo $isAdmin ? 'false' : 'true'; ?>;
    $('fStatus').value = p.status || 'pending';
    if (p.image) {
        $('currentImage').classList.remove('hidden');
        $('currentImagePreview').src = p.image;
    } else $('currentImage').classList.add('hidden');
    if (p.file_path) {
        $('currentFile').classList.remove('hidden');
        $('currentFileName').textContent = 'Attached (' + (p.file_type || 'file') + ')';
    } else $('currentFile').classList.add('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ---- Search + filter client-side ---- */
const searchInput = $('searchProducts');
const filterSel = $('filterStatus');
function applyFilters() {
    const q = (searchInput.value || '').toLowerCase().trim();
    const s = filterSel.value;
    let visible = 0;
    document.querySelectorAll('.product-row').forEach(row => {
        const matchQ = !q || (row.dataset.search || '').includes(q);
        const matchS = !s || row.dataset.status === s;
        const show = matchQ && matchS;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    $('noProducts').classList.toggle('hidden', visible > 0);
}
searchInput.addEventListener('input', applyFilters);
filterSel.addEventListener('change', applyFilters);

/* ---- Intercept delete form submits to fetch via JSON ---- */
document.querySelectorAll('button[formaction="../handlers/product_handler.php"]').forEach(btn => {
    const form = btn.closest('form');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('csrf_token', csrfToken);
        fetch(HANDLER_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) window.location.reload();
            })
            .catch(err => alert('Network error: ' + err));
    });
});
</script>
<?php require_once __DIR__ . '/layout/footer.php'; ?>
