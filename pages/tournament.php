<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

$db = Database::getInstance()->getConnection();
$viewerId = $_SESSION['user_id'] ?? null;
$viewerRole = $_SESSION['role'] ?? '';
$security = new Security();
$csrfToken = $security->generateCSRFToken();

$tournamentId = (int)($_GET['id'] ?? 0);
$tournament = $tournamentId ? getTournamentByIdWithCounts($db, $tournamentId) : null;

if (!$tournament) {
    echo '<div class="tfd-missing" style="text-align:center;padding:80px 20px;color:var(--gp-muted,#6b7280)">'
        . '<i class="fas fa-circle-exclamation" style="font-size:3rem;color:#f87171;margin-bottom:12px;display:block"></i>'
        . '<h2 style="color:var(--gp-text,#1f2937);margin-bottom:18px">Tournament not found</h2>'
        . '<a class="gp-btn gp-btn-primary" href="index.php?page=tournaments" data-no-ajax>Browse tournaments</a></div>';
    return;
}

enforceTournamentDeadlines($db, $tournamentId);

$status = $tournament['status'] ?? 'upcoming';
$title = htmlspecialchars($tournament['title'] ?? '', ENT_QUOTES);
$desc = (string)($tournament['description'] ?? '');
$rules = trim((string)($tournament['rules'] ?? ''));
$startsAt = $tournament['starts_at'] ?? null;
$entryFee = (float)($tournament['entry_fee'] ?? 0);
$prizeMoney = trim((string)($tournament['prize_money'] ?? ''));
$agentId = (int)($tournament['agent_id'] ?? 0);
$maxTeams = (int)($tournament['max_teams'] ?? 0);
$bracketType = $tournament['bracket_type'] ?? 'single_elimination';
$bestOf = (int)($tournament['best_of'] ?? 1);
$regd = (int)($tournament['registered_teams'] ?? 0);
$accent = $tournament['accent_color'] ?? '#7c3aed';
$icon = $tournament['game_icon'] ?? 'fa-gamepad';
$category = $tournament['category'] ?? '';
$prizeBreakdown = json_decode((string)($tournament['prize_breakdown'] ?? ''), true) ?: null;

$checkinState = tnCheckinState($tournament, date('Y-m-d H:i:s'));
$checkinOpens = tnCheckinOpensAt($tournament);

$agentName = '';
if ($agentId > 0) {
    $s = $db->prepare("SELECT full_name, username, avatar FROM users WHERE id = ?");
    $s->execute([$agentId]);
    $a = $s->fetch();
    if ($a) $agentName = $a['full_name'] ?: $a['username'];
}

// Viewer's registration
$myReg = null;
if ($viewerId) {
    $s = $db->prepare("SELECT * FROM tournament_participants WHERE tournament_id = ? AND user_id = ?");
    $s->execute([$tournamentId, $viewerId]);
    $myReg = $s->fetch() ?: null;
}
$isRegistered = $myReg && $myReg['status'] === 'confirmed';
$isAgentOwner = $agentId > 0 && $agentId === (int)$viewerId && $viewerRole === 'agent';
$hasBracket = false;
$s = $db->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?");
$s->execute([$tournamentId]);
$matchCount = (int)$s->fetchColumn();
$hasBracket = $matchCount > 0;
$isFull = $maxTeams > 0 && $regd >= $maxTeams;
$canRegister = !$isRegistered && in_array($status, ['upcoming', 'live']) && !$hasBracket && (!$isFull || $isAgentOwner) && $viewerId;

$canOpenRoom = $viewerId ? userCanAccessTournamentRoom($db, $tournamentId, (int)$viewerId) : false;

// Bracket + summary + standings
$bracketRows = $hasBracket ? bracketMatchesForRender($db, $tournamentId) : [];
$summary = $hasBracket ? bracketSummary($db, $tournamentId) : null;
$standings = ($bracketType === 'round_robin' && $hasBracket) ? bracketStandings($db, $tournamentId) : [];

// Participants
$participants = getTournamentParticipants($db, $tournamentId);

$badges = ['upcoming' => 'gp-badge gp-badge-upcoming', 'live' => 'gp-badge gp-badge-live', 'completed' => 'gp-badge gp-badge-completed', 'cancelled' => 'gp-badge gp-badge-cancelled'];
$bracketLabels = ['single_elimination' => 'Single Elimination', 'double_elimination' => 'Double Elimination', 'round_robin' => 'Round Robin'];
?>
<style>
:root{--tfd-card:#fff;--tfd-strong:#111827;--tfd-text:#374151;--tfd-muted:#6b7280;--tfd-border:#e5e7eb;--tfd-line:rgba(15,23,42,.08);--tfd-soft:rgba(15,23,42,.04);--tfd-input:#f8fafc;--tfd-br-title:#111827;--tfd-br-muted:#94a3b8;--tfd-br-slot:#334155;--tfd-br-card:#f8fafc;--tfd-br-border:#e5e7eb}
.dark,[data-theme="dark"]{--tfd-card:#0d1321;--tfd-strong:#fff;--tfd-text:#cbd5e1;--tfd-muted:#94a3b8;--tfd-border:rgba(255,255,255,.09);--tfd-line:rgba(255,255,255,.07);--tfd-soft:rgba(255,255,255,.04);--tfd-input:#0a0f1a;--tfd-br-title:#fff;--tfd-br-muted:rgba(255,255,255,.45);--tfd-br-slot:rgba(255,255,255,.8);--tfd-br-card:rgba(255,255,255,.04);--tfd-br-border:rgba(255,255,255,.09)}
.tfd-wrap{max-width:1200px;margin:0 auto;padding:18px 16px 60px}
.tfd-hero{position:relative;border-radius:18px;overflow:hidden;background:linear-gradient(135deg,#0f172a,#1e1b4b,#1a0533);padding:30px 28px;color:#fff;border:1px solid rgba(255,255,255,.08)}
.tfd-hero:after{content:"";position:absolute;inset:0;background:radial-gradient(600px 200px at 90% 0%,<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>33,transparent);pointer-events:none}
.tfd-hero-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.tfd-hero-icon{width:46px;height:46px;border-radius:12px;background:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff}
.tfd-hero h1{font-size:clamp(1.3rem,3vw,2rem);margin:14px 0 6px;line-height:1.2;color:#fff;position:relative;z-index:1}
.tfd-hero-sub{display:flex;gap:14px;flex-wrap:wrap;font-size:.85rem;color:rgba(255,255,255,.72);position:relative;z-index:1}
.tfd-hero-sub i{margin-right:5px;color:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>}
.tfd-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(124px,100%),1fr));gap:12px;margin-top:20px;position:relative;z-index:1}
.tfd-stat{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);border-radius:12px;padding:12px 14px}
.tfd-stat .lbl{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.55);font-weight:700}
.tfd-stat .val{font-size:1.05rem;font-weight:800;margin-top:3px}
.tfd-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;position:relative;z-index:1}
.tfd-btn{border:0;border-radius:10px;padding:11px 20px;font-weight:800;font-size:.9rem;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:.18s;text-decoration:none}
.tfd-btn-primary{background:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>;color:#fff}
.tfd-btn-primary:hover{filter:brightness(1.12);transform:translateY(-1px)}
.tfd-btn-success{background:#10b981;color:#fff}
.tfd-btn-outline{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.2)}
.tfd-btn-outline:hover{background:rgba(255,255,255,.16)}
.tfd-btn-ghost{background:transparent;color:#f87171;border:1px solid rgba(248,113,113,.4)}
.tfd-btn[disabled]{opacity:.5;cursor:not-allowed;transform:none}
.tfd-checkin{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.4);color:#6ee7b7;border-radius:10px;padding:10px 16px;font-weight:700;font-size:.85rem;display:inline-flex;gap:8px;align-items:center}
.tfd-checkin.is-late{background:rgba(248,113,113,.12);border-color:rgba(248,113,113,.4);color:#fca5a5}
.tfd-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);gap:18px;margin-top:20px;align-items:stretch}
.tfd-grid>div{display:flex;flex-direction:column;gap:18px;min-width:0}
.tfd-grid>div>.tfd-card{margin-bottom:0}
.tfd-grid>div>.tfd-card:first-child{flex:1 1 auto}
@media(max-width:900px){.tfd-grid{grid-template-columns:minmax(0,1fr)}}
.gp-badge{font-size:.64rem;font-weight:800;padding:4px 12px;border-radius:999px;display:inline-flex;align-items:center;gap:5px;letter-spacing:.05em;white-space:nowrap}
.gp-badge-upcoming{background:rgba(59,130,246,.2);color:#93c5fd}
.gp-badge-live{background:rgba(16,185,129,.22);color:#6ee7b7}
.gp-badge-completed{background:rgba(245,158,11,.2);color:#fcd34d}
.gp-badge-cancelled{background:rgba(248,113,113,.2);color:#fca5a5}
.tfd-card{background:var(--tfd-card);border:1px solid var(--tfd-border);border-radius:14px;padding:18px;margin-bottom:18px}
.tfd-card h2{font-size:.95rem;margin:0 0 14px;display:flex;align-items:center;gap:8px;color:var(--tfd-strong)}
.tfd-card h2 i{color:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>}
.tfd-tabs{display:flex;gap:6px;border-bottom:1px solid var(--tfd-border);margin-bottom:16px;overflow-x:auto}
.tfd-tab{background:none;border:0;color:var(--tfd-muted);font-weight:700;font-size:.85rem;padding:10px 14px;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap}
.tfd-tab.is-on{color:var(--tfd-strong);border-bottom-color:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>}
.tfd-tab .gp-count{background:var(--tfd-soft);color:var(--tfd-muted)}
.tfd-pane{display:none}.tfd-pane.is-on{display:block}
.tfd-desc{color:var(--tfd-text);font-size:.92rem;line-height:1.65;white-space:pre-wrap}
.tfd-rules{color:var(--tfd-text);font-size:.87rem;line-height:1.7;white-space:pre-wrap}
.tfd-kv{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px dashed var(--tfd-line);font-size:.87rem}
.tfd-kv:last-child{border-bottom:0}
.tfd-kv .k{color:var(--tfd-muted)}
.tfd-kv .v{font-weight:700;color:var(--tfd-strong);text-align:right}
.tfd-prize-row{display:flex;justify-content:space-between;padding:9px 12px;border-radius:8px;background:rgba(245,158,11,.07);margin-bottom:6px;font-size:.88rem}
.tfd-prize-row b{color:#d97706}
.dark .tfd-prize-row b,[data-theme="dark"] .tfd-prize-row b{color:#f59e0b}
.tfd-part{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;background:var(--tfd-soft);margin-bottom:7px;font-size:.87rem}
.tfd-part .av{width:30px;height:30px;border-radius:50%;background:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>22;color:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.75rem;flex:0 0 auto}
.tfd-part .nm{font-weight:700;color:var(--tfd-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tfd-part .st{margin-left:auto;font-size:.68rem;font-weight:800;padding:3px 8px;border-radius:999px;background:rgba(16,185,129,.15);color:#059669}
.tfd-part .st.chk{background:rgba(59,130,246,.18);color:#2563eb}
.dark .tfd-part .st,[data-theme="dark"] .tfd-part .st{color:#6ee7b7}
.dark .tfd-part .st.chk,[data-theme="dark"] .tfd-part .st.chk{color:#93c5fd}
.tfd-stand-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -4px;padding:0 4px}
.tfd-stand{width:100%;border-collapse:collapse;font-size:.85rem;min-width:420px}
.tfd-stand th{color:var(--tfd-muted);font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;padding:8px;text-align:left}
.tfd-stand td{padding:9px 8px;border-top:1px solid var(--tfd-line);color:var(--tfd-text)}
.tfd-stand tr:first-child td{color:#d97706;font-weight:800}
.dark .tfd-stand tr:first-child td,[data-theme="dark"] .tfd-stand tr:first-child td{color:#f59e0b}
.tfd-champ{text-align:center;padding:16px;background:linear-gradient(135deg,rgba(245,158,11,.14),rgba(245,158,11,.04));border:1px solid rgba(245,158,11,.35);border-radius:12px;margin-bottom:16px}
.tfd-champ .c-lbl{font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:#d97706;font-weight:800}
.dark .tfd-champ .c-lbl,[data-theme="dark"] .tfd-champ .c-lbl{color:#f59e0b}
.tfd-champ .c-name{font-size:1.2rem;font-weight:900;color:var(--tfd-strong);margin-top:4px}
.tfd-empty{text-align:center;padding:34px 16px;color:var(--tfd-muted)}
.tfd-empty i{font-size:2.2rem;color:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>;opacity:.6;margin-bottom:10px;display:block}
.tfd-empty p{font-size:.9rem;margin:0 0 4px}
.tfd-empty small{font-size:.78rem;opacity:.75}
.tfd-c-gold{color:#b45309}.tfd-c-green{color:#059669}.tfd-c-blue{color:#2563eb}
.tfd-fairplay{margin-top:18px}
.tfd-fairplay-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:12px}
.tfd-fairplay-grid>div{display:flex;flex-direction:column;gap:5px;background:var(--tfd-soft);border:1px solid var(--tfd-border);border-radius:12px;padding:14px 16px}
.tfd-fairplay-grid i{color:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>;font-size:1rem}
.tfd-fairplay-grid b{font-size:.86rem;color:var(--tfd-strong)}
.tfd-fairplay-grid span{font-size:.79rem;line-height:1.55;color:var(--tfd-muted)}
.tfd-ogrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(210px,100%),1fr));gap:10px;margin-top:16px}
.tfd-oitem{display:flex;align-items:center;gap:9px;background:var(--tfd-soft);border:1px solid var(--tfd-border);border-radius:10px;padding:11px 13px;font-size:.83rem;min-width:0}
.tfd-oitem i{color:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>;flex:0 0 auto}
.tfd-oitem .k{color:var(--tfd-muted);flex:0 0 auto}
.tfd-oitem .v{color:var(--tfd-strong);font-weight:700;margin-left:auto;text-align:right;min-width:0;overflow-wrap:anywhere}
.tfd-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(190px,100%),1fr));gap:10px;margin-top:16px}
.tfd-steps>div{position:relative;background:var(--tfd-soft);border:1px solid var(--tfd-border);border-radius:12px;padding:13px 14px 13px 44px}
.tfd-steps .n{position:absolute;left:13px;top:13px;width:22px;height:22px;border-radius:50%;background:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>;color:#fff;font-size:.72rem;font-weight:800;display:flex;align-items:center;justify-content:center}
.tfd-steps b{display:block;font-size:.83rem;color:var(--tfd-strong)}
.tfd-steps small{display:block;font-size:.75rem;color:var(--tfd-muted);line-height:1.5;margin-top:2px}
.dark .tfd-c-gold,[data-theme="dark"] .tfd-c-gold{color:#f59e0b}
.dark .tfd-c-green,[data-theme="dark"] .tfd-c-green{color:#6ee7b7}
.dark .tfd-c-blue,[data-theme="dark"] .tfd-c-blue{color:#93c5fd}

/* Bracket */
<?php require __DIR__ . '/../includes/bracket_styles.php'; ?>

/* Score report cards */
.tfd-score-card{background:var(--tfd-soft);border:1px solid var(--tfd-border);border-radius:12px;padding:14px;margin-bottom:12px}
.tfd-score-card.is-you{border-color:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>66}
.tfd-score-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;font-size:.8rem;margin-bottom:10px}
.tfd-score-head .vs{font-weight:900;color:var(--tfd-strong);min-width:0;overflow-wrap:anywhere}
.tfd-score-state{font-size:.65rem;font-weight:900;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.05em}
.tfd-score-state.s-none{background:rgba(148,163,184,.15);color:#64748b}
.tfd-score-state.s-reported{background:rgba(59,130,246,.18);color:#2563eb}
.tfd-score-state.s-disputed{background:rgba(248,113,113,.18);color:#dc2626}
.tfd-score-state.s-confirmed{background:rgba(16,185,129,.18);color:#059669}
.dark .tfd-score-state.s-none,[data-theme="dark"] .tfd-score-state.s-none{color:#94a3b8}
.dark .tfd-score-state.s-reported,[data-theme="dark"] .tfd-score-state.s-reported{color:#93c5fd}
.dark .tfd-score-state.s-disputed,[data-theme="dark"] .tfd-score-state.s-disputed{color:#fca5a5}
.dark .tfd-score-state.s-confirmed,[data-theme="dark"] .tfd-score-state.s-confirmed{color:#6ee7b7}
.tfd-score-inputs{display:flex;gap:8px;align-items:center;justify-content:center;margin-bottom:10px}
.tfd-score-inputs input{width:58px;text-align:center;background:var(--tfd-input);border:1px solid var(--tfd-border);border-radius:8px;color:var(--tfd-strong);font-weight:900;font-size:1rem;padding:8px 4px}
.tfd-score-inputs span{color:var(--tfd-muted);font-weight:900}
.tfd-score-btns{display:flex;gap:8px;flex-wrap:wrap;justify-content:center}
.tfd-mini-btn{border:0;border-radius:8px;padding:7px 14px;font-weight:800;font-size:.78rem;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.tfd-mini-primary{background:<?php echo htmlspecialchars($accent, ENT_QUOTES) ?>;color:#fff}
.tfd-mini-ok{background:#10b981;color:#fff}
.tfd-mini-warn{background:#f59e0b;color:#1a1000}
.tfd-mini-ghost{background:var(--tfd-soft);color:var(--tfd-text);border:1px solid var(--tfd-border)}
.tfd-note{font-size:.75rem;color:var(--tfd-muted);margin-top:8px}
.tfd-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--tfd-card);border:1px solid var(--tfd-border);color:var(--tfd-strong);padding:12px 22px;border-radius:12px;font-weight:700;font-size:.88rem;z-index:9999;box-shadow:0 12px 40px rgba(0,0,0,.35);opacity:0;pointer-events:none;transition:.25s}
.tfd-toast.show{opacity:1;transform:translateX(-50%) translateY(-6px)}
</style>

<div class="tfd-wrap">
    <!-- HERO -->
    <div class="tfd-hero">
        <div class="tfd-hero-top">
            <span class="tfd-hero-icon"><i class="fas <?php echo htmlspecialchars($icon, ENT_QUOTES); ?>"></i></span>
            <span class="<?php echo $badges[$status] ?? 'gp-badge'; ?>"><?php echo strtoupper(htmlspecialchars($status)); ?></span>
            <span class="gp-badge" style="background:rgba(255,255,255,.1);color:#fff"><i class="fas fa-diagram-project"></i> <?php echo htmlspecialchars($bracketLabels[$bracketType] ?? 'Bracket'); ?></span>
            <?php if ($category): ?><span class="gp-badge" style="background:<?php echo htmlspecialchars($accent, ENT_QUOTES); ?>22;color:<?php echo htmlspecialchars($accent, ENT_QUOTES); ?>"><?php echo htmlspecialchars($category); ?></span><?php endif; ?>
            <?php if ($summary && $summary['complete']): ?><span class="gp-badge" style="background:rgba(245,158,11,.18);color:#fcd34d"><i class="fas fa-flag-checkered"></i> FINISHED</span><?php endif; ?>
        </div>
        <h1><?php echo $title; ?></h1>
        <div class="tfd-hero-sub">
            <?php if ($agentName): ?><span><i class="fas fa-crown"></i> Hosted by <?php echo htmlspecialchars($agentName); ?></span><?php endif; ?>
            <?php if ($startsAt): ?><span><i class="fas fa-calendar"></i> <?php echo date('D, M j, Y g:i A', strtotime($startsAt)); ?></span><?php endif; ?>
            <span><i class="fas fa-users"></i> <?php echo $regd; ?><?php echo $maxTeams > 0 ? '/' . $maxTeams : ''; ?> registered</span>
            <span><i class="fas fa-layer-group"></i> Best of <?php echo $bestOf; ?></span>
        </div>

        <div class="tfd-stat-grid">
            <div class="tfd-stat"><div class="lbl">Prize pool</div><div class="val" style="color:#f59e0b"><?php echo $prizeMoney !== '' && $prizeMoney !== '0' && $prizeMoney !== '0.00' ? '৳' . htmlspecialchars($prizeMoney) : 'Trophy only'; ?></div></div>
            <div class="tfd-stat"><div class="lbl">Entry fee</div><div class="val" style="color:#6ee7b7"><?php echo $entryFee > 0 ? '৳' . number_format($entryFee, 0) : 'Free'; ?></div></div>
            <div class="tfd-stat"><div class="lbl">Slots</div><div class="val"><?php echo $maxTeams > 0 ? $regd . ' / ' . $maxTeams : $regd . ' joined'; ?></div></div>
            <?php if ($startsAt && !in_array($status, ['completed', 'cancelled'])): ?>
            <div class="tfd-stat"><div class="lbl">Starts in</div><div class="val"><span class="gp-countdown" data-starts="<?php echo strtotime($startsAt); ?>"><?php echo date('M j, g:i A', strtotime($startsAt)); ?></span></div></div>
            <?php endif; ?>
        </div>

        <div class="tfd-actions">
            <?php if ($canRegister): ?>
                <button class="tfd-btn tfd-btn-primary" id="tfd-join"><i class="fas fa-right-to-bracket"></i> <?php echo $entryFee > 0 ? 'Join (৳' . number_format($entryFee, 0) . ')' : 'Join free'; ?></button>
            <?php elseif ($isRegistered): ?>
                <span class="tfd-btn tfd-btn-success"><i class="fas fa-check-circle"></i> Registered</span>
                <?php if (!$hasBracket && $status !== 'completed'): ?>
                <button class="tfd-btn tfd-btn-ghost" id="tfd-leave"><i class="fas fa-xmark"></i> Leave &amp; refund</button>
                <?php endif; ?>
            <?php elseif ($isFull && !$isRegistered): ?>
                <span class="tfd-btn tfd-btn-outline"><i class="fas fa-lock"></i> Tournament full</span>
            <?php elseif (!$viewerId): ?>
                <a class="tfd-btn tfd-btn-primary" href="index.php?page=login"><i class="fas fa-right-to-bracket"></i> Log in to join</a>
            <?php endif; ?>

            <?php if ($isRegistered && !in_array($checkinState, ['closed', 'none'])): ?>
                <?php if ((int)($myReg['checked_in'] ?? 0)): ?>
                    <span class="tfd-checkin"><i class="fas fa-circle-check"></i> Checked in</span>
                <?php else: ?>
                    <button class="tfd-btn tfd-btn-outline" id="tfd-checkin"><i class="fas fa-clipboard-check"></i> Check in</button>
                <?php endif; ?>
            <?php elseif ($isRegistered && $checkinState === 'late'): ?>
                <span class="tfd-checkin is-late"><i class="fas fa-triangle-exclamation"></i> Check-in missed</span>
            <?php endif; ?>

            <?php if ($canOpenRoom): ?>
                <a class="tfd-btn tfd-btn-outline" href="index.php?page=tournament-room&id=<?php echo $tournamentId; ?>" data-no-ajax><i class="fas fa-door-open"></i> Open room</a>
            <?php endif; ?>
            <?php if ($isAgentOwner && !$hasBracket): ?>
                <a class="tfd-btn tfd-btn-outline" href="index.php?page=agent-dashboard" data-no-ajax><i class="fas fa-sliders"></i> Manage</a>
            <?php endif; ?>
            <?php if ($isRegistered && !in_array($checkinState, ['closed', 'none']) && !(int)($myReg['checked_in'] ?? 0)): ?>
                <span class="tfd-note" style="align-self:center">Check-in opens <?php echo $checkinOpens ? date('g:i A', strtotime($checkinOpens)) : 'soon'; ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="tfd-grid">
        <!-- LEFT -->
        <div>
            <div class="tfd-card">
                <div class="tfd-tabs">
                    <button class="tfd-tab is-on" data-pane="overview"><i class="fas fa-info-circle"></i> Overview</button>
                    <?php if ($hasBracket): ?>
                    <button class="tfd-tab" data-pane="bracket"><i class="fas fa-diagram-project"></i> Bracket <span class="gp-count"><?php echo $matchCount; ?></span></button>
                    <?php endif; ?>
                    <?php if ($bracketType === 'round_robin' && $hasBracket): ?>
                    <button class="tfd-tab" data-pane="standings"><i class="fas fa-ranking-star"></i> Standings</button>
                    <?php endif; ?>
                    <button class="tfd-tab" data-pane="players"><i class="fas fa-users"></i> Players <span class="gp-count"><?php echo count($participants); ?></span></button>
                    <?php if ($rules): ?>
                    <button class="tfd-tab" data-pane="rules"><i class="fas fa-gavel"></i> Rules</button>
                    <?php endif; ?>
                </div>

                <!-- Overview -->
                <div class="tfd-pane is-on" id="tfd-pane-overview">
                    <?php if ($desc): ?><div class="tfd-desc"><?php echo htmlspecialchars($desc); ?></div>
                    <?php else: ?><div class="tfd-empty"><i class="fas fa-align-left"></i><p>No description provided.</p><small>The host hasn't added details — check Rules tab or ask in the tournament room.</small></div><?php endif; ?>

                    <div class="tfd-ogrid">
                        <?php if ($agentName): ?><div class="tfd-oitem"><i class="fas fa-crown"></i><span class="k">Host</span><span class="v"><?php echo htmlspecialchars($agentName); ?></span></div><?php endif; ?>
                        <?php if ($startsAt): ?><div class="tfd-oitem"><i class="fas fa-calendar"></i><span class="k">Starts</span><span class="v"><?php echo date('D, M j · g:i A', strtotime($startsAt)); ?></span></div>
                        <?php else: ?><div class="tfd-oitem"><i class="fas fa-calendar"></i><span class="k">Starts</span><span class="v">TBD</span></div><?php endif; ?>
                        <div class="tfd-oitem"><i class="fas fa-layer-group"></i><span class="k">Format</span><span class="v"><?php echo htmlspecialchars($bracketLabels[$bracketType] ?? 'Bracket'); ?> · Best of <?php echo $bestOf; ?></span></div>
                        <div class="tfd-oitem"><i class="fas fa-coins"></i><span class="k">Entry</span><span class="v"><?php echo $entryFee > 0 ? '৳' . number_format($entryFee, 0) : 'Free'; ?></span></div>
                        <div class="tfd-oitem"><i class="fas fa-users"></i><span class="k">Slots</span><span class="v"><?php echo $maxTeams > 0 ? $regd . ' / ' . $maxTeams : $regd . ' joined'; ?></span></div>
                        <div class="tfd-oitem"><i class="fas fa-clipboard-check"></i><span class="k">Check-in</span><span class="v"><?php echo (int)($tournament['checkin_minutes'] ?? 30); ?> min before</span></div>
                    </div>

                    <div class="tfd-steps">
                        <div><span class="n">1</span><b>Register</b><small>Join with your team before slots fill</small></div>
                        <div><span class="n">2</span><b>Check in</b><small>Opens before start — miss it and you're out</small></div>
                        <div><span class="n">3</span><b>Play &amp; report</b><small>Report scores, opponent confirms each match</small></div>
                        <div><span class="n">4</span><b>Get paid</b><small>Prize releases from escrow when bracket completes</small></div>
                    </div>

                    <?php if ($prizeBreakdown): ?>
                    <div style="margin-top:16px">
                        <h2 style="font-size:.85rem"><i class="fas fa-trophy" style="color:#f59e0b"></i> Prize breakdown</h2>
                        <?php foreach ($prizeBreakdown as $place => $amount): if (!(float)$amount) continue; ?>
                        <div class="tfd-prize-row"><span>#<?php echo htmlspecialchars((string)$place); ?> place</span><b>৳<?php echo number_format((float)$amount, 0); ?></b></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($summary && $summary['champion']): ?>
                    <div class="tfd-champ" style="margin-top:16px">
                        <div class="c-lbl"><i class="fas fa-crown"></i> Champion</div>
                        <div class="c-name"><?php echo htmlspecialchars($summary['champion']['name'] ?? '—'); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Bracket -->
                <?php if ($hasBracket): ?>
                <div class="tfd-pane" id="tfd-pane-bracket">
                    <?php if ($summary && !$summary['complete'] && $summary['pending']): ?>
                    <div class="tfd-note" style="margin-bottom:12px"><i class="fas fa-circle-notch fa-spin"></i> Bracket in progress — <?php echo (int)$summary['match_count']; ?> matches total.</div>
                    <?php endif; ?>
                    <?php echo bracketRenderStages($bracketRows); ?>
                </div>
                <?php endif; ?>

                <!-- Standings -->
                <?php if ($bracketType === 'round_robin' && $hasBracket): ?>
                <div class="tfd-pane" id="tfd-pane-standings">
                    <?php if ($standings): ?>
                    <div class="tfd-stand-wrap"><table class="tfd-stand">
                        <thead><tr><th>#</th><th>Team / Player</th><th>P</th><th>W</th><th>D</th><th>L</th><th>Diff</th><th>Pts</th></tr></thead>
                        <tbody>
                        <?php foreach ($standings as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['rank']; ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo (int)$row['played']; ?></td>
                                <td><?php echo (int)$row['wins']; ?></td>
                                <td><?php echo (int)$row['draws']; ?></td>
                                <td><?php echo (int)$row['losses']; ?></td>
                                <td><?php echo ($row['diff'] > 0 ? '+' : '') . (int)$row['diff']; ?></td>
                                <td><?php echo (int)$row['points']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php else: ?><div class="tfd-note">Standings appear after the first results.</div><?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Players -->
                <div class="tfd-pane" id="tfd-pane-players">
                    <?php if (!$participants): ?>
                    <div class="tfd-note">No registrations yet — be the first!</div>
                    <?php else: foreach ($participants as $p):
                        $pName = $p['team_name'] ?: ($p['full_name'] ?: $p['username'] ?? ('#' . $p['user_id']));
                        $initial = mb_strtoupper(mb_substr($pName, 0, 1));
                        $checked = (int)($p['checked_in'] ?? 0);
                        $pStatus = $p['status'] ?? 'confirmed';
                    ?>
                    <div class="tfd-part">
                        <span class="av"><?php echo htmlspecialchars($initial); ?></span>
                        <span class="nm"><?php echo htmlspecialchars($pName); ?><?php echo (int)$p['user_id'] === (int)$viewerId ? ' <span style="color:<?php echo htmlspecialchars($accent, ENT_QUOTES); ?>;font-size:.7rem">(you)</span>' : ''; ?></span>
                        <?php if ($pStatus !== 'confirmed'): ?><span class="st" style="background:rgba(248,113,113,.15);color:#dc2626"><?php echo htmlspecialchars(strtoupper($pStatus)); ?></span>
                        <?php elseif ($checked): ?><span class="st chk"><i class="fas fa-clipboard-check"></i> IN</span>
                        <?php else: ?><span class="st">READY</span><?php endif; ?>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Rules -->
                <?php if ($rules): ?>
                <div class="tfd-pane" id="tfd-pane-rules">
                    <div class="tfd-rules"><?php echo htmlspecialchars($rules); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- My matches (score report/confirm) -->
            <?php if ($isRegistered && $hasBracket && $status === 'live'): ?>
            <div class="tfd-card" id="tfd-mymatches">
                <h2><i class="fas fa-gamepad"></i> Your matches</h2>
                <div id="tfd-match-list"><div class="tfd-note"><i class="fas fa-circle-notch fa-spin"></i> Loading…</div></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT -->
        <div>
            <div class="tfd-card">
                <h2><i class="fas fa-circle-info"></i> Tournament info</h2>
                <div class="tfd-kv"><span class="k">Format</span><span class="v"><?php echo htmlspecialchars($bracketLabels[$bracketType] ?? 'Bracket'); ?></span></div>
                <div class="tfd-kv"><span class="k">Best of</span><span class="v"><?php echo $bestOf; ?></span></div>
                <div class="tfd-kv"><span class="k">Entry</span><span class="v"><?php echo $entryFee > 0 ? '৳' . number_format($entryFee, 0) : 'Free'; ?></span></div>
                <div class="tfd-kv"><span class="k">Prize pool</span><span class="v tfd-c-gold"><?php echo $prizeMoney !== '' && $prizeMoney !== '0' && $prizeMoney !== '0.00' ? '৳' . htmlspecialchars($prizeMoney) : 'Trophy only'; ?></span></div>
                <div class="tfd-kv"><span class="k">Slots</span><span class="v"><?php echo $maxTeams > 0 ? $regd . ' / ' . $maxTeams : 'Open'; ?></span></div>
                <div class="tfd-kv"><span class="k">Check-in</span><span class="v"><?php echo (int)($tournament['checkin_minutes'] ?? 30); ?> min before start</span></div>
                <?php if ($startsAt): ?><div class="tfd-kv"><span class="k">Starts</span><span class="v"><?php echo date('M j, g:i A', strtotime($startsAt)); ?></span></div><?php endif; ?>
                <?php if ($entryFee > 0): ?><div class="tfd-kv"><span class="k">Fee held in</span><span class="v tfd-c-green"><i class="fas fa-lock"></i> Escrow</span></div><?php endif; ?>
            </div>

            <div class="tfd-card">
                <h2><i class="fas fa-signal"></i> Live status</h2>
                <div class="tfd-kv"><span class="k">State</span><span class="v" id="tfd-live-state"><?php echo htmlspecialchars(strtoupper($status)); ?></span></div>
                <?php if ($summary): ?>
                <div class="tfd-kv"><span class="k">Matches</span><span class="v"><?php echo (int)($summary['stages'] ? array_sum(array_column($summary['stages'], 'completed')) : 0); ?> / <?php echo (int)$summary['match_count']; ?></span></div>
                <div class="tfd-kv"><span class="k">Bracket</span><span class="v <?php echo $summary['complete'] ? 'tfd-c-green' : 'tfd-c-blue'; ?>"><?php echo $summary['complete'] ? 'Complete' : 'In progress'; ?></span></div>
                <?php endif; ?>
                <div class="tfd-note" id="tfd-refresh-note">Auto-refreshes every 8s.</div>
            </div>
        </div>
    </div>

    <div class="tfd-card tfd-fairplay">
        <h2><i class="fas fa-shield-halved"></i> Fair play</h2>
        <div class="tfd-fairplay-grid">
            <div><i class="fas fa-flag"></i><b>Report &amp; confirm</b><span>Both players report the score; the opponent confirms it. No silent wins.</span></div>
            <div><i class="fas fa-scale-balanced"></i><b>Disputes</b><span>Disagree? Dispute with a note — the tournament agent decides.</span></div>
            <div><i class="fas fa-lock"></i><b>Escrow</b><span>Entry fees stay locked until the bracket completes, then payouts release.</span></div>
        </div>
    </div>
</div>

<div class="tfd-toast" id="tfd-toast"></div>

<script>
(function(){
    const CSRF = <?php echo json_encode($csrfToken); ?>;
    const TID = <?php echo (int)$tournamentId; ?>;
    const API = 'handlers/tournament_handler.php';
    const toast = document.getElementById('tfd-toast');
    let toastTimer = null;
    function say(msg, ok) {
        toast.textContent = msg;
        toast.style.borderColor = ok === false ? 'rgba(248,113,113,.6)' : 'rgba(16,185,129,.6)';
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 3200);
    }
    async function api(payload) {
        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify(Object.assign({csrf_token: CSRF}, payload))
            });
            return await res.json();
        } catch (e) { return {success:false, message:'Network error'}; }
    }

    // Tabs
    const openTab = (name) => {
        const btn = document.querySelector('.tfd-tab[data-pane="' + name + '"]');
        if (btn) btn.click();
    };
    document.querySelectorAll('.tfd-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tfd-tab').forEach(b => b.classList.remove('is-on'));
            document.querySelectorAll('.tfd-pane').forEach(p => p.classList.remove('is-on'));
            btn.classList.add('is-on');
            const pane = document.getElementById('tfd-pane-' + btn.dataset.pane);
            if (pane) pane.classList.add('is-on');
        });
    });
    // Deep-link support: index.php?page=tournament&id=X#standings|#bracket
    const hash = (location.hash || '').replace('#', '');
    if (['bracket', 'standings', 'players', 'rules', 'overview'].includes(hash)) openTab(hash);

    // Join
    const joinBtn = document.getElementById('tfd-join');
    if (joinBtn) joinBtn.addEventListener('click', async () => {
        joinBtn.disabled = true;
        const r = await api({action: 'register', tournament_id: TID});
        say(r.message || (r.success ? 'Joined!' : 'Failed'), r.success);
        if (r.success) setTimeout(() => location.reload(), 900);
        else joinBtn.disabled = false;
    });

    // Leave
    const leaveBtn = document.getElementById('tfd-leave');
    if (leaveBtn) leaveBtn.addEventListener('click', async () => {
        if (!confirm('Leave this tournament? Your entry fee will be refunded.')) return;
        leaveBtn.disabled = true;
        const r = await api({action: 'unregister', tournament_id: TID});
        say(r.message || (r.success ? 'Left' : 'Failed'), r.success);
        if (r.success) setTimeout(() => location.reload(), 900);
        else leaveBtn.disabled = false;
    });

    // Check-in
    const chkBtn = document.getElementById('tfd-checkin');
    if (chkBtn) chkBtn.addEventListener('click', async () => {
        chkBtn.disabled = true;
        const r = await api({action: 'check_in', tournament_id: TID});
        say(r.message || (r.success ? 'Checked in!' : 'Failed'), r.success);
        if (r.success) setTimeout(() => location.reload(), 900);
        else chkBtn.disabled = false;
    });

    // My matches + score flow
    const matchList = document.getElementById('tfd-match-list');
    function renderMatches(rows) {
        if (!matchList) return;
        const mine = rows.filter(m => {
            const k1 = m.team1_kind === 'user' && m.team1_id === <?php echo (int)($viewerId ?? 0); ?>;
            const k2 = m.team2_kind === 'user' && m.team2_id === <?php echo (int)($viewerId ?? 0); ?>;
            return k1 || k2;
        });
        if (!mine.length) { matchList.innerHTML = '<div class="tfd-note">No active match for you right now.</div>'; return; }
        matchList.innerHTML = mine.map(m => {
            const state = m.report_state || 'none';
            const stateLabel = {none:'Awaiting result', reported: state==='reported' && m.reported_by===<?php echo (int)($viewerId ?? 0); ?> ? 'You reported — awaiting confirm' : 'Opponent reported', disputed:'Disputed — agent deciding', confirmed:'Confirmed'}[state] || state;
            const cls = 'tfd-score-state s-' + state;
            let controls = '';
            if (m.status === 'completed' || m.status === 'walkover') {
                controls = '<div class="tfd-note"><i class="fas fa-check"></i> Final: ' + (m.team1_name||'?') + ' ' + (m.score1 ?? '') + ' – ' + (m.score2 ?? '') + ' ' + (m.team2_name||'?') + '</div>';
            } else if (state === 'none') {
                controls = '<div class="tfd-score-inputs"><input type="number" min="0" id="s1-'+m.id+'" value="1"><span>–</span><input type="number" min="0" id="s2-'+m.id+'" value="0"></div>' +
                    '<div class="tfd-score-btns"><button class="tfd-mini-btn tfd-mini-primary" data-act="report" data-m="'+m.id+'"><i class="fas fa-flag"></i> Report score</button></div>';
            } else if (state === 'reported') {
                const mineReported = m.reported_by === <?php echo (int)($viewerId ?? 0); ?>;
                if (mineReported) {
                    controls = '<div class="tfd-note">Reported ' + (m.rep1??'?') + ' – ' + (m.rep2??'?') + '. Waiting for your opponent…</div>';
                } else {
                    controls = '<div class="tfd-score-btns">' +
                        '<button class="tfd-mini-btn tfd-mini-ok" data-act="confirm" data-m="'+m.id+'"><i class="fas fa-check"></i> Confirm ' + (m.rep1??0) + '–' + (m.rep2??0) + '</button>' +
                        '<button class="tfd-mini-btn tfd-mini-warn" data-act="dispute" data-m="'+m.id+'"><i class="fas fa-scale-balanced"></i> Dispute</button></div>' +
                        '<div class="tfd-note">If the score is right, confirm it. If not, dispute and the agent decides.</div>';
                }
            } else if (state === 'disputed') {
                controls = '<div class="tfd-note"><i class="fas fa-hourglass-half"></i> Disputed — waiting for the agent. Reported: ' + (m.rep1??'?') + '–' + (m.rep2??'?') + (m.dispute_note ? ' | "' + escapeHtml(m.dispute_note) + '"' : '') + '</div>';
            }
            return '<div class="tfd-score-card">' +
                '<div class="tfd-score-head"><span class="vs">' + escapeHtml(m.team1_name||'TBD') + ' vs ' + escapeHtml(m.team2_name||'TBD') + '</span><span class="'+cls+'">'+stateLabel+'</span></div>' +
                controls + '</div>';
        }).join('');
        matchList.querySelectorAll('button[data-act]').forEach(b => {
            b.addEventListener('click', async () => {
                b.disabled = true;
                const id = b.dataset.m;
                let payload;
                if (b.dataset.act === 'report') {
                    const s1 = parseInt(document.getElementById('s1-'+id).value, 10) || 0;
                    const s2 = parseInt(document.getElementById('s2-'+id).value, 10) || 0;
                    if (s1 === s2) { say('A match cannot end in a draw', false); b.disabled = false; return; }
                    payload = {action:'report_score', match_id:id, score1:s1, score2:s2};
                } else if (b.dataset.act === 'confirm') {
                    if (!confirm('Confirm the reported score?')) { b.disabled = false; return; }
                    payload = {action:'confirm_score', match_id:id};
                } else {
                    const note = prompt('Why are you disputing this score?');
                    if (note === null) { b.disabled = false; return; }
                    payload = {action:'dispute_score', match_id:id, note:note};
                }
                const r = await api(payload);
                say(r.message || (r.success ? 'Done' : 'Failed'), r.success);
                if (r.success) { loadMatches(); refreshBracket(); }
                else b.disabled = false;
            });
        });
    }
    function escapeHtml(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
    async function loadMatches() {
        if (!matchList) return;
        const r = await api({action:'get_bracket', tournament_id: TID});
        if (r.success) renderMatches(r.bracket || []);
    }

    // Bracket live refresh
    async function refreshBracket() {
        const r = await api({action:'get_bracket_summary', tournament_id: TID});
        if (!r.success) return;
        const pane = document.getElementById('tfd-pane-bracket');
        if (pane && r.bracket) {
            // Re-render only when match count/states change would require server HTML —
            // keep it light: reload page data via soft poll every cycle only if complete flag flips.
            const note = document.getElementById('tfd-refresh-note');
            if (note && r.summary) note.textContent = r.summary.complete ? 'Bracket complete.' : 'Auto-refreshes every 8s.';
        }
        if (r.summary && r.summary.complete) loadMatches();
    }

    loadMatches();
    setInterval(() => { loadMatches(); refreshBracket(); }, 8000);
})();
</script>
