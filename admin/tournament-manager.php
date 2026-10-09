<?php
$pageTitle = 'Tournament Manager';
$pageHeading = 'Tournament Manager';
$currentPage = 'tournaments';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => 'index.php'],
    ['label' => 'Tournaments']
];
require_once __DIR__ . '/layout/header.php';
$db = Database::getInstance()->getConnection();
$messages = []; $errors = [];
$editItem = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add' || $action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);
                $data = [
                    trim($_POST['title'] ?? ''),
                    trim($_POST['description'] ?? ''),
                    trim($_POST['prize_money'] ?? ''),
                    trim($_POST['category'] ?? ''),
                    (int) ($_POST['max_teams'] ?? 0),
                    trim($_POST['game_icon'] ?? 'fa-gamepad'),
                    trim($_POST['accent_color'] ?? '#7c3aed'),
                    trim($_POST['starts_at'] ?: null),
                    trim($_POST['status'] ?? 'upcoming'),
                    in_array($_POST['bracket_type'] ?? '', ['single_elimination', 'double_elimination', 'round_robin'], true) ? $_POST['bracket_type'] : 'single_elimination',
                    (int) ($_POST['best_of'] ?? 1) ?: 1,
                    (int) ($_POST['checkin_minutes'] ?? 30) ?: 30,
                    trim($_POST['rules'] ?? ''),
                ];
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO tournaments (title, description, prize_money, category, max_teams, game_icon, accent_color, starts_at, status, bracket_type, best_of, checkin_minutes, rules) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute($data);
                    $messages[] = 'Tournament created!';
                } else {
                    $data[] = $id;
                    $stmt = $db->prepare("UPDATE tournaments SET title=?, description=?, prize_money=?, category=?, max_teams=?, game_icon=?, accent_color=?, starts_at=?, status=?, bracket_type=?, best_of=?, checkin_minutes=?, rules=? WHERE id=?");
                    $stmt->execute($data);
                    $messages[] = 'Tournament updated!';
                }
            }
            if ($action === 'delete') {
                $tid = (int)($_POST['id'] ?? 0);
                if ($tid > 0) {
                    $db->beginTransaction();
                    // Refund escrowed fees (participants) back to users
                    $st = $db->prepare("SELECT tp.user_id, t.entry_fee FROM tournament_participants tp JOIN tournaments t ON t.id = tp.tournament_id WHERE tp.tournament_id = ? AND tp.status = 'confirmed'");
                    $st->execute([$tid]);
                    $feeRow = $st->fetch();
                    $fee = $feeRow ? (float)$feeRow['entry_fee'] : 0.0;
                    if ($fee > 0) {
                        $st2 = $db->prepare("SELECT user_id FROM tournament_participants WHERE tournament_id = ? AND status = 'confirmed'");
                        $st2->execute([$tid]);
                        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $p) {
                            $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$fee, (int)$p['user_id']]);
                        }
                        // Return escrowed fees to agent (or zero if not collected)
                        $db->prepare("UPDATE tournaments SET fee_escrow = GREATEST(fee_escrow - ?, 0) WHERE id = ?")->execute([$fee * $st2->rowCount(), $tid]);
                    }
                    // Return prize escrow to agent balance
                    $pr = $db->prepare("SELECT agent_id, prize_escrow FROM tournaments WHERE id = ? FOR UPDATE");
                    $pr->execute([$tid]);
                    $prow = $pr->fetch();
                    if ($prow && (float)$prow['prize_escrow'] > 0 && (int)$prow['agent_id'] > 0) {
                        $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([(float)$prow['prize_escrow'], (int)$prow['agent_id']]);
                    }
                    // Remove children first (no orphans)
                    foreach (['tournament_matches', 'tournament_participants', 'tournament_results', 'tournament_chat_messages', 'tournament_room_messages', 'tournament_player_leaderboard'] as $child) {
                        try { $db->prepare("DELETE FROM {$child} WHERE tournament_id = ?")->execute([$tid]); } catch (PDOException $e) { /* table may not exist */ }
                    }
                    $db->prepare("DELETE FROM notifications WHERE entity_id = ? AND type LIKE 'tournament%'")->execute([$tid]);
                    $db->prepare("DELETE FROM tournaments WHERE id = ?")->execute([$tid]);
                    $db->commit();
                    $messages[] = 'Tournament deleted, all money returned.';
                }
            }
            if ($action === 'set_live') {
                $stmt = $db->prepare("UPDATE tournaments SET status='live', starts_at=COALESCE(starts_at, NOW()) WHERE id=? AND status='upcoming'");
                $stmt->execute([(int)($_POST['id'] ?? 0)]);
                if ($stmt->rowCount()) {
                    $messages[] = 'Tournament is now LIVE!';
                } else {
                    $errors[] = 'Tournament must be upcoming to go live.';
                }
            }
            if ($action === 'edit') {
                $stmt = $db->prepare("SELECT * FROM tournaments WHERE id=?");
                $stmt->execute([(int)($_POST['id'] ?? 0)]);
                $editItem = $stmt->fetch();
            }
        } catch (PDOException $e) { $errors[] = $e->getMessage(); }
    }
}

$items = [];
try {
    $stmt = $db->query("SELECT t.*,
        (SELECT COUNT(*) FROM tournament_participants tp WHERE tp.tournament_id = t.id AND tp.status = 'confirmed') AS reg_count,
        (SELECT COUNT(*) FROM tournament_matches tm WHERE tm.tournament_id = t.id) AS match_count,
        a.username AS agent_name
        FROM tournaments t LEFT JOIN users a ON a.id = t.agent_id
        ORDER BY COALESCE(t.starts_at, t.created_at) DESC");
    $items = $stmt->fetchAll();
} catch (PDOException $e) { $errors[] = 'Table not found.'; }
?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 slide-in">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">
            <i class="fas fa-<?php echo $editItem ? 'edit' : 'plus'; ?> text-purple-500 mr-2"></i>
            <?php echo $editItem ? 'Edit Tournament' : 'Add Tournament'; ?>
        </h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="<?php echo $editItem ? 'update' : 'add'; ?>">
            <?php if ($editItem): ?>
            <input type="hidden" name="id" value="<?php echo $editItem['id']; ?>">
            <?php endif; ?>

            <div class="space-y-5">

                <!-- Title + Icon -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="md:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tournament Title</label>
                        <input type="text" name="title" required value="<?php echo htmlspecialchars($editItem['title'] ?? ''); ?>"
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Game Icon</label>
                        <input type="text" name="game_icon" value="<?php echo htmlspecialchars($editItem['game_icon'] ?? 'fa-gamepad'); ?>"
                               class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white font-mono text-sm"
                               placeholder="fa-gamepad" id="gameIconInput" oninput="updateGameIconPreview()">
                        <div class="flex gap-1.5 mt-1.5 flex-wrap">
                            <?php $gameIcons = ['fa-gamepad','fa-crosshairs','fa-chess','fa-dice','fa-joystick','fa-headset','fa-keyboard','fa-shield-halved','fa-bullseye','fa-skull','fa-fighter-jet','fa-gun','fa-swords','fa-axe','fa-bow-arrow']; ?>
                            <?php foreach ($gameIcons as $gi): ?>
                            <button type="button" onclick="setGameIcon('<?php echo $gi; ?>')" class="w-7 h-7 rounded-lg bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-500 hover:bg-purple-50 dark:hover:bg-purple-900/30 hover:border-purple-300 hover:text-purple-600 text-xs text-gray-600 dark:text-gray-300 flex items-center justify-center transition-all" title="<?php echo $gi; ?>" data-icon="<?php echo $gi; ?>">
                                <i class="fas <?php echo $gi; ?>"></i>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                    <textarea name="description" rows="3" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white"><?php echo htmlspecialchars($editItem['description'] ?? ''); ?></textarea>
                </div>

                <!-- Prize + Category + Teams -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Prize Money</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-semibold text-sm">৳</span>
                            <input type="text" name="prize_money" value="<?php echo htmlspecialchars($editItem['prize_money'] ?? ''); ?>"
                                   class="w-full pl-7 pr-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white"
                                   placeholder="1,200">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Game / Category</label>
                        <div class="relative">
                            <i class="fas fa-tag absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                            <select name="category" class="w-full pl-9 pr-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white appearance-none">
                                <option value="">Select category</option>
                                <?php $cats = ['PUBG','Free Fire','Valorant','Call of Duty','Fortnite','League of Legends','Dota 2','CS:GO','Overwatch','Apex Legends','Minecraft','Rocket League','FIFA','GTA Online','Other']; ?>
                                <?php foreach ($cats as $cat): ?>
                                <option value="<?php echo $cat; ?>" <?php echo ($editItem['category'] ?? '') === $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Max Teams</label>
                        <input type="number" name="max_teams" min="0" value="<?php echo (int) ($editItem['max_teams'] ?? 0); ?>"
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white"
                               placeholder="32">
                    </div>
                </div>

                <!-- Date + Status -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Start Date</label>
                        <input type="datetime-local" name="starts_at" value="<?php echo $editItem ? date('Y-m-d\TH:i', strtotime($editItem['starts_at'])) : ''; ?>"
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                        <select name="status" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                            <?php foreach (['upcoming', 'live', 'ongoing', 'completed', 'cancelled'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo ($editItem['status'] ?? 'upcoming') === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Bracket format + Best of + Check-in -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bracket Format</label>
                        <select name="bracket_type" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                            <option value="single_elimination" <?php echo ($editItem['bracket_type'] ?? '') === 'single_elimination' ? 'selected' : ''; ?>>Single Elimination</option>
                            <option value="double_elimination" <?php echo ($editItem['bracket_type'] ?? '') === 'double_elimination' ? 'selected' : ''; ?>>Double Elimination</option>
                            <option value="round_robin" <?php echo ($editItem['bracket_type'] ?? '') === 'round_robin' ? 'selected' : ''; ?>>Round Robin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Best Of</label>
                        <select name="best_of" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                            <?php foreach ([1 => 'BO1', 3 => 'BO3', 5 => 'BO5'] as $v => $lbl): ?>
                            <option value="<?php echo $v; ?>" <?php echo (int)($editItem['best_of'] ?? 1) === $v ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Check-in Window</label>
                        <select name="checkin_minutes" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white">
                            <?php foreach ([15 => '15 min before', 30 => '30 min before', 60 => '60 min before'] as $v => $lbl): ?>
                            <option value="<?php echo $v; ?>" <?php echo (int)($editItem['checkin_minutes'] ?? 30) === $v ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Rules -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rules</label>
                    <textarea name="rules" rows="2" class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-white" placeholder="Match rules, loadout restrictions, conduct..."><?php echo htmlspecialchars($editItem['rules'] ?? ''); ?></textarea>
                </div>

                <!-- Accent Color with Palette -->
                <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-4 border border-gray-200 dark:border-gray-600">
                    <div class="flex items-center gap-2 mb-3">
                        <i class="fas fa-palette text-purple-500 text-sm"></i>
                        <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Accent Color</span>
                    </div>
                    <div class="flex gap-2 mb-3">
                        <input type="color" name="accent_color" value="<?php echo htmlspecialchars($editItem['accent_color'] ?? '#7c3aed'); ?>"
                               class="w-9 h-9 rounded-lg cursor-pointer block border border-gray-300 dark:border-gray-500" id="tournamentAccent"
                               oninput="document.querySelector('input[name=\'accent_color_hex\']').value=this.value">
                        <input type="text" name="accent_color_hex" value="<?php echo htmlspecialchars($editItem['accent_color'] ?? '#7c3aed'); ?>"
                               class="w-24 px-3 py-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-500 rounded-lg text-gray-800 dark:text-white font-mono text-xs text-center"
                               oninput="document.querySelector('input[name=\'accent_color\']').value=this.value">
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <?php
                        $palette = ['#7c3aed','#6d28d9','#2563eb','#1d4ed8','#ec4899','#db2777','#ef4444','#dc2626','#f59e0b','#d97706','#10b981','#059669','#06b6d4','#0891b2','#f97316','#ea580c','#64748b','#475569','#1e293b'];
                        foreach ($palette as $c):
                        ?>
                        <button type="button" onclick="setTournamentColor('<?php echo $c; ?>')"
                                class="w-7 h-7 rounded-lg border-2 border-transparent hover:scale-110 hover:shadow-md transition-all shadow-sm"
                                style="background: <?php echo $c; ?>;" title="<?php echo $c; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Submit -->
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-xl font-semibold shadow-lg shadow-purple-500/20 transition-all hover:-translate-y-0.5">
                        <i class="fas fa-save mr-2"></i><?php echo $editItem ? 'Update Tournament' : 'Create Tournament'; ?>
                    </button>
                    <?php if ($editItem): ?>
                    <a href="tournament-manager.php" class="px-6 py-2.5 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-800 dark:text-white rounded-xl font-semibold transition-all">Cancel</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Tournaments List -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white">
                <i class="fas fa-trophy text-purple-500 mr-2"></i>Tournaments (<?php echo count($items); ?>)
            </h2>
        </div>
        <?php if ($items): ?>
        <div class="space-y-4">
            <?php foreach ($items as $item): ?>
            <div class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden hover:shadow-md transition-shadow">
                <div class="h-2" style="background: <?php echo htmlspecialchars($item['accent_color'] ?? '#7c3aed'); ?>;"></div>
                <div class="p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0">
                            <span class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0" style="background: <?php echo htmlspecialchars($item['accent_color'] ?? '#7c3aed'); ?>;">
                                <i class="fas <?php echo htmlspecialchars($item['game_icon'] ?? 'fa-gamepad'); ?>"></i>
                            </span>
                            <div class="min-w-0">
                                <strong class="text-gray-900 dark:text-white text-lg"><?php echo htmlspecialchars($item['title']); ?></strong>
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-sm text-gray-500">
                                    <?php if ($item['category']): ?>
                                    <span><i class="fas fa-tag text-xs"></i> <?php echo htmlspecialchars($item['category']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['prize_money']): ?>
                                    <span><i class="fas fa-coins text-xs"></i> ৳<?php echo htmlspecialchars($item['prize_money']); ?></span>
                                    <?php endif; ?>
                                    <?php if ((int) ($item['max_teams'] ?? 0) > 0): ?>
                                    <span><i class="fas fa-users text-xs"></i> <?php echo (int) $item['max_teams']; ?> teams</span>
                                    <?php endif; ?>
                                    <?php if ($item['starts_at']): ?>
                                    <span><i class="fas fa-calendar text-xs"></i> <?php echo date('M j, Y', strtotime($item['starts_at'])); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-diagram-project text-xs"></i> <?php echo htmlspecialchars(str_replace(['_elimination', 'round_'], '', $item['bracket_type'] ?? 'single')); ?></span>
                                    <span><i class="fas fa-user-check text-xs"></i> <?php echo (int)($item['reg_count'] ?? 0); ?> reg</span>
                                    <?php if ((int)($item['match_count'] ?? 0) > 0): ?>
                                    <span><i class="fas fa-server text-xs"></i> <?php echo (int)$item['match_count']; ?> matches</span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['agent_name'])): ?>
                                    <span><i class="fas fa-user-tie text-xs"></i> <?php echo htmlspecialchars($item['agent_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-wrap gap-x-3 gap-y-1 mt-1 text-xs font-mono">
                                    <span class="text-amber-600 dark:text-amber-400" title="Entry fees held in escrow"><i class="fas fa-lock"></i> fee ৳<?php echo number_format((float)($item['fee_escrow'] ?? 0), 2); ?></span>
                                    <span class="text-emerald-600 dark:text-emerald-400" title="Prize money held in escrow"><i class="fas fa-vault"></i> prize ৳<?php echo number_format((float)($item['prize_escrow'] ?? 0), 2); ?></span>
                                    <?php if ((float)($item['escrow_released'] ?? 0)): ?>
                                    <span class="text-gray-400"><i class="fas fa-check-double"></i> released</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($item['description']): ?>
                                <p class="text-xs text-gray-400 mt-1.5 line-clamp-1"><?php echo htmlspecialchars($item['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-2 shrink-0">
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold
                                <?php echo match($item['status']) {
                                    'live' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                    'upcoming' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                    'ongoing' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
                                    'completed' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
                                    default => 'bg-gray-100 text-gray-600',
                                }; ?>"><?php echo strtoupper($item['status']); ?></span>
                            <div class="flex gap-1">
                                <a href="../index.php?page=tournament&id=<?php echo $item['id']; ?>" target="_blank" class="p-2 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg transition-colors" title="View public page"><i class="fas fa-external-link-alt"></i></a>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"><i class="fas fa-edit"></i></button>
                                </form>
                                <?php if ($item['status'] === 'upcoming'): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="set_live">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button class="p-2 text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-900/20 rounded-lg transition-colors" title="Go Live"><i class="fas fa-broadcast-tower"></i></button>
                                </form>
                                <?php endif; ?>
                                <?php if (in_array($item['status'], ['completed', 'live'])): ?>
                                <a href="index.php?page=tournament-room&id=<?php echo $item['id']; ?>" class="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors" title="Submit Results" data-no-ajax>
                                    <i class="fas fa-medal"></i>
                                </a>
                                <?php endif; ?>
                                <form method="POST" onsubmit="return confirm('Delete tournament?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center text-gray-500 py-12">
            <i class="fas fa-trophy text-5xl mb-4 opacity-30"></i>
            <p class="font-medium">No tournaments yet</p>
            <p class="text-sm mt-1">Create your first tournament above.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function setGameIcon(icon) {
    document.getElementById('gameIconInput').value = icon;
    document.querySelectorAll('[data-icon]').forEach(b => {
        b.classList.toggle('active', b.dataset.icon === icon);
    });
}

function setTournamentColor(color) {
    document.querySelector('input[name="accent_color"]').value = color;
    document.querySelector('input[name="accent_color_hex"]').value = color;
    document.querySelectorAll('.color-swatch-t').forEach(b => {
        b.classList.toggle('active', b.dataset.color === color);
    });
}

function updateGameIconPreview() {
    const val = document.getElementById('gameIconInput').value;
    document.querySelectorAll('[data-icon]').forEach(b => {
        b.classList.toggle('active', b.dataset.icon === val);
    });
}

// Highlight saved icon + color on load
document.addEventListener('DOMContentLoaded', () => {
    const gi = document.getElementById('gameIconInput');
    if (gi) updateGameIconPreview();
    const ac = document.querySelector('input[name="accent_color"]')?.value;
    if (ac) {
        document.querySelectorAll('.color-swatch-t').forEach(b => {
            b.classList.toggle('active', b.dataset.color === ac);
        });
    }
});
</script>
<style>
[data-icon].active { background: #f3e8ff; border-color: #7c3aed !important; color: #7c3aed; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(124,58,237,0.15); }
.dark [data-icon].active { background: rgba(124,58,237,0.15); border-color: #a78bfa; color: #c4b5fd; }
.color-swatch-t { outline: none; }
.color-swatch-t.active { transform: scale(1.15); border-color: #7c3aed !important; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #7c3aed; }
.line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
</style>
<?php require_once __DIR__ . '/layout/footer.php'; ?>
