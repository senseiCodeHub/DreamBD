<?php
$pageTitle = 'Community Hero Banner';
$pageHeading = 'Community Hero Banner';
$currentPage = 'community-hero';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => 'index.php'],
    ['label' => 'Community Hero Banner']
];

require_once __DIR__ . '/layout/header.php';

$db = Database::getInstance()->getConnection();
$messages = [];
$errors = [];

$cmHeroDefaults = [
    'enabled'    => '1',
    'pill'       => 'DREAMBD COMMUNITY',
    'heading'    => 'Connect, share &',
    'accent'     => 'grow together',
    'sub'        => 'Post updates, react to moments and meet players from all over DreamBD — all in one feed.',
    'btn1_text'  => '',
    'btn1_url'   => '',
    'btn2_text'  => '',
    'btn2_url'   => '',
];

$cmCleanUrl = static function ($raw): string {
    $v = trim((string) $raw);
    if ($v === '') return '';
    if (preg_match('~^(https?://|index\.php|/|\#|\?|mailto:)~i', $v)) {
        return mb_substr($v, 0, 255);
    }
    return '';
};

// ─── Handle form submissions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_community_hero') {
            $values = [
                'community_hero_enabled'   => (($_POST['enabled'] ?? '0') === '1') ? '1' : '0',
                'community_hero_pill'      => mb_substr(trim(strip_tags($_POST['pill'] ?? '')), 0, 80),
                'community_hero_heading'   => mb_substr(trim(strip_tags($_POST['heading'] ?? '')), 0, 160),
                'community_hero_accent'    => mb_substr(trim(strip_tags($_POST['accent'] ?? '')), 0, 160),
                'community_hero_sub'       => mb_substr(trim(strip_tags($_POST['sub'] ?? '')), 0, 300),
                'community_hero_btn1_text' => mb_substr(trim(strip_tags($_POST['btn1_text'] ?? '')), 0, 60),
                'community_hero_btn1_url'  => $cmCleanUrl($_POST['btn1_url'] ?? ''),
                'community_hero_btn2_text' => mb_substr(trim(strip_tags($_POST['btn2_text'] ?? '')), 0, 60),
                'community_hero_btn2_url'  => $cmCleanUrl($_POST['btn2_url'] ?? ''),
            ];
            try {
                $stmt = $db->prepare("INSERT INTO site_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
                foreach ($values as $k => $v) {
                    $stmt->execute([$k, $v]);
                }
                $messages[] = 'Hero banner saved! Changes are live on the Community page.';
            } catch (Throwable $e) {
                $errors[] = 'Could not save hero banner settings.';
            }
        }

        if ($action === 'reset_community_hero') {
            try {
                $db->exec("DELETE FROM site_settings WHERE `key` LIKE 'community_hero_%'");
                $messages[] = 'Hero banner reset to default content.';
            } catch (Throwable $e) {
                $errors[] = 'Could not reset hero banner settings.';
            }
        }
    }
}

// ─── Load current settings ───
$cmHero = $cmHeroDefaults;
try {
    $stmt = $db->query("SELECT `key`, `value` FROM site_settings WHERE `key` LIKE 'community_hero_%'");
    while ($row = $stmt->fetch()) {
        $short = substr($row['key'], strlen('community_hero_'));
        if (array_key_exists($short, $cmHeroDefaults)) {
            $cmHero[$short] = (string) $row['value'];
        }
    }
} catch (Throwable $e) {}

$cmHeroOn = $cmHero['enabled'] !== '0';
$ph = function ($k) use ($cmHeroDefaults) { return htmlspecialchars($cmHeroDefaults[$k]); };
?>

<?php foreach ($messages as $msg): ?>
<div class="mb-4 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-400 fade-in text-sm font-semibold flex items-center gap-2.5">
    <i class="fas fa-check-circle"></i><?php echo htmlspecialchars($msg); ?>
</div>
<?php endforeach; ?>
<?php foreach ($errors as $err): ?>
<div class="mb-4 p-4 rounded-2xl bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-400 fade-in text-sm font-semibold flex items-center gap-2.5">
    <i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($err); ?>
</div>
<?php endforeach; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">

    <!-- Editor Form -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white">
                <i class="fas fa-image text-purple-500 mr-2"></i>Banner Settings
            </h2>
            <span id="heroStatusBadge" class="text-[11px] font-bold px-3 py-1 rounded-full <?php echo $cmHeroOn ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400' : 'bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400'; ?>">
                <?php echo $cmHeroOn ? 'VISIBLE' : 'HIDDEN'; ?>
            </span>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save_community_hero">

            <!-- Show / Hide Toggle -->
            <div class="mb-6 flex items-center justify-between gap-4 p-4 rounded-xl bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700">
                <div>
                    <div class="text-sm font-bold text-gray-800 dark:text-white">Show banner on Community page</div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Off = hero section fully hidden (stats &amp; CTA included)</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="checkbox" name="enabled" value="1" class="sr-only peer" <?php echo $cmHeroOn ? 'checked' : ''; ?> onchange="toggleHeroStatus(this)">
                    <div class="w-11 h-6 bg-gray-300 dark:bg-gray-600 rounded-full peer peer-checked:bg-blue-600 peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
                </label>
            </div>

            <div class="space-y-4 mb-6">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Pill / Badge Text</label>
                        <input type="text" name="pill" maxlength="80"
                               value="<?php echo htmlspecialchars($cmHero['pill']); ?>"
                               placeholder="<?php echo $ph('pill'); ?>"
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-gray-800 dark:text-white"
                               oninput="updateHeroPreview()">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Gradient Part (accent)</label>
                        <input type="text" name="accent" maxlength="160"
                               value="<?php echo htmlspecialchars($cmHero['accent']); ?>"
                               placeholder="<?php echo $ph('accent'); ?>"
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-gray-800 dark:text-white"
                               oninput="updateHeroPreview()">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Heading (white part)</label>
                    <input type="text" name="heading" maxlength="160"
                           value="<?php echo htmlspecialchars($cmHero['heading']); ?>"
                           placeholder="<?php echo $ph('heading'); ?>"
                           class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-gray-800 dark:text-white"
                           oninput="updateHeroPreview()">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subtitle</label>
                    <textarea name="sub" rows="2" maxlength="300"
                              placeholder="<?php echo $ph('sub'); ?>"
                              class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-gray-800 dark:text-white"
                              oninput="updateHeroPreview()"><?php echo htmlspecialchars($cmHero['sub']); ?></textarea>
                </div>

                <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                    <div class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3"><i class="fas fa-hand-pointer mr-1"></i>Action Buttons</div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Button 1 Text</label>
                            <input type="text" name="btn1_text" maxlength="60"
                                   value="<?php echo htmlspecialchars($cmHero['btn1_text']); ?>"
                                   placeholder="Join the community / Create post"
                                   class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-white"
                                   oninput="updateHeroPreview()">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Button 1 Link</label>
                            <input type="text" name="btn1_url" maxlength="255"
                                   value="<?php echo htmlspecialchars($cmHero['btn1_url']); ?>"
                                   placeholder="index.php?page=register"
                                   class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-white font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Button 2 Text</label>
                            <input type="text" name="btn2_text" maxlength="60"
                                   value="<?php echo htmlspecialchars($cmHero['btn2_text']); ?>"
                                   placeholder="Sign in / Find friends"
                                   class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-white"
                                   oninput="updateHeroPreview()">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Button 2 Link</label>
                            <input type="text" name="btn2_url" maxlength="255"
                                   value="<?php echo htmlspecialchars($cmHero['btn2_url']); ?>"
                                   placeholder="index.php?page=login"
                                   class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-white font-mono">
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-2">
                        Khali rakhle default kaj korbe — guest: Register/Login, member: Create-post box / Find friends. Link dile shetai beat hobe.
                    </p>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors font-semibold">
                    <i class="fas fa-save mr-2"></i>Save Banner
                </button>
            </div>
        </form>

        <form method="POST" action="" class="mt-3" onsubmit="return confirm('Reset banner content to original defaults?');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="reset_community_hero">
            <button type="submit" class="px-5 py-2.5 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg transition-colors text-sm font-semibold">
                <i class="fas fa-rotate-left mr-2"></i>Reset to Default
            </button>
        </form>
    </div>

    <!-- Live Preview -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">
            <i class="fas fa-eye text-blue-500 mr-2"></i>Live Preview
        </h2>

        <div id="heroPreviewBox" class="rounded-2xl overflow-hidden relative <?php echo $cmHeroOn ? '' : 'opacity-40'; ?>" style="background: linear-gradient(135deg,#0f172a,#1e1b4b,#1a0533);">
            <div class="px-6 py-8 text-center">
                <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/15 text-white/80 text-[11px] font-bold tracking-wider uppercase">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                    <span id="prevPill"><?php echo htmlspecialchars($cmHero['pill'] !== '' ? $cmHero['pill'] : $cmHeroDefaults['pill']); ?></span>
                </span>
                <h3 class="text-2xl font-black text-white mt-4 mb-2 leading-tight">
                    <span id="prevHeading"><?php echo htmlspecialchars($cmHero['heading'] !== '' ? $cmHero['heading'] : $cmHeroDefaults['heading']); ?></span>
                    <span id="prevAccent" class="cm-hero-accent" style="background: linear-gradient(90deg,#a78bfa,#60a5fa,#34d399); -webkit-background-clip:text; background-clip:text; color:transparent;"><?php echo htmlspecialchars($cmHero['accent'] !== '' ? $cmHero['accent'] : $cmHeroDefaults['accent']); ?></span>
                </h3>
                <p id="prevSub" class="text-sm text-slate-300 max-w-lg mx-auto mb-5"><?php echo htmlspecialchars($cmHero['sub'] !== '' ? $cmHero['sub'] : $cmHeroDefaults['sub']); ?></p>
                <div class="flex items-center justify-center gap-3">
                    <span class="px-5 py-2.5 rounded-full bg-purple-600 text-white text-sm font-bold"><i class="fas fa-pen-to-square mr-1.5"></i><span id="prevBtn1"><?php echo htmlspecialchars($cmHero['btn1_text'] !== '' ? $cmHero['btn1_text'] : 'Create post'); ?></span></span>
                    <span class="px-5 py-2.5 rounded-full bg-white/10 border border-white/20 text-white text-sm font-bold"><i class="fas fa-user-group mr-1.5"></i><span id="prevBtn2"><?php echo htmlspecialchars($cmHero['btn2_text'] !== '' ? $cmHero['btn2_text'] : 'Find friends'); ?></span></span>
                </div>
            </div>
        </div>

        <div class="mt-5 p-4 rounded-xl bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
            <i class="fas fa-circle-info text-blue-500 mr-1"></i>
            Preview-ta roughly dekhacche. Heading er white part + gradient accent alada kore edit korte paro. Stats row (Members / Online / Posts) shob somoy theke thake — sheta banner er sathe hide hobe.
        </div>
    </div>
</div>

<script>
function updateHeroPreview() {
    const f = (n) => document.querySelector('[name="' + n + '"]');
    const d = <?php echo json_encode($cmHeroDefaults); ?>;
    const pill = f('pill'), heading = f('heading'), accent = f('accent'), sub = f('sub'),
          b1 = f('btn1_text'), b2 = f('btn2_text');
    const set = (id, val, fallback) => {
        const el = document.getElementById(id);
        if (el) el.textContent = (val && val.value.trim() !== '') ? val.value : (fallback || '');
    };
    set('prevPill', pill, d.pill);
    set('prevHeading', heading, d.heading);
    set('prevAccent', accent, d.accent);
    set('prevSub', sub, d.sub);
    set('prevBtn1', b1, 'Create post');
    set('prevBtn2', b2, 'Find friends');
}
function toggleHeroStatus(cb) {
    const badge = document.getElementById('heroStatusBadge');
    const box = document.getElementById('heroPreviewBox');
    if (badge) {
        badge.textContent = cb.checked ? 'VISIBLE' : 'HIDDEN';
        badge.className = 'text-[11px] font-bold px-3 py-1 rounded-full ' + (cb.checked
            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
            : 'bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400');
    }
    if (box) box.classList.toggle('opacity-40', !cb.checked);
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
