<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

$viewerId = (int) ($_SESSION['user_id'] ?? 0);
if ($viewerId <= 0) {
    header('Location: index.php?page=login');
    exit;
}

$db = Database::getInstance()->getConnection();
$security = new Security();
$csrfToken = $security->generateCSRFToken();
$tournamentId = (int) ($_GET['id'] ?? 0);
$tournament = $tournamentId > 0 ? getTournamentByIdWithCounts($db, $tournamentId) : null;

if (!$tournament || !userCanAccessTournamentRoom($db, $tournamentId, $viewerId)) {
    echo '<div class="p-8 text-center text-red-500">You do not have access to this tournament room.</div>';
    return;
}

$participants = getTournamentParticipants($db, $tournamentId);
$teams = getTournamentTeams($db, $tournamentId);
$playerPool = getTournamentPlayerPool($db, $tournamentId);
$messages = getTournamentRoomMessages($db, $tournamentId);
$results = getTournamentResultsBundle($db, $tournamentId);
$bracket = $tournamentId > 0 ? bracketMatchesForRender($db, $tournamentId) : [];
$hasBracket = !empty($bracket);
if ($tournamentId > 0) { enforceTournamentDeadlines($db, $tournamentId); }
$isAgentOwner = (int) ($tournament['agent_id'] ?? 0) === $viewerId && (($_SESSION['role'] ?? '') === 'agent');
$status = (string) ($tournament['status'] ?? 'upcoming');
$accent = htmlspecialchars($tournament['accent_color'] ?? '#7c3aed');
$title = htmlspecialchars($tournament['title'] ?? 'Tournament');
?>

<style>
:root {
  --tr-accent: <?php echo $accent; ?>;
  --tr-accent-rgb: <?php echo implode(',', sscanf($accent, '#%02x%02x%02x') ?: [124, 58, 237]); ?>;
  --tr-radius: 16px;
  --tr-glass: rgba(255,255,255,0.85);
  --tr-glass-border: rgba(148,163,184,0.18);
  --tr-bg-soft: rgba(148,163,184,0.07);
  --tr-muted: #64748b;
  --tfd-br-title:#111827; --tfd-br-muted:#94a3b8; --tfd-br-slot:#334155; --tfd-br-card:#f8fafc; --tfd-br-border:#e5e7eb;
}
.dark, [data-theme="dark"] {
  --tr-glass: rgba(15,23,42,0.88);
  --tr-glass-border: rgba(71,85,105,0.35);
  --tr-bg-soft: rgba(30,41,59,0.6);
  --tr-muted: #94a3b8;
  --tfd-br-title:#fff; --tfd-br-muted:rgba(255,255,255,.45); --tfd-br-slot:rgba(255,255,255,.8); --tfd-br-card:rgba(255,255,255,.04); --tfd-br-border:rgba(255,255,255,.09);
}

.tr-shell { max-width: 1280px; margin: 0 auto; padding: 20px 16px 40px; color: var(--text-primary, #0f172a); }

/* -- Back link */
.tr-back { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 16px; padding: 8px 16px 8px 12px; border-radius: 999px; background: var(--tr-bg-soft); color: inherit; text-decoration: none; font-size: 13px; font-weight: 600; transition: all .2s; }
.tr-back:hover { background: rgba(148,163,184,0.15); transform: translateX(-3px); }

/* -- Hero */
.tr-hero { position: relative; padding: 28px 28px 24px; margin-bottom: 24px; border-radius: var(--tr-radius); background: var(--tr-glass); border: 1px solid var(--tr-glass-border); box-shadow: 0 4px 24px rgba(0,0,0,0.04); overflow: hidden; }
.tr-hero::before { content: ""; position: absolute; inset: 0 0 auto; height: 4px; background: linear-gradient(90deg, var(--tr-accent), color-mix(in srgb, var(--tr-accent) 60%, #fff)); }
.tr-hero-accent-bg { position: absolute; top: -60%; right: -10%; width: 300px; height: 300px; border-radius: 50%; background: radial-gradient(circle, color-mix(in srgb, var(--tr-accent) 12%, transparent), transparent 70%); pointer-events: none; }
.tr-title-row { display: flex; align-items: start; justify-content: space-between; gap: 16px; margin-top: 0; flex-wrap: wrap; position: relative; z-index: 1; }
.tr-title-row h1 { margin: 0; font-size: clamp(24px, 3.6vw, 38px); font-weight: 800; line-height: 1.08; letter-spacing: -.02em; }
.tr-meta { display: flex; flex-wrap: wrap; gap: 10px 18px; margin-top: 10px; color: #64748b; font-size: 13px; }
.dark .tr-meta, [data-theme="dark"] .tr-meta { color: #94a3b8; }
.tr-meta span { display: inline-flex; align-items: center; gap: 6px; }
.tr-meta i { width: 14px; text-align: center; font-size: 12px; }
.tr-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 16px; border-radius: 999px; font-size: 12px; font-weight: 800; letter-spacing: .04em; white-space: nowrap; }
.tr-badge.live { background: rgba(239,68,68,0.12); color: #b91c1c; }
.tr-badge.live::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: #b91c1c; animation: tr-pulse 1.5s ease-in-out infinite; }
.tr-badge.upcoming { background: rgba(37,99,235,0.12); color: #1d4ed8; }
.tr-badge.completed { background: rgba(16,185,129,0.14); color: #047857; }
.tr-badge.cancelled { background: rgba(220,38,38,0.1); color: #b91c1c; }
@keyframes tr-pulse { 0%,100% { opacity:1; } 50% { opacity:.3; } }
@keyframes tr-reconnect-pulse { 0%,100% { opacity:1; transform: scale(1); } 50% { opacity:.5; transform: scale(0.95); } }

/* -- Status buttons row */
.tr-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.tr-hero-actions { margin-top: 20px; position: relative; z-index: 1; display: inline-flex; flex-wrap: wrap; gap: 4px; padding: 5px; background: var(--tr-bg-soft); border: 1px solid var(--tr-glass-border); border-radius: 14px; }
.tr-hero-actions .tr-btn.ghost { background: transparent; }
.tr-hero-actions .tr-btn.ghost:hover { background: rgba(148,163,184,0.16); }

/* -- Buttons */
.tr-btn { display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 10px; padding: 9px 16px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s; }
.tr-btn:active { transform: scale(.96); }
.tr-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.tr-btn.primary { background: var(--tr-accent); color: #fff; box-shadow: 0 2px 10px color-mix(in srgb, var(--tr-accent) 30%, transparent); }
.tr-btn.primary:hover { filter: brightness(1.1); box-shadow: 0 4px 16px color-mix(in srgb, var(--tr-accent) 40%, transparent); }
.tr-btn.ghost { background: var(--tr-bg-soft); backdrop-filter: blur(4px); color: inherit; }
.tr-btn.ghost:hover { background: rgba(148,163,184,0.15); }
.tr-btn.success { background: #16a34a; color: #fff; }
.tr-btn.success:hover { filter: brightness(1.1); }
.tr-btn.warn { background: #f59e0b; color: #111827; }
.tr-btn.danger { background: #dc2626; color: #fff; }
.tr-btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }
.tr-btn-icon { width: 36px; height: 36px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; }

/* -- Grid layout */
.tr-grid { display: grid; grid-template-columns: 1.3fr 0.7fr; gap: 24px; align-items: start; }
.tr-stack { display: grid; gap: 24px; }

/* -- Panels */
.tr-panel { border-radius: var(--tr-radius); background: var(--tr-glass); border: 1px solid var(--tr-glass-border); box-shadow: 0 2px 12px rgba(0,0,0,0.03); }
.tr-panel-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px 0; flex-wrap: wrap; }
.tr-panel-header h2 { margin: 0; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.tr-panel-body { padding: 14px 20px 20px; }
.tr-panel-divider { height: 1px; background: var(--tr-glass-border); margin: 0 20px; }

/* -- Chat feed */
.tr-chat-feed { display: flex; flex-direction: column; gap: 10px; max-height: 480px; overflow-y: auto; padding-right: 6px; scroll-behavior: smooth; }
.tr-chat-feed::-webkit-scrollbar { width: 4px; }
.tr-chat-feed::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.3); border-radius: 4px; }
.tr-chat-feed::-webkit-scrollbar-track { background: transparent; }

.tr-chat-card { display: flex; gap: 10px; max-width: 88%; }
.tr-chat-card.is-self { align-self: flex-end; flex-direction: row-reverse; }
.tr-chat-avatar { width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; object-fit: cover; background: #e2e8f0; margin-top: 4px; }
.tr-chat-bubble { padding: 10px 14px; border-radius: 14px; background: var(--tr-bg-soft); min-width: 0; }
.tr-chat-card.is-self .tr-chat-bubble { background: color-mix(in srgb, var(--tr-accent) 14%, transparent); border-bottom-right-radius: 4px; }
.tr-chat-card:not(.is-self) .tr-chat-bubble { border-bottom-left-radius: 4px; }
.tr-chat-bubble strong { display: block; font-size: 13px; color: var(--tr-accent); }
.tr-chat-bubble .tr-text { margin: 4px 0 0; font-size: 14px; line-height: 1.5; word-break: break-word; }
.tr-chat-bubble .tr-time { display: block; margin-top: 4px; font-size: 11px; color: #94a3b8; }
.tr-chat-card.is-self .tr-chat-bubble .tr-time { text-align: right; }

/* -- Room card embed */
.tr-room-card-embed { margin-top: 8px; padding: 12px; border-radius: 12px; background: rgba(0,0,0,0.04); border: 1px solid rgba(148,163,184,0.15); }
.dark .tr-room-card-embed, [data-theme="dark"] .tr-room-card-embed { background: rgba(0,0,0,0.15); border-color: rgba(71,85,105,0.4); }
.tr-room-card-embed .rc-title { font-weight: 700; font-size: 14px; margin-bottom: 6px; }
.tr-room-card-embed .rc-row { font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px; margin-top: 4px; }
.dark .tr-room-card-embed .rc-row { color: #94a3b8; }
.tr-room-card-embed .rc-link { color: var(--tr-accent); font-weight: 600; text-decoration: none; }
.tr-room-card-embed .rc-link:hover { text-decoration: underline; }

/* -- Chat input bar */
.tr-chat-input-area { border-top: 1px solid var(--tr-glass-border); padding: 12px 20px 16px; }
.tr-chat-input-row { display: flex; gap: 8px; align-items: flex-end; }
.tr-chat-input-row textarea { flex: 1; border-radius: 12px; border: 1px solid var(--tr-glass-border); padding: 10px 14px; font: inherit; font-size: 14px; background: rgba(148,163,184,0.06); color: inherit; resize: none; min-height: 40px; max-height: 120px; }
.tr-chat-input-row textarea:focus { outline: none; border-color: var(--tr-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--tr-accent) 15%, transparent); }
.tr-chat-send-btn { width: 40px; height: 40px; border-radius: 12px; border: 0; background: var(--tr-accent); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .15s; flex-shrink: 0; }
.tr-chat-send-btn:hover { filter: brightness(1.1); transform: scale(1.05); }
.tr-chat-send-btn:active { transform: scale(.95); }
.tr-chat-send-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; }

/* -- Team/results list items */
.tr-list { display: grid; gap: 8px; }
.tr-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-radius: 12px; background: var(--tr-bg-soft); transition: all .15s; }
.tr-item:hover { background: rgba(148,163,184,0.12); }
.tr-item strong { display: block; font-size: 14px; }
.tr-item span { color: #64748b; font-size: 12px; }
.dark .tr-item span, [data-theme="dark"] .tr-item span { color: #94a3b8; }

/* -- Results board */
.tr-results-board { display: grid; gap: 8px; }
.tr-result-card { padding: 12px 16px; border-radius: 12px; background: var(--tr-bg-soft); display: flex; align-items: baseline; gap: 8px; }
.tr-result-card .tr-placement { font-weight: 800; font-size: 15px; color: var(--tr-accent); min-width: 32px; }
.tr-result-card .tr-player-name { font-weight: 600; font-size: 14px; }
.tr-result-card .tr-detail { font-size: 12px; color: #64748b; margin-left: auto; text-align: right; white-space: nowrap; }
.dark .tr-result-card .tr-detail { color: #94a3b8; }

/* -- Result form rows */
.tr-results-grid { display: grid; gap: 10px; }
.tr-result-row { display: grid; grid-template-columns: 1.2fr repeat(6, minmax(70px, 1fr)); gap: 6px; align-items: center; padding: 10px 14px; border-radius: 12px; background: var(--tr-bg-soft); }
.tr-result-name strong { font-size: 13px; }
.tr-result-name small { display: block; font-size: 11px; color: #64748b; }
.tr-result-row input, .tr-result-row select { width: 100%; border-radius: 8px; border: 1px solid var(--tr-glass-border); padding: 6px 8px; font: inherit; font-size: 12px; background: rgba(148,163,184,0.06); color: inherit; }
.tr-result-row input:focus { outline: none; border-color: var(--tr-accent); }

/* -- Forms */
.tr-form { display: grid; gap: 12px; }
.tr-field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.tr-form input, .tr-form textarea, .tr-form select { width: 100%; border-radius: 10px; border: 1px solid var(--tr-glass-border); padding: 10px 12px; font: inherit; font-size: 13px; background: rgba(148,163,184,0.06); color: inherit; }
.tr-form input:focus, .tr-form textarea:focus, .tr-form select:focus { outline: none; border-color: var(--tr-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--tr-accent) 12%, transparent); }
.tr-form textarea { min-height: 80px; resize: vertical; }
.tr-form label { font-size: 13px; font-weight: 600; }

/* -- Feedback */
.tr-feedback { font-size: 13px; min-height: 20px; display: flex; align-items: center; gap: 6px; }
.tr-feedback.success { color: #15803d; }
.tr-feedback.error { color: #dc2626; }

/* -- Bracket */
.tr-bracket-container { position: relative; }
<?php require __DIR__ . '/../includes/bracket_styles.php'; ?>
.tr-bracket-wrapper { display: flex; gap: 0; overflow-x: auto; padding: 16px 4px 20px; min-height: 220px; align-items: stretch; }
.tr-bracket-round { display: flex; flex-direction: column; gap: 8px; min-width: 175px; flex-shrink: 0; position: relative; padding: 0 12px; }
.tr-bracket-round:not(:last-child)::after { content: ''; position: absolute; right: -4px; top: 40%; bottom: 40%; width: 2px; background: linear-gradient(to bottom, transparent, var(--tr-accent), transparent); opacity: .3; }
.tr-bracket-round-header { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: #64748b; padding: 6px 14px; border-radius: 8px; background: var(--tr-bg-soft); text-align: center; margin-bottom: 6px; border: 1px solid var(--tr-glass-border); }
.dark .tr-bracket-round-header { color: #94a3b8; }
.tr-bracket-matches { display: flex; flex-direction: column; gap: 10px; flex: 1; justify-content: space-around; }
.tr-bracket-match { padding: 12px 14px; border-radius: 12px; background: var(--tr-bg-soft); border: 1px solid var(--tr-glass-border); font-size: 12px; transition: all .2s; position: relative; }
.tr-bracket-match:hover { border-color: color-mix(in srgb, var(--tr-accent) 30%, transparent); box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
.tr-bracket-team { padding: 5px 8px; font-weight: 600; cursor: pointer; transition: all .15s; border-radius: 8px; font-size: 12px; display: flex; align-items: center; gap: 4px; }
.tr-bracket-team:hover { background: color-mix(in srgb, var(--tr-accent) 10%, transparent); }
.tr-bracket-team.is-winner { color: var(--tr-accent); font-weight: 700; }
.tr-bracket-team.is-winner::after { content: " ✓"; margin-left: auto; }
.tr-bracket-team.tbd { color: #94a3b8; font-style: italic; cursor: default; }
.tr-bracket-team.tbd:hover { background: none; }
.tr-bracket-vs { font-size: 9px; font-weight: 700; color: #94a3b8; text-align: center; padding: 1px 0; letter-spacing: .15em; }
.tr-bracket-winner { margin-top: 6px; padding: 4px 8px; border-radius: 8px; background: color-mix(in srgb, var(--tr-accent) 12%, transparent); color: var(--tr-accent); font-weight: 700; font-size: 10px; text-align: center; }
.tr-bracket-live { margin-top: 6px; padding: 4px 8px; border-radius: 8px; background: rgba(239,68,68,0.12); color: #b91c1c; font-weight: 700; font-size: 10px; text-align: center; animation: tr-pulse 1.5s ease-in-out infinite; }
.tr-bracket-connector { display: none; }

/* -- Participants mini-list */
.tr-participant-mini { display: flex; flex-wrap: wrap; gap: 6px; }
.tr-participant-mini .tr-p-mini-avatar { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 2px solid var(--tr-glass-border); transition: transform .15s; cursor: pointer; }
.tr-participant-mini .tr-p-mini-avatar:hover { transform: scale(1.15); z-index: 2; position: relative; }
.tr-participant-mini .tr-p-mini-more { width: 28px; height: 28px; border-radius: 50%; background: var(--tr-bg-soft); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: #64748b; border: 2px solid var(--tr-glass-border); }

/* -- Empty state */
.tr-empty { text-align: center; padding: 24px 16px; color: #94a3b8; }
.tr-empty i { font-size: 28px; margin-bottom: 8px; opacity: .4; display: block; }
.tr-empty p { margin: 0; font-size: 13px; }

/* -- Responsive */
@media (max-width: 820px) {
  .tr-grid { grid-template-columns: 1fr; }
  .tr-panel-body { padding: 12px 16px 16px; }
  .tr-panel-header { padding: 14px 16px 0; }
  .tr-chat-input-area { padding: 10px 16px 12px; }
  .tr-result-row { grid-template-columns: 1fr 1fr 1fr; }
  .tr-result-row .tr-result-name { grid-column: 1 / -1; }
  .tr-meta { gap: 6px 12px; font-size: 12px; }
  .tr-hero { padding: 20px 16px; }
  .tr-chat-card { max-width: 95%; }
}
@media (max-width: 480px) {
  .tr-shell { padding: 12px 10px 80px; }
  .tr-chat-feed { max-height: 60vh; }
  .tr-result-row { grid-template-columns: 1fr 1fr; }
  .tr-title-row h1 { font-size: 20px; }
}
</style>

<div class="tr-shell" data-tournament-room data-tournament-id="<?php echo $tournamentId; ?>" data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>" data-viewer-id="<?php echo $viewerId; ?>">
    <a class="tr-back" href="index.php?page=tournaments" data-no-ajax><i class="fas fa-arrow-left"></i><span>Back to tournaments</span></a>

    <!-- ===== Hero ===== -->
    <section class="tr-hero">
        <div class="tr-hero-accent-bg"></div>
        <div class="tr-title-row">
            <div>
                <h1><?php echo $title; ?></h1>
                <div class="tr-meta">
                    <span><i class="fas fa-calendar-days"></i> <?php echo !empty($tournament['starts_at']) ? date('M j, Y g:i A', strtotime($tournament['starts_at'])) : 'Start time TBD'; ?></span>
                    <span><i class="fas fa-users"></i> <?php echo (int) ($tournament['registered_teams'] ?? 0); ?> joined</span>
                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($tournament['category'] ?? 'General'); ?></span>
                    <span><i class="fas fa-trophy"></i> <?php echo (float)($tournament['prize_money'] ?? 0) > 0 ? 'Prize ৳' . htmlspecialchars((string)$tournament['prize_money']) : 'Trophy only'; ?></span>
                    <?php if ((float)($tournament['entry_fee'] ?? 0) > 0): ?>
                    <span><i class="fas fa-coins"></i> Entry ৳<?php echo number_format((float)$tournament['entry_fee'], 0); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($tournament['prize_breakdown'])): ?>
                    <span><i class="fas fa-list-ol"></i> Prize breakdown available</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($tournament['description'])): ?>
                    <p style="margin-top:12px;font-size:14px;line-height:1.6;color:var(--tr-muted)"><?php echo htmlspecialchars($tournament['description']); ?></p>
                <?php endif; ?>
            </div>
            <span class="tr-badge <?php echo htmlspecialchars($status); ?>" id="trStatusBadge"><i class="fas fa-signal"></i> <?php echo strtoupper($status); ?></span>
        </div>
        <?php if ($isAgentOwner): ?>
            <div class="tr-actions tr-hero-actions">
                <button class="tr-btn tr-btn-sm ghost" data-status-action="upcoming"><i class="fas fa-pause"></i> Upcoming</button>
                <button class="tr-btn tr-btn-sm warn" data-status-action="live"><i class="fas fa-broadcast-tower"></i> Live</button>
                <button class="tr-btn tr-btn-sm success" data-status-action="completed"><i class="fas fa-check-circle"></i> Complete</button>
                <button class="tr-btn tr-btn-sm danger" data-cancel-tournament><i class="fas fa-ban"></i> Cancel</button>
                <div class="tr-feedback" id="trCancelFeedback"></div>
            </div>
        <?php endif; ?>
    </section>

    <!-- ===== Grid ===== -->
    <div class="tr-grid">
        <!-- Left column -->
        <div class="tr-stack">

            <!-- Chat panel -->
            <section class="tr-panel">
                <div class="tr-panel-header">
                    <h2><i class="fas fa-comments"></i> Room chat <span class="tr-badge" style="font-size:10px;padding:2px 8px;background:rgba(16,185,129,0.14);color:#047857;margin-left:6px" id="trLiveBadge">LIVE</span></h2>
                    <button class="tr-btn tr-btn-sm ghost" type="button" data-refresh-chat><i class="fas fa-rotate"></i> Refresh</button>
                </div>
                <div class="tr-panel-body" style="padding-bottom:0">
                    <div class="tr-chat-feed" id="trChatFeed">
                        <?php if (!$messages): ?>
                            <div class="tr-empty"><i class="fas fa-comment-dots"></i><p>No messages yet. Invites and updates will appear here.</p></div>
                        <?php endif; ?>
                        <?php foreach ($messages as $message):
                            $meta = !empty($message['metadata_json']) ? json_decode($message['metadata_json'], true) : [];
                            $isSelf = (int)($message['sender_id'] ?? 0) === $viewerId;
                            $avatar = htmlspecialchars($message['avatar'] ?? 'default.png');
                        ?>
                            <article class="tr-chat-card<?php echo $isSelf ? ' is-self' : ''; ?>" data-message-id="<?php echo (int)($message['id'] ?? 0); ?>">
                                <?php if (!$isSelf): ?>
                                <img src="assets/avatars/<?php echo $avatar; ?>" alt="" class="tr-chat-avatar" onerror="this.src='assets/avatars/default.png'">
                                <?php endif; ?>
                                <div class="tr-chat-bubble">
                                    <strong><?php echo htmlspecialchars($message['full_name'] ?: $message['username']); ?></strong>
                                    <div class="tr-text"><?php echo nl2br(htmlspecialchars($message['message'] ?? '')); ?></div>
                                    <?php if (($message['message_type'] ?? '') === 'room_card' && is_array($meta)): ?>
                                    <div class="tr-room-card-embed">
                                        <?php if (!empty($meta['room_title'])): ?><div class="rc-title"><?php echo htmlspecialchars($meta['room_title']); ?></div><?php endif; ?>
                                        <?php if (!empty($meta['room_code'])): ?><div class="rc-row"><i class="fas fa-key"></i> Room: <strong><?php echo htmlspecialchars($meta['room_code']); ?></strong></div><?php endif; ?>
                                        <?php if (!empty($meta['room_link'])): ?><div class="rc-row"><i class="fas fa-link"></i> <a href="<?php echo htmlspecialchars($meta['room_link']); ?>" target="_blank" rel="noopener" class="rc-link">Open invite</a></div><?php endif; ?>
                                        <?php if (!empty($meta['starts_at'])): ?><div class="rc-row"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($meta['starts_at']); ?></div><?php endif; ?>
                                        <?php if (!empty($meta['note'])): ?><div class="rc-row"><i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($meta['note']); ?></div><?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <span class="tr-time"><?php echo date('M j, g:i A', strtotime($message['created_at'] ?? 'now')); ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="tr-chat-input-area">
                    <form class="tr-chat-input-row" id="trTextChatForm">
                        <input type="hidden" name="message_type" value="text">
                        <textarea name="message" placeholder="Type a message..." required rows="1" id="trChatInput"></textarea>
                        <button class="tr-chat-send-btn" type="submit" title="Send"><i class="fas fa-paper-plane"></i></button>
                    </form>
                    <div class="tr-feedback" id="trChatFeedback" style="margin-top:6px;min-height:0"></div>
                </div>
            </section>

            <!-- Bracket panel -->
            <section class="tr-panel" id="trBracketPanel">
                <div class="tr-panel-header">
                    <h2><i class="fas fa-diagram-project"></i> Match bracket</h2>
                    <?php if ($isAgentOwner): ?>
                    <div class="tr-actions">
                        <button class="tr-btn tr-btn-sm ghost" type="button" data-generate-bracket<?php echo $hasBracket ? ' style="display:none"' : ''; ?>><i class="fas fa-shuffle"></i> Generate bracket</button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="tr-panel-body">
                    <div id="trBracketContainer" class="tr-bracket-container">
                        <?php if (!$hasBracket): ?>
                        <div class="tr-empty" id="trBracketEmpty"><i class="fas fa-diagram-project"></i><p>Bracket not yet generated. The tournament agent can generate a bracket once participants are registered.</p></div>
                        <?php else: ?>
                        <?php echo bracketRenderStages($bracket); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Agent-only panels -->
            <?php if ($isAgentOwner): ?>

            <!-- Room card -->
            <section class="tr-panel">
                <div class="tr-panel-header">
                    <h2><i class="fas fa-id-card-clip"></i> Share room card</h2>
                </div>
                <div class="tr-panel-body">
                    <p style="font-size:13px;color:#64748b;margin:0 0 12px">Send an invite card with room details to all participants.</p>
                    <form class="tr-form" id="trRoomCardForm">
                        <input type="hidden" name="message_type" value="room_card">
                        <div class="tr-field-grid">
                            <input type="text" name="room_title" placeholder="Room title" required>
                            <input type="text" name="room_code" placeholder="Room code / ID">
                        </div>
                        <div class="tr-field-grid">
                            <input type="url" name="room_link" placeholder="Invite link">
                            <input type="text" name="starts_at" placeholder="Open time / note">
                        </div>
                        <input type="text" name="note" placeholder="Quick note for players">
                        <textarea name="message" placeholder="Optional message above the card" rows="2"></textarea>
                        <div class="tr-actions">
                            <button class="tr-btn primary" type="submit"><i class="fas fa-paper-plane"></i> Share card</button>
                            <div class="tr-feedback" id="trRoomCardFeedback"></div>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Submit results -->
            <section class="tr-panel">
                <div class="tr-panel-header">
                    <h2><i class="fas fa-medal"></i> Submit final results</h2>
                </div>
                <div class="tr-panel-body">
                    <p style="font-size:13px;color:#64748b;margin:0 0 14px">Publishing results moves the tournament to <strong>completed</strong> and awards points/prizes to players.</p>
                    <form class="tr-form" id="trResultsForm">
                        <h3 style="font-size:14px;font-weight:700;margin:0">Teams</h3>
                        <div class="tr-results-grid">
                            <?php if ($teams): ?>
                                <?php foreach ($teams as $team): ?>
                                <div class="tr-result-row team-row">
                                    <div class="tr-result-name">
                                        <strong><?php echo htmlspecialchars($team['linked_team_name'] ?: $team['team_name'] ?: 'Team'); ?></strong>
                                        <small>by <?php echo htmlspecialchars($team['captain_name'] ?: $team['captain_username'] ?: '?'); ?></small>
                                    </div>
                                    <input type="hidden" name="team_id" value="<?php echo (int) ($team['team_id'] ?? 0); ?>">
                                    <input type="number" name="placement" min="1" placeholder="#">
                                    <input type="number" name="points_earned" min="0" placeholder="Pts">
                                    <input type="text" name="score" placeholder="Score">
                                    <input type="text" name="result_label" placeholder="Label">
                                    <input type="number" name="prize_amount" min="0" step="0.01" placeholder="Prize">
                                    <input type="text" name="notes" placeholder="Notes">
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="tr-empty" style="padding:12px">No registered teams yet.</p>
                            <?php endif; ?>
                        </div>

                        <div class="tr-panel-divider"></div>

                        <h3 style="font-size:14px;font-weight:700;margin:0">Players</h3>
                        <div class="tr-results-grid">
                            <?php if ($playerPool): ?>
                                <?php foreach ($playerPool as $participant): ?>
                                <div class="tr-result-row player-row">
                                    <div class="tr-result-name">
                                        <strong><?php echo htmlspecialchars($participant['full_name'] ?: $participant['username'] ?: 'Player'); ?></strong>
                                        <small><?php echo htmlspecialchars($participant['team_name'] ?: 'Solo'); ?></small>
                                    </div>
                                    <input type="hidden" name="user_id" value="<?php echo (int) ($participant['user_id'] ?? 0); ?>">
                                    <input type="hidden" name="team_id" value="<?php echo (int) ($participant['team_id'] ?? 0); ?>">
                                    <input type="number" name="placement" min="1" placeholder="#">
                                    <input type="number" name="points_earned" min="0" placeholder="Pts">
                                    <input type="text" name="score" placeholder="Score">
                                    <input type="text" name="result_label" placeholder="Label">
                                    <input type="number" name="prize_amount" min="0" step="0.01" placeholder="Prize">
                                    <input type="text" name="notes" placeholder="Notes">
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="tr-empty" style="padding:12px">No participants yet.</p>
                            <?php endif; ?>
                        </div>
                        <div class="tr-actions" style="margin-top:4px">
                            <button class="tr-btn success" type="submit"><i class="fas fa-cloud-upload-alt"></i> Publish results</button>
                            <div class="tr-feedback" id="trResultsFeedback"></div>
                        </div>
                    </form>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <!-- Right sidebar -->
        <div class="tr-stack">
            <section class="tr-panel">
                <div class="tr-panel-header">
                    <h2><i class="fas fa-users"></i> Teams</h2>
                    <span class="tr-badge" style="font-size:11px;padding:3px 10px;background:var(--tr-bg-soft)"><?php echo count($teams); ?></span>
                </div>
                <div class="tr-panel-body">
                    <div class="tr-list">
                        <?php if (!$teams): ?>
                            <div class="tr-empty"><i class="fas fa-users-slash"></i><p>No teams registered yet.</p></div>
                        <?php endif; ?>
                        <?php foreach ($teams as $team): ?>
                            <div class="tr-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($team['linked_team_name'] ?: $team['team_name'] ?: 'Team'); ?></strong>
                                    <span><?php echo (int) ($team['member_count'] ?? 0); ?> members &middot; <?php echo htmlspecialchars($team['captain_name'] ?: $team['captain_username'] ?: '?'); ?></span>
                                </div>
                                <?php $teamStatus = (string) ($team['status'] ?? 'ok'); ?>
                                <?php if (!in_array($teamStatus, ['ok', 'active', ''], true)): ?>
                                <span class="tr-badge" style="font-size:10px;padding:3px 10px;background:color-mix(in srgb, var(--tr-accent) 10%, transparent);color:var(--tr-accent)"><?php echo htmlspecialchars(strtoupper($teamStatus)); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="tr-panel">
                <div class="tr-panel-header">
                    <h2><i class="fas fa-user-group"></i> Participants</h2>
                    <span class="tr-badge" style="font-size:11px;padding:3px 10px;background:var(--tr-bg-soft)"><?php echo count($playerPool); ?></span>
                </div>
                <div class="tr-panel-body">
                    <?php if (!$playerPool): ?>
                    <div class="tr-empty"><i class="fas fa-user-plus"></i><p>No participants yet.</p></div>
                    <?php else: ?>
                    <div class="tr-participant-mini">
                        <?php foreach (array_slice($playerPool, 0, 20) as $p): ?>
                        <img src="assets/avatars/<?php echo htmlspecialchars($p['avatar'] ?? 'default.png'); ?>" alt="<?php echo htmlspecialchars($p['full_name'] ?: $p['username']); ?>" class="tr-p-mini-avatar" title="<?php echo htmlspecialchars($p['full_name'] ?: $p['username']); ?>" onerror="this.src='assets/avatars/default.png'">
                        <?php endforeach; ?>
                        <?php if (count($playerPool) > 20): ?>
                        <span class="tr-p-mini-more">+<?php echo count($playerPool) - 20; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="tr-panel">
                <div class="tr-panel-header">
                    <h2><i class="fas fa-ranking-star"></i> Results</h2>
                </div>
                <div class="tr-panel-body">
                    <div class="tr-results-board" id="trResultsBoard">
                        <?php if (!$results['teams'] && !$results['players']): ?>
                            <div class="tr-empty"><i class="fas fa-hourglass-half"></i><p>Results appear here once published.</p></div>
                        <?php endif; ?>
                        <?php foreach ($results['teams'] as $result): ?>
                            <div class="tr-result-card">
                                <span class="tr-placement">#<?php echo (int) ($result['placement'] ?? 0); ?></span>
                                <span class="tr-player-name"><?php echo htmlspecialchars($result['linked_team_name'] ?: 'Team'); ?></span>
                                <span class="tr-detail"><?php if (!empty($result['points_earned'])): ?><?php echo (int) $result['points_earned']; ?> pts<?php endif; ?><?php if (!empty($result['prize_amount']) && (float)$result['prize_amount'] > 0): ?>, ৳<?php echo htmlspecialchars($result['prize_amount']); ?><?php endif; ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($results['players'] as $result): ?>
                            <div class="tr-result-card">
                                <span class="tr-placement">#<?php echo (int) ($result['placement'] ?? 0); ?></span>
                                <span class="tr-player-name"><?php echo htmlspecialchars($result['full_name'] ?: $result['username'] ?: 'Player'); ?></span>
                                <span class="tr-detail"><?php if (!empty($result['points_earned'])): ?><?php echo (int) $result['points_earned']; ?> pts<?php endif; ?><?php if (!empty($result['prize_amount']) && (float)$result['prize_amount'] > 0): ?>, ৳<?php echo htmlspecialchars($result['prize_amount']); ?><?php endif; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
(function () {
    var root = document.querySelector('[data-tournament-room]');
    if (!root) { return; }

    var tournamentId = root.getAttribute('data-tournament-id');
    var csrfToken = root.getAttribute('data-csrf-token');
    var viewerId = parseInt(root.getAttribute('data-viewer-id') || '0', 10);
    var chatInput = document.getElementById('trChatInput');

    // Auto-resize chat input
    if (chatInput) {
        chatInput.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    }

    function setFeedback(id, message, ok) {
        var node = document.getElementById(id);
        if (!node) { return; }
        node.textContent = message || '';
        node.className = 'tr-feedback' + (message ? (ok ? ' success' : ' error') : '');
        if (message) {
            clearTimeout(node._hideTimer);
            node._hideTimer = setTimeout(function () { node.textContent = ''; node.className = 'tr-feedback'; }, 4000);
        }
    }

    function api(payload) {
        payload.csrf_token = csrfToken;
        return fetch('handlers/tournament_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        }).then(function (res) { return res.json(); });
    }

    function renderMessages(messages) {
        var feed = document.getElementById('trChatFeed');
        if (!feed) { return; }
        if (!Array.isArray(messages) || !messages.length) {
            feed.innerHTML = '<div class="tr-empty"><i class="fas fa-comment-dots"></i><p>No messages yet.</p></div>';
            return;
        }
        feed.innerHTML = messages.map(function (message) {
            var isSelf = parseInt(message.sender_id || '0', 10) === viewerId;
            var avatar = message.avatar || 'default.png';
            var meta = {};
            if (message.metadata_json) {
                try { meta = JSON.parse(message.metadata_json); } catch (e) { meta = {}; }
            }
            var roomMeta = '';
            if (message.message_type === 'room_card') {
                roomMeta += '<div class="tr-room-card-embed">';
                if (meta.room_title) { roomMeta += '<div class="rc-title">' + escapeHtml(meta.room_title) + '</div>'; }
                if (meta.room_code) { roomMeta += '<div class="rc-row"><i class="fas fa-key"></i> Room: <strong>' + escapeHtml(meta.room_code) + '</strong></div>'; }
                if (meta.room_link) { roomMeta += '<div class="rc-row"><i class="fas fa-link"></i> <a href="' + escapeAttr(meta.room_link) + '" target="_blank" rel="noopener" class="rc-link">Open invite</a></div>'; }
                if (meta.starts_at) { roomMeta += '<div class="rc-row"><i class="fas fa-clock"></i> ' + escapeHtml(meta.starts_at) + '</div>'; }
                if (meta.note) { roomMeta += '<div class="rc-row"><i class="fas fa-sticky-note"></i> ' + escapeHtml(meta.note) + '</div>'; }
                roomMeta += '</div>';
            }
            return '<article class="tr-chat-card' + (isSelf ? ' is-self' : '') + '">' +
                (isSelf ? '' : '<img src="assets/avatars/' + escapeAttr(avatar) + '" alt="" class="tr-chat-avatar" onerror="this.src=\'assets/avatars/default.png\'">') +
                '<div class="tr-chat-bubble">' +
                '<strong>' + escapeHtml(message.full_name || message.username || 'User') + '</strong>' +
                '<div class="tr-text">' + nl2br(escapeHtml(message.message || '')) + '</div>' +
                roomMeta +
                '<span class="tr-time">' + escapeHtml(formatDate(message.created_at)) + '</span>' +
                '</div></article>';
        }).join('');
        feed.scrollTop = feed.scrollHeight;
    }

    function renderResults(results) {
        var board = document.getElementById('trResultsBoard');
        if (!board) { return; }
        var cards = [];
        var totalPrize = 0;
        (results.teams || []).forEach(function (result) {
            var detail = (result.points_earned ? escapeHtml(String(result.points_earned)) + ' pts' : '') +
                (result.prize_amount && parseFloat(result.prize_amount) > 0 ? ', ৳' + escapeHtml(String(result.prize_amount)) : '');
            if (result.prize_amount && parseFloat(result.prize_amount) > 0) totalPrize += parseFloat(result.prize_amount);
            cards.push('<div class="tr-result-card"><span class="tr-placement">#' + escapeHtml(String(result.placement || '0')) + '</span><span class="tr-player-name">' + escapeHtml(result.linked_team_name || 'Team') + '</span>' + (detail ? '<span class="tr-detail">' + detail + '</span>' : '') + '</div>');
        });
        (results.players || []).forEach(function (result) {
            var detail = (result.points_earned ? escapeHtml(String(result.points_earned)) + ' pts' : '') +
                (result.prize_amount && parseFloat(result.prize_amount) > 0 ? ', ৳' + escapeHtml(String(result.prize_amount)) : '');
            if (result.prize_amount && parseFloat(result.prize_amount) > 0) totalPrize += parseFloat(result.prize_amount);
            cards.push('<div class="tr-result-card"><span class="tr-placement">#' + escapeHtml(String(result.placement || '0')) + '</span><span class="tr-player-name">' + escapeHtml(result.full_name || result.username || 'Player') + '</span>' + (detail ? '<span class="tr-detail">' + detail + '</span>' : '') + '</div>');
        });
        var html = cards.length ? cards.join('') : '<div class="tr-empty"><i class="fas fa-hourglass-half"></i><p>Results appear here once published.</p></div>';
        if (totalPrize > 0) {
            html = '<div class="tr-result-summary" style="padding:8px 12px;margin-bottom:10px;border-radius:10px;background:color-mix(in srgb, var(--tr-accent) 8%, transparent);border:1px solid color-mix(in srgb, var(--tr-accent) 15%, transparent);font-size:12px;display:flex;align-items:center;gap:8px"><i class="fas fa-coins" style="color:var(--tr-accent)"></i> <strong style="color:var(--tr-accent)">৳' + numberFormat(totalPrize) + '</strong> prize distributed</div>' + html;
        }
        board.innerHTML = html;
    }
    function numberFormat(n) { return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    function collectRows(selector, includeUser) {
        return Array.prototype.map.call(document.querySelectorAll(selector), function (row) {
            var payload = {
                team_id: (row.querySelector('input[name="team_id"]') || {}).value || '',
                placement: (row.querySelector('input[name="placement"]') || {}).value || '',
                points_earned: (row.querySelector('input[name="points_earned"]') || {}).value || '0',
                score: (row.querySelector('input[name="score"]') || {}).value || '',
                result_label: (row.querySelector('input[name="result_label"]') || {}).value || '',
                prize_amount: (row.querySelector('input[name="prize_amount"]') || {}).value || '0',
                notes: (row.querySelector('input[name="notes"]') || {}).value || ''
            };
            if (includeUser) {
                var uid = row.querySelector('input[name="user_id"]');
                if (uid) { payload.user_id = uid.value; }
            }
            return payload;
        }).filter(function (row) {
            return row.placement || row.score || row.result_label;
        });
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    function nl2br(value) {
        return (value || '').replace(/\n/g, '<br>');
    }

    function formatDate(value) {
        if (!value) { return 'Just now'; }
        var date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) { return value; }
        var now = new Date();
        var diffMs = now - date;
        var diffMin = Math.floor(diffMs / 60000);
        if (diffMin < 1) { return 'Just now'; }
        if (diffMin < 60) { return diffMin + 'm ago'; }
        var diffHr = Math.floor(diffMin / 60);
        if (diffHr < 24) { return diffHr + 'h ago'; }
        return date.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    // ─── SSE: Real-time chat, bracket & status ───
    var lastMessageId = 0;
    Array.prototype.forEach.call(document.querySelectorAll('#trChatFeed .tr-chat-card[data-message-id]'), function (el) {
        var mid = parseInt(el.getAttribute('data-message-id') || '0', 10);
        if (mid > lastMessageId) lastMessageId = mid;
    });
    var sseUrl = 'handlers/tournament_stream.php?tournament_id=' + tournamentId + '&last_id=' + lastMessageId;
    var evtSource = new EventSource(sseUrl);

    function appendMessages(messages) {
        var feed = document.getElementById('trChatFeed');
        if (!feed) return;
        if (!Array.isArray(messages) || !messages.length) return;
        var wasEmpty = feed.querySelector('.tr-empty');
        if (wasEmpty) feed.innerHTML = '';
        var html = messages.map(function (message) {
            var isSelf = parseInt(message.sender_id || '0', 10) === viewerId;
            var avatar = message.avatar || 'default.png';
            var meta = {};
            if (message.metadata_json) { try { meta = JSON.parse(message.metadata_json); } catch (e) { meta = {}; } }
            var roomMeta = '';
            if (message.message_type === 'room_card') {
                roomMeta += '<div class="tr-room-card-embed">';
                if (meta.room_title) { roomMeta += '<div class="rc-title">' + escapeHtml(meta.room_title) + '</div>'; }
                if (meta.room_code) { roomMeta += '<div class="rc-row"><i class="fas fa-key"></i> Room: <strong>' + escapeHtml(meta.room_code) + '</strong></div>'; }
                if (meta.room_link) { roomMeta += '<div class="rc-row"><i class="fas fa-link"></i> <a href="' + escapeAttr(meta.room_link) + '" target="_blank" rel="noopener" class="rc-link">Open invite</a></div>'; }
                if (meta.starts_at) { roomMeta += '<div class="rc-row"><i class="fas fa-clock"></i> ' + escapeHtml(meta.starts_at) + '</div>'; }
                if (meta.note) { roomMeta += '<div class="rc-row"><i class="fas fa-sticky-note"></i> ' + escapeHtml(meta.note) + '</div>'; }
                roomMeta += '</div>';
            }
            return '<article class="tr-chat-card' + (isSelf ? ' is-self' : '') + '" data-message-id="' + parseInt(message.id || '0', 10) + '">' +
                (isSelf ? '' : '<img src="assets/avatars/' + escapeAttr(avatar) + '" alt="" class="tr-chat-avatar" onerror="this.src=\'assets/avatars/default.png\'">') +
                '<div class="tr-chat-bubble">' +
                '<strong>' + escapeHtml(message.full_name || message.username || 'User') + '</strong>' +
                '<div class="tr-text">' + nl2br(escapeHtml(message.message || '')) + '</div>' +
                roomMeta +
                '<span class="tr-time">' + escapeHtml(formatDate(message.created_at)) + '</span>' +
                '</div></article>';
        }).join('');
        feed.insertAdjacentHTML('beforeend', html);
        feed.scrollTop = feed.scrollHeight;
    }

    // Dedupe: skip messages already rendered (SSE may re-send after reconnect)
    var renderedIds = {};
    Array.prototype.forEach.call(document.querySelectorAll('#trChatFeed .tr-chat-card[data-message-id]'), function (el) {
        renderedIds[el.getAttribute('data-message-id')] = true;
    });

    evtSource.addEventListener('messages', function (event) {
        try {
            var msgs = JSON.parse(event.data);
            if (Array.isArray(msgs) && msgs.length) {
                msgs = msgs.filter(function (m) {
                    var key = String(m.id || '');
                    if (!key || renderedIds[key]) return false;
                    renderedIds[key] = true;
                    return true;
                });
                if (msgs.length) {
                    appendMessages(msgs);
                    msgs.forEach(function (m) {
                        var mid = parseInt(m.id || '0', 10);
                        if (mid > lastMessageId) lastMessageId = mid;
                    });
                }
            }
        } catch (e) {}
    });

    evtSource.addEventListener('bracket', function (event) {
        try {
            var data = JSON.parse(event.data);
            if (Array.isArray(data.bracket) && data.bracket.length) {
                renderBracket(data.bracket);
                if (data.status === 'completed') {
                    setTimeout(function () { window.location.reload(); }, 1500);
                }
            }
        } catch (e) {}
    });

    evtSource.addEventListener('status', function (event) {
        try {
            var data = JSON.parse(event.data);
            if (data.status) {
                var badge = document.getElementById('trStatusBadge');
                if (!badge) { window.location.reload(); return; }
                badge.className = 'tr-badge ' + data.status;
                badge.innerHTML = '<i class="fas fa-signal"></i> ' + data.status.toUpperCase();
            }
        } catch (e) {}
    });

    evtSource.onerror = function () {
        var liveBadge = document.getElementById('trLiveBadge');
        if (liveBadge) { liveBadge.textContent = 'RECONNECT'; liveBadge.style.background = 'rgba(245,158,11,0.14)'; liveBadge.style.color = '#b45309'; liveBadge.style.animation = 'tr-reconnect-pulse 2s ease-in-out infinite'; }
    };

    evtSource.onopen = function () {
        var liveBadge = document.getElementById('trLiveBadge');
        if (liveBadge) { liveBadge.textContent = 'LIVE'; liveBadge.style.background = 'rgba(16,185,129,0.14)'; liveBadge.style.color = '#047857'; liveBadge.style.animation = 'none'; }
    };

    function closeSSE() { evtSource.close(); }
    window.addEventListener('pagehide', closeSSE);
    window.addEventListener('beforeunload', closeSSE);
    var backLink = document.querySelector('.tr-back');
    if (backLink) { backLink.addEventListener('click', closeSSE); }

    // Also update SSE URL after sending a message to ensure we get new messages
    var origSend = api;
    api = function (payload) {
        return origSend(payload).then(function (result) {
            if (payload.action === 'send_tournament_chat' && result.success && result.messages) {
                result.messages.forEach(function (m) {
                    var mid = parseInt(m.id || '0', 10);
                    if (mid > lastMessageId) lastMessageId = mid;
                });
            }
            return result;
        });
    };

    // Cancel tournament with inline confirmation
    var cancelBtn = document.querySelector('[data-cancel-tournament]');
    if (cancelBtn) {
        var cancelConfirm = document.createElement('div');
        cancelConfirm.className = 'tr-cancel-confirm';
        cancelConfirm.style.cssText = 'display:none;margin-top:8px;padding:10px 14px;border-radius:10px;background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.2)';
        cancelConfirm.innerHTML = '<div style="font-size:13px;font-weight:600;color:#dc2626;margin-bottom:8px"><i class="fas fa-exclamation-triangle"></i> All fees refunded, prize returned to your balance. Proceed?</div><div style="display:flex;gap:6px"><button class="tr-btn tr-btn-sm danger" id="trCancelConfirmYes"><i class="fas fa-check"></i> Yes, cancel</button><button class="tr-btn tr-btn-sm ghost" id="trCancelConfirmNo">No</button></div>';
        cancelBtn.parentNode.insertBefore(cancelConfirm, cancelBtn.nextSibling);

        cancelBtn.addEventListener('click', function () {
            cancelConfirm.style.display = 'block';
            cancelBtn.style.display = 'none';
        });

        document.getElementById('trCancelConfirmNo').addEventListener('click', function () {
            cancelConfirm.style.display = 'none';
            cancelBtn.style.display = 'inline-flex';
        });

        document.getElementById('trCancelConfirmYes').addEventListener('click', function () {
            document.getElementById('trCancelConfirmYes').disabled = true;
            api({ action: 'cancel_tournament', tournament_id: tournamentId })
                .then(function (result) {
                    setFeedback('trCancelFeedback', result.message || '', !!result.success);
                    if (result.success) { setTimeout(function () { window.location.reload(); }, 1500); }
                    document.getElementById('trCancelConfirmYes').disabled = false;
                })
                .catch(function () {
                    setFeedback('trCancelFeedback', 'Request failed.', false);
                    document.getElementById('trCancelConfirmYes').disabled = false;
                });
        });
    }

    // Status buttons
    Array.prototype.forEach.call(document.querySelectorAll('[data-status-action]'), function (button) {
        button.addEventListener('click', function () {
            button.disabled = true;
            api({ action: 'update_tournament_status', tournament_id: tournamentId, status: button.getAttribute('data-status-action') })
                .then(function (result) {
                    if (result.success) { window.location.reload(); return; }
                    setFeedback('trChatFeedback', result.message || 'Could not update status.', false);
                    button.disabled = false;
                })
                .catch(function () {
                    setFeedback('trChatFeedback', 'Request failed.', false);
                    button.disabled = false;
                });
        });
    });

    // Chat form
    var textChatForm = document.getElementById('trTextChatForm');
    if (textChatForm) {
        textChatForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var formData = Object.fromEntries(new FormData(textChatForm));
            if (!formData.message || !formData.message.trim()) { return; }
            formData.action = 'send_tournament_chat';
            formData.tournament_id = tournamentId;
            var btn = textChatForm.querySelector('.tr-chat-send-btn');
            btn.disabled = true;
            api(formData).then(function (result) {
                setFeedback('trChatFeedback', result.message || '', !!result.success);
                if (result.success) {
                    textChatForm.reset();
                    if (chatInput) { chatInput.style.height = 'auto'; }
                    renderMessages(result.messages || []);
                }
                btn.disabled = false;
            }).catch(function () {
                setFeedback('trChatFeedback', 'Could not send message.', false);
                btn.disabled = false;
            });
        });
    }

    // Room card form
    var roomCardForm = document.getElementById('trRoomCardForm');
    if (roomCardForm) {
        roomCardForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var formData = Object.fromEntries(new FormData(roomCardForm));
            formData.action = 'send_tournament_chat';
            formData.tournament_id = tournamentId;
            var btn = roomCardForm.querySelector('.tr-btn.primary');
            btn.disabled = true;
            api(formData).then(function (result) {
                setFeedback('trRoomCardFeedback', result.message || '', !!result.success);
                if (result.success) {
                    roomCardForm.reset();
                    renderMessages(result.messages || []);
                }
                btn.disabled = false;
            }).catch(function () {
                setFeedback('trRoomCardFeedback', 'Could not share room card.', false);
                btn.disabled = false;
            });
        });
    }

    // Results form
    var resultsForm = document.getElementById('trResultsForm');
    if (resultsForm) {
        resultsForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var btn = resultsForm.querySelector('.tr-btn.success');
            btn.disabled = true;
            api({
                action: 'submit_tournament_results',
                tournament_id: tournamentId,
                team_results: collectRows('.team-row', false),
                player_results: collectRows('.player-row', true)
            }).then(function (result) {
                setFeedback('trResultsFeedback', result.message || '', !!result.success);
                if (result.success) {
                    renderResults(result.results || { teams: [], players: [] });
                }
                btn.disabled = false;
            }).catch(function () {
                setFeedback('trResultsFeedback', 'Could not submit results.', false);
                btn.disabled = false;
            });
        });
    }

    // Refresh
    var refreshButton = document.querySelector('[data-refresh-chat]');
    if (refreshButton) {
        refreshButton.addEventListener('click', function () {
            api({ action: 'get_tournament_chat', tournament_id: tournamentId }).then(function (result) {
                if (result.success) { renderMessages(result.messages || []); }
            });
        });
    }

    // ─── Bracket ───
    var stageOrder = ['single', 'winners', 'losers', 'grandfinal', 'group'];
    var stageTitles = { single: 'Bracket', winners: 'Winners Bracket', losers: 'Losers Bracket', grandfinal: 'Grand Final', group: 'Group Stage' };
    function renderBracket(bracket) {
        var container = document.getElementById('trBracketContainer');
        if (!container) return;
        if (!Array.isArray(bracket) || !bracket.length) {
            container.innerHTML = '<div class="tr-empty"><i class="fas fa-diagram-project"></i><p>Bracket not yet generated.</p></div>';
            return;
        }
        var stages = {};
        bracket.forEach(function (match) {
            var st = match.stage || 'single';
            if (!stages[st]) stages[st] = {};
            var r = parseInt(match.round || '1', 10);
            if (!stages[st][r]) stages[st][r] = [];
            stages[st][r].push(match);
        });
        var stageKeys = Object.keys(stages).sort(function (a, b) {
            var ia = stageOrder.indexOf(a), ib = stageOrder.indexOf(b);
            return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
        });
        var html = '';
        stageKeys.forEach(function (st) {
            html += '<div class="tr-bracket-stage"><div class="tr-bracket-stage-title">' + escapeHtml(stageTitles[st] || st) + '</div><div class="tr-bracket-wrapper">';
            var roundKeys = Object.keys(stages[st]).sort(function (a, b) { return parseInt(a, 10) - parseInt(b, 10); });
            roundKeys.forEach(function (roundNum) {
                html += '<div class="tr-bracket-round">';
                html += '<div class="tr-bracket-round-header">Round ' + roundNum + '</div>';
                html += '<div class="tr-bracket-matches">';
                (stages[st][roundNum] || []).forEach(function (match) {
                    var team1 = escapeHtml(match.team1_name || 'TBD');
                    var team2 = escapeHtml(match.team2_name || 'TBD');
                    var winner = match.winner_name || '';
                    var status = match.status || 'scheduled';
                    var matchId = parseInt(match.id || '0', 10);
                    var team1Id = parseInt(match.team1_id || '0', 10);
                    var team2Id = parseInt(match.team2_id || '0', 10);
                    var rpt = match.report_state || 'none';
                    html += '<div class="tr-bracket-match" data-match-id="' + matchId + '" data-team1-id="' + team1Id + '" data-team2-id="' + team2Id + '">';
                    html += '<div class="tr-bracket-team' + (winner && winner === team1 ? ' is-winner' : '') + '" data-match-id="' + matchId + '" data-team-id="' + team1Id + '">' + team1 + (match.score1 != null ? ' <strong>' + match.score1 + '</strong>' : '') + '</div>';
                    html += '<div class="tr-bracket-vs">VS</div>';
                    html += '<div class="tr-bracket-team' + (winner && winner === team2 ? ' is-winner' : '') + '" data-match-id="' + matchId + '" data-team-id="' + team2Id + '">' + team2 + (match.score2 != null ? ' <strong>' + match.score2 + '</strong>' : '') + '</div>';
                    if (status === 'completed' && winner) {
                        html += '<div class="tr-bracket-winner"><i class="fas fa-trophy"></i> ' + escapeHtml(winner) + '</div>';
                    } else if (status === 'live') {
                        html += '<div class="tr-bracket-live">Live</div>';
                    }
                    if (rpt === 'reported') html += '<div class="tr-bracket-live">Reported — awaiting confirmation</div>';
                    else if (rpt === 'disputed') html += '<div class="tr-bracket-winner"><i class="fas fa-triangle-exclamation"></i> Disputed</div>';
                    html += '</div>';
                });
                html += '</div></div>';
            });
            html += '</div></div>';
        });
        container.innerHTML = html;
        attachBracketTeamClick();
    }

    function attachBracketTeamClick() {
        var isAgent = <?php echo $isAgentOwner ? 'true' : 'false'; ?>;
        Array.prototype.forEach.call(document.querySelectorAll('.tr-bracket-team[data-match-id][data-team-id]'), function (el) {
            var matchId = parseInt(el.getAttribute('data-match-id') || '0', 10);
            var teamId = parseInt(el.getAttribute('data-team-id') || '0', 10);
            if (!matchId || !teamId) return;
            if (isAgent) {
                el.title = 'Click to advance to next round';
                el.style.cursor = 'pointer';
                el.addEventListener('click', function () {
                    var confirmEl = document.createElement('div');
                    confirmEl.className = 'tr-bracket-advance-confirm';
                    confirmEl.style.cssText = 'position:absolute;top:100%;left:0;right:0;z-index:10;padding:8px;border-radius:10px;background:var(--tr-glass);border:1px solid var(--tr-glass-border);box-shadow:0 8px 24px rgba(0,0,0,0.12);margin-top:4px;font-size:12px';
                    confirmEl.innerHTML = 'Advance <strong>' + escapeHtml(el.textContent.trim()) + '</strong>?<div style="display:flex;gap:4px;margin-top:6px"><button class="tr-btn tr-btn-sm success" style="padding:3px 10px;font-size:11px">Yes</button><button class="tr-btn tr-btn-sm ghost" style="padding:3px 10px;font-size:11px">No</button></div>';
                    var existing = el.parentNode.querySelector('.tr-bracket-advance-confirm');
                    if (existing) existing.remove();
                    el.parentNode.style.position = 'relative';
                    el.parentNode.appendChild(confirmEl);
                    confirmEl.querySelector('button:last-child').addEventListener('click', function (e) { e.stopPropagation(); confirmEl.remove(); });
                    confirmEl.querySelector('button:first-child').addEventListener('click', function (e) {
                        e.stopPropagation();
                        confirmEl.querySelector('button:first-child').disabled = true;
                        api({ action: 'advance_winner', match_id: matchId, winner_team_id: teamId, tournament_id: tournamentId })
                            .then(function (result) {
                                if (result.success && result.bracket) renderBracket(result.bracket);
                                setFeedback('trBracketFeedback', result.message || '', !!result.success);
                            });
                    });
                });
            } else {
                el.title = el.textContent.trim();
            }
        });
    }

    initBracket: {
        var modalRoot = document.getElementById('trBracketContainer');
        if (modalRoot && !modalRoot.querySelector('.tr-empty')) {
            attachBracketTeamClick();
        }
    }

    // Generate bracket button
    var genBtn = document.querySelector('[data-generate-bracket]');
    if (genBtn) {
        genBtn.addEventListener('click', function () {
            genBtn.disabled = true;
            setFeedback('trBracketFeedback', 'Generating bracket...', true);
            api({ action: 'generate_bracket', tournament_id: tournamentId })
                .then(function (result) {
                    setFeedback('trBracketFeedback', result.message || '', !!result.success);
                    if (result.success && result.bracket) {
                        renderBracket(result.bracket);
                        genBtn.style.display = 'none';
                    }
                    genBtn.disabled = false;
                })
                .catch(function () {
                    setFeedback('trBracketFeedback', 'Could not generate bracket.', false);
                    genBtn.disabled = false;
                });
        });
    }

    // Bracket feedback element
    var bracketPanel = document.getElementById('trBracketPanel');
    if (bracketPanel) {
        var fb = document.createElement('div');
        fb.id = 'trBracketFeedback';
        fb.className = 'tr-feedback';
        fb.style.marginTop = '8px';
        bracketPanel.querySelector('.tr-panel-body').appendChild(fb);
    }
})();
</script>
