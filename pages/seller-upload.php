<?php
$db = Database::getInstance()->getConnection();
$viewerId = (int)($_SESSION['user_id'] ?? 0);
$viewerRole = $_SESSION['role'] ?? 'user';
$sellerRoles = ['merchant', 'seller'];
$adminRoles = ['admin', 'moderator', 'super_admin'];
$isSeller = in_array($viewerRole, $sellerRoles, true) || in_array($viewerRole, $adminRoles, true);

if (!$isSeller) {
    echo '<div class="min-h-screen flex items-center justify-center p-8">
        <div class="text-center max-w-lg">
            <div class="text-7xl mb-4 opacity-60">🔐</div>
            <h1 class="text-3xl font-bold mb-3">Seller Access Required</h1>
            <p class="text-gray-500 mb-6">You need a Seller account to upload products. Contact an admin to upgrade your role.</p>
            <a href="index.php?page=products" class="inline-block px-6 py-3 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transition">
                <i class="fas fa-store mr-2"></i>Browse Products
            </a>
        </div>
    </div>';
    return;
}

$myProducts = [];
try {
    $stmt = $db->prepare("SELECT * FROM products WHERE author_id = ? ORDER BY created_at DESC");
    $stmt->execute([$viewerId]);
    $myProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$myProducts = array_map(function($p) {
    $p['id'] = (int)$p['id'];
    $p['price'] = (float)$p['price'];
    $p['rating'] = (float)$p['rating'];
    $p['sales'] = (int)($p['sales'] ?? 0);
    $p['pages'] = (int)$p['pages'];
    $p['stock'] = (int)$p['stock'];
    return $p;
}, $myProducts);

$totalEarnings = 0;
$totalSales = 0;
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(author_earnings),0) AS total, COUNT(*) AS cnt FROM product_purchases pp JOIN products p ON p.id = pp.product_id WHERE p.author_id = ? AND pp.status = 'completed'");
    $stmt->execute([$viewerId]);
    $row = $stmt->fetch();
    $totalEarnings = (float)($row['total'] ?? 0);
    $totalSales = (int)($row['cnt'] ?? 0);
} catch (Throwable $e) {}
?>
<div class="dp-wrap">
    <section class="dp-hero">
        <div class="dp-hero-bg"></div>
        <div class="dp-hero-inner">
            <span class="dp-kicker"><i class="fas fa-user-tie"></i> Seller Dashboard</span>
            <h1>Sell Your Guides &amp; Products</h1>
            <p>Upload game guides, tutorials and PDFs. Earn <strong>70% revenue</strong> on every sale.</p>
            <div class="dp-hero-stats" style="margin-top:1.5rem">
                <div class="dp-hero-stat"><strong><?php echo count($myProducts); ?></strong><span>Products</span></div>
                <div class="dp-hero-stat"><strong><?php echo $totalSales; ?></strong><span>Sales</span></div>
                <div class="dp-hero-stat"><strong>৳<?php echo number_format($totalEarnings); ?></strong><span>Earnings</span></div>
            </div>
        </div>
    </section>

    <nav class="dp-nav">
        <a href="#upload-section" class="dp-nav-link dp-nav-link--pdf">
            <span class="dp-nav-icon"><i class="fas fa-cloud-upload"></i></span>
            <span class="dp-nav-label">Upload New</span>
        </a>
        <a href="#my-products-section" class="dp-nav-link dp-nav-link--topup">
            <span class="dp-nav-icon"><i class="fas fa-box-stacked"></i></span>
            <span class="dp-nav-label">My Products</span>
            <span class="dp-nav-count"><?php echo count($myProducts); ?></span>
        </a>
    </nav>

    <!-- UPLOAD SECTION -->
    <section class="dp-section" id="upload-section">
        <div class="dp-section-hd">
            <div>
                <span class="dp-section-tag" style="--tag-color:#8b5cf6;--tag-bg:rgba(139,92,246,0.12)"><i class="fas fa-cloud-upload"></i> Upload Product</span>
                <h2 id="formTitle">List a New Product</h2>
                <p>Fill in details and upload a cover image + PDF file. New seller submissions go to <strong>pending</strong> until an admin approves.</p>
            </div>
        </div>

        <div class="max-w-4xl mx-auto bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 md:p-8">
            <form id="sellerProductForm" method="POST" enctype="multipart/form-data" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" id="sellerAction" value="add_product">
                <input type="hidden" name="id" id="sellerProductId" value="">

                <div class="grid md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Product Title *</label>
                        <input type="text" name="name" id="sName" required maxlength="150"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent" placeholder="e.g. Pro Free Fire Rank Push Guide">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Price (৳) *</label>
                        <input type="number" name="price" id="sPrice" required min="0" step="0.01"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent" placeholder="0">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Category</label>
                        <select name="category" id="sCategory"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                            <option value="pdf">PDF Guide</option>
                            <option value="digital">Digital Product</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Pages (PDF)</label>
                        <input type="number" name="pages" id="sPages" min="0" value="0"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Stock (optional)</label>
                        <input type="number" name="stock" id="sStock" min="0" value="0"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Short Description (1 line shown in cards)</label>
                        <input type="text" name="short_desc" id="sShortDesc" maxlength="255"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent" placeholder="Short, catchy summary">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Full Description</label>
                        <textarea name="description" id="sDescription" rows="4"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent" placeholder="Tell buyers what they will learn..."></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Free Preview Text <small class="text-gray-400 font-normal">(shown before purchase)</small></label>
                        <textarea name="preview_text" id="sPreviewText" rows="4"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent" placeholder="Give a sneak peek of the first chapter / intro..."></textarea>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-5 pt-2">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Promo Badge</label>
                        <input type="text" name="badge" id="sBadge" maxlength="50"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent" placeholder="e.g. Hot / New / Best">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Badge Color</label>
                        <div class="flex gap-2">
                            <input type="color" name="badge_color" id="sBadgeColor" value="#ef4444"
                                class="w-12 h-[50px] rounded-xl border border-gray-300 dark:border-gray-600 cursor-pointer">
                            <input type="text" id="sBadgeColorText" value="#ef4444"
                                class="flex-1 px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 font-mono text-sm text-gray-800 dark:text-white">
                        </div>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            Cover Image <small class="text-gray-400 font-normal">(JPG/PNG/WebP · max 8MB)</small>
                        </label>
                        <div class="border-2 border-dashed border-purple-300 dark:border-purple-800 rounded-2xl p-6 hover:bg-purple-50 dark:hover:bg-purple-900/10 transition">
                            <input type="file" name="image" id="sImage" accept="image/*"
                                class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300">
                            <div id="sellerCurrentImage" class="mt-4 hidden">
                                <div class="text-xs text-gray-500 mb-2">Current:</div>
                                <img id="sellerCurrentImagePreview" src="" alt="" class="w-full h-40 object-cover rounded-xl border">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            PDF File <small class="text-gray-400 font-normal">(max 50MB)</small>
                        </label>
                        <div class="border-2 border-dashed border-red-300 dark:border-red-900 rounded-2xl p-6 hover:bg-red-50 dark:hover:bg-red-900/10 transition">
                            <input type="file" name="file" id="sFile" accept="application/pdf"
                                class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-red-50 file:text-red-700 hover:file:bg-red-100 dark:file:bg-red-900/30 dark:file:text-red-300">
                            <div id="sellerCurrentFile" class="mt-4 hidden text-sm text-gray-600 dark:text-gray-300">
                                <i class="fas fa-file-pdf text-red-500 mr-2"></i><span id="sellerCurrentFileName"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="submit" id="sellerSubmitBtn"
                        class="px-8 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transition">
                        <i class="fas fa-cloud-upload-alt mr-2"></i><span id="sellerSubmitText">Submit Product</span>
                    </button>
                    <button type="button" id="sellerCancelBtn" onclick="resetSellerForm()"
                        class="px-8 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-white rounded-xl font-semibold hover:bg-gray-300 transition hidden">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </section>

    <!-- MY PRODUCTS SECTION -->
    <section class="dp-section" id="my-products-section">
        <div class="dp-section-hd">
            <div>
                <span class="dp-section-tag"><i class="fas fa-box-stacked"></i> My Products</span>
                <h2>Manage Your Listings</h2>
                <p>Edit, delete, or view PDFs for products you have uploaded.</p>
            </div>
            <div class="dp-section-hd-stats">
                <div class="dp-hd-stat"><strong><?php echo count($myProducts); ?></strong> Total</div>
                <div class="dp-hd-stat"><strong><?php echo array_reduce($myProducts, fn($s,$p)=>$s+($p['status']==='active'?1:0), 0); ?></strong> Live</div>
            </div>
        </div>

        <?php if (!$myProducts): ?>
        <div class="max-w-2xl mx-auto text-center py-16 border-2 border-dashed rounded-3xl border-gray-300 dark:border-gray-700">
            <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
            <h3 class="text-xl font-bold mb-2">No products yet</h3>
            <p class="text-gray-500 mb-6">Publish your first guide and start earning!</p>
            <a href="#upload-section" class="inline-block px-6 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl font-semibold shadow">
                <i class="fas fa-plus mr-2"></i>Upload First Product
            </a>
        </div>
        <?php else: ?>
        <div class="dp-pdf-grid">
            <?php foreach ($myProducts as $product): ?>
            <article class="dp-pdf-card" data-product-id="<?php echo $product['id']; ?>">
                <?php if ($product['badge']): ?>
                    <span class="dp-pdf-badge" style="--badge: <?php echo htmlspecialchars($product['badge_color'] ?? '#ef4444'); ?>"><?php echo htmlspecialchars($product['badge']); ?></span>
                <?php endif; ?>
                <span class="dp-pdf-badge" style="--badge: <?php echo $product['status']==='active'?'#10b981':($product['status']==='pending'?'#f59e0b':'#6b7280'); ?>; right:auto; left:12px;">
                    <?php echo htmlspecialchars(ucfirst($product['status'])); ?>
                </span>
                <div class="dp-pdf-media">
                    <?php if ($product['image']): ?>
                    <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="" loading="lazy" onerror="this.src='https://picsum.photos/seed/<?php echo $product['id']; ?>/400/300'">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-200 to-gray-400 text-white text-4xl"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                    <div class="dp-pdf-media-overlay">
                        <span class="dp-pdf-pages"><i class="fas fa-cart-shopping"></i> <?php echo $product['sales']; ?> sold</span>
                    </div>
                </div>
                <div class="dp-pdf-body">
                    <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                    <p><?php echo htmlspecialchars($product['short_desc'] ?? ''); ?></p>
                    <div class="dp-pdf-rating">
                        <span class="dp-stars">
                            <?php for ($i=1;$i<=5;$i++): ?>
                                <i class="fas fa-star <?php echo $i<=floor($product['rating'])?'filled':''; ?>"></i>
                            <?php endfor; ?>
                        </span>
                        <span class="dp-rating-val"><?php echo $product['rating']; ?></span>
                    </div>
                    <div class="dp-pdf-footer">
                        <div class="dp-pdf-price">
                            <strong>৳<?php echo number_format($product['price']); ?></strong>
                            <small>You earn ৳<?php echo number_format(round($product['price']*0.7)); ?></small>
                        </div>
                        <div class="dp-pdf-actions">
                            <?php if ($product['file_type']==='pdf'): ?>
                                <a href="handlers/product_handler.php?action=download_pdf&amp;product_id=<?php echo $product['id']; ?>" target="_blank"
                                    class="dp-btn-preview" style="text-decoration:none"><i class="fas fa-eye"></i> View</a>
                            <?php endif; ?>
                            <button type="button" class="dp-btn-preview" onclick="editSellerProduct(<?php echo $product['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                            <form method="POST" onsubmit="return confirm('Delete this product? This cannot be undone.')" class="inline" id="delForm-<?php echo $product['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
                                <button type="button" onclick="doDeleteSeller(<?php echo $product['id']; ?>)" class="dp-btn-buy" style="background:#ef4444"><i class="fas fa-trash"></i> Del</button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</div>

<!-- Toast -->
<div class="dp-toast" id="dpToast"><div class="dp-toast-inner"><i class="fas fa-check-circle" style="color:#10b981"></i><span id="dpToastMsg">Done!</span></div></div>

<script>
(function(){
    const HANDLER = 'handlers/product_handler.php';
    const csrf = '<?php echo htmlspecialchars($csrf_token); ?>';
    const myProducts = <?php echo json_encode($myProducts, JSON_UNESCAPED_UNICODE); ?>;
    const byId = Object.fromEntries(myProducts.map(p => [p.id, p]));

    const showToast = (msg, ok=true) => {
        var t = document.getElementById('dpToast');
        var icon = t.querySelector('i');
        icon.className = 'fas ' + (ok ? 'fa-check-circle' : 'fa-exclamation-circle');
        icon.style.color = ok ? '#10b981' : '#ef4444';
        document.getElementById('dpToastMsg').textContent = msg;
        t.classList.add('active');
        setTimeout(()=>t.classList.remove('active'), 4000);
    };

    const bc = document.getElementById('sBadgeColor');
    const bct = document.getElementById('sBadgeColorText');
    bc.addEventListener('input', () => bct.value = bc.value);
    bct.addEventListener('input', () => bc.value = bct.value);

    window.resetSellerForm = function() {
        document.getElementById('sellerProductForm').reset();
        document.getElementById('sellerAction').value = 'add_product';
        document.getElementById('sellerProductId').value = '';
        document.getElementById('formTitle').textContent = 'List a New Product';
        document.getElementById('sellerSubmitText').textContent = 'Submit Product';
        document.getElementById('sellerCancelBtn').classList.add('hidden');
        document.getElementById('sellerCurrentImage').classList.add('hidden');
        document.getElementById('sellerCurrentFile').classList.add('hidden');
        document.getElementById('sBadgeColor').value = '#ef4444';
        document.getElementById('sBadgeColorText').value = '#ef4444';
        location.hash = '#upload-section';
        window.scrollTo({top: 0, behavior: 'smooth'});
    };

    window.editSellerProduct = function(id) {
        const p = byId[id];
        if (!p) return;
        document.getElementById('sellerAction').value = 'update_product';
        document.getElementById('sellerProductId').value = p.id;
        document.getElementById('formTitle').textContent = 'Edit Product (#' + p.id + ')';
        document.getElementById('sellerSubmitText').textContent = 'Update Product';
        document.getElementById('sellerCancelBtn').classList.remove('hidden');
        document.getElementById('sName').value = p.name || '';
        document.getElementById('sDescription').value = p.description || '';
        document.getElementById('sShortDesc').value = p.short_desc || '';
        document.getElementById('sPrice').value = p.price;
        document.getElementById('sCategory').value = p.category || 'pdf';
        document.getElementById('sStock').value = p.stock;
        document.getElementById('sPages').value = p.pages;
        document.getElementById('sBadge').value = p.badge || '';
        document.getElementById('sBadgeColor').value = p.badge_color || '#ef4444';
        document.getElementById('sBadgeColorText').value = p.badge_color || '#ef4444';
        document.getElementById('sPreviewText').value = p.preview_text || '';
        if (p.image) {
            document.getElementById('sellerCurrentImage').classList.remove('hidden');
            document.getElementById('sellerCurrentImagePreview').src = p.image;
        } else {
            document.getElementById('sellerCurrentImage').classList.add('hidden');
        }
        if (p.file_path) {
            document.getElementById('sellerCurrentFile').classList.remove('hidden');
            document.getElementById('sellerCurrentFileName').textContent = 'Attached (' + (p.file_type || 'pdf') + ')';
        } else {
            document.getElementById('sellerCurrentFile').classList.add('hidden');
        }
        location.hash = '#upload-section';
        window.scrollTo({top: 0, behavior: 'smooth'});
    };

    window.doDeleteSeller = function(id) {
        if (!confirm('Delete this product? Files will be permanently removed.')) return;
        const fd = new FormData();
        fd.append('action', 'delete_product');
        fd.append('id', String(id));
        fd.append('csrf_token', csrf);
        const btn = document.querySelector('#delForm-' + id + ' button');
        btn.disabled = true;
        fetch(HANDLER, { method:'POST', body: fd })
            .then(r=>r.json())
            .then(data => {
                showToast(data.message, !!data.success);
                if (data.success) setTimeout(()=>location.reload(), 800);
                else btn.disabled = false;
            })
            .catch(err => { btn.disabled = false; showToast('Network error', false); });
    };

    document.getElementById('sellerProductForm').addEventListener('submit', function(e){
        e.preventDefault();
        const fd = new FormData(this);
        const btn = document.getElementById('sellerSubmitBtn');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
        fetch(HANDLER, { method:'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = orig;
                showToast(data.message || (data.success ? 'Saved' : 'Failed'), !!data.success);
                if (data.success) setTimeout(()=>location.reload(), 900);
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = orig;
                showToast('Network error', false);
            });
    });
})();
</script>
