<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

$db = Database::getInstance()->getConnection();
$viewerId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? 'user';
$security = new Security();
$csrfToken = $security->generateCSRFToken();

$tournaments = [];
try { $tournaments = getTournamentsWithCounts($db, null, 50); } catch (Throwable $e) { $tournaments = []; }

$myRegistrations = [];
if ($viewerId) {
    try { $myRegistrations = getUserTournamentRegistrations($db, $viewerId); } catch (Throwable $e) {}
}

$myTeams = [];
if ($viewerId) {
    try { $myTeams = getUserTeams($db, $viewerId); } catch (Throwable $e) {}
}

$userBalance = 0;
if ($viewerId) {
    try {
        $stmt = $db->prepare("SELECT balance, agent_verified_at FROM users WHERE id = ?");
        $stmt->execute([$viewerId]);
        $u = $stmt->fetch();
        $userBalance = (float)($u['balance'] ?? 0);
        $agentVerifiedAt = $u['agent_verified_at'] ?? null;
    } catch (Throwable $e) {}
}

// Clubs data
$myClubs = $viewerId ? getUserClubs($db, $viewerId) : [];
$allClubs = getClubStandings($db);
$clubDetail = null; $clubMembers = [];
$clubId = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
if ($clubId) {
    $clubDetail = getClub($db, $clubId);
    $clubMembers = $clubDetail ? getClubMembers($db, $clubId) : [];
}

// Player Market data
$players = getMarketPlayers($db, 'free_agent');
$auctions = getActiveAuctions($db);
$playerDetail = null;
$detailUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($detailUserId) $playerDetail = getPlayerDetail($db, $detailUserId);

$pmData = ['bkash' => ['number' => '01888780877', 'instruction' => 'send_money'],
           'nagad' => ['number' => '01888780877', 'instruction' => 'send_money'],
           'rocket' => ['number' => '01888780877', 'instruction' => 'send_money']];
try {
    $stmt = $db->query("SELECT `key`, `value` FROM site_settings WHERE `key` LIKE 'payment_%'");
    while ($row = $stmt->fetch()) {
        foreach (['bkash','nagad','rocket'] as $m) {
            if ($row['key'] === "payment_{$m}_number") $pmData[$m]['number'] = $row['value'] ?: '01888780877';
            if ($row['key'] === "payment_{$m}_instruction") $pmData[$m]['instruction'] = $row['value'] ?: 'send_money';
        }
    }
} catch (Throwable $e) {}

$statusStats = ['upcoming' => 0, 'live' => 0, 'ongoing' => 0, 'completed' => 0, 'cancelled' => 0];
$totalPrize = 0;
foreach ($tournaments as $t) {
    $s = $t['status'] ?? 'upcoming';
    if (isset($statusStats[$s])) $statusStats[$s]++;
    $prize = (float) str_replace(',', '', $t['prize_money'] ?? '0');
    $totalPrize += $prize;
}

$statusBadgeClasses = [
    'live' => 'badge-live', 'upcoming' => 'badge-upcoming',
    'ongoing' => 'badge-ongoing', 'completed' => 'badge-completed', 'cancelled' => 'badge-cancelled',
];

$categories = ['PUBG','Free Fire','Valorant','Call of Duty','Fortnite','League of Legends','Dota 2','CS:GO','Overwatch','Apex Legends','Minecraft','Rocket League','FIFA','GTA Online','eFootball','Other'];
$gameIcons = ['fa-gamepad','fa-crosshairs','fa-chess','fa-dice','fa-joystick','fa-headset','fa-keyboard','fa-shield-halved','fa-bullseye','fa-skull','fa-fighter-jet','fa-gun','fa-swords','fa-axe','fa-bow-arrow','fa-futbol'];
$palette = ['#7c3aed','#2563eb','#ec4899','#ef4444','#f59e0b','#10b981','#06b6d4','#f97316','#64748b'];

$gameSkills = [];
$playerStats = [];
if ($viewerId) {
    try {
        $stmt = $db->prepare("SELECT game, skill_level, game_icon FROM game_skills WHERE user_id = ? ORDER BY game");
        $stmt->execute([$viewerId]);
        $gameSkills = $stmt->fetchAll();
    } catch (Throwable $e) { $gameSkills = []; }
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(matches_played),0) AS total_matches, COALESCE(SUM(wins),0) AS total_wins, COALESCE(SUM(kills),0) AS total_kills, COALESCE(SUM(goals),0) AS total_goals, COALESCE(SUM(assists),0) AS total_assists, COALESCE(SUM(score),0) AS total_score FROM player_stats WHERE user_id = ?");
        $stmt->execute([$viewerId]);
        $playerStats = $stmt->fetch() ?: [];
    } catch (Throwable $e) { $playerStats = []; }
}
?>

<style>
:root { --gp-bg:#f5f7fa; --gp-card:#fff; --gp-border:#e2e8f0; --gp-text:#1f2937; --gp-muted:#6b7280; --gp-accent:#8b5cf6; --gp-green:#059669; --gp-red:#dc2626; --gp-yellow:#d97706; --gp-shadow:0 4px 16px rgba(0,0,0,.03) }
.dark { --gp-bg:#0f172a; --gp-card:#1e293b; --gp-border:#334155; --gp-text:#f1f5f9; --gp-muted:#94a3b8 }

/* ═══ PAGE ═══ */
.gp-page { max-width:900px; margin:0 auto; padding:0 12px 2rem }

/* ═══ HERO ═══ */
.gp-hero { position:relative; padding:28px 24px 0; border-radius:0 0 32px 32px; min-height:auto; overflow:hidden }
.gp-hero-bg { position:absolute; inset:0; z-index:0; background:linear-gradient(135deg,#0f172a,#1e1b4b,#1a0533); min-height:360px; border-radius:0 0 32px 32px }
.gp-hero-bg::before { content:''; position:absolute; top:-120px; right:-120px; width:320px; height:320px; border-radius:50%; background:radial-gradient(circle,rgba(139,92,246,.18),transparent 70%) }
.gp-hero-bg::after { content:''; position:absolute; bottom:-80px; left:-80px; width:240px; height:240px; border-radius:50%; background:radial-gradient(circle,rgba(5,150,105,.1),transparent 70%) }
.gp-hero-content { position:relative; z-index:1; text-align:center; padding:10px 0 4px }
.gp-hero-badge { display:inline-flex; align-items:center; gap:6px; padding:6px 16px; border-radius:999px; background:rgba(139,92,246,.15); color:#a78bfa; font-size:.72rem; font-weight:700; letter-spacing:.3px; margin-bottom:14px; border:1px solid rgba(139,92,246,.2) }
.gp-hero-content h1 { font-size:clamp(24px,4vw,38px); font-weight:900; color:#fff; letter-spacing:-.03em; line-height:1.15; margin:0 0 6px }
.gp-hero-content p { font-size:.85rem; color:#94a3b8; margin:0 0 16px }
.text-gradient { background:linear-gradient(135deg,#a78bfa,#c084fc); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text }
.gp-hero-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; padding:20px 0 24px; position:relative; z-index:1 }
.gp-stat-card { padding:22px 14px; border-radius:20px; background:rgba(255,255,255,.92); backdrop-filter:blur(14px); border:1px solid rgba(255,255,255,.5); box-shadow:0 4px 20px rgba(0,0,0,.04); transition:all .35s cubic-bezier(.34,1.56,.64,1); display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center }
.dark .gp-stat-card { background:rgba(30,41,59,.88); border-color:rgba(71,85,105,.35) }
.gp-stat-card:hover { transform:translateY(-5px); box-shadow:0 16px 40px rgba(0,0,0,.08) }
.gp-stat-icon { font-size:26px; width:54px; height:54px; display:flex; align-items:center; justify-content:center; border-radius:16px; margin-bottom:10px }
.gp-stat-card:nth-child(1) .gp-stat-icon { background:linear-gradient(135deg,#5b21b6,#7c3aed); color:#fff; box-shadow:0 6px 20px rgba(91,33,182,.35) }
.gp-stat-card:nth-child(2) .gp-stat-icon { background:linear-gradient(135deg,#d97706,#f59e0b); color:#fff; box-shadow:0 6px 20px rgba(217,119,6,.35) }
.gp-stat-card:nth-child(3) .gp-stat-icon { background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff; box-shadow:0 6px 20px rgba(220,38,38,.35) }
.gp-stat-card:nth-child(4) .gp-stat-icon { background:linear-gradient(135deg,#059669,#10b981); color:#fff; box-shadow:0 6px 20px rgba(5,150,105,.35) }
.gp-stat-value { font-size:26px; font-weight:900; line-height:1.1; color:var(--gp-text); display:block }
.gp-stat-label { font-size:12px; color:var(--gp-muted); font-weight:600; display:block; margin-top:3px }

/* ═══ PROFILE BAR ═══ */
.gp-profile-bar { display:flex; align-items:center; gap:14px; padding:14px 20px; margin:0 0 18px; border-radius:20px; background:var(--gp-card); border:1px solid var(--gp-border); box-shadow:var(--gp-shadow) }
.gp-profile-bar-avatar { width:46px; height:46px; border-radius:50%; overflow:hidden; flex-shrink:0; border:2px solid rgba(139,92,246,.2); box-shadow:0 2px 8px rgba(139,92,246,.12) }
.gp-profile-bar-avatar img { width:100%; height:100%; object-fit:cover }
.gp-profile-bar-info { flex:1; min-width:0 }
.gp-profile-bar-info strong { display:block; font-size:14px; font-weight:700; color:var(--gp-text) }
.gp-profile-bar-balance { display:flex; align-items:center; gap:5px; margin-left:6px; padding:5px 12px; border-radius:12px; background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.12) }
.gp-balance-label { font-size:10px; color:var(--gp-muted) }
.gp-balance-value { font-size:15px; font-weight:800; color:var(--gp-green) }
.gp-profile-bar-actions { display:flex; align-items:center; gap:6px }

/* ═══ MOBILE BOTTOM NAV — PREMIUM ═══ */
.gp-mobile-nav { position:fixed; bottom:0; left:0; right:0; z-index:9999; display:none; padding:0 0 env(safe-area-inset-bottom,0); background:linear-gradient(180deg,var(--gp-bg) 0%,var(--gp-card) 100%); border-top:1px solid var(--gp-border); box-shadow:0 -8px 32px rgba(0,0,0,.06) }
.dark .gp-mobile-nav { box-shadow:0 -8px 32px rgba(0,0,0,.25) }
.gp-mobile-nav-inner { display:flex; justify-content:space-around; align-items:flex-start; max-width:540px; margin:0 auto; padding-top:4px }
.gp-mobile-nav-item { display:flex; flex-direction:column; align-items:center; justify-content:flex-start; gap:2px; padding:6px 0 8px; border:0; background:none; cursor:pointer; color:var(--gp-muted); font-size:.5rem; font-weight:600; transition:color .3s cubic-bezier(.4,0,.2,1); text-decoration:none; font-family:'Plus Jakarta Sans',sans-serif; letter-spacing:.2px; position:relative; -webkit-tap-highlight-color:transparent; user-select:none; flex:1; max-width:64px; min-height:52px }
.gp-mobile-nav-item i { font-size:1.15rem; transition:transform .4s cubic-bezier(.34,1.56,.64,1),color .3s; margin-bottom:0 }
.gp-mobile-nav-item .gp-nav-indicator { position:absolute; bottom:4px; left:50%; transform:translateX(-50%) scaleX(0); width:18px; height:3px; border-radius:4px; background:var(--gp-accent); transition:transform .35s cubic-bezier(.34,1.56,.64,1) }
.gp-mobile-nav-item.active .gp-nav-indicator { transform:translateX(-50%) scaleX(1) }
.gp-mobile-nav-item.active { color:var(--gp-accent); font-weight:700 }
.gp-mobile-nav-item.active i { transform:translateY(-2px) scale(1.2); filter:drop-shadow(0 2px 6px rgba(139,92,246,.25)) }
.gp-mobile-nav-item:active i { transform:scale(.85) }
.gp-mobile-nav-item.nav-spacer { flex:0.4 }
.gp-mobile-nav-plus { position:relative; margin-top:-18px; z-index:2; flex:1; max-width:64px; display:flex; align-items:center; justify-content:center }
.gp-mobile-nav-plus .gp-mobile-nav-plus-btn { width:58px; height:58px; border-radius:50%; border:0; display:flex; align-items:center; justify-content:center; cursor:pointer; position:relative; transition:transform .4s cubic-bezier(.34,1.56,.64,1),box-shadow .3s; background:linear-gradient(135deg,#8b5cf6,#7c3aed); color:#fff; font-size:1.6rem; box-shadow:0 6px 24px rgba(139,92,246,.35) }
.gp-mobile-nav-plus .gp-mobile-nav-plus-btn::after { content:''; position:absolute; inset:-3px; border-radius:50%; background:linear-gradient(135deg,transparent 40%,rgba(255,255,255,.15) 100%); pointer-events:none }
.gp-mobile-nav-plus .gp-mobile-nav-plus-btn:active { transform:scale(.88); box-shadow:0 3px 12px rgba(139,92,246,.2) }
@keyframes navPlusPulse { 0%,100% { box-shadow:0 6px 24px rgba(139,92,246,.35) } 50% { box-shadow:0 6px 36px rgba(139,92,246,.55),0 0 0 6px rgba(139,92,246,.1) } }
.gp-mobile-nav-plus .gp-mobile-nav-plus-btn { animation:navPlusPulse 3s ease-in-out infinite }
@media(hover:hover) {
  .gp-mobile-nav-plus .gp-mobile-nav-plus-btn:hover { transform:scale(1.06) translateY(-3px); box-shadow:0 10px 32px rgba(139,92,246,.45) }
  .gp-mobile-nav-item:hover i { transform:translateY(-1px) }
}
@media(max-width:768px) {
  .gp-mobile-nav { display:block }
  body { padding-bottom:78px }
}

/* ═══ SECTION ═══ */
.gp-section { padding:16px 8px }
.gp-section-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:0 12px 16px; flex-wrap:wrap }
.gp-section-header h2 { font-size:1.15rem; font-weight:800; display:flex; align-items:center; gap:8px; color:var(--gp-text); flex-shrink:0; letter-spacing:-.02em }
.gp-section-header h2 i { font-size:1rem; color:var(--gp-accent) }

/* ═══ TABS ═══ */
.gp-tabs { display:flex; gap:4px; flex-wrap:nowrap; overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:none }
.gp-tabs::-webkit-scrollbar { display:none }
.gp-tab { border-radius:10px; font-weight:700; transition:all .18s; padding:7px 16px; font-size:.75rem; background:rgba(148,163,184,.08); color:var(--gp-muted); border:1px solid transparent; cursor:pointer; flex-shrink:0; white-space:nowrap; font-family:'Plus Jakarta Sans',sans-serif }
.gp-tab:hover { background:rgba(139,92,246,.08); color:var(--gp-accent); border-color:rgba(139,92,246,.1) }
.gp-tab.active { background:rgba(139,92,246,.1); color:var(--gp-accent); border-color:rgba(139,92,246,.2); box-shadow:0 2px 8px rgba(139,92,246,.1) }

/* ═══ SEARCH ═══ */
.gp-search { position:relative; width:260px; flex-shrink:0; border-radius:12px; border:2px solid var(--gp-border); background:var(--gp-card); transition:border-color .2s,box-shadow .2s }
.gp-search:focus-within { border-color:var(--gp-accent); box-shadow:0 0 0 4px rgba(139,92,246,.1) }
.gp-search-icon { position:absolute; left:13px; top:50%; transform:translateY(-50%); font-size:14px; color:var(--gp-muted); pointer-events:none; transition:color .2s }
.gp-search:focus-within .gp-search-icon { color:var(--gp-accent) }
.gp-search-input { width:100%; padding:10px 42px 10px 40px; border:0; border-radius:12px; font:inherit; font-size:.78rem; background:transparent; color:var(--gp-text); box-sizing:border-box; outline:none; font-family:'Plus Jakarta Sans',sans-serif }
.gp-search-input::placeholder { color:var(--gp-muted) }
.gp-search-clear { position:absolute; right:8px; top:50%; transform:translateY(-50%); width:22px; height:22px; border-radius:50%; border:0; background:rgba(148,163,184,.12); color:var(--gp-muted); display:none; align-items:center; justify-content:center; cursor:pointer; font-size:10px; transition:background .2s,transform .2s; padding:0; line-height:1 }
.gp-search-clear:hover { background:rgba(139,92,246,.12); color:var(--gp-accent); transform:translateY(-50%) scale(1.1) }

/* ═══ CARD GRID ═══ */
.gp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; padding:0 8px }
.gp-card { background:var(--gp-card); border-radius:18px; padding:0; transition:all .3s ease; position:relative; overflow:hidden; border:1px solid var(--gp-border); box-shadow:0 1px 4px rgba(0,0,0,.02); display:flex; flex-direction:column }
.gp-card:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(139,92,246,.06); border-color:rgba(139,92,246,.12) }
.gp-card-accent { height:3px; flex-shrink:0 }
.gp-card-head { display:flex; align-items:center; gap:10px; padding:14px 16px 0 }
.gp-card-icon { width:38px; height:38px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0 }
.gp-badge { font-size:.6rem; font-weight:700; padding:3px 10px; border-radius:999px; letter-spacing:.3px; display:inline-flex; align-items:center; gap:4px }
.badge-live { background:rgba(5,150,105,.12); color:#059669 }
.badge-upcoming { background:rgba(59,130,246,.12); color:#3b82f6 }
.badge-ongoing { background:rgba(251,191,36,.12); color:#d97706 }
.badge-completed { background:rgba(139,92,246,.12); color:#7c3aed }
.badge-cancelled { background:rgba(220,38,38,.12); color:#dc2626 }
.dark .badge-live { background:rgba(5,150,105,.15); color:#34d399 }
.dark .badge-upcoming { background:rgba(59,130,246,.15); color:#93c5fd }
.dark .badge-ongoing { background:rgba(251,191,36,.15); color:#fbbf24 }
.dark .badge-completed { background:rgba(139,92,246,.15); color:#a78bfa }
.dark .badge-cancelled { background:rgba(220,38,38,.15); color:#fca5a5 }
.gp-card-body { padding:8px 16px 4px; flex:1 }
.gp-card-body h3 { font-size:.95rem; font-weight:800; color:var(--gp-text); margin:0 0 2px; letter-spacing:-.02em }
.gp-card-tag { font-size:.68rem; color:var(--gp-muted); display:inline-flex; align-items:center; gap:4px; margin-right:6px }
.gp-card-host { font-size:.68rem; color:var(--gp-muted); display:inline-flex; align-items:center; gap:4px }
.gp-card-body p { font-size:.75rem; color:var(--gp-muted); margin:4px 0 0; line-height:1.4 }
.gp-card-meta { display:grid; grid-template-columns:1fr 1fr; gap:4px 10px; padding:4px 16px 10px; font-size:.73rem; color:var(--gp-muted) }
.gp-card-meta > div { display:flex; align-items:center; gap:5px }
.gp-card-meta i { font-size:.6rem; width:13px; text-align:center; opacity:.7 }
.gp-countdown { font-size:.65rem; font-weight:700; color:var(--gp-accent); margin-left:auto }
.gp-card-actions { display:flex; align-items:center; gap:6px; flex-wrap:wrap; border-top:1px solid var(--gp-border); margin:0 16px 0; padding:10px 0 14px }

/* ═══ BUTTONS (P2P style) ═══ */
.gp-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; border:0; cursor:pointer; transition:all .2s; font-weight:700; font-family:'Plus Jakarta Sans',sans-serif; text-decoration:none; flex-shrink:0; font-size:.78rem; padding:10px 18px; border-radius:12px }
.gp-btn:active { transform:scale(.97) }
.gp-btn-primary { background:linear-gradient(135deg,#8b5cf6,#7c3aed); color:#fff; box-shadow:0 4px 12px rgba(139,92,246,.2) }
.gp-btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(139,92,246,.3) }
.gp-btn-danger { background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff; box-shadow:0 4px 12px rgba(220,38,38,.2) }
.gp-btn-danger:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(220,38,38,.3) }
.gp-btn-accent { background:linear-gradient(135deg,#8b5cf6,#7c3aed); color:#fff; box-shadow:0 4px 12px rgba(139,92,246,.2) }
.gp-btn-accent:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(139,92,246,.3) }
.gp-btn-gradient { background:linear-gradient(135deg,#8b5cf6,#6366f1); color:#fff; box-shadow:0 4px 14px rgba(139,92,246,.25) }
.gp-btn-gradient:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(139,92,246,.35) }
.gp-btn-gradient--gold { background:linear-gradient(135deg,#f59e0b,#d97706); box-shadow:0 4px 14px rgba(245,158,11,.25) }
.gp-btn-gradient--gold:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(245,158,11,.35) }
.gp-btn-ghost { background:transparent; color:var(--gp-muted); border:1px solid transparent }
.gp-btn-ghost:hover { background:rgba(148,163,184,.08); color:var(--gp-text) }
.gp-btn-outline { background:transparent; color:var(--gp-text); border:2px solid var(--gp-border) }
.gp-btn-outline:hover { border-color:var(--gp-accent); color:var(--gp-accent); background:rgba(139,92,246,.04) }
.gp-btn-success { background:linear-gradient(135deg,#059669,#10b981); color:#fff; box-shadow:0 4px 12px rgba(5,150,105,.2) }
.gp-btn-success:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(5,150,105,.3) }
.gp-btn-disabled { background:rgba(148,163,184,.08); color:var(--gp-muted); cursor:not-allowed; opacity:.6; transform:none !important; box-shadow:none !important }
.gp-btn-sm { font-size:.72rem; padding:9px 16px; border-radius:10px }
.gp-btn-xs { font-size:.65rem; padding:6px 12px; border-radius:9px }
.gp-agent-badge { display:inline-flex; align-items:center; gap:4px; font-size:.6rem; font-weight:700; padding:3px 10px; border-radius:999px; background:rgba(251,191,36,.12); color:#d97706 }
.gp-modal-actions .gp-btn { min-width:80px; justify-content:center }

/* ═══ ROLE BADGE ═══ */
.gp-role-badge { display:inline-flex; align-items:center; gap:4px; font-size:.6rem; font-weight:700; padding:2px 10px; border-radius:999px; margin-top:2px }
.gp-role-agent { background:rgba(251,191,36,.12); color:#d97706 }
.gp-role-gamer { background:rgba(139,92,246,.1); color:var(--gp-accent) }

/* ═══ MY STUFF ═══ */
.gp-my-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; padding:0 8px }
.gp-my-panel { background:var(--gp-card); border:1px solid var(--gp-border); border-radius:20px; overflow:hidden; box-shadow:var(--gp-shadow) }
.gp-my-panel-header { display:flex; align-items:center; gap:8px; padding:14px 20px; font-size:.85rem; font-weight:800; border-bottom:1px solid var(--gp-border); color:var(--gp-text) }
.gp-my-panel-header i { color:var(--gp-accent) }
.gp-my-panel-header .gp-count { margin-left:auto; font-size:.7rem; font-weight:700; background:rgba(139,92,246,.1); color:var(--gp-accent); padding:2px 10px; border-radius:999px }
.gp-my-list { padding:4px }
.gp-my-item { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:14px; transition:background .15s }
.gp-my-item:hover { background:var(--gp-bg) }
.gp-my-icon { width:40px; height:40px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0 }
.gp-my-info { flex:1; min-width:0 }
.gp-my-info strong { display:block; font-size:.82rem; font-weight:700; color:var(--gp-text) }
.gp-my-info span { font-size:.7rem; color:var(--gp-muted); display:flex; align-items:center; gap:4px }
.gp-my-empty { text-align:center; padding:24px 16px; font-size:.78rem; color:var(--gp-muted) }
.gp-agent-quick { display:flex; gap:8px; padding:14px 20px }
.gp-agent-quick .gp-btn { flex:1; justify-content:center }

/* ═══ EMPTY STATE ═══ */
.gp-empty { text-align:center; padding:48px 20px; color:var(--gp-muted); grid-column:1/-1 }
.gp-empty i { font-size:2.5rem; display:block; margin-bottom:12px; opacity:.3 }
.gp-empty h3 { font-size:1.05rem; font-weight:700; color:var(--gp-text); margin:0 0 4px }
.gp-empty p { font-size:.8rem; margin:0 }

/* ═══ LEADERBOARD ═══ */
.gp-lb-row { display:grid; grid-template-columns:36px 1fr 80px 90px 60px; gap:8px; padding:12px 16px; align-items:center; border-bottom:1px solid var(--gp-border); transition:background .15s; font-size:.78rem; cursor:pointer; text-decoration:none; color:inherit }
.gp-lb-row:last-child { border-bottom:none }
.gp-lb-row:hover { background:var(--gp-bg) }
.gp-lb-rank { font-weight:800; color:var(--gp-muted); text-align:center }
.gp-lb-rank.gold { color:#f59e0b } .gp-lb-rank.silver { color:#94a3b8 } .gp-lb-rank.bronze { color:#cd7f32 }
.gp-lb-cell { font-weight:700; color:var(--gp-text); text-align:center }
.gp-lb-prize { color:var(--gp-green); text-align:center; font-weight:700 }

/* ═══ MODALS (P2P design) ═══ */
.gp-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:99998; backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px) }
.gp-overlay.hidden { display:none }
.gp-modal { position:fixed; inset:0; z-index:99999; display:flex; align-items:center; justify-content:center; animation:gpModalIn .3s ease }
.gp-modal.hidden { display:none }
@keyframes gpModalIn { from { opacity:0; transform:translateY(30px) scale(.98) } to { opacity:1; transform:translateY(0) scale(1) } }
.gp-modal-panel { background:var(--gp-card); width:100%; max-width:520px; max-height:92vh; border-radius:28px; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,.2); margin:16px; scrollbar-width:none; -ms-overflow-style:none; display: flex; flex-direction: column; }
.gp-modal-panel::-webkit-scrollbar { display:none }
.gp-modal-panel.lg { max-width:640px }
.gp-modal-panel.sm { max-width:440px }

@media(max-width:576px) {
  .gp-modal .gp-modal-panel { max-width:none !important; max-height:none !important; border-radius:0 !important; width:100vw !important; height:100dvh !important; margin:0 !important; box-shadow:none !important; display: flex; flex-direction: column; }
}

.gp-modal-panel--crown { background:linear-gradient(180deg,var(--gp-card),rgba(255,255,255,.95)) }
.dark .gp-modal-panel--crown { background:linear-gradient(180deg,var(--gp-card),rgba(30,41,59,.98)) }
.gp-modal-head { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid var(--gp-border); position:sticky; top:0; background:var(--gp-card); z-index:2; flex-shrink: 0; }
.gp-modal-head h3 { margin:0; font-size:1.05rem; font-weight:800; display:flex; align-items:center; gap:8px; color:var(--gp-text) }
.gp-modal-close { width:34px; height:34px; border-radius:50%; border:0; background:rgba(0,0,0,.05); cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--gp-muted); font-size:14px; transition:all .2s; backdrop-filter:blur(4px) }
.gp-modal-close:hover { background:rgba(239,68,68,.12); color:#dc2626; transform:rotate(90deg) }
.dark .gp-modal-close { background:rgba(255,255,255,.08); color:#9ca3af }
.dark .gp-modal-close:hover { background:rgba(239,68,68,.2); color:#fca5a5 }
.gp-modal-body { padding:20px 22px 24px; flex: 1; overflow-y: auto; }
.gp-modal-sub { font-size:.82rem; color:var(--gp-muted); margin:0 0 12px }

/* ═══ FORM ELEMENTS ═══ */
/* --- CUSTOM SELECTS --- */
.gp-custom-select { position:relative; width:100%; text-align: left; }
.gp-select-trigger { display:flex; align-items:center; gap:10px; padding:12px 16px; background:var(--gp-card); border:2px solid var(--gp-border); border-radius:14px; cursor:pointer; font-size:.85rem; font-weight:700; color:var(--gp-text); transition:all .2s; font-family:'Plus Jakarta Sans',sans-serif; }
.gp-select-trigger:hover { border-color:var(--gp-accent); background:rgba(139,92,246,.04); }
.gp-select-trigger span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: left; }
.gp-select-trigger::after { content:'\f078'; font-family:'Font Awesome 6 Free'; font-weight:900; font-size:10px; opacity:.4; transition:transform .2s; margin-left:4px; }
.gp-custom-select.active .gp-select-trigger::after { transform:rotate(180deg); opacity:1; color:var(--gp-accent); }
.gp-select-options { position:absolute; top:calc(100% + 6px); left:0; right:0; background:var(--gp-card); border:1px solid var(--gp-border); border-radius:14px; box-shadow:0 12px 32px rgba(0,0,0,.15); z-index:200; display:none; padding:6px; animation:gpSelectIn .2s ease; max-height:220px; overflow-y:auto; scrollbar-width:none; }
.gp-select-options::-webkit-scrollbar { display:none; }
@keyframes gpSelectIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
.gp-select-option { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:10px; cursor:pointer; font-size:.82rem; font-weight:600; color:var(--gp-muted); transition:all .15s; margin-bottom:2px; text-align: left; }
.gp-select-option:hover { background:var(--gp-bg); color:var(--gp-accent); }
.gp-select-option.active { background:rgba(139,92,246,.1); color:var(--gp-accent); }
.gp-custom-select.active .gp-select-options { display:block; }
.gp-custom-select.active .gp-select-trigger { border-color:var(--gp-accent); box-shadow:0 0 0 4px rgba(139,92,246,.1); }
.gp-input-group { display:flex; align-items:stretch; border-radius:12px; overflow:hidden; border:2px solid var(--gp-border); transition:border-color .2s,box-shadow .2s }
.gp-input-group:focus-within { border-color:var(--gp-accent); box-shadow:0 0 0 4px rgba(139,92,246,.1) }
.gp-input-group .gp-input { border:none; border-radius:0 }
.gp-input-group .gp-input:focus { box-shadow:none }
.gp-input-prefix { display:flex; align-items:center; padding:0 12px; font-size:.82rem; font-weight:700; background:var(--gp-bg); color:var(--gp-muted); border-right:1px solid var(--gp-border) }
.gp-form-grid { display:grid; gap:12px; margin-bottom:10px }
.gp-form-grid.two { grid-template-columns:1fr 1fr }
.gp-form-grid.three { grid-template-columns:1fr 1fr 1fr }

/* ═══ RADIO ═══ */
.gp-radio-group { display:flex; gap:8px; flex-wrap:wrap }
.gp-radio { display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:12px; border:2px solid var(--gp-border); cursor:pointer; transition:all .18s; flex:1; font-size:.78rem; font-weight:600; color:var(--gp-text); background:var(--gp-card) }
.gp-radio:has(input:checked) { border-color:var(--gp-accent); background:rgba(139,92,246,.06); color:var(--gp-accent) }
.gp-radio input { accent-color:var(--gp-accent) }

/* ═══ ICON & COLOR PICKERS ═══ */
.gp-icon-picker { display:flex; gap:6px; flex-wrap:wrap }
.gp-icon-opt { width:40px; height:40px; border-radius:12px; border:2px solid transparent; background:var(--gp-bg); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:16px; color:var(--gp-muted); transition:all .15s }
.gp-icon-opt:hover { border-color:var(--gp-accent); color:var(--gp-accent) }
.gp-icon-opt.active { border-color:var(--gp-accent); background:rgba(139,92,246,.1); color:var(--gp-accent) }
.gp-color-picker { display:flex; gap:6px; flex-wrap:wrap }
.gp-color-opt { width:32px; height:32px; border-radius:50%; border:3px solid transparent; cursor:pointer; transition:all .15s; outline:none }
.gp-color-opt:hover { transform:scale(1.15) }
.gp-color-opt.active { border-color:var(--gp-accent); box-shadow:0 0 0 2px rgba(139,92,246,.3); transform:scale(1.1) }

/* ═══ AGENT BADGE GRID ═══ */
.gp-agent-badge-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px }
@keyframes gpGlow { 0%,100%{opacity:.5;transform:translate(0,0)} 50%{opacity:1;transform:translate(10%,-10%)} }

/* ═══ AGENT HERO ═══ */
.gp-agent-hero { text-align:center; padding:40px 20px; position:relative; overflow:hidden; border-radius:24px; background:linear-gradient(135deg,#1a1a2e,#16213e); border:1px solid rgba(139,92,246,.2); margin-bottom:24px }
.gp-agent-hero-glow { position:absolute; inset:0; background:radial-gradient(circle at 70% 20%,rgba(139,92,246,.15),transparent 50%),radial-gradient(circle at 20% 80%,rgba(245,158,11,.1),transparent 50%); pointer-events:none }
.gp-agent-hero-icon { font-size:3.5rem; display:block; margin-bottom:12px; filter:drop-shadow(0 0 15px rgba(245,158,11,.4)) }
.gp-agent-hero-title { font-size:1.4rem; font-weight:900; color:#fff; margin-bottom:6px; letter-spacing:-.02em }
.gp-agent-hero-sub { font-size:.85rem; color:rgba(255,255,255,.7); line-height:1.4 }

.gp-agent-badge-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:24px }
.gp-agent-badge { background:var(--gp-card); border:1px solid var(--gp-border); padding:12px; border-radius:8px; text-align:center; transition:all .3s ease }
.gp-agent-badge:hover { transform:translateY(-5px); border-color:var(--gp-accent); box-shadow:0 12px 24px rgba(139,92,246,.1) }
.gp-agent-badge-icon { font-size:1.8rem; display:block; margin-bottom:8px }
.gp-agent-badge-label { font-size:.82rem; font-weight:800; color:var(--gp-text); margin-bottom:2px }
.gp-agent-badge-desc { font-size:.68rem; color:var(--gp-muted) }

.gp-agent-fee { background:linear-gradient(135deg,rgba(245,158,11,.1),rgba(217,119,6,.05)); border:1.5px dashed #f59e0b; padding:20px; border-radius:20px; text-align:center; margin-bottom:24px; position:relative }
.gp-agent-fee-label { font-size:.75rem; font-weight:700; color:#b45309; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px }
.gp-agent-fee-amount { font-size:2rem; font-weight:900; color:#d97706; display:block }

/* ═══ PAYMENT (BECOME AGENT) — REDESIGNED ═══ */
.gp-pay-note { padding:14px 18px; border-radius:14px; background:linear-gradient(135deg,rgba(245,158,11,.1),rgba(217,119,6,.04)); border:1px solid rgba(245,158,11,.18); font-size:.82rem; color:#92400e; margin-bottom:18px; display:flex; align-items:center; gap:10px; line-height:1.5 }
.dark .gp-pay-note { color:#fbbf24; background:rgba(245,158,11,.07) }
.gp-pay-note i { font-size:1.2rem; color:#d97706; flex-shrink:0 }

.ba-method-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:18px }
.ba-method-card { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; padding:14px 10px; border-radius:14px; border:2px solid var(--gp-border); background:var(--gp-card); cursor:pointer; transition:all .25s ease; min-height:64px }
.ba-method-card:hover { border-color:var(--gp-accent); background:rgba(139,92,246,.04) }
.ba-method-card.active { border-color:#E2136E; background:rgba(226,19,110,.06); box-shadow:0 4px 16px rgba(226,19,110,.12) }
.ba-method-card img { max-height:28px; max-width:100%; object-fit:contain }
.ba-method-label { font-size:.65rem; font-weight:700; color:var(--gp-muted); letter-spacing:.3px }

#baMerchantBox { padding:18px; border-radius:16px; background:var(--gp-bg); border:1px solid var(--gp-border); margin-bottom:18px }
.ba-merchant-header { display:flex; align-items:center; gap:8px; font-size:.9rem; font-weight:800; margin-bottom:12px; color:var(--gp-text) }
.ba-merchant-header img { height:22px }
.ba-instr-step { font-size:.75rem; color:var(--gp-muted); padding:5px 0; line-height:1.5; display:flex; align-items:flex-start; gap:8px }
.ba-instr-step::before { content:'→'; color:var(--gp-accent); font-weight:700; font-size:.7rem; opacity:.7; flex-shrink:0; margin-top:1px }
.ba-instr-step strong { color:var(--gp-text); font-weight:800 }
.ba-merchant-num { font-size:.9rem; font-weight:900; letter-spacing:.6px; font-family:monospace; background:rgba(139,92,246,.08); padding:2px 10px; border-radius:6px; display:inline-block }
.ba-copy-btn { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:8px; border:0; background:var(--gp-accent); color:#fff; font-size:.65rem; font-weight:700; cursor:pointer; transition:all .2s; margin-left:6px }
.ba-copy-btn:hover { transform:scale(1.05); filter:brightness(1.1) }
.ba-instr-footer { margin-top:10px; padding:10px 14px; border-radius:10px; background:rgba(139,92,246,.06); border:1px solid rgba(139,92,246,.12); font-size:.75rem; color:var(--gp-text); font-weight:600; text-align:center }

.ba-inputs-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px }
.ba-input-label { font-size:.7rem; font-weight:700; color:var(--gp-muted); margin-bottom:6px; display:block }
.ba-input-wrap { position:relative; display:flex; align-items:center; border-radius:12px; border:2px solid var(--gp-border); background:var(--gp-bg); transition:all .2s }
.ba-input-wrap:focus-within { border-color:var(--gp-accent); box-shadow:0 0 0 3px rgba(139,92,246,.1) }
.ba-input-icon { width:40px; display:flex; align-items:center; justify-content:center; color:var(--gp-muted); font-size:1rem; border-right:1px solid var(--gp-border) }
.ba-input { flex:1; padding:10px 14px; border:0; font-size:.85rem; font-weight:700; outline:none; background:transparent; color:var(--gp-text) }
.ba-input::placeholder { font-weight:500; opacity:.45 }
.ba-actions { margin-top:10px }
.gp-modal-step { display:none; animation:gpModalIn .3s ease }
.gp-modal-step.active { display:block }
.gp-balance-notice { font-size:.72rem; color:var(--gp-muted); padding:8px 0; text-align:right }

/* ═══ FEEDBACK & TOAST ═══ */
.gp-feedback { padding:10px 14px; border-radius:12px; font-size:.75rem; font-weight:600; margin-top:12px; display:none }
.gp-feedback.success { display:block; background:rgba(5,150,105,.1); color:var(--gp-green); border:1px solid rgba(5,150,105,.2) }
.gp-feedback.error { display:block; background:rgba(220,38,38,.1); color:var(--gp-red); border:1px solid rgba(220,38,38,.2) }
.dark .gp-feedback.success { background:rgba(5,150,105,.12); color:#34d399 }
.dark .gp-feedback.error { background:rgba(220,38,38,.12); color:#fca5a5 }
.gp-feedback.hidden { display:none }
.gp-toast { position:fixed; bottom:24px; right:24px; z-index:999999; padding:12px 20px; border-radius:16px; font-size:.82rem; font-weight:700; display:flex; align-items:center; gap:10px; box-shadow:0 8px 32px rgba(0,0,0,.12); animation:gpToastIn .35s ease; max-width:360px; color:#fff }
.gp-toast.success { background:linear-gradient(135deg,#059669,#10b981) }
.gp-toast.error { background:linear-gradient(135deg,#dc2626,#ef4444) }
.gp-toast.info { background:linear-gradient(135deg,#3b82f6,#6366f1) }
@keyframes gpToastIn { from { opacity:0; transform:translateY(20px) scale(.95) } to { opacity:1; transform:translateY(0) scale(1) } }
.gp-loading { text-align:center; padding:24px; font-size:.78rem; color:var(--gp-muted) }

/* ═══ SUCCESS OVERLAY — REDESIGNED ═══ */
.gp-success-overlay { position:fixed; inset:0; z-index:999999; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.55); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); animation:gpOverlayIn .25s ease }
@keyframes gpOverlayIn { from { opacity:0 } to { opacity:1 } }
.gp-success-box { position:relative; text-align:center; padding:44px 48px 36px; border-radius:28px; background:var(--gp-card); box-shadow:0 25px 80px rgba(0,0,0,.25); max-width:400px; width:calc(100% - 32px); margin:16px; animation:gpSuccessIn .45s cubic-bezier(.34,1.56,.64,1) }
@keyframes gpSuccessIn { 0% { opacity:0; transform:scale(.85) translateY(40px) } 100% { opacity:1; transform:scale(1) translateY(0) } }
.gp-success-close { position:absolute; top:12px; right:12px; width:34px; height:34px; border-radius:50%; border:0; background:rgba(0,0,0,.05); cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--gp-muted); font-size:14px; transition:all .2s; z-index:2 }
.gp-success-close:hover { background:rgba(239,68,68,.12); color:#dc2626; transform:rotate(90deg) }
.dark .gp-success-close { background:rgba(255,255,255,.08); color:#9ca3af }
.dark .gp-success-close:hover { background:rgba(239,68,68,.2); color:#fca5a5 }
.gp-success-icon-wrap { width:72px; height:72px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:1.8rem; animation:gpIconPop .5s cubic-bezier(.34,1.56,.64,1) .15s both }
@keyframes gpIconPop { 0% { opacity:0; transform:scale(0) rotate(-30deg) } 100% { opacity:1; transform:scale(1) rotate(0deg) } }
.gp-success-icon-wrap.success { background:rgba(5,150,105,.12); color:var(--gp-green); box-shadow:0 0 0 4px rgba(5,150,105,.1) }
.gp-success-icon-wrap.fail { background:rgba(220,38,38,.12); color:var(--gp-red); box-shadow:0 0 0 4px rgba(220,38,38,.1) }
.gp-success-text { font-size:1.25rem; font-weight:800; color:var(--gp-text); margin-bottom:6px; letter-spacing:-.02em }
.gp-success-sub { font-size:.82rem; color:var(--gp-muted); line-height:1.5; margin-bottom:24px }
.gp-success-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 28px; border-radius:12px; border:0; font-size:.82rem; font-weight:700; cursor:pointer; transition:all .2s; font-family:inherit }
.gp-success-btn:hover { transform:translateY(-2px); filter:brightness(1.08) }
.gp-success-btn.primary { background:var(--gp-accent); color:#fff; box-shadow:0 4px 16px rgba(139,92,246,.3) }
.gp-success-btn.secondary { background:rgba(0,0,0,.05); color:var(--gp-text) }
.dark .gp-success-btn.secondary { background:rgba(255,255,255,.08); color:var(--gp-text) }
@media(max-width:480px) {
  .gp-success-box { padding:32px 24px 28px; border-radius:20px }
  .gp-success-icon-wrap { width:60px; height:60px; font-size:1.5rem }
  .gp-success-text { font-size:1.05rem }
  .gp-success-sub { font-size:.78rem }
  .gp-success-btn { width:100%; justify-content:center; padding:12px 20px }
}

/* ═══ PARTICIPANT ITEMS ═══ */
.gp-participants { display:flex; flex-direction:column; gap:8px }
.gp-participant { display:flex; align-items:center; gap:12px; padding:10px 14px; border-radius:14px; background:var(--gp-bg); transition:background .15s }
.gp-participant img { width:36px; height:36px; border-radius:50%; object-fit:cover; flex-shrink:0 }
.gp-participant-info { flex:1; min-width:0 }
.gp-participant-info strong { display:block; font-size:.8rem; font-weight:700; color:var(--gp-text) }
.gp-participant-info span { font-size:.68rem; color:var(--gp-muted) }
.gp-team-members { display:flex; flex-direction:column; gap:6px }
.gp-team-member { display:flex; align-items:center; gap:12px; padding:10px 14px; border-radius:14px; background:var(--gp-bg) }
.gp-team-member img { width:34px; height:34px; border-radius:50%; object-fit:cover; flex-shrink:0 }
.gp-team-member-info { flex:1; min-width:0 }
.gp-team-member-info strong { display:block; font-size:.78rem; font-weight:700; color:var(--gp-text) }
.gp-team-member-role { font-size:.65rem; color:var(--gp-muted); text-transform:capitalize }
.gp-team-add { display:flex; gap:8px; padding:12px 16px }
.gp-team-add .gp-input { flex:1 }

/* ═══ TXN ITEMS ═══ */
.gp-txns { display:flex; flex-direction:column; gap:4px }
.gp-txn-item { display:flex; align-items:center; gap:12px; padding:10px 14px; border-radius:14px; background:var(--gp-bg); transition:background .15s }
.gp-txn-icon { width:34px; height:34px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0 }
.gp-txn-icon.credit { background:rgba(5,150,105,.1); color:var(--gp-green) }
.gp-txn-icon.debit { background:rgba(220,38,38,.1); color:var(--gp-red) }
.gp-txn-info { flex:1; min-width:0 }
.gp-txn-info strong { display:block; font-size:.78rem; font-weight:700; color:var(--gp-text) }
.gp-txn-info span { font-size:.65rem; color:var(--gp-muted) }
.gp-txn-amount { font-size:.85rem; font-weight:800; flex-shrink:0 }
.gp-txn-amount.credit { color:var(--gp-green) }
.gp-txn-amount.debit { color:var(--gp-red) }

/* ═══ MISC ═══ */
.gp-join-fee { font-size:.78rem; color:var(--gp-muted); padding:8px 14px; border-radius:12px; background:rgba(16,185,129,.06); border:1px solid rgba(16,185,129,.1); margin-bottom:12px }
.gp-modal-actions { display:flex; gap:8px; margin-top:14px; justify-content:flex-end }
.hidden { display:none !important }
/* ═══ PROFILE HERO (View Mode) ═══ */
.gp-profile-hero { position:relative; padding:40px 24px 30px; overflow:hidden; border-radius:0 0 32px 32px; background:linear-gradient(135deg,#1e1b4b,#0f172a) }
.gp-profile-hero-bg { position:absolute; inset:0; background:radial-gradient(circle at top right,rgba(139,92,246,.2),transparent),radial-gradient(circle at bottom left,rgba(236,72,153,.1),transparent); opacity:.8 }
.gp-profile-hero-content { position:relative; z-index:1; display:flex; align-items:center; gap:24px }
.gp-profile-avatar-large { position:relative; width:100px; height:100px; flex-shrink:0 }
.gp-profile-avatar-large img { width:100%; height:100%; border-radius:50%; object-fit:cover; border:4px solid rgba(255,255,255,.1); box-shadow:0 12px 32px rgba(0,0,0,.3) }
.gp-avatar-status { position:absolute; bottom:6px; right:6px; width:18px; height:18px; background:#10b981; border:3px solid #1e1b4b; border-radius:50% }
.gp-profile-main-info { flex:1 }
.gp-profile-full-name { font-size:1.6rem; font-weight:900; color:#fff; margin:0 0 4px; letter-spacing:-.03em }
.gp-profile-sub-name { font-size:.85rem; font-weight:600; color:rgba(255,255,255,.7); margin-bottom:12px; display:block }
.gp-profile-nickname-badge { font-size:.9rem; font-weight:700; color:var(--gp-accent); background:rgba(139,92,246,.15); padding:3px 12px; border-radius:99px; display:inline-block; margin-bottom:10px }
.gp-profile-badges { display:flex; gap:8px; flex-wrap:wrap }
.gp-badge { padding:4px 12px; border-radius:8px; font-size:.7rem; font-weight:800; display:flex; align-items:center; gap:6px; text-transform:uppercase }
.gp-badge-skill { background:rgba(255,255,255,.1); color:#fff }
.gp-badge-agent { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff }
.gp-badge-status { background:rgba(34,197,94,.2); color:#4ade80; border:1px solid rgba(34,197,94,.2) }
.gp-badge-status i { font-size:.5rem; margin-top:-1px }

/* ═══ VISUAL STATS GRID ═══ */
.gp-stats-visual-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:24px }
.gp-stat-visual-card { background:var(--gp-card); border:1px solid var(--gp-border); padding:16px; border-radius:20px; display:flex; align-items:center; gap:12px; transition:all .2s }
.gp-stat-visual-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.05) }
.gp-stat-visual-icon { width:42px; height:42px; border-radius:12px; background:rgba(0,0,0,.04); display:flex; align-items:center; justify-content:center; font-size:1.1rem }
.dark .gp-stat-visual-icon { background:rgba(255,255,255,.04) }
.gp-stat-visual-data { display:flex; flex-direction:column }
.gp-stat-visual-value { font-size:1.2rem; font-weight:900; color:var(--gp-text); line-height:1.1 }
.gp-stat-visual-label { font-size:.65rem; font-weight:700; color:var(--gp-muted); text-transform:uppercase }

/* ═══ PROFILE CONTENT LAYOUT ═══ */
.gp-profile-content-grid { display:grid; grid-template-columns:1fr 240px; gap:24px }
.gp-profile-block { margin-bottom:20px }
.gp-block-title { font-size:.8rem; font-weight:800; color:var(--gp-muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; display:flex; align-items:center; gap:8px }
.gp-profile-bio-box { background:var(--gp-bg); padding:16px; border-radius:16px; border:1px solid var(--gp-border) }
.gp-profile-bio-box p { font-size:.85rem; color:var(--gp-text); margin:0; line-height:1.6 }

/* ═══ VISUAL SKILLS ═══ */
.gp-visual-skills { display:flex; flex-direction:column; gap:10px }
.gp-skill-visual-item { background:var(--gp-card); border:1px solid var(--gp-border); padding:12px 16px; border-radius:16px }
.gp-skill-visual-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px }
.gp-skill-name { font-size:.82rem; font-weight:800; color:var(--gp-text) }
.gp-skill-rank { font-size:.7rem; font-weight:700; color:var(--gp-accent); background:rgba(139,92,246,.1); padding:2px 8px; border-radius:6px }
.gp-skill-progress-wrap { height:6px; background:var(--gp-bg); border-radius:99px; overflow:hidden }
.gp-skill-progress-bar { height:100%; background:linear-gradient(90deg,var(--gp-accent),#ec4899); border-radius:99px }

/* ═══ SOCIAL LINKS ═══ */
.gp-social-links-list { display:flex; flex-direction:column; gap:8px }
.gp-social-link-item { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:14px; background:var(--gp-bg); border:1px solid var(--gp-border); font-size:.8rem; font-weight:700 }
.gp-social-link-item.discord { color:#5865f2; background:rgba(88,101,242,.05) }
.gp-social-link-item.facebook { color:#1877f2; background:rgba(24,119,242,.05) }
.gp-social-link-item.instagram { color:#e4405f; background:rgba(228,64,95,.05) }
.gp-social-link-item.youtube { color:#ff0000; background:rgba(255,0,0,.05) }
.gp-social-link-item.wallet { color:var(--gp-green); background:rgba(16,185,129,.05) }

/* ═══ EDIT TABS ═══ */
.gp-form-tabs { display:flex; border-bottom:1px solid var(--gp-border); background:var(--gp-bg) }
.gp-form-tab { flex:1; padding:16px; border:0; background:transparent; font-size:.8rem; font-weight:700; color:var(--gp-muted); cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:8px }
.gp-form-tab:hover { color:var(--gp-text) }
.gp-form-tab.active { color:var(--gp-accent); position:relative }
.gp-form-tab.active::after { content:''; position:absolute; bottom:0; left:20%; right:20%; height:3px; background:var(--gp-accent); border-radius:3px 3px 0 0 }
.gp-tab-content { display:none }
.gp-tab-content.active { display:block }

@media (max-width:768px) {
    .gp-profile-hero { padding:30px 16px 24px; border-radius:0 }
    .gp-profile-hero-content { flex-direction:column; text-align:center; gap:16px }
    .gp-profile-avatar-large { width:80px; height:80px }
    .gp-profile-full-name { font-size:1.3rem }
    .gp-stats-visual-grid { grid-template-columns:1fr }
    .gp-profile-content-grid { grid-template-columns:1fr }
    .gp-form-tab span { display:none }
}
.gp-profile-info-links { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; font-size:.72rem; color:var(--gp-muted) }
.gp-profile-info-links i { width:14px; text-align:center }

/* ═══ GAME SKILLS EDITOR ═══ */
.gp-game-skills-editor { display:flex; flex-direction:column; gap:5px; margin-bottom:12px }
.gp-game-skill-row { display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:10px; background:var(--gp-bg); border:1px solid var(--gp-border) }
.gp-game-skill-icon-preview { width:26px; height:26px; border-radius:7px; display:flex; align-items:center; justify-content:center; background:rgba(139,92,246,.1); color:var(--gp-accent); font-size:.65rem; flex-shrink:0 }
.gp-game-skill-row-name { font-size:.72rem; font-weight:600; color:var(--gp-text); flex:1; min-width:0 }
.gp-game-skill-row-select { width:125px; font-size:.7rem; padding:6px 28px 6px 10px; border-radius:10px; flex-shrink:0 }
.gp-add-game-skill-btn { margin-top:2px; font-size:.7rem; padding:6px 12px; border-radius:10px; align-self:flex-start }

/* ═══ PROFILE BIO ═══ */
.gp-profile-bio { margin-bottom:16px; padding:14px 16px; border-radius:14px; background:var(--gp-bg); border:1px solid var(--gp-border) }
.gp-profile-bio label { font-size:.65rem; font-weight:700; color:var(--gp-muted); display:flex; align-items:center; gap:4px; margin-bottom:4px }
.gp-profile-bio label i { font-size:.55rem; opacity:.5 }
.gp-profile-bio p { font-size:.8rem; color:var(--gp-text); margin:0; line-height:1.5 }

/* ═══ PROFILE BAR (v2) ═══ */
.gp-profile-bar { display:flex; align-items:center; gap:16px; padding:12px 20px; margin:0 0 20px; border-radius:24px; background:linear-gradient(135deg,var(--gp-card),rgba(139,92,246,.04)); border:1px solid var(--gp-border); box-shadow:0 8px 30px rgba(0,0,0,.04); position:relative; overflow:hidden }
.gp-profile-avatar-wrap { position:relative; width:48px; height:48px; flex-shrink:0; cursor:pointer; transition:transform .2s }
.gp-profile-avatar-wrap:hover { transform:scale(1.05) }
.gp-profile-avatar-wrap img { width:100%; height:100%; border-radius:50%; object-fit:cover; position:relative; z-index:1; border:2px solid var(--gp-card) }
.gp-profile-header-top { display:flex; align-items:center; gap:10px; margin-bottom:4px }
.gp-verified-gamer-badge { color:#10b981; font-size:1.1rem; text-shadow:0 0 12px rgba(16,185,129,.4) }
.gp-profile-hero-bio { margin-bottom:18px; max-width:480px; position:relative }
.gp-profile-hero-bio p { font-size:.85rem; color:rgba(255,255,255,.75); line-height:1.5; margin:0; font-style:italic }
.gp-profile-hero-bio i { font-size:.7rem; color:var(--gp-accent); opacity:.6; margin-right:4px }

/* ═══ PLAYER HIGHLIGHTS ═══ */
.gp-player-highlights { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; padding:16px; background:rgba(139,92,246,.04); border-radius:20px; border:1px solid rgba(139,92,246,.1) }
.gp-highlight-item { display:flex; flex-direction:column; gap:4px }
.gp-highlight-label { font-size:.65rem; font-weight:800; color:var(--gp-muted); text-transform:uppercase; letter-spacing:.5px }
.gp-highlight-value { font-size:.95rem; font-weight:800; color:var(--gp-text); display:flex; align-items:center; gap:6px }
.gp-highlight-value.skill-rank { color:var(--gp-accent) }
.gp-highlight-value.fav-game { color:#ec4899 }

@media (max-width:768px) {
    .gp-profile-hero { padding:32px 20px 24px; border-radius:0 }
    .gp-profile-hero-content { flex-direction:column; text-align:center; gap:18px }
    .gp-profile-avatar-large { width:90px; height:90px; margin:0 auto }
    .gp-profile-header-top { justify-content:center; flex-wrap:wrap }
    .gp-profile-full-name { font-size:1.4rem }
    .gp-profile-hero-bio { margin:0 auto 16px }
    .gp-profile-hero-bio p { font-size:.8rem }
    .gp-profile-badges { justify-content:center }
    .gp-player-highlights { grid-template-columns:1fr; gap:12px; padding:12px 16px }
    .gp-highlight-item { align-items:center; text-align:center }
}

.gp-btn-remove-skill { width:32px; height:32px; border-radius:8px; border:0; background:rgba(239,68,68,.1); color:#ef4444; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .2s; flex-shrink:0 }
.gp-btn-remove-skill:hover { background:#ef4444; color:#fff; transform:scale(1.1) }

.gp-profile-meta { flex:1; min-width:0; cursor:pointer }
.gp-profile-name { font-size:1rem; font-weight:800; color:var(--gp-text); letter-spacing:-.02em; line-height:1.2 }
.gp-profile-nick { color:var(--gp-accent); font-weight:700 }
.gp-profile-tags { margin-top:4px; display:flex; align-items:center; gap:8px }

.gp-tag-cta { font-size:.7rem; font-weight:700; color:var(--gp-accent); background:rgba(139,92,246,.1); padding:4px 10px; border-radius:99px; border:1px dashed var(--gp-accent); cursor:pointer; transition:all .2s; display:inline-flex; align-items:center; gap:5px }
.gp-tag-cta:hover { background:var(--gp-accent); color:#fff; border-style:solid; transform:translateY(-1px) }

.gp-role-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:8px; font-size:.68rem; font-weight:700; background:rgba(0,0,0,.04); color:var(--gp-muted) }
.dark .gp-role-badge { background:rgba(255,255,255,.05) }
.gp-role-gamer { background:rgba(139,92,246,.1); color:var(--gp-accent) }
.gp-role-gamer i:only-child { margin-right:0 }
.gp-role-agent { background:rgba(245,158,11,.1); color:#d97706 }

.gp-profile-balance { display:flex; align-items:center; gap:8px; padding:6px 14px; border-radius:16px; background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.12); flex-shrink:0; margin-left:auto }
.gp-balance-amount { font-size:.95rem; font-weight:900; color:var(--gp-green); letter-spacing:-.5px }
.gp-balance-add { width:22px; height:22px; border-radius:50%; background:var(--gp-green); color:#fff; display:flex; align-items:center; justify-content:center; font-size:10px; text-decoration:none; transition:all .2s }
.gp-balance-add:hover { transform:scale(1.1) rotate(90deg) }

.gp-profile-actions { display:flex; align-items:center; gap:8px; flex-shrink:0 }
.gp-btn-become-agent { padding:8px 16px; border-radius:14px; font-size:.78rem; font-weight:800; gap:8px }
.gp-profile-more .gp-btn { width:38px; height:38px; border-radius:14px }

@media (max-width:768px) {
    .gp-profile-bar { padding:12px 14px; gap:10px; border-radius:20px }
    .gp-profile-avatar-wrap { width:40px; height:40px }
    .gp-profile-name { font-size:.85rem }
    .gp-profile-balance { padding:5px 10px; gap:6px }
    .gp-balance-amount { font-size:.8rem }
    .gp-btn-become-agent span { display:none }
    .gp-btn-become-agent { padding:8px; width:36px; height:36px; justify-content:center }
    .gp-profile-actions { gap:6px }
}

/* ═══ BECOME AGENT IN PROFILE ═══ */
.gp-profile-become-agent { display:flex; align-items:center; gap:12px; padding:14px 16px; border-radius:16px; background:linear-gradient(135deg,rgba(251,191,36,.06),rgba(245,158,11,.03)); border:1px solid rgba(251,191,36,.15); margin-bottom:14px }
.gp-profile-agent-icon { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:rgba(251,191,36,.12); color:#d97706; font-size:1.1rem; flex-shrink:0 }
.gp-profile-become-agent div { flex:1; min-width:0 }
.gp-profile-become-agent strong { display:block; font-size:.78rem; color:var(--gp-text) }
.gp-profile-become-agent p { font-size:.68rem; color:var(--gp-muted); margin:2px 0 0 }
.gp-profile-become-agent .gp-btn { flex-shrink:0 }

/* ═══ FULL-SCREEN MODALS ON MOBILE (P2P style) ═══ */
@media (max-width:480px) {
  .gp-modal .gp-modal-panel { max-width:none !important; max-height:none !important; border-radius:0 !important; width:100vw !important; height:100dvh !important; margin:0 !important; box-shadow:none !important }
  .gp-modal .gp-modal-head { border-radius:0 }
  body.gp-modal-open { overflow:hidden }
}

/* ═══ CUSTOM SELECT (P2P style) ═══ */
.gp-custom-select { position:relative; width:100% }
.gp-select-trigger { display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--gp-card); border:2px solid var(--gp-border); border-radius:12px; cursor:pointer; font-size:.78rem; font-weight:700; color:var(--gp-text); transition:all .2s; font-family:'Plus Jakarta Sans',sans-serif }
.gp-select-trigger:hover { border-color:var(--gp-accent); background:rgba(139,92,246,.04) }
.gp-select-trigger span { flex:1; text-align:left }
.gp-select-trigger::after { content:'\f078'; font-family:'Font Awesome 6 Free'; font-weight:900; font-size:10px; opacity:.4; transition:transform .2s; margin-left:4px }
.gp-custom-select.active .gp-select-trigger::after { transform:rotate(180deg); opacity:1; color:var(--gp-accent) }
.gp-select-options { position:absolute; top:calc(100% + 6px); left:0; right:0; background:var(--gp-card); border:1px solid var(--gp-border); border-radius:12px; box-shadow:0 12px 32px rgba(0,0,0,.12); z-index:200; display:none; padding:6px; animation:gpSelectIn .2s ease; max-height:220px; overflow-y:auto; scrollbar-width:none }
.gp-select-options::-webkit-scrollbar { display:none }
@keyframes gpSelectIn { from { opacity:0; transform:translateY(10px) } to { opacity:1; transform:translateY(0) } }
.gp-select-option { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:8px; cursor:pointer; font-size:.78rem; font-weight:600; color:var(--gp-muted); transition:all .15s; margin-bottom:3px; font-family:'Plus Jakarta Sans',sans-serif }
.gp-select-option:hover { background:var(--gp-bg); color:var(--gp-accent) }
.gp-select-option.active { background:rgba(139,92,246,.1); color:var(--gp-accent) }
.gp-custom-select.active .gp-select-options { display:block }
.gp-custom-select.active .gp-select-trigger { border-color:var(--gp-accent); box-shadow:0 0 0 4px rgba(139,92,246,.1) }
.gp-filter-select { flex-shrink:0; width:200px }
.gp-modal-step-indicator { display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:18px }
.gp-step-dot { width:8px; height:8px; border-radius:50%; background:var(--gp-border); transition:all .2s }
.gp-step-dot.active { background:var(--gp-accent); width:24px; border-radius:99px }
.gp-step-line { width:32px; height:2px; background:var(--gp-border) }

/* ═══ OTP ═══ */
.gp-otp-wrap { display:flex; gap:8px; justify-content:center; margin:12px 0 }
.gp-otp-box { width:44px; height:52px; border-radius:14px; border:2px solid var(--gp-border); text-align:center; font-size:1.3rem; font-weight:800; outline:none; font-family:'Plus Jakarta Sans',sans-serif; background:var(--gp-card); color:var(--gp-text); transition:all .2s }
.gp-otp-box:focus { border-color:var(--gp-accent); box-shadow:0 0 0 4px rgba(139,92,246,.1) }

/* ═══ RESPONSIVE ═══ */
@media (max-width:768px) {
  .gp-page { padding:0 6px 1.5rem }
  .gp-hero { padding:16px 12px 0; border-radius:0 0 24px 24px }
  .gp-hero-bg { min-height:300px; border-radius:0 0 24px 24px }
  .gp-hero-content h1 { font-size:clamp(18px,5vw,28px) }
  .gp-hero-content p { font-size:.78rem }
  .gp-hero-stats { grid-template-columns:1fr 1fr; gap:10px; padding:14px 4px 18px }
  .gp-stat-card { padding:16px 10px; border-radius:18px }
  .gp-stat-card .gp-stat-icon { font-size:20px; width:46px; height:46px; border-radius:14px; margin-bottom:8px }
  .gp-stat-value { font-size:20px }
  .gp-stat-label { font-size:11px }
  .gp-stat-card:hover { transform:none }
  .gp-profile-bar { flex-wrap:wrap; gap:6px; padding:10px 12px; border-radius:16px }
  .gp-profile-avatar-wrap { width:34px; height:34px }
  .gp-profile-meta { flex:1; min-width:0 }
  .gp-profile-name { font-size:.75rem; display:flex; align-items:center; gap:4px; flex-wrap:wrap }
  .gp-profile-nick { font-size:.58rem }
  .gp-profile-tags { margin-top:2px; gap:4px }
  .gp-profile-tags .gp-role-badge { font-size:.58rem; padding:2px 7px; border-radius:6px; gap:3px }
  .gp-profile-tags .gp-tag-cta { font-size:.6rem; padding:2px 8px; gap:3px }
  .gp-profile-balance { padding:3px 8px; gap:3px; border-radius:999px }
  .gp-balance-amount { font-size:.7rem }
  .gp-balance-add { width:18px; height:18px; font-size:7px }
  .gp-profile-actions { gap:4px; width:100%; justify-content:flex-end; border-top:1px solid var(--gp-border); padding-top:6px; margin-top:2px }
  .gp-profile-actions .gp-btn { width:30px; height:30px; font-size:.65rem; border-radius:9px; min-width:30px }
  .gp-profile-actions .gp-btn-accent span { display:none }
  .gp-profile-actions .gp-btn-accent { width:30px; padding:0; justify-content:center }
  .gp-section { padding:10px 4px }
  .gp-section-header { flex-direction:column; gap:10px; padding:0 8px 12px }
  .gp-section-header h2 { font-size:1rem }
  .gp-filter-select { width:100% }
  .gp-search { width:100% }
  .gp-search-input { font-size:.75rem; padding:9px 38px 9px 36px }
  .gp-grid { grid-template-columns:1fr; gap:12px; padding:0 4px }
  .gp-card { border-radius:20px }
  .gp-card-body h3 { font-size:.9rem }
.gp-card-body p { font-size:.72rem; display:-webkit-box; line-clamp:2; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden }
  .gp-card-meta { font-size:.7rem; grid-template-columns:1fr 1fr; gap:4px 8px }
  .gp-card-actions .gp-btn { font-size:.65rem; padding:6px 10px; border-radius:10px }
  .gp-my-grid { grid-template-columns:1fr; gap:14px }
  .gp-my-panel { border-radius:18px }
  .gp-my-panel-header { font-size:.82rem; padding:12px 16px }
  .gp-my-item { gap:8px; padding:10px 14px; border-radius:12px }
  .gp-my-info strong { font-size:.75rem }
  .gp-my-info span { font-size:.65rem }
  .gp-agent-quick { flex-direction:column; gap:6px }
  .gp-agent-quick .gp-btn { width:100% }
  .gp-modal-panel { margin:12px; max-height:88vh; border-radius:22px }
  .gp-modal-body { padding:16px }
  .gp-form-grid.two { grid-template-columns:1fr }
  .gp-form-grid.three { grid-template-columns:1fr }
  .gp-icon-picker { gap:4px }
  .gp-icon-opt { width:36px; height:36px; font-size:14px; border-radius:10px }
  .gp-color-picker { gap:4px }
  .gp-color-opt { width:28px; height:28px }
  .ba-method-grid { gap:6px }
  .ba-method-card { padding:10px 6px; border-radius:10px; min-height:50px }
  .ba-method-label { font-size:.6rem }
  #baMerchantBox { padding:14px; border-radius:12px }
  .gp-radio-group { flex-direction:column }
  .gp-radio { padding:10px 12px }
  .gp-participant { flex-wrap:wrap; gap:8px; padding:10px; border-radius:12px }
  .gp-team-member { flex-wrap:wrap; gap:8px; padding:10px; border-radius:12px }
  .gp-team-add { flex-direction:column; gap:8px }
  .gp-team-add .gp-btn { width:100%; justify-content:center }
  .gp-txn-item { flex-wrap:wrap; padding:10px; border-radius:12px }
  .gp-txn-amount { margin-left:auto }
  .gp-empty { padding:32px 16px; border-radius:16px }
  .gp-empty i { font-size:2rem }
  .gp-countdown { font-size:.65rem }
  .gp-agent-badge-grid { grid-template-columns:1fr 1fr; gap:8px }
  .gp-agent-hero-title { font-size:1rem }
  .ba-inputs-grid { grid-template-columns:1fr; gap:10px }
  .ba-merchant-box--premium { padding:12px; border-radius:14px }
  .ba-instr-step { font-size:.7rem }
  .gp-modal-actions { flex-wrap:wrap }
  .gp-modal-actions .gp-btn { flex:1; justify-content:center; font-size:.7rem; padding:9px 12px; border-radius:10px; min-width:0 }
  .gp-card-head .gp-card-icon { width:38px; height:38px; font-size:17px; border-radius:12px }
  .gp-card-head .gp-badge { font-size:9px; padding:3px 9px; border-radius:8px }
  .gp-card-actions .gp-agent-badge { font-size:9px; padding:2px 7px }
  .gp-card-meta i { font-size:.6rem }
  .gp-card-tag { font-size:.65rem }
  .gp-card-host { font-size:.65rem }
  .gp-my-panel .gp-my-icon { width:34px; height:34px; font-size:15px; border-radius:10px }
  .gp-badge.sm { font-size:8px; padding:2px 7px }
}
@media(max-width:768px) {
  .gp-grid { grid-template-columns:repeat(auto-fill,minmax(280px,1fr)) }
  .gp-card-body h3 { font-size:.9rem }
}
@media (max-width:480px) {
  .gp-hero-stats { gap:6px; padding:10px 2px 14px }
  .gp-stat-card { padding:10px 6px; border-radius:14px }
  .gp-stat-card .gp-stat-icon { font-size:16px; width:34px; height:34px; border-radius:10px; margin-bottom:4px }
  .gp-stat-value { font-size:15px }
  .gp-stat-label { font-size:9px }
  .gp-card { padding:0 }
  .gp-card-accent { height:2px }
  .gp-card-head { gap:6px; padding:10px 12px 0 }
  .gp-card-head .gp-card-icon { width:30px; height:30px; font-size:13px; border-radius:9px }
  .gp-card-body { padding:6px 12px 2px }
  .gp-card-body h3 { font-size:.85rem }
  .gp-card-meta { padding:2px 12px 8px; grid-template-columns:1fr; gap:3px 8px; font-size:.7rem }
  .gp-card-actions { margin:0 12px 0; padding:8px 0 12px; gap:4px }
  .gp-card-actions .gp-btn { font-size:10px; padding:5px 7px; border-radius:8px; min-width:0 }
  .gp-badge { font-size:8px; padding:2px 7px }
  .gp-countdown { font-size:9px }
  .gp-section-header h2 { font-size:.85rem }
  .gp-section-header { padding:0 4px 8px; gap:6px }
  .gp-grid { padding:0 2px; gap:8px; grid-template-columns:1fr }
  .gp-profile-bar { padding:6px 8px; gap:4px; border-radius:12px }
  .gp-profile-avatar-wrap { width:28px; height:28px }
  .gp-profile-name { font-size:.6rem; gap:2px }
  .gp-profile-nick { font-size:.5rem }
  .gp-profile-tags .gp-role-badge { font-size:.5rem; padding:1px 5px; border-radius:4px }
  .gp-profile-tags .gp-tag-cta { font-size:.52rem; padding:2px 5px }
  .gp-profile-balance { padding:2px 6px; gap:2px }
  .gp-balance-amount { font-size:.6rem }
  .gp-balance-add { width:14px; height:14px; font-size:5px }
  .gp-profile-actions { gap:2px; padding-top:3px; margin-top:1px }
  .gp-profile-actions .gp-btn { width:24px; height:24px; font-size:.5rem; border-radius:6px; min-width:24px }
  .gp-modal-panel { border-radius:18px; margin:8px }
  .gp-modal-body { padding:14px }
  .gp-modal-head { padding:12px 14px }
  .gp-hero { padding:10px 8px 0; border-radius:0 0 18px 18px }
  .gp-hero-bg { min-height:240px; border-radius:0 0 18px 18px }
  .gp-hero-content h1 { font-size:clamp(20px,5vw,30px) }
  .gp-hero-content p { font-size:.78rem }
  .gp-my-grid { grid-template-columns:1fr }

}

/* ═══ CLUBS (integrated) ═══ */
:root { --cl-primary:#7c3aed; --cl-card:#fff; --cl-bg:#f5f7fa; --cl-border:#e2e8f0; --cl-text:#1f2937; --cl-muted:#6b7280; --cl-green:#059669; --cl-red:#dc2626 }
.dark { --cl-card:#1e293b; --cl-bg:#0f172a; --cl-border:#334155; --cl-text:#f1f5f9; --cl-muted:#94a3b8 }
.cl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; margin-bottom:28px }
.cl-card { background:var(--cl-card); border-radius:20px; padding:20px; border:1px solid var(--cl-border); box-shadow:0 1px 4px rgba(0,0,0,.03); transition:all .25s; cursor:pointer; position:relative; overflow:hidden }
.cl-card:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,.06); border-color:var(--cl-primary, #7c3aed) }
.cl-card-top { display:flex; align-items:center; gap:14px; margin-bottom:12px }
.cl-card-logo { width:48px; height:48px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; font-weight:900; color:#fff; flex-shrink:0 }
.cl-card-info { flex:1 }
.cl-card-name { font-size:.95rem; font-weight:800; color:var(--cl-text); margin:0; line-height:1.2 }
.cl-card-tag { font-size:.7rem; font-weight:700; color:var(--cl-primary, #7c3aed); text-transform:uppercase; letter-spacing:.5px }
.cl-card-stats { display:flex; gap:12px; margin-top:10px; padding-top:10px; border-top:1px solid var(--cl-border) }
.cl-card-stat { text-align:center; flex:1 }
.cl-card-stat-value { font-size:1rem; font-weight:800; color:var(--cl-text) }
.cl-card-stat-label { font-size:.6rem; font-weight:600; color:var(--cl-muted); text-transform:uppercase; letter-spacing:.3px }
.cl-card-role { position:absolute; top:12px; right:12px; font-size:.6rem; font-weight:800; text-transform:uppercase; padding:3px 10px; border-radius:999px; background:rgba(124,58,237,.12); color:#7c3aed; letter-spacing:.3px }
.cl-card-role.owner { background:rgba(245,158,11,.12); color:#d97706 }
.cl-detail-header { display:flex; align-items:center; gap:20px; padding:24px; border-radius:24px; background:var(--cl-card); border:1px solid var(--cl-border); margin-bottom:16px }
.cl-detail-logo { width:72px; height:72px; border-radius:18px; display:flex; align-items:center; justify-content:center; font-size:1.8rem; font-weight:900; color:#fff; flex-shrink:0 }
.cl-detail-info { flex:1 }
.cl-detail-name { font-size:1.4rem; font-weight:900; color:var(--cl-text); margin:0; line-height:1.1 }
.cl-detail-tag { font-size:.75rem; font-weight:700; color:var(--cl-primary, #7c3aed) }
.cl-detail-stats { display:flex; gap:20px; margin-top:10px; flex-wrap:wrap }
.cl-detail-stat { text-align:center; padding:8px 18px; border-radius:12px; background:var(--cl-bg) }
.cl-detail-stat-value { font-size:1.1rem; font-weight:800; color:var(--cl-text) }
.cl-detail-stat-label { font-size:.65rem; font-weight:600; color:var(--cl-muted); text-transform:uppercase }
.cl-leaderboard { background:var(--cl-card); border-radius:20px; border:1px solid var(--cl-border); overflow:hidden }
.cl-lb-header { display:grid; grid-template-columns:40px 1fr 80px 80px 60px; gap:8px; padding:12px 16px; font-size:.7rem; font-weight:700; color:var(--cl-muted); text-transform:uppercase; background:var(--cl-bg); border-bottom:1px solid var(--cl-border) }
.cl-lb-row { display:grid; grid-template-columns:40px 1fr 80px 80px 60px; gap:8px; padding:12px 16px; align-items:center; border-bottom:1px solid var(--cl-border); transition:background .15s; font-size:.82rem; text-decoration:none; color:inherit }
.cl-lb-row:last-child { border-bottom:none }
.cl-lb-row:hover { background:var(--cl-bg) }
.cl-lb-rank { font-weight:800; color:var(--cl-muted); text-align:center }
.cl-lb-rank.gold { color:#f59e0b } .cl-lb-rank.silver { color:#94a3b8 } .cl-lb-rank.bronze { color:#cd7f32 }
.cl-lb-club { display:flex; align-items:center; gap:8px; font-weight:700; color:var(--cl-text) }
.cl-lb-club-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0 }
.cl-lb-val { font-weight:700; color:var(--cl-text); text-align:center }
.cl-club-hero { padding:28px 24px; border-radius:24px; background:linear-gradient(135deg,#0f172a,#1e1b4b,#1a0533); margin:0 0 24px; position:relative; overflow:hidden; border:1px solid rgba(139,92,246,.15) }
.cl-club-hero::before { content:''; position:absolute; top:-120px; right:-80px; width:350px; height:350px; border-radius:50%; background:radial-gradient(circle,rgba(139,92,246,.2),transparent 70%) }
.cl-club-hero-content { position:relative; z-index:1 }
.cl-club-hero h2 { font-size:1.7rem; font-weight:900; color:#fff; margin:0 0 4px; letter-spacing:-.03em }
.cl-club-hero p { font-size:.85rem; color:#94a3b8; margin:0 }
.cl-empty { text-align:center; padding:40px 20px; color:var(--cl-muted) }
.cl-empty i { font-size:2.5rem; margin-bottom:12px; opacity:.4 }
.cl-empty p { font-size:.9rem; margin:0 }
.cl-members { display:flex; flex-direction:column; gap:8px }
.cl-member { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:14px; background:var(--cl-card); border:1px solid var(--cl-border); transition:all .15s }
.cl-member:hover { border-color:var(--cl-primary, #7c3aed) }
.cl-member-avatar { width:40px; height:40px; border-radius:50%; object-fit:cover; background:var(--cl-bg) }
.cl-member-info { flex:1 }
.cl-member-name { font-size:.85rem; font-weight:700; color:var(--cl-text) }
.cl-member-joined { font-size:.7rem; color:var(--cl-muted) }
.cl-member-role { font-size:.65rem; font-weight:800; padding:3px 10px; border-radius:999px; text-transform:uppercase; letter-spacing:.3px }
.cl-member-role.owner { background:rgba(245,158,11,.12); color:#d97706 }
.cl-member-role.manager { background:rgba(59,130,246,.12); color:#2563eb }
.cl-member-role.player { background:rgba(16,185,129,.12); color:#059669 }

/* ═══ PLAYER MARKET (integrated) ═══ */
:root { --pm-primary:#7c3aed; --pm-card:#fff; --pm-bg:#f5f7fa; --pm-border:#e2e8f0; --pm-text:#1f2937; --pm-muted:#6b7280; --pm-green:#059669; --pm-red:#dc2626 }
.dark { --pm-card:#1e293b; --pm-bg:#0f172a; --pm-border:#334155; --pm-text:#f1f5f9; --pm-muted:#94a3b8 }
.pm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px }
.pm-card { background:var(--pm-card); border-radius:18px; padding:18px; border:1px solid var(--pm-border); transition:all .25s; position:relative; overflow:hidden }
.pm-card:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,.06); border-color:var(--pm-primary, #7c3aed) }
.pm-card-top { display:flex; align-items:center; gap:12px; margin-bottom:10px }
.pm-card-avatar { width:44px; height:44px; border-radius:50%; object-fit:cover; border:2px solid var(--pm-border) }
.pm-card-info { flex:1 }
.pm-card-name { font-size:.88rem; font-weight:800; color:var(--pm-text); margin:0 }
.pm-card-status { font-size:.65rem; font-weight:700; padding:2px 8px; border-radius:999px; display:inline-block }
.pm-card-status.free_agent { background:rgba(148,163,184,.15); color:var(--pm-muted) }
.pm-card-status.active { background:rgba(16,185,129,.12); color:var(--pm-green) }
.pm-card-body { margin-bottom:10px }
.pm-card-row { display:flex; justify-content:space-between; font-size:.75rem; color:var(--pm-muted); padding:3px 0; border-bottom:1px solid var(--pm-border) }
.pm-card-row:last-child { border-bottom:none }
.pm-card-row span:last-child { font-weight:700; color:var(--pm-text) }
.pm-card-actions { display:flex; gap:6px; flex-wrap:wrap }
.pm-card-badge { position:absolute; top:10px; right:10px; font-size:.55rem; font-weight:800; padding:3px 8px; border-radius:999px; text-transform:uppercase; letter-spacing:.3px }
.pm-card-badge.auction { background:rgba(245,158,11,.12); color:#d97706 }
.pm-tabs { display:flex; gap:4px; margin-bottom:20px; background:var(--pm-card); border-radius:14px; padding:4px; border:1px solid var(--pm-border) }
.pm-tab { flex:1; padding:10px; border-radius:10px; border:none; font-size:.78rem; font-weight:700; cursor:pointer; background:transparent; color:var(--pm-muted); font-family:'Plus Jakarta Sans',sans-serif; transition:all .2s }
.pm-tab:hover { color:var(--pm-text) }
.pm-tab.active { background:#7c3aed; color:#fff }
.pm-empty { text-align:center; padding:40px 20px; color:var(--pm-muted) }
.pm-empty i { font-size:2.5rem; margin-bottom:12px; opacity:.4 }
.pm-empty p { font-size:.9rem; margin:0 }
.pm-hero { padding:28px 24px; border-radius:24px; background:linear-gradient(135deg,#0f172a,#1e1b4b,#1a0533); margin:0 0 24px; position:relative; overflow:hidden; border:1px solid rgba(139,92,246,.15) }
.pm-hero::before { content:''; position:absolute; top:-120px; right:-80px; width:350px; height:350px; border-radius:50%; background:radial-gradient(circle,rgba(236,72,153,.15),transparent 70%) }
.pm-hero-content { position:relative; z-index:1 }
.pm-hero h2 { font-size:1.7rem; font-weight:900; color:#fff; margin:0; letter-spacing:-.03em }
.pm-hero p { font-size:.85rem; color:#94a3b8; margin:4px 0 0 }
.pm-detail { background:var(--pm-card); border-radius:24px; padding:24px; border:1px solid var(--pm-border); margin-bottom:24px; box-shadow:0 4px 20px rgba(0,0,0,.04) }
.pm-detail-head { display:flex; align-items:center; gap:20px; margin-bottom:20px }
.pm-detail-avatar { width:72px; height:72px; border-radius:50%; object-fit:cover; border:3px solid var(--pm-border) }
.pm-detail-info { flex:1 }
.pm-detail-name { font-size:1.3rem; font-weight:900; color:var(--pm-text); margin:0; line-height:1.2 }
.pm-detail-meta { font-size:.8rem; color:var(--pm-muted); margin-top:2px }
.pm-detail-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; margin-bottom:16px }
.pm-detail-stat { padding:12px; border-radius:12px; background:var(--pm-bg); text-align:center }
.pm-detail-stat-value { font-size:1.2rem; font-weight:800; color:var(--pm-text) }
.pm-detail-stat-label { font-size:.65rem; font-weight:600; color:var(--pm-muted); text-transform:uppercase; letter-spacing:.3px }
.pm-transfer-list { display:flex; flex-direction:column; gap:6px }
.pm-transfer-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:10px; background:var(--pm-bg); font-size:.78rem; color:var(--pm-text) }
@media (max-width:768px) {
    .cl-grid, .pm-grid { grid-template-columns:1fr }
    .cl-detail-header, .pm-detail-head { flex-direction:column; text-align:center }
    .cl-lb-header, .cl-lb-row { grid-template-columns:30px 1fr 60px 60px 50px; font-size:.75rem }
}

/* Ensure footer stays below bottom nav on mobile */
.dream-footer { position: relative !important; z-index: 1 !important; }

/* Offset for fixed top navbar when scrolling to sections */
.gp-section { scroll-margin-top: 70px; transition: transform .4s cubic-bezier(.4,0,.2,1), opacity .4s cubic-bezier(.4,0,.2,1); }
.gp-section.slide-out-left { transform: translateX(-40px); opacity: 0; }
.gp-section.slide-out-right { transform: translateX(40px); opacity: 0; }
.gp-section.slide-in-right { transform: translateX(40px); opacity: 0; }
.gp-section.slide-in-left { transform: translateX(-40px); opacity: 0; }
.gp-section.slide-in { transform: translateX(0); opacity: 1; }
</style>
<div class="gp-page" id="tournamentsPage" data-csrf="<?php echo htmlspecialchars($csrfToken); ?>" data-user-id="<?php echo (int)($viewerId ?? 0); ?>" data-role="<?php echo htmlspecialchars($userRole); ?>" data-balance="<?php echo $userBalance; ?>" data-has-profile="<?php echo !empty($_SESSION['nickname']) ? '1' : '0'; ?>">

    <!-- ═══ HERO ═══ -->
    <section class="gp-hero">
        <div class="gp-hero-bg"></div>
        <div class="gp-hero-content">
            <span class="gp-hero-badge"><i class="fas fa-trophy"></i> DreamBD Arena</span>
            <h1>Compete. Conquer. <span class="text-gradient">Rise Up.</span></h1>
            <p>Join tournaments, build your team, and battle for glory and prizes.</p>
            <!-- <div class="gp-hero-actions">
                <?php if ($viewerId): ?>
                    <a href="#browse" class="gp-btn gp-btn-primary" data-scroll-to="browse"><i class="fas fa-gamepad"></i> Browse tournaments</a>
                    <a href="#dashboard" class="gp-btn gp-btn-ghost" data-scroll-to="dashboard"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <?php else: ?>
                    <a href="index.php?page=register" class="gp-btn gp-btn-primary" data-page="register"><i class="fas fa-user-plus"></i> Join free</a>
                    <a href="index.php?page=login" class="gp-btn gp-btn-ghost" data-page="login"><i class="fas fa-right-to-bracket"></i> Sign in</a>
                <?php endif; ?>
            </div> -->
        </div>
        <div class="gp-hero-stats">
            <div class="gp-stat-card"><span class="gp-stat-icon"><i class="fas fa-trophy"></i></span><span class="gp-stat-value"><?php echo count($tournaments); ?></span><span class="gp-stat-label">Tournaments</span></div>
            <div class="gp-stat-card"><span class="gp-stat-icon"><i class="fas fa-coins"></i></span><span class="gp-stat-value">৳<?php echo number_format($totalPrize); ?></span><span class="gp-stat-label">Prize pool</span></div>
            <div class="gp-stat-card"><span class="gp-stat-icon"><i class="fas fa-fire"></i></span><span class="gp-stat-value"><?php echo $statusStats['live'] + $statusStats['ongoing']; ?></span><span class="gp-stat-label">Active</span></div>
            <div class="gp-stat-card"><span class="gp-stat-icon"><i class="fas fa-calendar-check"></i></span><span class="gp-stat-value"><?php echo $statusStats['upcoming']; ?></span><span class="gp-stat-label">Upcoming</span></div>
        </div>
    </section>

    <!-- ═══ PROFILE BAR (v2) ═══ -->
    <?php if ($viewerId):
        $gpNickname = $_SESSION['nickname'] ?? '';
        $gpAvatar = htmlspecialchars($_SESSION['avatar'] ?? 'default.png');
        $gpFullName = htmlspecialchars($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User'));
    ?>
    <section class="gp-profile-bar">
        <div class="gp-profile-avatar-wrap" data-gp-profile title="View profile">
            <img src="assets/avatars/<?php echo $gpAvatar; ?>" alt="" onerror="this.src='assets/avatars/default.png'">
            <span class="gp-profile-avatar-ring"></span>
        </div>
        <div class="gp-profile-meta" data-gp-profile>
            <div class="gp-profile-name">
                <?php if ($gpNickname): ?>
                    <span class="gp-profile-nick">@<?php echo htmlspecialchars($gpNickname); ?></span>
                <?php else: ?>
                    <span><?php echo $gpFullName; ?></span>
                <?php endif; ?>
            </div>
            <div class="gp-profile-tags">
                <?php if (!$gpNickname): ?>
                    <span class="gp-tag-cta" onclick="event.stopPropagation(); document.getElementById('createGamerProfileModal').classList.remove('hidden'); document.getElementById('gpOverlay').classList.remove('hidden');">
                        <i class="fas fa-plus-circle"></i> Create Gamer Profile
                    </span>
                <?php else: ?>
                    <span class="gp-role-badge gp-role-gamer">
                        <i class="fas fa-gamepad"></i> <?php echo htmlspecialchars($_SESSION['skill_level'] ?? ''); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="gp-profile-balance">
            <span class="gp-balance-amount">৳<?php echo number_format($userBalance, 0); ?></span>
            <a href="index.php?page=balance" class="gp-balance-add" title="Add funds"><i class="fas fa-plus"></i></a>
        </div>

        <div class="gp-profile-actions">
            <?php if ($userRole !== 'agent'): ?>
                <button type="button" class="gp-btn gp-btn-sm gp-btn-gradient gp-btn-become-agent" data-open-modal="becomeAgentModal" title="Become an agent">
                    <i class="fas fa-crown"></i> <span>Become Agent</span>
                </button>
            <?php else: ?>
                <button type="button" class="gp-btn gp-btn-sm gp-btn-accent" data-open-modal="createTournamentModal" title="Create Tournament">
                    <i class="fas fa-plus"></i> <span>Tournament</span>
                </button>
            <?php endif; ?>
            
            <div class="gp-profile-more">
                <button type="button" class="gp-btn gp-btn-sm gp-btn-ghost" data-open-modal="createTeamModal" title="Create team"><i class="fas fa-users"></i></button>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ═══ MOBILE BOTTOM NAV ═══ -->
    <nav class="gp-mobile-nav">
        <div class="gp-mobile-nav-inner">
            <button type="button" class="gp-mobile-nav-item active" data-scroll-to="browse">
                <i class="fas fa-trophy"></i> Tournaments
                <span class="gp-nav-indicator"></span>
            </button>
            <?php if ($viewerId): ?>
            <button type="button" class="gp-mobile-nav-item" data-scroll-to="dashboard">
                <i class="fas fa-chart-pie"></i> Dashboard
                <span class="gp-nav-indicator"></span>
            </button>
            <div class="gp-mobile-nav-plus">
                <button type="button" class="gp-mobile-nav-plus-btn" data-open-modal="<?php echo $userRole === 'agent' ? 'createTournamentModal' : 'becomeAgentModal'; ?>">
                    <?php if ($userRole === 'agent'): ?>
                    <i class="fas fa-plus"></i>
                    <?php else: ?>
                    <span style="font-size:1.5rem;line-height:1">👑</span>
                    <?php endif; ?>
                </button>
            </div>
            <button type="button" class="gp-mobile-nav-item" data-scroll-to="clubs">
                <i class="fas fa-flag"></i> Club
                <span class="gp-nav-indicator"></span>
            </button>
            <button type="button" class="gp-mobile-nav-item" data-scroll-to="hire">
                <i class="fas fa-gavel"></i> Hire
                <span class="gp-nav-indicator"></span>
            </button>
            <?php endif; ?>
        </div>
    </nav>

    <!-- ═══ TOURNAMENT LIST ═══ -->
    <section id="browse" class="gp-section">
        <div class="gp-section-header">
            <h2><i class="fas fa-trophy"></i> Tournaments</h2>
            <div class="gp-filter-select">
                <div class="gp-custom-select" id="gpFilterSelect">
                    <div class="gp-select-trigger">
                        <span>All Tournaments</span>
                    </div>
                    <div class="gp-select-options">
                        <div class="gp-select-option active" data-value="all">All Tournaments</div>
                        <div class="gp-select-option" data-value="live">Live</div>
                        <div class="gp-select-option" data-value="upcoming">Upcoming</div>
                        <div class="gp-select-option" data-value="ongoing">Ongoing</div>
                        <div class="gp-select-option" data-value="completed">Completed</div>
                        <div class="gp-select-option" data-value="cancelled">Cancelled</div>
                    </div>
                    <input type="hidden" id="tournamentStatusFilter" value="all">
                </div>
            </div>
            <div class="gp-search">
                <i class="fas fa-search gp-search-icon"></i>
                <input type="text" id="gpSearch" placeholder="Search tournaments..." class="gp-search-input">
                <button type="button" class="gp-search-clear" id="gpSearchClear" aria-label="Clear search"><i class="fas fa-times"></i></button>
            </div>
        </div>

        <div class="gp-grid" id="tournamentGrid">
            <?php if (empty($tournaments)): ?>
            <div class="gp-empty">
                <i class="fas fa-trophy"></i>
                <h3>No tournaments yet</h3>
                <p>Tournaments will appear here once created.</p>
            </div>
            <?php else: ?>
                <?php foreach ($tournaments as $t):
                    $tid = (int)($t['id'] ?? 0);
                    $title = htmlspecialchars($t['title'] ?? 'Tournament');
                    $desc = htmlspecialchars($t['description'] ?? '');
                    $status = $t['status'] ?? 'upcoming';
                    $prize = htmlspecialchars($t['prize_money'] ?? '');
                    $cat = htmlspecialchars($t['category'] ?? '');
                    $maxTeams = (int)($t['max_teams'] ?? 0);
                    $regd = (int)($t['registered_teams'] ?? 0);
                    $icon = htmlspecialchars($t['game_icon'] ?? 'fa-gamepad');
                    $accent = htmlspecialchars($t['accent_color'] ?? '#7c3aed');
                    $startsAt = $t['starts_at'] ?? null;
                    $startDate = $startsAt ? date('M j, Y', strtotime($startsAt)) : 'TBD';
                    $startTime = $startsAt ? date('g:i A', strtotime($startsAt)) : '';
                    $entryFee = (float)($t['entry_fee'] ?? 0);
                    $agentId = (int)($t['agent_id'] ?? 0);

                    // Get agent name
                    $agentName = '';
                    if ($agentId > 0) {
                        try { $aStmt = $db->prepare("SELECT full_name, username FROM users WHERE id = ?"); $aStmt->execute([$agentId]); $a = $aStmt->fetch(); $agentName = htmlspecialchars($a['full_name'] ?? $a['username'] ?? ''); } catch (Throwable $e) {}
                    }

                    $badgeClass = $statusBadgeClasses[$status] ?? '';
                    $isRegistered = false; $regTeamId = 0;
                    foreach ($myRegistrations as $reg) {
                        if ((int)$reg['tournament_id'] === $tid && $reg['status'] === 'confirmed') {
                            $isRegistered = true; $regTeamId = (int)($reg['team_id'] ?? 0); break;
                        }
                    }
                    $canRegister = in_array($status, ['upcoming', 'live']) && $viewerId;
                    $isFull = $maxTeams > 0 && $regd >= $maxTeams;
                    $isAgentOwner = $agentId > 0 && $agentId === (int)$viewerId;
                    $canOpenRoom = $viewerId ? userCanAccessTournamentRoom($db, $tid, (int)$viewerId) : false;
                ?>
                <article class="gp-card" data-id="<?php echo $tid; ?>" data-status="<?php echo $status; ?>">
                    <div class="gp-card-accent" style="background:<?php echo $accent; ?>"></div>
                    <div class="gp-card-head">
                        <span class="gp-card-icon" style="background:<?php echo $accent; ?>;color:#fff"><i class="fas <?php echo $icon; ?>"></i></span>
                        <span class="gp-badge <?php echo $badgeClass; ?>"><?php echo strtoupper($status); ?></span>
                        <?php if ($prize): ?>
                        <span style="margin-left:auto;font-size:.7rem;font-weight:800;color:#f59e0b;display:inline-flex;align-items:center;gap:3px;padding:3px 10px;border-radius:999px;background:rgba(245,158,11,.1)"><i class="fas fa-trophy" style="font-size:.6rem"></i> ৳<?php echo $prize; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="gp-card-body">
                        <h3><?php echo $title; ?></h3>
                        <?php if ($cat || $agentName): ?>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:2px 0 0">
                            <?php if ($cat): ?><span class="gp-card-tag"><i class="fas fa-tag"></i> <?php echo $cat; ?></span><?php endif; ?>
                            <?php if ($agentName): ?><span class="gp-card-host"><i class="fas fa-crown" style="color:#f59e0b"></i> <?php echo $agentName; ?></span><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($desc): ?><p><?php echo $desc; ?></p><?php endif; ?>
                    </div>
                    <div class="gp-card-meta">
                        <div><i class="fas fa-calendar"></i> <span class="gp-date"><?php echo $startDate; ?></span> <?php if ($startTime): ?><span class="gp-time"><?php echo $startTime; ?></span><?php endif; ?>
                            <?php if ($startsAt && !in_array($status, ['completed','cancelled'])): ?>
                            <span class="gp-countdown" data-starts="<?php echo strtotime($startsAt); ?>"></span>
                            <?php endif; ?>
                        </div>
                        <div><i class="fas fa-users"></i> <?php echo $regd; ?><?php echo $maxTeams > 0 ? "/$maxTeams" : ''; ?> teams</div>
                        <?php if ($entryFee > 0): ?><div><i class="fas fa-coins" style="color:#10b981"></i> ৳<?php echo number_format($entryFee, 0); ?> entry</div><?php endif; ?>
                        <?php if ($status === 'cancelled'): ?><div style="color:#dc2626"><i class="fas fa-ban"></i> Cancelled</div><?php endif; ?>
                        <?php
                        $bracketType = $t['bracket_type'] ?? 'single_elimination';
                        $bracketIcons = ['single_elimination'=>'fa-diagram-project','double_elimination'=>'fa-arrow-right-arrow-left','round_robin'=>'fa-arrows-rotate','swiss'=>'fa-sitemap'];
                        $bracketLabels = ['single_elimination'=>'Single Elim','double_elimination'=>'Double Elim','round_robin'=>'Round Robin','swiss'=>'Swiss'];
                        $restrictedClubId = $t['restricted_club_id'] ?? null;
                        $restrictedClubName = '';
                        $restrictedClubColour = '';
                        if ($restrictedClubId) {
                            $rcStmt = $db->prepare("SELECT name, colour FROM clubs WHERE id = ?");
                            $rcStmt->execute([$restrictedClubId]);
                            $rc = $rcStmt->fetch();
                            if ($rc) { $restrictedClubName = $rc['name']; $restrictedClubColour = $rc['colour']; }
                        }
                        ?>
                        <div><i class="fas <?php echo $bracketIcons[$bracketType] ?? 'fa-diagram-project'; ?>" style="color:#7c3aed"></i> <?php echo $bracketLabels[$bracketType] ?? 'Single Elim'; ?></div>
                        <?php if ($maxTeams > 0): ?>
                        <div style="grid-column:1/-1;margin-top:2px">
                            <div style="display:flex;align-items:center;gap:8px;font-size:.7rem;color:var(--gp-muted)">
                                <span style="flex:1;height:4px;border-radius:2px;background:var(--gp-border);overflow:hidden">
                                    <span style="display:block;height:100%;width:<?php echo min(100, round($regd/$maxTeams*100)); ?>%;border-radius:2px;background:linear-gradient(90deg,#7c3aed,#a78bfa);transition:width .3s"></span>
                                </span>
                                <span style="font-weight:700"><?php echo $regd; ?>/<?php echo $maxTeams; ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($restrictedClubName): ?>
                        <div style="grid-column:1/-1;margin-top:2px;display:flex;align-items:center;gap:6px"><span style="display:inline-flex;align-items:center;gap:4px;font-size:.65rem;font-weight:700;padding:3px 8px;border-radius:999px;background:rgba(124,58,237,.1);color:<?php echo $restrictedClubColour ?: '#7c3aed'; ?>"><i class="fas fa-lock"></i> <?php echo htmlspecialchars($restrictedClubName); ?> only</span></div>
                        <?php endif; ?>
                    </div>
                    <div class="gp-card-actions">
                        <?php if ($status === 'cancelled'): ?>
                            <span class="gp-btn gp-btn-sm gp-btn-disabled"><i class="fas fa-ban"></i> Cancelled</span>
                        <?php elseif ($isRegistered): ?>
                            <button class="gp-btn gp-btn-sm gp-btn-success" disabled><i class="fas fa-check-circle"></i> Registered</button>
                            <button class="gp-btn gp-btn-sm gp-btn-ghost gp-unregister" data-id="<?php echo $tid; ?>" data-fee="<?php echo $entryFee; ?>"><i class="fas fa-xmark"></i></button>
                        <?php elseif ($canRegister && !$isFull): ?>
                            <button class="gp-btn gp-btn-sm gp-btn-primary gp-join-btn" data-id="<?php echo $tid; ?>" data-title="<?php echo htmlspecialchars($title, ENT_QUOTES); ?>" data-fee="<?php echo $entryFee; ?>"><i class="fas fa-right-to-bracket"></i> <?php echo $entryFee > 0 ? 'Join (৳'.number_format($entryFee,0).')' : 'Join free'; ?></button>
                        <?php elseif ($isFull): ?>
                            <span class="gp-btn gp-btn-sm gp-btn-disabled"><i class="fas fa-lock"></i> Full</span>
                        <?php else: ?>
                            <span class="gp-btn gp-btn-sm gp-btn-disabled">Closed</span>
                        <?php endif; ?>
                        <button class="gp-btn gp-btn-sm gp-btn-ghost gp-view-btn" data-id="<?php echo $tid; ?>"><i class="fas fa-eye"></i> View</button>
                        <?php if ($canOpenRoom): ?>
                            <a class="gp-btn gp-btn-sm gp-btn-outline" href="index.php?page=tournament-room&id=<?php echo $tid; ?>" data-no-ajax><i class="fas fa-door-open"></i> Room</a>
                        <?php endif; ?>
                        <?php if ($isAgentOwner): ?>
                        <span class="gp-agent-badge"><i class="fas fa-crown"></i> Yours</span>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- ═══ MY STUFF ═══ -->
    <?php if ($viewerId): ?>
    <section id="dashboard" class="gp-section">
        <div class="gp-section-header">
            <h2><i class="fas fa-chart-pie"></i> Dashboard</h2>
        </div>
        <div class="gp-my-grid">
            <div class="gp-my-panel">
                <div class="gp-my-panel-header"><i class="fas fa-trophy"></i> My registrations <span class="gp-count"><?php echo count($myRegistrations); ?></span></div>
                <div class="gp-my-list">
                    <?php if (empty($myRegistrations)): ?>
                    <div class="gp-my-empty">No registrations yet. Join a tournament above!</div>
                    <?php else: foreach ($myRegistrations as $reg):
                        $rTitle = htmlspecialchars($reg['title'] ?? '');
                        $rStatus = $reg['tournament_status'] ?? '';
                        $rBadge = $statusBadgeClasses[$rStatus] ?? '';
                    ?>
                    <div class="gp-my-item">
                        <span class="gp-my-icon" style="background:<?php echo htmlspecialchars($reg['accent_color'] ?? '#7c3aed'); ?>"><i class="fas <?php echo htmlspecialchars($reg['game_icon'] ?? 'fa-gamepad'); ?>"></i></span>
                        <div class="gp-my-info">
                            <strong><?php echo $rTitle; ?></strong>
                            <span><span class="gp-badge sm <?php echo $rBadge; ?>"><?php echo strtoupper($rStatus); ?></span></span>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center">
                            <a class="gp-btn gp-btn-xs gp-btn-outline" href="index.php?page=tournament-room&id=<?php echo (int)$reg['tournament_id']; ?>" data-no-ajax><i class="fas fa-door-open"></i> Room</a>
                            <button class="gp-btn gp-btn-xs gp-btn-ghost gp-unregister" data-id="<?php echo (int)$reg['tournament_id']; ?>" data-fee="<?php echo (float)($reg['entry_fee'] ?? 0); ?>"><i class="fas fa-xmark"></i> Leave</button>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="gp-my-panel">
                <div class="gp-my-panel-header"><i class="fas fa-users"></i> My teams <span class="gp-count"><?php echo count($myTeams); ?></span></div>
                <div class="gp-my-list">
                    <?php if (empty($myTeams)): ?>
                    <div class="gp-my-empty">No teams yet. <button class="gp-btn gp-btn-xs gp-btn-primary" data-open-modal="createTeamModal">Create one</button></div>
                    <?php else: foreach ($myTeams as $team):
                        $tId = (int)$team['id'];
                        $tName = htmlspecialchars($team['name'] ?? '');
                        $tGame = htmlspecialchars($team['game'] ?? '');
                        $tRole = $team['my_role'] ?? 'member';
                        $memberStmt = $db->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ?");
                        $memberStmt->execute([$tId]);
                        $memberCount = (int)$memberStmt->fetchColumn();
                    ?>
                    <div class="gp-my-item team-item" data-team-id="<?php echo $tId; ?>">
                        <span class="gp-my-icon team-icon" style="background:linear-gradient(135deg,#7c3aed,#2563eb)"><i class="fas fa-users"></i></span>
                        <div class="gp-my-info">
                            <strong><?php echo $tName; ?></strong>
                            <span><?php echo $memberCount; ?> members <?php if ($tGame): ?>&middot; <?php echo $tGame; ?><?php endif; ?> &middot; <?php echo ucfirst($tRole); ?></span>
                        </div>
                        <button class="gp-btn gp-btn-xs gp-btn-outline gp-manage-team" data-team-id="<?php echo $tId; ?>" data-team-name="<?php echo htmlspecialchars($tName, ENT_QUOTES); ?>"><i class="fas fa-users-gear"></i> Manage</button>
                        <?php if ($tRole === 'captain'): ?>
                        <button class="gp-btn gp-btn-xs gp-btn-ghost gp-delete-team" data-team-id="<?php echo $tId; ?>" data-team-name="<?php echo htmlspecialchars($tName, ENT_QUOTES); ?>" style="color:#dc2626"><i class="fas fa-trash-can"></i></button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <?php if ($userRole === 'agent'): ?>
            <div class="gp-my-panel gp-agent-panel">
                <div class="gp-my-panel-header"><i class="fas fa-crown"></i> Agent dashboard <span class="gp-count">৳<?php echo number_format($userBalance, 0); ?></span></div>
                <div class="gp-agent-quick">
                    <button class="gp-btn gp-btn-sm gp-btn-accent" data-open-modal="createTournamentModal"><i class="fas fa-trophy"></i> Add tournament</button>
                    <button class="gp-btn gp-btn-sm gp-btn-outline" data-open-modal="agentHistoryModal"><i class="fas fa-clock-rotate-left"></i> History</button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ═══ LEADERBOARD ═══ -->
    <section id="leaderboard" class="gp-section" style="margin-top:32px">
        <div class="gp-section-header">
            <h2><i class="fas fa-ranking-star"></i> Leaderboard</h2>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <div class="gp-custom-select" style="width:160px" id="lbFilterWrap">
                    <div class="gp-select-trigger"><span>Player Rankings</span></div>
                    <div class="gp-select-options">
                        <div class="gp-select-option active" data-value="">Player Rankings</div>
                        <div class="gp-select-option" data-value="club">Club Rankings</div>
                    </div>
                    <input type="hidden" id="lbFilter" value="">
                </div>
                <button class="gp-btn gp-btn-sm gp-btn-ghost" onclick="loadLeaderboard()"><i class="fas fa-rotate"></i> Refresh</button>
            </div>
        </div>
        <div style="background:var(--gp-card);border-radius:20px;border:1px solid var(--gp-border);overflow:hidden">
            <div style="display:grid;grid-template-columns:36px 1fr 80px 90px 60px;gap:8px;padding:12px 16px;font-size:.7rem;font-weight:700;color:var(--gp-muted);text-transform:uppercase;background:var(--gp-bg);border-bottom:1px solid var(--gp-border)" id="lbHeader">
                <span>#</span><span>Player</span><span>Points</span><span>Prize</span><span>Rank</span>
            </div>
            <div id="lbBody">
                <div style="text-align:center;padding:40px 20px;color:var(--gp-muted)"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.4"></i><p style="font-size:.9rem;margin:8px 0 0">Loading leaderboard...</p></div>
            </div>
        </div>
    </section>

    <!-- ═══ CLUBS ═══ -->
    <section id="clubs" class="gp-section">
        <div class="cl-club-hero">
            <div class="cl-club-hero-content">
                <h2><i class="fas fa-flag"></i> Clubs & Teams</h2>
                <p>Browse clubs, check standings, and manage your team.</p>
                <?php if ($viewerId): ?>
                <div style="margin-top:14px;display:flex;gap:8px">
                    <button class="gp-btn gp-btn-sm gp-btn-primary" onclick="openClubModal()"><i class="fas fa-plus"></i> Create Club</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($viewerId && $myClubs): ?>
        <h2 style="font-size:1rem;font-weight:800;margin:0 0 12px;color:var(--cl-text)"><i class="fas fa-star"></i> My Clubs</h2>
        <div class="cl-grid">
            <?php foreach ($myClubs as $c): ?>
            <div class="cl-card" onclick="window.location.href='?page=tournaments&club_id=<?php echo $c['id']; ?>#clubs'">
                <span class="cl-card-role <?php echo $c['my_role']; ?>"><?php echo htmlspecialchars($c['my_role']); ?></span>
                <div class="cl-card-top">
                    <div class="cl-card-logo" style="background:<?php echo htmlspecialchars($c['colour'] ?? '#7c3aed'); ?>"><?php echo strtoupper(substr($c['tag'], 0, 2)); ?></div>
                    <div class="cl-card-info">
                        <div class="cl-card-name"><?php echo htmlspecialchars($c['name']); ?></div>
                        <div class="cl-card-tag"><?php echo htmlspecialchars($c['tag']); ?></div>
                    </div>
                </div>
                <div class="cl-card-stats">
                    <div class="cl-card-stat"><div class="cl-card-stat-value"><?php echo (int)$c['member_count']; ?></div><div class="cl-card-stat-label">Members</div></div>
                    <div class="cl-card-stat"><div class="cl-card-stat-value"><?php echo (int)$c['trophies']; ?></div><div class="cl-card-stat-label">Trophies</div></div>
                    <div class="cl-card-stat"><div class="cl-card-stat-value">#<?php echo (int)$c['rank'] ?: '--'; ?></div><div class="cl-card-stat-label">Rank</div></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($clubDetail): ?>
        <div class="cl-detail-header">
            <div class="cl-detail-logo" style="background:<?php echo htmlspecialchars($clubDetail['colour'] ?? '#7c3aed'); ?>"><?php echo strtoupper(substr($clubDetail['tag'], 0, 2)); ?></div>
            <div class="cl-detail-info">
                <div class="cl-detail-name"><?php echo htmlspecialchars($clubDetail['name']); ?></div>
                <div class="cl-detail-tag">#<?php echo htmlspecialchars($clubDetail['tag']); ?> by <?php echo htmlspecialchars($clubDetail['owner_full'] ?: $clubDetail['owner_name']); ?></div>
                <?php if ($clubDetail['description']): ?><p style="margin:6px 0 0;font-size:.82rem;color:var(--cl-muted)"><?php echo nl2br(htmlspecialchars($clubDetail['description'])); ?></p><?php endif; ?>
                <div class="cl-detail-stats">
                    <div class="cl-detail-stat"><div class="cl-detail-stat-value"><?php echo (int)$clubDetail['member_count']; ?></div><div class="cl-detail-stat-label">Members</div></div>
                    <div class="cl-detail-stat"><div class="cl-detail-stat-value"><?php echo (int)$clubDetail['trophies']; ?></div><div class="cl-detail-stat-label">Trophies</div></div>
                    <div class="cl-detail-stat"><div class="cl-detail-stat-value"><?php echo (int)$clubDetail['total_points']; ?></div><div class="cl-detail-stat-label">Points</div></div>
                    <div class="cl-detail-stat"><div class="cl-detail-stat-value">#<?php echo (int)$clubDetail['rank'] ?: '--'; ?></div><div class="cl-detail-stat-label">Rank</div></div>
                </div>
            </div>
            <?php if ($viewerId): $clubIsOwner = $clubDetail['owner_id'] == $viewerId; $clubIsMember = false; foreach ($clubMembers as $m) { if ((int)$m['id'] === $viewerId) { $clubIsMember = true; break; } } ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php if ($clubIsOwner): ?>
                <button class="gp-btn gp-btn-sm gp-btn-accent" onclick="openEditClubModal(<?php echo $clubDetail['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                <?php elseif ($clubIsMember): ?>
                <button class="gp-btn gp-btn-sm gp-btn-danger" onclick="leaveClub(<?php echo $clubDetail['id']; ?>)"><i class="fas fa-sign-out-alt"></i> Leave</button>
                <?php else: ?>
                <button class="gp-btn gp-btn-sm gp-btn-primary" onclick="joinClub(<?php echo $clubDetail['id']; ?>)"><i class="fas fa-plus"></i> Join</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="cl-members" style="margin-bottom:16px">
            <?php foreach ($clubMembers as $m): ?>
            <div class="cl-member">
                <img src="assets/avatars/<?php echo htmlspecialchars($m['avatar'] ?? 'default.png'); ?>" alt="" class="cl-member-avatar" onerror="this.src='assets/avatars/default.png'">
                <div class="cl-member-info">
                    <div class="cl-member-name"><?php echo htmlspecialchars($m['full_name'] ?: $m['username']); ?></div>
                    <div class="cl-member-joined">Joined <?php echo date('M j, Y', strtotime($m['joined_at'])); ?></div>
                </div>
                <span class="cl-member-role <?php echo $m['role']; ?>"><?php echo $m['role']; ?></span>
            </div>
            <?php endforeach; if (empty($clubMembers)): ?>
            <div class="cl-empty"><i class="fas fa-users"></i><p>No members yet.</p></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <h2 style="font-size:1rem;font-weight:800;margin:0 0 12px;color:var(--cl-text)"><i class="fas fa-trophy"></i> Club Rankings</h2>
        <div class="cl-leaderboard">
            <div class="cl-lb-header">
                <span>#</span><span>Club</span><span>Points</span><span>Trophies</span><span>Members</span>
            </div>
            <?php $i=0; foreach ($allClubs as $c): $i++; ?>
            <a href="?page=tournaments&club_id=<?php echo $c['id']; ?>#clubs" class="cl-lb-row">
                <div class="cl-lb-rank <?php if ($i===1) echo 'gold'; elseif ($i===2) echo 'silver'; elseif ($i===3) echo 'bronze'; ?>"><?php echo $i; ?></div>
                <div class="cl-lb-club"><span class="cl-lb-club-dot" style="background:<?php echo htmlspecialchars($c['colour'] ?? '#7c3aed'); ?>"></span><?php echo htmlspecialchars($c['name']); ?></div>
                <div class="cl-lb-val"><?php echo number_format((int)$c['total_points']); ?></div>
                <div class="cl-lb-val"><?php echo (int)$c['trophies']; ?></div>
                <div class="cl-lb-val"><?php echo (int)$c['member_count']; ?></div>
            </a>
            <?php endforeach; if (empty($allClubs)): ?>
            <div class="cl-empty" style="padding:30px"><i class="fas fa-flag"></i><p>No clubs yet.</p></div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ═══ PLAYER MARKET / HIRE ═══ -->
    <section id="hire" class="gp-section">
        <div class="pm-hero">
            <div class="pm-hero-content">
                <h2><i class="fas fa-gavel"></i> Player Market</h2>
                <p>Buy, sell, and bid on players. Build your dream squad.</p>
            </div>
        </div>

        <?php if ($playerDetail): ?>
        <div class="pm-detail">
            <a href="?page=tournaments#hire" class="gp-btn gp-btn-sm gp-btn-ghost" style="margin-bottom:12px">&larr; Back to Market</a>
            <div class="pm-detail-head">
                <img src="assets/avatars/<?php echo htmlspecialchars($playerDetail['avatar'] ?? 'default.png'); ?>" alt="" class="pm-detail-avatar" onerror="this.src='assets/avatars/default.png'">
                <div class="pm-detail-info">
                    <div class="pm-detail-name"><?php echo htmlspecialchars($playerDetail['full_name'] ?: $playerDetail['username']); ?></div>
                    <div class="pm-detail-meta">
                        <?php if ($playerDetail['nickname']): ?>@<?php echo htmlspecialchars($playerDetail['nickname']); ?> · <?php endif; ?>
                        Status: <?php echo $playerDetail['status']; ?>
                        <?php if ($playerDetail['club_name']): ?> · <span style="color:<?php echo htmlspecialchars($playerDetail['club_colour'] ?? '#7c3aed'); ?>;font-weight:700"><?php echo htmlspecialchars($playerDetail['club_name']); ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($playerDetail['stats']): $s = $playerDetail['stats']; ?>
            <div class="pm-detail-stats">
                <div class="pm-detail-stat"><div class="pm-detail-stat-value"><?php echo (int)$s['total_matches']; ?></div><div class="pm-detail-stat-label">Matches</div></div>
                <div class="pm-detail-stat"><div class="pm-detail-stat-value"><?php echo (int)$s['total_goals']; ?></div><div class="pm-detail-stat-label">Goals</div></div>
                <div class="pm-detail-stat"><div class="pm-detail-stat-value"><?php echo (int)$s['total_assists']; ?></div><div class="pm-detail-stat-label">Assists</div></div>
                <div class="pm-detail-stat"><div class="pm-detail-stat-value"><?php echo (int)$s['total_wins']; ?></div><div class="pm-detail-stat-label">Wins</div></div>
                <div class="pm-detail-stat"><div class="pm-detail-stat-value"><?php echo (int)$s['total_kills']; ?></div><div class="pm-detail-stat-label">Kills</div></div>
            </div>
            <?php endif; ?>
            <?php if ($viewerId && $playerDetail['status'] === 'free_agent' && $playerDetail['owner_id'] != $viewerId): ?>
            <button class="gp-btn gp-btn-sm gp-btn-accent" onclick="openBuyModal(<?php echo $playerDetail['id']; ?>, '<?php echo htmlspecialchars($playerDetail['full_name'] ?: $playerDetail['username']); ?>', <?php echo $playerDetail['market_value'] ?: 0; ?>)"><i class="fas fa-shopping-cart"></i> Buy (৳<?php echo number_format($playerDetail['market_value'] ?: 0); ?>)</button>
            <?php endif; ?>
            <?php if (!empty($playerDetail['transfers'])): ?>
            <h4 style="font-size:.85rem;font-weight:800;margin:20px 0 10px;color:var(--pm-text)"><i class="fas fa-exchange-alt"></i> Transfer History</h4>
            <div class="pm-transfer-list">
                <?php foreach ($playerDetail['transfers'] as $t): ?>
                <div class="pm-transfer-item">
                    <i class="fas fa-circle" style="color:<?php echo $t['type']==='auction'?'#f59e0b':($t['type']==='direct_sale'?'#10b981':'#dc2626'); ?>"></i>
                    <span style="font-weight:700;text-transform:uppercase;font-size:.7rem"><?php echo $t['type']; ?></span>
                    <?php if ($t['amount'] > 0): ?><span style="font-weight:700;color:var(--pm-green)">৳<?php echo number_format($t['amount']); ?></span><?php endif; ?>
                    <span style="margin-left:auto;font-size:.7rem;color:var(--pm-muted)"><?php echo date('M j, Y', strtotime($t['created_at'])); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="pm-tabs" id="pmTabs">
            <button class="pm-tab active" data-tab="free"><i class="fas fa-users"></i> Free Agents (<?php echo count($players); ?>)</button>
            <button class="pm-tab" data-tab="auctions"><i class="fas fa-gavel"></i> Live Auctions (<?php echo count($auctions); ?>)</button>
        </div>

        <div class="pm-tab-content active" id="tabFree">
            <?php if (empty($players)): ?>
            <div class="pm-empty"><i class="fas fa-users-slash"></i><p>No free agents available.</p></div>
            <?php else: ?>
            <div class="pm-grid">
                <?php foreach ($players as $p): ?>
                <div class="pm-card">
                    <div class="pm-card-top">
                        <img src="assets/avatars/<?php echo htmlspecialchars($p['avatar'] ?? 'default.png'); ?>" alt="" class="pm-card-avatar" onerror="this.src='assets/avatars/default.png'">
                        <div class="pm-card-info">
                            <div class="pm-card-name"><?php echo htmlspecialchars($p['full_name'] ?: $p['username']); ?></div>
                            <span class="pm-card-status <?php echo $p['status']; ?>"><?php echo str_replace('_', ' ', $p['status']); ?></span>
                        </div>
                    </div>
                    <div class="pm-card-body">
                        <div class="pm-card-row"><span>Market Value</span><span>৳<?php echo number_format($p['market_value'] ?: 0); ?></span></div>
                        <?php if ($p['rating'] > 0): ?>
                        <div class="pm-card-row"><span>Rating</span><span><?php echo $p['rating']; ?> / 5.0</span></div>
                        <?php endif; ?>
                    </div>
                    <div class="pm-card-actions">
                        <a href="?page=tournaments&user_id=<?php echo $p['user_id']; ?>#hire" class="gp-btn gp-btn-sm gp-btn-ghost"><i class="fas fa-eye"></i> Details</a>
                        <?php if ($viewerId && (int)$p['owner_id'] === $viewerId): ?>
                        <button class="gp-btn gp-btn-sm gp-btn-outline" onclick="openAuctionModal(<?php echo $p['id']; ?>)"><i class="fas fa-gavel"></i> Auction</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="pm-tab-content" id="tabAuctions" style="display:none">
            <?php if (empty($auctions)): ?>
            <div class="pm-empty"><i class="fas fa-hourglass"></i><p>No active auctions right now.</p></div>
            <?php else: ?>
            <div class="pm-grid">
                <?php foreach ($auctions as $a): ?>
                <?php $timeLeft = max(0, strtotime($a['end_time']) - time()); $hours = floor($timeLeft / 3600); $mins = floor(($timeLeft % 3600) / 60); ?>
                <div class="pm-card">
                    <span class="pm-card-badge auction"><i class="fas fa-gavel"></i> LIVE</span>
                    <div class="pm-card-top">
                        <img src="assets/avatars/<?php echo htmlspecialchars($a['player_avatar'] ?? 'default.png'); ?>" alt="" class="pm-card-avatar" onerror="this.src='assets/avatars/default.png'">
                        <div class="pm-card-info">
                            <div class="pm-card-name"><?php echo htmlspecialchars($a['player_full'] ?: $a['player_name']); ?></div>
                            <span style="font-size:.7rem;color:var(--pm-muted)">by <?php echo htmlspecialchars($a['seller_name']); ?></span>
                        </div>
                    </div>
                    <div class="pm-card-body">
                        <div class="pm-card-row"><span>Current Bid</span><span style="color:var(--pm-green);font-size:.9rem">৳<?php echo number_format($a['highest_bid'] ?: $a['base_price']); ?></span></div>
                        <div class="pm-card-row"><span>Base Price</span><span>৳<?php echo number_format($a['base_price']); ?></span></div>
                        <div class="pm-card-row"><span>Total Bids</span><span><?php echo (int)$a['total_bids']; ?></span></div>
                        <div class="pm-card-row"><span>Time Left</span><span style="font-weight:800;color:<?php echo $timeLeft < 3600 ? '#dc2626' : 'var(--pm-text)'; ?>"><?php echo $hours; ?>h <?php echo $mins; ?>m</span></div>
                    </div>
                    <div class="pm-card-actions">
                        <a href="?page=tournaments&user_id=<?php echo $a['player_id']; ?>#hire" class="gp-btn gp-btn-sm gp-btn-ghost"><i class="fas fa-eye"></i> Details</a>
                        <?php if ($viewerId && (int)$a['seller_id'] !== $viewerId): ?>
                        <button class="gp-btn gp-btn-sm gp-btn-primary" onclick="openBidModal(<?php echo $a['id']; ?>, <?php echo $a['highest_bid'] ?: $a['base_price']; ?>, <?php echo $a['min_increment'] ?: 50; ?>)"><i class="fas fa-gavel"></i> Bid</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>



<script>
function loadLeaderboard() {
    var body = document.getElementById('lbBody');
    body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--gp-muted)"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.4"></i></div>';
    var type = document.getElementById('lbFilter').value;
    var data = type === 'club' 
        ? new URLSearchParams({ action:'get_club_standings' })
        : new URLSearchParams({ action:'get_leaderboard', limit:50 });
    fetch('handlers/tournament_handler.php', { method:'POST', body:data })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (type === 'club') {
            if (!res.success || !res.standings || !res.standings.length) {
                body.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--gp-muted)"><i class="fas fa-flag" style="font-size:2.5rem;opacity:.4;margin-bottom:12px;display:block"></i><p style="font-size:.9rem;margin:0">No club rankings yet.</p></div>';
                document.getElementById('lbHeader').innerHTML = '<span>#</span><span>Club</span><span>Points</span><span>Trophies</span><span>Members</span>';
                return;
            }
            document.getElementById('lbHeader').innerHTML = '<span>#</span><span>Club</span><span>Points</span><span>Trophies</span><span>Members</span>';
            var html = '';
            res.standings.forEach(function(c, i) {
                var rankClass = i===0 ? 'gold' : (i===1 ? 'silver' : (i===2 ? 'bronze' : ''));
                html += '<a href="index.php?page=tournaments&club_id=' + c.id + '#clubs" class="gp-lb-row">';
                html += '<div class="gp-lb-rank ' + rankClass + '">' + (i+1) + '</div>';
                html += '<div style="display:flex;align-items:center;gap:8px;font-weight:700;color:var(--gp-text)"><span style="width:10px;height:10px;border-radius:50%;background:' + (c.colour || '#7c3aed') + ';flex-shrink:0"></span>' + c.name + '</div>';
                html += '<div class="gp-lb-cell">' + (c.total_club_points || c.total_points || 0) + '</div>';
                html += '<div class="gp-lb-cell">' + (c.trophies || 0) + '</div>';
                html += '<div class="gp-lb-cell">' + (c.member_count || 0) + '</div></a>';
            });
            body.innerHTML = html;
        } else {
            if (!res.success || !res.leaderboard || !res.leaderboard.length) {
                body.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--gp-muted)"><i class="fas fa-trophy" style="font-size:2.5rem;opacity:.4;margin-bottom:12px;display:block"></i><p style="font-size:.9rem;margin:0">No rankings yet. Complete a tournament to appear.</p></div>';
                return;
            }
            document.getElementById('lbHeader').innerHTML = '<span>#</span><span>Player</span><span>Points</span><span>Prize</span><span>Rank</span>';
            var html = '';
            res.leaderboard.forEach(function(p, i) {
                var rankClass = i===0 ? 'gold' : (i===1 ? 'silver' : (i===2 ? 'bronze' : ''));
                html += '<div class="gp-lb-row" onclick="window.location.href=\'index.php?page=profile&user=' + p.user_id + '\'">';
                html += '<div class="gp-lb-rank ' + rankClass + '">' + (i+1) + '</div>';
                html += '<div style="display:flex;align-items:center;gap:8px;font-weight:600;color:var(--gp-text)">';
                if (p.avatar) html += '<img src="assets/avatars/' + p.avatar + '" style="width:28px;height:28px;border-radius:50%;object-fit:cover" onerror="this.style.display=\'none\'">';
                html += (p.nickname || p.full_name || p.username) + '</div>';
                html += '<div class="gp-lb-cell">' + (p.total_points || 0) + '</div>';
                html += '<div class="gp-lb-prize">৳' + (parseFloat(p.total_prize) || 0).toLocaleString() + '</div>';
                html += '<div class="gp-lb-cell">' + (p.best_rank || '--') + '</div></div>';
            });
            body.innerHTML = html;
        }
    }).catch(function() {
        body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gp-muted)"><i class="fas fa-exclamation-triangle" style="font-size:2rem;opacity:.4;margin-bottom:8px;display:block"></i><p style="font-size:.9rem;margin:0">Failed to load leaderboard.</p></div>';
    });
}

/** --- CUSTOM SELECTS --- **/
function initAllCustomSelects() {
    document.querySelectorAll('.gp-custom-select').forEach(sel => {
        // Prevent double init
        if (sel.dataset.initialized) return;
        sel.dataset.initialized = 'true';

        const trigger = sel.querySelector('.gp-select-trigger');
        const options = sel.querySelectorAll('.gp-select-option');
        const hiddenInput = sel.querySelector('input[type="hidden"]');
        const triggerSpan = trigger ? trigger.querySelector('span') : null;

        if (!trigger || !triggerSpan) return;

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            // Close others
            document.querySelectorAll('.gp-custom-select.active').forEach(other => {
                if (other !== sel) other.classList.remove('active');
            });
            sel.classList.toggle('active');
        });

        options.forEach(opt => {
            opt.addEventListener('click', () => {
                const val = opt.dataset.value;
                const txt = opt.innerText;

                options.forEach(o => o.classList.remove('active'));
                opt.classList.add('active');

                triggerSpan.innerText = txt;
                if (hiddenInput) {
                    hiddenInput.value = val;
                    // Trigger change event manually
                    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                sel.classList.remove('active');
            });
        });
    });
}
window.initAllCustomSelects = initAllCustomSelects;

// Global click to close selects (guard against duplicate on ajax nav)
if (!window._gpCloseSelectsInit) {
    window._gpCloseSelectsInit = true;
    document.addEventListener('click', () => {
        document.querySelectorAll('.gp-custom-select.active').forEach(s => s.classList.remove('active'));
    });
}

document.addEventListener('DOMContentLoaded', function() {
initAllCustomSelects();

// Tournament Filter Logic
var statusFilterInput = document.getElementById('tournamentStatusFilter');
if (statusFilterInput) {
    statusFilterInput.addEventListener('change', function() {
        var f = this.value;
        document.querySelectorAll('.gp-card').forEach(function(c) {
            c.classList.toggle('hidden', f !== 'all' && c.getAttribute('data-status') !== f);
        });
    });
}

var filter = document.getElementById('lbFilter');

    if (filter) {
        filter.addEventListener('change', loadLeaderboard);
        loadLeaderboard();
    }

    // Update join type UI toggle
    document.querySelectorAll('input[name="join_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const teamGroup = document.getElementById('teamSelectGroup');
            const soloGroup = document.getElementById('soloNameGroup');
            if (this.value === 'team') {
                if (teamGroup) teamGroup.style.display = 'block';
                if (soloGroup) soloGroup.style.display = 'none';
            } else {
                if (teamGroup) teamGroup.style.display = 'none';
                if (soloGroup) soloGroup.style.display = 'block';
            }
        });
    });

    // Re-init when modals open (in case they were hidden or newly added)
    document.querySelectorAll('[data-open-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
            setTimeout(initAllCustomSelects, 50);
        });
    });

    // Profile Edit Tab Switching
    document.querySelectorAll('.gp-form-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            const target = this.dataset.tab;
            const modal = this.closest('.gp-modal');
            
            // Update tabs
            modal.querySelectorAll('.gp-form-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Update content
            modal.querySelectorAll('.gp-tab-content').forEach(c => c.classList.remove('active'));
            modal.querySelector(`#tab-${target}`).classList.add('active');
            
            // Re-init selects for new visible content
            setTimeout(initAllCustomSelects, 20);
        });
    });
});
</script>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- MODALS -->
<!-- ════════════════════════════════════════════════════════════ -->

<div class="gp-overlay hidden" id="gpOverlay"></div>

<!-- Become Agent Modal (Info + Payment) — Premium Bangla Design -->
<div class="gp-modal hidden" id="becomeAgentModal">
    <div class="gp-modal-panel gp-modal-panel--crown">
        <div class="gp-modal-head">
            <h3><i class="fas fa-crown" style="color:#f59e0b"></i> এজেন্ট হোন</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body">

            <!-- ═══ Step 1: Benefits Info (Bangla) ═══ -->
            <div class="gp-modal-step active" id="baInfoStep">
                <div class="gp-agent-hero">
                    <div class="gp-agent-hero-glow"></div>
                    <span class="gp-agent-hero-icon">👑</span>
                    <div class="gp-agent-hero-title">এজেন্ট পাওয়ার্স আনলক করুন</div>
                    <div class="gp-agent-hero-sub">টুর্নামেন্ট তৈরি করুন, আয় করুন এবং নিজের ব্র্যান্ড গড়ুন। প্রো গেমারদের জন্য সেরা সুযোগ!</div>
                </div>

                <div class="gp-agent-badge-grid">
                    <div class="gp-agent-badge">
                        <span class="gp-agent-badge-icon">🏆</span>
                        <div class="gp-agent-badge-label">টুর্নামেন্ট হোস্ট</div>
                        <div class="gp-agent-badge-desc">আনলিমিটেড ইভেন্ট তৈরি করুন</div>
                    </div>
                    <div class="gp-agent-badge">
                        <span class="gp-agent-badge-icon">💰</span>
                        <div class="gp-agent-badge-label">রিয়েল ইনকাম</div>
                        <div class="gp-agent-badge-desc">এন্ট্রি ফি থেকে কমিশন আয়</div>
                    </div>
                    <div class="gp-agent-badge">
                        <span class="gp-agent-badge-icon">⚡</span>
                        <div class="gp-agent-badge-label">ইনস্ট্যান্ট অ্যাক্সেস</div>
                        <div class="gp-agent-badge-desc">সব প্রিমিয়াম ফিচার আনলক</div>
                    </div>
                    <div class="gp-agent-badge">
                        <span class="gp-agent-badge-icon">👑</span>
                        <div class="gp-agent-badge-label">এজেন্ট ভেরিফাইড</div>
                        <div class="gp-agent-badge-desc">প্রোফাইলে গোল্ডেন ব্যাজ</div>
                    </div>
                </div>

                <div class="gp-agent-fee">
                    <span class="gp-agent-fee-label">এককালীন অ্যাক্টিভেশন ফি</span>
                    <span class="gp-agent-fee-amount">৳৫০০</span>
                </div>

                <div class="gp-modal-actions" style="border-top:1px solid var(--gp-border); padding-top:20px">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>পরে করব</button>
                    <button type="button" class="gp-btn gp-btn-accent" id="baProceedBtn" style="background:linear-gradient(135deg,#f59e0b,#d97706); border:0; color:#fff"><i class="fas fa-bolt"></i> পেমেন্টে যান</button>
                </div>
            </div>

            <!-- ═══ Step 2: Payment (Redesigned) ═══ -->
            <div class="gp-modal-step" id="baPayStep">
                <div class="ba-pay-wrap">

                    <div class="gp-pay-note">
                        <i class="fas fa-shield-halved"></i>
                        <span>নিচের যেকোনো একটি মেথডে <strong>৫০০ টাকা</strong> পাঠিয়ে এজেন্ট অ্যাক্টিভেট করুন।</span>
                    </div>

                    <div class="ba-method-grid">
                        <?php $methods = ['bkash'=>['label'=>'bKash','color'=>'#E2136E'],'nagad'=>['label'=>'Nagad','color'=>'#E8522E'],'rocket'=>['label'=>'Rocket','color'=>'#CC0000']]; ?>
                        <?php foreach ($methods as $mk => $mv): ?>
                        <div class="ba-method-card<?php echo $mk === 'bkash' ? ' active' : ''; ?>" data-method="<?php echo $mk; ?>" style="<?php echo $mk === 'bkash' ? 'border-color:'.$mv['color'].'; background:rgba(226,19,110,.06)' : ''; ?>">
                            <img src="assets/images/payment-icon/<?php echo $mk; ?>-logo-mobile-banking.png" alt="<?php echo $mv['label']; ?>">
                            <span class="ba-method-label"><?php echo $mv['label']; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="baMerchantBox"></div>

                    <div class="ba-inputs-grid">
                        <div>
                            <label class="ba-input-label"><i class="fas fa-phone"></i> মোবাইল নাম্বার</label>
                            <div class="ba-input-wrap">
                                <input type="tel" id="baPhone" class="ba-input" placeholder="01XXXXXXXXX">
                            </div>
                        </div>
                        <div>
                            <label class="ba-input-label"><i class="fas fa-hashtag"></i> ট্রানজেকশন আইডি</label>
                            <div class="ba-input-wrap">
                                <input type="text" id="baTxid" class="ba-input" placeholder="TXID দিন" style="text-transform:uppercase">
                            </div>
                        </div>
                    </div>

                    <div class="gp-modal-actions" style="border-top:1px solid var(--gp-border); padding-top:18px">
                        <button type="button" class="gp-btn gp-btn-ghost" id="baBackToInfo"><i class="fas fa-arrow-left"></i> পেছনে</button>
                        <button type="button" class="gp-btn gp-btn-accent" id="baPayBtn" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed); border:0; color:#fff"><i class="fas fa-paper-plane"></i> সাবমিট</button>
                    </div>
                    <div class="gp-feedback hidden" id="baFeedback"></div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ═══ View Gamer Profile Modal (v2) ═══ -->
<div class="gp-modal hidden" id="viewGamerProfileModal">
    <div class="gp-modal-panel lg">
        <div class="gp-modal-head">
            <h3><i class="fas fa-id-card" style="color:#7c3aed"></i> Gamer Profile</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body" style="padding:0">
            <!-- Profile Hero Section -->
            <div class="gp-profile-hero">
                <div class="gp-profile-hero-bg"></div>
                <div class="gp-profile-hero-content">
                    <div class="gp-profile-avatar-large">
                        <img src="assets/avatars/<?php echo htmlspecialchars($_SESSION['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                        <span class="gp-avatar-status"></span>
                    </div>
                    <div class="gp-profile-main-info">
                        <div class="gp-profile-header-top">
                            <?php if (!empty($_SESSION['nickname'])): ?>
                                <h2 class="gp-profile-full-name">@<?php echo htmlspecialchars($_SESSION['nickname']); ?> <span class="gp-verified-gamer-badge"><i class="fas fa-certificate"></i></span></h2>
                                <div class="gp-profile-sub-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?></div>
                            <?php else: ?>
                                <h2 class="gp-profile-full-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?> <span class="gp-verified-gamer-badge"><i class="fas fa-certificate"></i></span></h2>
                            <?php endif; ?>
                        </div>

                        <div class="gp-profile-hero-bio">
                            <?php if (!empty($_SESSION['bio'])): ?>
                                <p><i class="fas fa-quote-left"></i> <?php echo htmlspecialchars($_SESSION['bio']); ?></p>
                            <?php else: ?>
                                <p class="text-white opacity-40" style="font-style:italic; font-size:.8rem">"Mastering the game, one level at a time."</p>
                            <?php endif; ?>
                        </div>

                        <div class="gp-profile-badges">
                            <?php if ($userRole === 'agent'): ?>
                            <span class="gp-badge gp-badge-agent"><i class="fas fa-crown"></i> Official Agent</span>
                            <?php endif; ?>
                            <span class="gp-badge gp-badge-status"><i class="fas fa-circle"></i> Online</span>
                        </div>
                    </div>
                </div>
            </div>

            <div style="padding:24px">
                <!-- Player Highlights (Skill & Favorite Game) -->
                <div class="gp-player-highlights">
                    <div class="gp-highlight-item">
                        <span class="gp-highlight-label">Ranking Level</span>
                        <span class="gp-highlight-value skill-rank"><i class="fas fa-bolt"></i> <?php echo htmlspecialchars($_SESSION['skill_level'] ?? 'Rookie'); ?></span>
                    </div>
                    <div class="gp-highlight-item">
                        <span class="gp-highlight-label">Favorite Game</span>
                        <span class="gp-highlight-value fav-game"><i class="fas fa-heart"></i> <?php echo htmlspecialchars($_SESSION['favorite_game'] ?? 'Any'); ?></span>
                    </div>
                </div>

                <!-- Quick Stats Grid -->
                <div class="gp-stats-visual-grid">
                    <div class="gp-stat-visual-card">
                        <div class="gp-stat-visual-icon" style="color:#7c3aed"><i class="fas fa-gamepad"></i></div>
                        <div class="gp-stat-visual-data">
                            <span class="gp-stat-visual-value"><?php echo (int)($playerStats['total_matches'] ?? 0); ?></span>
                            <span class="gp-stat-visual-label">Matches</span>
                        </div>
                    </div>
                    <div class="gp-stat-visual-card">
                        <div class="gp-stat-visual-icon" style="color:#f59e0b"><i class="fas fa-trophy"></i></div>
                        <div class="gp-stat-visual-data">
                            <span class="gp-stat-visual-value"><?php echo (int)($playerStats['total_wins'] ?? 0); ?></span>
                            <span class="gp-stat-visual-label">Wins</span>
                        </div>
                    </div>
                    <div class="gp-stat-visual-card">
                        <div class="gp-stat-visual-icon" style="color:#ef4444"><i class="fas fa-crosshairs"></i></div>
                        <div class="gp-stat-visual-data">
                            <span class="gp-stat-visual-value"><?php echo (int)($playerStats['total_kills'] ?? 0); ?></span>
                            <span class="gp-stat-visual-label">Kills</span>
                        </div>
                    </div>
                </div>

                <div class="gp-profile-content-grid">
                    <div class="gp-profile-main-column">
                        <div class="gp-profile-block">
                            <h4 class="gp-block-title"><i class="fas fa-signal"></i> Expertise Showcase</h4>
                            <div class="gp-visual-skills">
                                <?php if (empty($gameSkills)): ?>
                                <div class="gp-no-skills-visual">
                                    <i class="fas fa-ghost"></i>
                                    <p>Expertise pending assessment.</p>
                                    <button class="gp-btn gp-btn-sm gp-btn-ghost" data-edit-profile>Setup Skills</button>
                                </div>
                                <?php else: foreach ($gameSkills as $gs): ?>
                                <div class="gp-skill-visual-item">
                                    <div class="gp-skill-visual-header">
                                        <span class="gp-skill-name"><i class="fas <?php echo htmlspecialchars($gs['game_icon'] ?? 'fa-gamepad'); ?>"></i> <?php echo htmlspecialchars($gs['game']); ?></span>
                                        <span class="gp-skill-rank"><?php echo htmlspecialchars($gs['skill_level'] ?: '--'); ?></span>
                                    </div>
                                    <div class="gp-skill-progress-wrap">
                                        <div class="gp-skill-progress-bar" style="width:<?php echo ['Beginner'=>20,'Intermediate'=>40,'Advanced'=>60,'Pro'=>80,'Elite'=>100][$gs['skill_level']] ?? 30; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="gp-profile-side-column">
                        <div class="gp-profile-block">
                            <h4 class="gp-block-title"><i class="fas fa-link"></i> Connectivity</h4>
                            <div class="gp-social-links-list">
                                <?php
                                $hasSocial = false;
                                $socials = [
                                    'discord' => ['icon' => 'fab fa-discord', 'class' => 'discord'],
                                    'facebook' => ['icon' => 'fab fa-facebook', 'class' => 'facebook'],
                                    'instagram' => ['icon' => 'fab fa-instagram', 'class' => 'instagram'],
                                    'youtube' => ['icon' => 'fab fa-youtube', 'class' => 'youtube']
                                ];
                                foreach ($socials as $key => $meta):
                                    $val = $_SESSION[$key] ?? '';
                                    if (!empty($val)):
                                        $hasSocial = true;
                                ?>
                                <div class="gp-social-link-item <?php echo $meta['class']; ?>">
                                    <i class="<?php echo $meta['icon']; ?>"></i>
                                    <span><?php echo htmlspecialchars($val); ?></span>
                                </div>
                                <?php endif; endforeach; ?>

                                <?php if (!$hasSocial): ?>
                                <p class="text-muted" style="font-size:.75rem">No social links added.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="gp-modal-actions" style="margin-top:12px; border-top:1px solid var(--gp-border); padding-top:20px">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Close</button>
                    <button type="button" class="gp-btn gp-btn-accent" data-edit-profile><i class="fas fa-pen-nib"></i> Edit Profile</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Create/Edit Gamer Profile Modal (v2) ═══ -->
<div class="gp-modal hidden" id="createGamerProfileModal">
    <div class="gp-modal-panel lg">
        <div class="gp-modal-head">
            <h3><i class="fas fa-id-card" style="color:#7c3aed"></i> <?php echo !empty($_SESSION['nickname']) ? 'Edit' : 'Create'; ?> Gamer Profile</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body" style="padding:0">
            <div class="gp-form-tabs">
                <button type="button" class="gp-form-tab active" data-tab="basic"><i class="fas fa-info-circle"></i> Basic Info</button>
                <button type="button" class="gp-form-tab" data-tab="skills"><i class="fas fa-signal"></i> Game Skills</button>
                <button type="button" class="gp-form-tab" data-tab="social"><i class="fas fa-share-nodes"></i> Social & More</button>
            </div>

            <form id="gamerProfileForm" style="padding:24px">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="update_profile">

                <div class="gp-tab-content active" id="tab-basic">
                    <div class="gp-form-grid two">
                        <div class="gp-form-group">
                            <label><i class="fas fa-tag"></i> Gaming nickname</label>
                            <input type="text" name="nickname" class="gp-input" placeholder="e.g. ShadowStrike" maxlength="50" value="<?php echo htmlspecialchars($_SESSION['nickname'] ?? ''); ?>" required>
                        </div>
                        <div class="gp-form-group">
                            <label><i class="fas fa-signal"></i> Overall Skill</label>
                            <?php $levels = ['Beginner','Intermediate','Advanced','Pro','Elite']; $current = $_SESSION['skill_level'] ?? ''; ?>
                            <div class="gp-custom-select" id="profileSkillLevelWrap">
                                <div class="gp-select-trigger"><span><?php echo $current ?: 'Select level'; ?></span></div>
                                <div class="gp-select-options">
                                    <div class="gp-select-option <?php echo empty($current) ? 'active' : ''; ?>" data-value="">Select level</div>
                                    <?php foreach ($levels as $lv): ?>
                                    <div class="gp-select-option <?php echo $current === $lv ? 'active' : ''; ?>" data-value="<?php echo $lv; ?>"><?php echo $lv; ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="skill_level" value="<?php echo htmlspecialchars($current); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="gp-form-group" style="margin-top:12px">
                        <label><i class="fas fa-quote-left"></i> Bio</label>
                        <textarea name="bio" class="gp-input" rows="3" placeholder="Tell other players about yourself..." maxlength="200"><?php echo htmlspecialchars($_SESSION['bio'] ?? ''); ?></textarea>
                    </div>
                    <div class="gp-form-group" style="margin-top:12px">
                        <label><i class="fas fa-gamepad"></i> Favorite Game</label>
                        <?php $favGame = $_SESSION['favorite_game'] ?? ''; ?>
                        <div class="gp-custom-select" id="profileFavoriteGameWrap">
                            <div class="gp-select-trigger"><span><?php echo $favGame ?: 'Select game'; ?></span></div>
                            <div class="gp-select-options">
                                <div class="gp-select-option <?php echo empty($favGame) ? 'active' : ''; ?>" data-value="">Select game</div>
                                <?php foreach ($categories as $gc): ?>
                                <div class="gp-select-option <?php echo $favGame === $gc ? 'active' : ''; ?>" data-value="<?php echo $gc; ?>"><?php echo $gc; ?></div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="favorite_game" value="<?php echo htmlspecialchars($favGame); ?>">
                        </div>
                    </div>
                </div>

                <div class="gp-tab-content" id="tab-skills">
                    <div id="gameSkillsContainer" class="gp-game-skills-editor">
                        <?php if (!empty($gameSkills)): foreach ($gameSkills as $gs): ?>
                        <div class="gp-game-skill-row">
                            <span class="gp-game-skill-icon-preview"><i class="fas <?php echo htmlspecialchars($gs['game_icon'] ?? 'fa-gamepad'); ?>"></i></span>
                            <span class="gp-game-skill-row-name"><?php echo htmlspecialchars($gs['game']); ?></span>
                            <div class="gp-custom-select gp-game-skill-row-select" style="width:130px">
                                <div class="gp-select-trigger"><span><?php echo $gs['skill_level'] ?: '--'; ?></span></div>
                                <div class="gp-select-options">
                                    <div class="gp-select-option <?php echo empty($gs['skill_level']) ? 'active' : ''; ?>" data-value="">--</div>
                                    <?php foreach (['Beginner','Intermediate','Advanced','Pro','Elite'] as $lv): ?>
                                    <div class="gp-select-option <?php echo $gs['skill_level'] === $lv ? 'active' : ''; ?>" data-value="<?php echo $lv; ?>"><?php echo $lv; ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" onchange="saveGameSkill(this, '<?php echo htmlspecialchars($gs['game']); ?>')" value="<?php echo htmlspecialchars($gs['skill_level']); ?>">
                            </div>
                            <button type="button" class="gp-btn-remove-skill" onclick="removeGameSkill(this, '<?php echo htmlspecialchars($gs['game']); ?>')" title="Remove Skill"><i class="fas fa-trash-can"></i></button>
                        </div>
                        <?php endforeach; endif; ?>
                        <button type="button" class="gp-btn gp-btn-sm gp-btn-ghost gp-add-game-skill-btn" style="width:100%; border-style:dashed; margin-top:10px" onclick="addGameSkillRow()"><i class="fas fa-plus"></i> Add Another Skill</button>
                    </div>
                </div>

                <div class="gp-tab-content" id="tab-social">
                    <div class="gp-form-grid two">
                        <div class="gp-form-group">
                            <label><i class="fab fa-discord"></i> Discord</label>
                            <div class="gp-input-group">
                                <span class="gp-input-prefix"><i class="fab fa-discord"></i></span>
                                <input type="text" name="discord" class="gp-input" placeholder="e.g. shadow#1234" value="<?php echo htmlspecialchars($_SESSION['discord'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="gp-form-group">
                            <label><i class="fab fa-facebook"></i> Facebook</label>
                            <div class="gp-input-group">
                                <span class="gp-input-prefix"><i class="fab fa-facebook"></i></span>
                                <input type="text" name="facebook" class="gp-input" placeholder="Profile URL or ID" value="<?php echo htmlspecialchars($_SESSION['facebook'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="gp-form-group">
                            <label><i class="fab fa-instagram"></i> Instagram</label>
                            <div class="gp-input-group">
                                <span class="gp-input-prefix"><i class="fab fa-instagram"></i></span>
                                <input type="text" name="instagram" class="gp-input" placeholder="Username" value="<?php echo htmlspecialchars($_SESSION['instagram'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="gp-form-group">
                            <label><i class="fab fa-youtube"></i> Youtube</label>
                            <div class="gp-input-group">
                                <span class="gp-input-prefix"><i class="fab fa-youtube"></i></span>
                                <input type="text" name="youtube" class="gp-input" placeholder="Channel URL" value="<?php echo htmlspecialchars($_SESSION['youtube'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <?php if ($userRole !== 'agent'): ?>
                    <div class="gp-profile-become-agent" style="margin-top:24px">
                        <span class="gp-profile-agent-icon"><i class="fas fa-crown"></i></span>
                        <div>
                            <strong>Want to host tournaments?</strong>
                            <p>Become an agent and create events.</p>
                        </div>
                        <button type="button" class="gp-btn gp-btn-sm gp-btn-gradient" data-open-modal="becomeAgentModal">Upgrade</button>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="gp-modal-actions" style="margin-top:24px">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="gp-btn gp-btn-accent"><i class="fas fa-check"></i> Save Changes</button>
                </div>
                <div class="gp-feedback hidden" id="profileFeedback"></div>
            </form>
        </div>
    </div>
</div>


<!-- Create Tournament Modal (Agent only) -->
<div class="gp-modal hidden" id="createTournamentModal">
    <div class="gp-modal-panel lg">
        <div class="gp-modal-head">
            <h3><i class="fas fa-trophy" style="color:#7c3aed"></i> Create tournament</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body">
            <form id="createTournamentForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="agent_create_tournament">
                <div class="gp-form-grid">
                    <div class="gp-form-group">
                        <label>Title</label>
                        <input type="text" name="title" class="gp-input" required placeholder="e.g. Dream Cup 2026">
                    </div>
                    <div class="gp-form-group">
                        <label>Game / Category</label>
                        <div class="gp-custom-select" id="createTournamentCategoryWrap">
                            <div class="gp-select-trigger"><span>Select game</span></div>
                            <div class="gp-select-options">
                                <div class="gp-select-option active" data-value="">Select game</div>
                                <?php foreach ($categories as $cat): ?>
                                <div class="gp-select-option" data-value="<?php echo $cat; ?>"><?php echo $cat; ?></div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="category" value="">
                        </div>
                    </div>
                </div>
                <div class="gp-form-group">
                    <label>Description</label>
                    <textarea name="description" class="gp-input" rows="2" placeholder="Tournament details..."></textarea>
                </div>
                <div class="gp-form-grid three">
                    <div class="gp-form-group">
                        <label>Prize money (৳)</label>
                        <div class="gp-input-group"><span class="gp-input-prefix">৳</span>
                            <input type="text" name="prize_money" class="gp-input" placeholder="e.g. 500" required>
                        </div>
                        <span class="gp-form-hint">Will be deducted from your balance</span>
                    </div>
                    <div class="gp-form-group">
                        <label>Entry fee (৳)</label>
                        <div class="gp-input-group"><span class="gp-input-prefix">৳</span>
                            <input type="text" name="entry_fee" class="gp-input" placeholder="e.g. 2" value="0">
                        </div>
                        <span class="gp-form-hint">Per team</span>
                    </div>
                    <div class="gp-form-group">
                        <label>Max teams</label>
                        <input type="number" name="max_teams" class="gp-input" min="0" placeholder="e.g. 16" value="16">
                    </div>
                </div>
                <div class="gp-form-grid two">
                    <div class="gp-form-group">
                        <label>Start date</label>
                        <input type="datetime-local" name="starts_at" class="gp-input">
                    </div>
                    <div class="gp-form-group">
                        <label>Game icon</label>
                        <div class="gp-icon-picker">
                            <?php foreach ($gameIcons as $gi): ?>
                            <button type="button" class="gp-icon-opt <?php echo $gi === 'fa-gamepad' ? 'active' : ''; ?>" data-icon="<?php echo $gi; ?>"><i class="fas <?php echo $gi; ?>"></i></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="game_icon" value="fa-gamepad">
                    </div>
                </div>
                <div class="gp-form-group">
                    <label>Accent color</label>
                    <div class="gp-color-picker">
                        <?php foreach ($palette as $c): ?>
                        <button type="button" class="gp-color-opt <?php echo $c === '#7c3aed' ? 'active' : ''; ?>" data-color="<?php echo $c; ?>" style="background:<?php echo $c; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="accent_color" value="#7c3aed">
                </div>
                <div class="gp-balance-notice" id="createTournamentBalance">
                    Balance: <strong>৳<?php echo number_format($userBalance, 0); ?></strong>
                </div>
                <div class="gp-modal-actions">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="gp-btn gp-btn-accent"><i class="fas fa-trophy"></i> Create tournament</button>
                </div>
                <div class="gp-feedback hidden"></div>
            </form>
        </div>
    </div>
</div>

<!-- Create Team Modal -->
<div class="gp-modal hidden" id="createTeamModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-users" style="color:#2563eb"></i> Create team</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body">
            <form id="createTeamForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="create_team">
                <div class="gp-form-group">
                    <label>Team name</label>
                    <input type="text" name="name" class="gp-input" required placeholder="e.g. Dream Crew" maxlength="100">
                </div>
                <div class="gp-form-group">
                    <label>Game</label>
                    <div class="gp-custom-select" id="createTeamGameWrap">
                        <div class="gp-select-trigger"><span>Select game</span></div>
                        <div class="gp-select-options">
                            <div class="gp-select-option active" data-value="">Select game</div>
                            <?php foreach ($categories as $cat): ?>
                            <div class="gp-select-option" data-value="<?php echo $cat; ?>"><?php echo $cat; ?></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="game" value="">
                    </div>
                </div>
                <div class="gp-form-group">
                    <label>Description</label>
                    <textarea name="description" class="gp-input" rows="2" placeholder="Team bio..."></textarea>
                </div>
                <div class="gp-modal-actions">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="gp-btn gp-btn-primary"><i class="fas fa-check"></i> Create team</button>
                </div>
                <div class="gp-feedback hidden"></div>
            </form>
        </div>
    </div>
</div>

<!-- Join Tournament Modal (choose team or solo) -->
<div class="gp-modal hidden" id="joinTournamentModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-right-to-bracket" style="color:#10b981"></i> Join tournament</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body">
            <p class="gp-modal-sub" id="joinTournamentTitle"></p>
            <div id="joinTournamentFee" class="gp-join-fee"></div>
            <form id="joinTournamentForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="tournament_id" id="joinTournamentId" value="">
                <div class="gp-form-group">
                    <label>Join as</label>
                    <div class="gp-radio-group">
                        <label class="gp-radio">
                            <input type="radio" name="join_type" value="solo" checked>
                            <span><i class="fas fa-user"></i> Solo player</span>
                        </label>
                        <?php if (!empty($myTeams)): ?>
                        <label class="gp-radio">
                            <input type="radio" name="join_type" value="team">
                            <span><i class="fas fa-users"></i> With team</span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="gp-form-group" id="teamSelectGroup" style="display:none">
                    <label>Select team</label>
                    <div class="gp-custom-select" id="joinTournamentTeamWrap">
                        <div class="gp-select-trigger"><span>Select team</span></div>
                        <div class="gp-select-options">
                            <?php if (!empty($myTeams)): ?>
                                <?php foreach ($myTeams as $idx => $team): ?>
                                <div class="gp-select-option <?php echo $idx === 0 ? 'active' : ''; ?>" data-value="<?php echo (int)$team['id']; ?>"><?php echo htmlspecialchars($team['name']); ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="gp-select-option" data-value="">No teams found</div>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="team_id" value="<?php echo !empty($myTeams) ? (int)$myTeams[0]['id'] : ''; ?>">
                    </div>
                </div>
                <div class="gp-form-group" id="soloNameGroup">
                    <label>Team name <span class="text-gray-400">(optional)</span></label>
                    <input type="text" name="team_name" class="gp-input" placeholder="Enter a name for your solo entry">
                </div>
                <div class="gp-modal-actions">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="gp-btn gp-btn-primary"><i class="fas fa-check"></i> Confirm join</button>
                </div>
                <div class="gp-feedback hidden"></div>
            </form>
        </div>
    </div>
</div>

<!-- View Participants Modal -->
<div class="gp-modal hidden" id="viewParticipantsModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-users"></i> Participants</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body" id="participantsList">
            <div class="gp-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>

<!-- Team Manage Modal -->
<div class="gp-modal hidden" id="teamManageModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-users-gear"></i> <span id="teamManageTitle">Manage team</span></h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body" id="teamManageBody">
            <div class="gp-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>

<!-- Agent History Modal -->
<div class="gp-modal hidden" id="agentHistoryModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-clock-rotate-left"></i> Transaction history</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body" id="agentHistoryBody">
            <div class="gp-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>

<!-- ═══ SUCCESS / FAIL OVERLAY — REDESIGNED ═══ -->
<div class="gp-success-overlay" id="baResultOverlay">
    <div class="gp-success-box">
        <button class="gp-success-close" id="baResultClose" title="Close"><i class="fas fa-times"></i></button>
        <div id="baResultIcon" class="gp-success-icon-wrap success"><i class="fas fa-check"></i></div>
        <div id="baResultTitle" class="gp-success-text">সফল!</div>
        <div id="baResultSub" class="gp-success-sub">আপনার অনুরোধ জমা দেওয়া হয়েছে</div>
        <button class="gp-success-btn primary" id="baResultDoneBtn"><i class="fas fa-check-circle"></i> ঠিক আছে</button>
    </div>
</div>

<!-- ═══ CREATE CLUB MODAL ═══ -->
<div class="gp-modal hidden" id="createClubModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-flag" style="color:#7c3aed"></i> Create New Club</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body">
            <form id="createClubForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create_club">
                <div class="gp-form-group">
                    <label>Club Name</label>
                    <input class="gp-input" name="name" placeholder="e.g. Alpha Esports" required>
                </div>
                <div class="gp-form-grid two">
                    <div class="gp-form-group">
                        <label>Tag (2-10 chars)</label>
                        <input class="gp-input" name="tag" placeholder="e.g. ALPHA" maxlength="10" required>
                    </div>
                    <div class="gp-form-group">
                        <label>Region</label>
                        <input class="gp-input" name="region" placeholder="e.g. Bangladesh">
                    </div>
                </div>
                <div class="gp-form-group">
                    <label>Club Colour</label>
                    <div class="gp-color-picker" id="clubColorPicker">
                        <?php $colors = ['#7c3aed','#2563eb','#059669','#d97706','#dc2626','#ec4899','#06b6d4','#8b5cf6','#10b981']; foreach ($colors as $cl): ?>
                        <button type="button" class="gp-color-opt<?php if ($cl==='#7c3aed') echo ' active'; ?>" style="background:<?php echo $cl; ?>" data-color="<?php echo $cl; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="colour" id="clubColourInput" value="#7c3aed">
                </div>
                <div class="gp-form-group">
                    <label>Description (optional)</label>
                    <textarea class="gp-input" name="description" rows="2" placeholder="Tell us about your club..."></textarea>
                </div>
                <div class="gp-modal-actions">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="gp-btn gp-btn-accent"><i class="fas fa-check"></i> Create Club</button>
                </div>
                <div class="gp-feedback hidden"></div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ BUY PLAYER MODAL ═══ -->
<div class="gp-modal hidden" id="buyModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-shopping-cart" style="color:#10b981"></i> Confirm Purchase</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body">
            <form id="buyForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="buy_player">
                <input type="hidden" name="player_id" id="buyPlayerId">
                <input type="hidden" name="price" id="buyPrice">
                <p id="buyInfo" style="font-size:.9rem;color:var(--gp-muted);margin:0 0 16px"></p>
                <div class="gp-modal-actions">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="gp-btn gp-btn-accent"><i class="fas fa-shopping-cart"></i> Confirm Purchase</button>
                </div>
                <div class="gp-feedback hidden"></div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ BID MODAL ═══ -->
<div class="gp-modal hidden" id="bidModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-gavel" style="color:#f59e0b"></i> Place a Bid</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body">
            <form id="bidForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="place_bid">
                <input type="hidden" name="auction_id" id="bidAuctionId">
                <div class="gp-form-group">
                    <label>Your Bid Amount</label>
                    <input class="gp-input" type="number" name="amount" id="bidAmount" min="0" step="50" required>
                    <span id="bidInfo" style="font-size:.75rem;color:var(--gp-muted);margin-top:4px"></span>
                </div>
                <div class="gp-modal-actions">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="gp-btn gp-btn-accent"><i class="fas fa-gavel"></i> Place Bid</button>
                </div>
                <div class="gp-feedback hidden"></div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ AUCTION MODAL ═══ -->
<div class="gp-modal hidden" id="auctionModal">
    <div class="gp-modal-panel sm">
        <div class="gp-modal-head">
            <h3><i class="fas fa-gavel" style="color:#f59e0b"></i> List Player for Auction</h3>
            <button class="gp-modal-close" data-close-modal><i class="fas fa-times"></i></button>
        </div>
        <div class="gp-modal-body">
            <form id="auctionForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="list_player_auction">
                <input type="hidden" name="player_id" id="auctionPlayerId">
                <div class="gp-form-group">
                    <label>Base Price (৳)</label>
                    <input class="gp-input" type="number" name="base_price" placeholder="e.g. 500" min="1" required>
                </div>
                <div class="gp-form-group">
                    <label>Duration (hours)</label>
                    <select class="gp-input" name="duration_hours">
                        <option value="6">6 hours</option>
                        <option value="12">12 hours</option>
                        <option value="24" selected>24 hours</option>
                        <option value="48">48 hours</option>
                    </select>
                </div>
                <div class="gp-modal-actions">
                    <button type="button" class="gp-btn gp-btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="gp-btn gp-btn-accent"><i class="fas fa-check"></i> List for Auction</button>
                </div>
                <div class="gp-feedback hidden"></div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {

    function escHtml(str) { var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
    function api(data) { return fetch('handlers/tournament_handler.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(data) }).then(function(r) { return r.json(); }); }
    function fb(form, r) { var el = form.querySelector('.gp-feedback'); if (!el) return; el.className = 'gp-feedback'; el.classList.add(r.success ? 'success' : 'error'); el.textContent = r.message || 'Done.'; }
    function toast(msg, type) { type = type || 'success'; var t = document.createElement('div'); t.className = 'gp-toast ' + type; t.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle') + '"></i><span>' + escHtml(msg) + '</span>'; document.body.appendChild(t); setTimeout(function() { t.style.opacity = '0'; t.style.transform = 'translateY(20px)'; t.style.transition = 'all .3s'; setTimeout(function() { t.remove(); }, 300); }, 3000); }

    function safe(name, fn) { try { fn(); } catch (e) {} }

    var csrfToken = (document.getElementById('tournamentsPage') || {}).getAttribute('data-csrf') || '';
    var availableGames = <?php echo json_encode($categories); ?>;
    var gameIconsMap = <?php echo json_encode(array_combine($categories, array_slice($gameIcons, 0, count($categories)))); ?>;

    window.saveGameSkill = function(sel, game) {
        var val = sel.value;
        fetch('handlers/tournament_handler.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({ action: 'save_game_skill', game: game, skill_level: val, csrf_token: csrfToken })
        }).then(function(r){ return r.json(); }).then(function(res){
            if (res.success) toast('Skill updated for ' + game, 'success');
            else toast(res.message || 'Failed', 'error');
        }).catch(function(){ toast('Network error', 'error'); });
    };

    window.removeGameSkill = function(btn, game) {
        if (!confirm('Remove expertise for ' + game + '?')) return;
        fetch('handlers/tournament_handler.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({ action: 'save_game_skill', game: game, skill_level: '', csrf_token: csrfToken })
        }).then(function(r){ return r.json(); }).then(function(res){
            if (res.success) {
                toast('Skill removed', 'success');
                btn.closest('.gp-game-skill-row').remove();
            } else toast(res.message || 'Failed', 'error');
        }).catch(function(){ toast('Network error', 'error'); });
    };

    window.addGameSkillRow = function() {
        var container = document.getElementById('gameSkillsContainer');
        if (!container) return;
        
        var row = document.createElement('div');
        row.className = 'gp-game-skill-row';
        
        var gameOptions = availableGames.map(function(g){ return '<div class="gp-select-option" data-value="' + g + '">' + g + '</div>'; }).join('');
        
        row.innerHTML = `
            <span class="gp-game-skill-icon-preview"><i class="fas fa-gamepad"></i></span>
            <div class="gp-custom-select gp-game-select" style="width:120px">
                <div class="gp-select-trigger"><span>Select Game</span></div>
                <div class="gp-select-options">
                    ${gameOptions}
                </div>
                <input type="hidden" class="game-input" value="">
            </div>
            <div class="gp-custom-select gp-game-skill-row-select" style="flex:1">
                <div class="gp-select-trigger"><span>Skill Level</span></div>
                <div class="gp-select-options">
                    <div class="gp-select-option" data-value="Beginner">Beginner</div>
                    <div class="gp-select-option" data-value="Intermediate">Intermediate</div>
                    <div class="gp-select-option" data-value="Advanced">Advanced</div>
                    <div class="gp-select-option" data-value="Pro">Pro</div>
                    <div class="gp-select-option" data-value="Elite">Elite</div>
                </div>
                <input type="hidden" class="skill-input" value="">
            </div>
            <button type="button" class="gp-btn-remove-skill" onclick="this.closest('.gp-game-skill-row').remove()" title="Cancel"><i class="fas fa-times"></i></button>
        `;
        
        var addBtn = container.querySelector('.gp-add-game-skill-btn');
        container.insertBefore(row, addBtn);
        if (typeof initAllCustomSelects === 'function') initAllCustomSelects();
        else if (typeof window.initAllCustomSelects === 'function') window.initAllCustomSelects();

        var gameInp = row.querySelector('.game-input');
        var skillInp = row.querySelector('.skill-input');
        var iconEl = row.querySelector('.gp-game-skill-icon-preview i');
        
        var handleChange = function() {
            var g = gameInp.value;
            var s = skillInp.value;
            if (g) {
                var icon = gameIconsMap[g] || 'fa-gamepad';
                iconEl.className = 'fas ' + icon;
            }
            if (g && s) {
                saveGameSkill({value: s}, g);
            }
        };
        
        gameInp.addEventListener('change', handleChange);
        skillInp.addEventListener('change', handleChange);
    };

    safe('Cards', function() {
        document.querySelectorAll('.gp-card').forEach(function(el, i) {
            el.style.opacity='0'; el.style.transform='translateY(20px)'; el.style.transition='opacity .5s,transform .5s cubic-bezier(.34,1.56,.64,1)';
            setTimeout(function(){ el.style.opacity='1'; el.style.transform='translateY(0)'; },80+i*60);
        });
    });

    // Nav active state is set manually by switchToSection on tab click

    // Tab-based section switching — only show the active nav section
    var sectionMap = {
        'browse': ['browse', 'leaderboard'],
        'dashboard': ['dashboard'],
        'clubs': ['clubs'],
        'hire': ['hire']
    };

    var _firstLoad = true;
    var _currentSectionId = 'browse';
    var _navOrder = ['browse', 'dashboard', 'clubs', 'hire'];

    function switchToSection(id) {
        var ids = sectionMap[id] || [id];
        var dir = _navOrder.indexOf(id) > _navOrder.indexOf(_currentSectionId) ? 'forward' : 'backward';

        if (_firstLoad) {
            var allSections = document.querySelectorAll('.gp-section');
            allSections.forEach(function(s) {
                s.style.display = ids.indexOf(s.id) !== -1 ? '' : 'none';
                s.classList.remove('slide-out-left', 'slide-out-right', 'slide-in-left', 'slide-in-right', 'slide-in');
            });
            _firstLoad = false;
        } else {
            var slideOutClass = dir === 'forward' ? 'slide-out-left' : 'slide-out-right';
            var slideInPrepClass = dir === 'forward' ? 'slide-in-right' : 'slide-in-left';

            // Find currently visible section(s) to slide out
            var visible = [];
            document.querySelectorAll('.gp-section').forEach(function(s) {
                if (s.style.display !== 'none') {
                    visible.push(s);
                    s.classList.remove('slide-in', 'slide-in-left', 'slide-in-right');
                    s.classList.add(slideOutClass);
                }
            });

            // After slide-out completes, swap and slide in new section from opposite side
            setTimeout(function() {
                visible.forEach(function(s) { s.style.display = 'none'; s.classList.remove(slideOutClass); });

                document.querySelectorAll('.gp-section').forEach(function(s) {
                    var show = ids.indexOf(s.id) !== -1;
                    s.style.display = show ? '' : 'none';
                    if (show) {
                        s.classList.add(slideInPrepClass);
                    }
                });

                // Force reflow, then slide to center
                void document.body.offsetHeight;
                document.querySelectorAll('.gp-section').forEach(function(s) {
                    if (ids.indexOf(s.id) !== -1) {
                        s.classList.remove(slideInPrepClass);
                        s.classList.add('slide-in');
                    }
                });
            }, 380);
        }

        _currentSectionId = id;

        // Update nav active state
        document.querySelectorAll('.gp-mobile-nav-item[data-scroll-to]').forEach(function(n) {
            n.classList.toggle('active', n.getAttribute('data-scroll-to') === id);
        });

        // Scroll to section
        var section = document.getElementById(id);
        if (section) {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    // Bottom nav items: switch section on click
    document.querySelectorAll('.gp-mobile-nav-item[data-scroll-to]').forEach(function(item) {
        item.addEventListener('click', function() {
            var targetId = this.getAttribute('data-scroll-to');
            switchToSection(targetId);
        });
    });

    // Hero buttons with data-scroll-to
    document.querySelectorAll('[data-scroll-to]').forEach(function(item) {
        if (!item.closest('.gp-mobile-nav')) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                switchToSection(this.getAttribute('data-scroll-to'));
            });
        }
    });

    // If hash on load, switch to that section; else show tournaments by default
    // Delay to let page render first
    setTimeout(function() {
        if (window.location.hash) {
            var hashId = window.location.hash.slice(1);
            if (sectionMap[hashId]) {
                switchToSection(hashId);
            }
        } else {
            switchToSection('browse');
        }
    }, 50);

    safe('Countdowns', function() {
        var els = document.querySelectorAll('.gp-countdown'); if (!els.length) return;
        var tick = function(el) {
            var t = parseInt(el.getAttribute('data-starts'),10); if (!t) return;
            var d = t - (Date.now()/1000|0); if (d<=0) { el.textContent='LIVE'; el.style.color='#16a34a'; return; }
            var dd = d/86400|0, hh = d%86400/3600|0, mm = d%3600/60|0, ss = d%60;
            el.textContent = dd ? dd+'d '+hh+'h' : hh ? hh+'h '+mm+'m '+ss+'s' : mm+'m '+ss+'s';
        };
        els.forEach(tick); clearInterval(window._gpIntv);
        window._gpIntv = setInterval(function(){ document.querySelectorAll('.gp-countdown').forEach(tick); },1000);
    });


    function gpModalBody(open) {
        document.body.classList.toggle('gp-modal-open', open);
        document.body.style.overflow = open ? 'hidden' : '';
    }

    // Become agent flow — step transition
        var baInfoStep = document.getElementById('baInfoStep');
        var baPayStep = document.getElementById('baPayStep');
        var baProceedBtn = document.getElementById('baProceedBtn');
        var baBackToInfo = document.getElementById('baBackToInfo');
        var baPayBtn = document.getElementById('baPayBtn');
        var baPhone = document.getElementById('baPhone');
        var baTxid = document.getElementById('baTxid');
        var baFeedback = document.getElementById('baFeedback');
        var baMerchantBox = document.getElementById('baMerchantBox');

        window.baPmData = <?php echo json_encode($pmData); ?>;

    // ═══ CLUB FUNCTIONS ═══
    window.openClubModal = function() {
        var overlay = document.getElementById('gpOverlay');
        if (overlay) overlay.classList.remove('hidden');
        document.getElementById('createClubModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };

    document.getElementById('clubColorPicker') && document.getElementById('clubColorPicker').addEventListener('click', function(e) {
        var opt = e.target.closest('.gp-color-opt');
        if (!opt) return;
        this.querySelectorAll('.gp-color-opt').forEach(function(o) { o.classList.remove('active'); });
        opt.classList.add('active');
        document.getElementById('clubColourInput').value = opt.getAttribute('data-color');
    });

    document.getElementById('createClubForm') && document.getElementById('createClubForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = this.querySelector('button[type="submit"]');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        fetch('handlers/tournament_handler.php', { method:'POST', body:new URLSearchParams(new FormData(this)) })
        .then(function(r) { return r.json(); }).then(function(res) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Create Club';
            if (res.success) { closeModal('createClubModal'); toast('Club created!','success'); setTimeout(function() { location.reload(); }, 800); }
            else toast(res.message || 'Error','error');
        }).catch(function() { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Create Club'; toast('Server error','error'); });
    });

    window.joinClub = function(id) {
        var data = new URLSearchParams({ action:'join_club', club_id:id, csrf_token:csrfToken });
        fetch('handlers/tournament_handler.php', { method:'POST', body:data })
        .then(function(r) { return r.json(); }).then(function(res) {
            if (res.success) { toast('Joined club!','success'); setTimeout(function() { location.reload(); }, 600); }
            else toast(res.message || 'Error','error');
        });
    };

    window.leaveClub = function(id) {
        if (!confirm('Leave this club?')) return;
        var data = new URLSearchParams({ action:'leave_club', club_id:id, csrf_token:csrfToken });
        fetch('handlers/tournament_handler.php', { method:'POST', body:data })
        .then(function(r) { return r.json(); }).then(function(res) {
            if (res.success) { toast('Left club.','success'); setTimeout(function() { location.reload(); }, 600); }
            else toast(res.message || 'Error','error');
        });
    };

    window.removeMember = function(btn, clubId, userId) {
        if (!confirm('Remove this member?')) return;
        var data = new URLSearchParams({ action:'fire_player', club_id:clubId, player_user_id:userId, csrf_token:csrfToken });
        fetch('handlers/tournament_handler.php', { method:'POST', body:data })
        .then(function(r) { return r.json(); }).then(function(res) {
            if (res.success) { btn.closest('.cl-member').remove(); toast('Member removed.','success'); }
            else toast(res.message || 'Error','error');
        });
    };

    // ═══ PLAYER MARKET FUNCTIONS ═══
    window.openBuyModal = function(playerId, name, price) {
        document.getElementById('buyPlayerId').value = playerId;
        document.getElementById('buyPrice').value = price;
        document.getElementById('buyInfo').textContent = 'Buy ' + name + ' for ৳' + price.toLocaleString() + '?';
        document.getElementById('buyModal').classList.remove('hidden');
        var overlay = document.getElementById('gpOverlay');
        if (overlay) overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };

    document.getElementById('buyForm') && document.getElementById('buyForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = this.querySelector('button[type="submit"]');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        fetch('handlers/tournament_handler.php', { method:'POST', body:new URLSearchParams(new FormData(this)) })
        .then(function(r) { return r.json(); }).then(function(res) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-shopping-cart"></i> Confirm Purchase';
            if (res.success) { closeModal('buyModal'); toast('Player purchased!','success'); setTimeout(function() { location.reload(); }, 800); }
            else toast(res.message || 'Error','error');
        }).catch(function() { btn.disabled = false; btn.innerHTML = '<i class="fas fa-shopping-cart"></i> Confirm Purchase'; toast('Server error','error'); });
    });

    window.openBidModal = function(auctionId, currentPrice, minIncrement) {
        document.getElementById('bidAuctionId').value = auctionId;
        var minBid = Math.ceil((currentPrice + minIncrement) / 50) * 50;
        document.getElementById('bidAmount').value = minBid;
        document.getElementById('bidAmount').min = minBid;
        document.getElementById('bidInfo').textContent = 'Min bid: ৳' + minBid.toLocaleString() + ' (current: ৳' + currentPrice.toLocaleString() + ')';
        document.getElementById('bidModal').classList.remove('hidden');
        var overlay = document.getElementById('gpOverlay');
        if (overlay) overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };

    document.getElementById('bidForm') && document.getElementById('bidForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = this.querySelector('button[type="submit"]');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing...';
        fetch('handlers/tournament_handler.php', { method:'POST', body:new URLSearchParams(new FormData(this)) })
        .then(function(r) { return r.json(); }).then(function(res) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-gavel"></i> Place Bid';
            if (res.success) { closeModal('bidModal'); toast('Bid placed!','success'); setTimeout(function() { location.reload(); }, 800); }
            else toast(res.message || 'Error','error');
        }).catch(function() { btn.disabled = false; btn.innerHTML = '<i class="fas fa-gavel"></i> Place Bid'; toast('Server error','error'); });
    });

    window.openAuctionModal = function(playerId) {
        document.getElementById('auctionPlayerId').value = playerId;
        document.getElementById('auctionModal').classList.remove('hidden');
        var overlay = document.getElementById('gpOverlay');
        if (overlay) overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };

    document.getElementById('auctionForm') && document.getElementById('auctionForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = this.querySelector('button[type="submit"]');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Listing...';
        fetch('handlers/tournament_handler.php', { method:'POST', body:new URLSearchParams(new FormData(this)) })
        .then(function(r) { return r.json(); }).then(function(res) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> List for Auction';
            if (res.success) { closeModal('auctionModal'); toast('Player listed!','success'); setTimeout(function() { location.reload(); }, 800); }
            else toast(res.message || 'Error','error');
        }).catch(function() { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> List for Auction'; toast('Server error','error'); });
    });

    window.releasePlayer = function(playerId) {
        if (!confirm('Release this player? This cannot be undone.')) return;
        var data = new URLSearchParams({ action:'release_player', player_id:playerId, csrf_token:csrfToken });
        fetch('handlers/tournament_handler.php', { method:'POST', body:data })
        .then(function(r) { return r.json(); }).then(function(res) {
            if (res.success) { toast('Player released.','success'); setTimeout(function() { location.reload(); }, 600); }
            else toast(res.message || 'Error','error');
        });
    };

    window.hirePlayer = function(playerId, clubId) {
        if (!confirm('Hire this player to your club?')) return;
        var data = new URLSearchParams({ action:'hire_player', player_id:playerId, club_id:clubId, csrf_token:csrfToken });
        fetch('handlers/tournament_handler.php', { method:'POST', body:data })
        .then(function(r) { return r.json(); }).then(function(res) {
            if (res.success) { toast('Player hired!','success'); setTimeout(function() { location.reload(); }, 600); }
            else toast(res.message || 'Error','error');
        });
    };

    // PM Tabs
    var pmTabs = document.getElementById('pmTabs');
    if (pmTabs) {
        pmTabs.querySelectorAll('.pm-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                pmTabs.querySelectorAll('.pm-tab').forEach(function(t) { t.classList.remove('active'); });
                document.querySelectorAll('.pm-tab-content').forEach(function(c) { c.style.display = 'none'; });
                this.classList.add('active');
                var target = document.getElementById('tab' + this.dataset.tab.charAt(0).toUpperCase() + this.dataset.tab.slice(1));
                if (target) target.style.display = 'block';
            });
        });
    }

    // Helper: close modal using existing gp-overlay pattern
    function closeModal(id) {
        document.getElementById(id).classList.add('hidden');
        var overlay = document.getElementById('gpOverlay');
        if (overlay) overlay.classList.add('hidden');
        document.body.style.overflow = '';
    }
    window.closeModal = closeModal;

    // Move bottom nav to end of body so it's never trapped in a stacking context
    var gpNav = document.querySelector('.gp-mobile-nav');
    if (gpNav) {
        document.body.appendChild(gpNav);
    }

    })();
</script>
