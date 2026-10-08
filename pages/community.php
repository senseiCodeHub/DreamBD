<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

$viewerId = $_SESSION['user_id'] ?? null;
$db = Database::getInstance()->getConnection();
ensureSocialTables($db);

$isGuest = !$viewerId;

$communityPosts = getVisibleFeedPosts($db, $viewerId, 50, 'community');
$communityOverview = getCommunityOverview($db, $viewerId);
$communitySecurity = new Security();
$communityCsrfToken = $communitySecurity->generateCSRFToken();
$communityUserDisplayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Guest';
$communityReactionOptions = [
    ['type' => 'like', 'label' => 'Like', 'emoji' => '👍'],
    ['type' => 'love', 'label' => 'Love', 'emoji' => '❤️'],
    ['type' => 'care', 'label' => 'Care', 'emoji' => '🥰'],
    ['type' => 'haha', 'label' => 'Haha', 'emoji' => '😆'],
    ['type' => 'wow', 'label' => 'Wow', 'emoji' => '😮'],
    ['type' => 'sad', 'label' => 'Sad', 'emoji' => '😢'],
    ['type' => 'angry', 'label' => 'Angry', 'emoji' => '😡'],
];
$communityLeftLinks = [
    ['icon' => 'fa-house', 'label' => 'Home feed', 'href' => 'index.php?page=home', 'page' => 'home', 'tone' => 'blue'],
    ['icon' => 'fa-user-group', 'label' => 'Friends', 'href' => 'index.php?page=profile#friends', 'page' => 'profile', 'tone' => 'cyan'],
    ['icon' => 'fa-clock-rotate-left', 'label' => 'Memories', 'href' => 'index.php?page=profile', 'page' => 'profile', 'tone' => 'sky'],
    ['icon' => 'fa-bookmark', 'label' => 'Saved', 'href' => 'index.php?page=profile', 'page' => 'profile', 'tone' => 'purple'],
    ['icon' => 'fa-store', 'label' => 'Marketplace', 'href' => 'index.php?page=products', 'page' => 'products', 'tone' => 'teal'],
    ['icon' => 'fa-trophy', 'label' => 'Tournaments', 'href' => 'index.php?page=tournaments', 'page' => 'tournaments', 'tone' => 'orange'],
];

$communityUserRole = $_SESSION['role'] ?? 'user';
if (in_array($communityUserRole, ['admin', 'moderator', 'super_admin'], true)) {
    $communityLeftLinks[] = ['icon' => 'fa-shield-alt', 'label' => 'Admin Panel', 'href' => 'admin/post-reports.php', 'page' => 'admin', 'tone' => 'red'];
}

/* ── Extra data for the redesigned page ── */
$communityTopPlayers = [];
$communityOnline = [];
$communitySuggestions = [];
$communityTournament = getFeaturedTournamentSummary($db);
$viewerStats = null;
$friendIdSet = [];
$friendRequestCount = 0;
$friendRequests = [];

try { $communityTopPlayers = getHomeTopPlayers($db, 5); } catch (Throwable $e) { $communityTopPlayers = []; }
try { $communityOnline = getOnlineUsersList($db, $viewerId, 8); } catch (Throwable $e) { $communityOnline = []; }

/* Strictly-active count (same source as the pulse strip), viewer included */
$onlineNow = count($communityOnline) + ($viewerId ? 1 : 0);
$communityOverview['online_users'] = $onlineNow;

if ($viewerId) {
    try { $viewerStats = getProfileStats($db, (int)$viewerId); } catch (Throwable $e) { $viewerStats = ['posts' => 0, 'friends' => 0, 'photos' => 0]; }
    try {
        $friendRows = getFriendsList($db, (int)$viewerId, 200);
        foreach ($friendRows as $fr) { $friendIdSet[(int)$fr['id']] = true; }
    } catch (Throwable $e) { $friendIdSet = []; }
    try { $friendRequests = getFriendRequests($db, $viewerId, 5); } catch (Throwable $e) { $friendRequests = []; }
    $friendRequestCount = count($friendRequests);
    try { $communitySuggestions = getSuggestedFriends($db, (int)$viewerId, 4); } catch (Throwable $e) { $communitySuggestions = []; }
}
$friendCount = count($friendIdSet);

$privacyMeta = [
    'public'  => ['icon' => 'fa-globe-americas', 'label' => 'Public'],
    'friends' => ['icon' => 'fa-user-group', 'label' => 'Friends'],
    'private' => ['icon' => 'fa-lock', 'label' => 'Only me'],
];

/* ── Hero banner settings (admin: admin/community-hero.php) ── */
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
$cmHero = $cmHeroDefaults;
try {
    $cmStmt = $db->query("SELECT `key`, `value` FROM site_settings WHERE `key` LIKE 'community_hero_%'");
    while ($cmRow = $cmStmt->fetch()) {
        $cmShort = substr($cmRow['key'], strlen('community_hero_'));
        if (array_key_exists($cmShort, $cmHeroDefaults)) {
            $cmHero[$cmShort] = (string) $cmRow['value'];
        }
    }
} catch (Throwable $e) {}
$cmHeroEnabled = $cmHero['enabled'] !== '0';
$cmHeroPill   = $cmHero['pill']     !== '' ? $cmHero['pill']     : $cmHeroDefaults['pill'];
$cmHeroHead   = $cmHero['heading']  !== '' ? $cmHero['heading']  : $cmHeroDefaults['heading'];
$cmHeroAccent = $cmHero['accent']   !== '' ? $cmHero['accent']   : $cmHeroDefaults['accent'];
$cmHeroSub    = $cmHero['sub']      !== '' ? $cmHero['sub']      : $cmHeroDefaults['sub'];
?>

<script src="<?php echo dream_asset('assets/js/community.js'); ?>" defer></script>
<div class="community-page" data-community-page data-csrf-token="<?php echo htmlspecialchars($communityCsrfToken); ?>" data-viewer-id="<?= (int)($viewerId ?? 0) ?>">

    <!-- ═══ HERO ═══ -->
    <?php if ($cmHeroEnabled): ?>
    <section class="cm-hero">
        <div class="cm-hero-inner">
            <span class="cm-hero-pill"><span class="cm-hero-pill-dot"></span> <?php echo htmlspecialchars($cmHeroPill); ?></span>
            <h1><?php echo htmlspecialchars($cmHeroHead); ?> <span class="cm-hero-accent"><?php echo htmlspecialchars($cmHeroAccent); ?></span></h1>
            <p class="cm-hero-sub"><?php echo htmlspecialchars($cmHeroSub); ?></p>
            <div class="cm-hero-cta">
                <?php if ($isGuest): ?>
                    <a class="cm-hero-btn cm-hero-btn--primary" href="<?php echo htmlspecialchars($cmHero['btn1_url'] !== '' ? $cmHero['btn1_url'] : 'index.php?page=register'); ?>"><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($cmHero['btn1_text'] !== '' ? $cmHero['btn1_text'] : 'Join the community'); ?></a>
                    <a class="cm-hero-btn cm-hero-btn--ghost" href="<?php echo htmlspecialchars($cmHero['btn2_url'] !== '' ? $cmHero['btn2_url'] : 'index.php?page=login'); ?>"><i class="fas fa-right-to-bracket"></i> <?php echo htmlspecialchars($cmHero['btn2_text'] !== '' ? $cmHero['btn2_text'] : 'Sign in'); ?></a>
                <?php else: ?>
                    <?php if ($cmHero['btn1_url'] === ''): ?>
                        <button type="button" class="cm-hero-btn cm-hero-btn--primary" id="heroCreatePost"><i class="fas fa-pen-to-square"></i> <?php echo htmlspecialchars($cmHero['btn1_text'] !== '' ? $cmHero['btn1_text'] : 'Create post'); ?></button>
                    <?php else: ?>
                        <a class="cm-hero-btn cm-hero-btn--primary" href="<?php echo htmlspecialchars($cmHero['btn1_url']); ?>"><i class="fas fa-pen-to-square"></i> <?php echo htmlspecialchars($cmHero['btn1_text'] !== '' ? $cmHero['btn1_text'] : 'Create post'); ?></a>
                    <?php endif; ?>
                    <a class="cm-hero-btn cm-hero-btn--ghost" href="<?php echo htmlspecialchars($cmHero['btn2_url'] !== '' ? $cmHero['btn2_url'] : 'index.php?page=profile#friends'); ?>"<?php echo $cmHero['btn2_url'] === '' ? ' data-page="profile"' : ''; ?>><i class="fas fa-user-group"></i> <?php echo htmlspecialchars($cmHero['btn2_text'] !== '' ? $cmHero['btn2_text'] : 'Find friends'); ?></a>
                <?php endif; ?>
            </div>
            <div class="cm-hero-stats">
                <div class="cm-stat-card">
                    <div class="cm-stat-icon cm-stat-icon--blue"><i class="fas fa-users"></i></div>
                    <div class="cm-stat-body"><strong><?php echo number_format($communityOverview['members']); ?></strong><span>Members</span></div>
                </div>
                <div class="cm-stat-card">
                    <div class="cm-stat-icon cm-stat-icon--green"><i class="fas fa-signal"></i></div>
                    <div class="cm-stat-body"><strong><?php echo number_format($communityOverview['online_users']); ?></strong><span>Online now</span></div>
                </div>
                <div class="cm-stat-card">
                    <div class="cm-stat-icon cm-stat-icon--amber"><i class="fas fa-feather-pointed"></i></div>
                    <div class="cm-stat-body"><strong><?php echo number_format($communityOverview['posts']); ?></strong><span>Posts</span></div>
                </div>
                <div class="cm-stat-card">
                    <div class="cm-stat-icon cm-stat-icon--purple"><i class="fas <?php echo $isGuest ? 'fa-earth-asia' : 'fa-user-group'; ?>"></i></div>
                    <div class="cm-stat-body">
                        <strong><?php echo $isGuest ? number_format($communityOverview['public_posts']) : number_format($friendCount); ?></strong>
                        <span><?php echo $isGuest ? 'Public posts' : 'Your friends'; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="community-layout">
        <!-- Sidebar Left -->
        <aside class="community-sidebar">
            <div class="community-panel community-profile-panel">
                <div class="community-left-user">
                    <img src="assets/avatars/<?php echo htmlspecialchars($_SESSION['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                    <div>
                        <strong><?php echo htmlspecialchars($communityUserDisplayName); ?></strong>
                        <?php if ($isGuest): ?>
                            <a href="index.php?page=login">Sign in to your account</a>
                        <?php else: ?>
                            <a href="index.php?page=profile" data-page="profile">View profile</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="community-left-menu">
                    <?php foreach ($communityLeftLinks as $leftLink): ?>
                        <a href="<?php echo htmlspecialchars($leftLink['href']); ?>" data-page="<?php echo htmlspecialchars($leftLink['page']); ?>" class="community-left-link" data-tone="<?php echo htmlspecialchars($leftLink['tone'] ?? 'blue'); ?>">
                            <i class="fas <?php echo htmlspecialchars($leftLink['icon']); ?>"></i>
                            <span><?php echo htmlspecialchars($leftLink['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!$isGuest && $viewerStats): ?>
            <div class="community-panel">
                <div class="community-panel-header"><h3>Your community</h3></div>
                <div class="cm-mini-stats">
                    <a class="cm-mini-stat" href="index.php?page=profile" data-page="profile">
                        <strong><?php echo (int)$viewerStats['posts']; ?></strong><span>Posts</span>
                    </a>
                    <a class="cm-mini-stat" href="index.php?page=profile#friends" data-page="profile">
                        <strong><?php echo (int)$viewerStats['friends']; ?></strong><span>Friends</span>
                    </a>
                    <a class="cm-mini-stat" href="index.php?page=products" data-page="products">
                        <strong><?php echo (int)$viewerStats['photos']; ?></strong><span>Photos</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </aside>

        <!-- Feed Center -->
        <div class="community-feed">
            <?php if ($isGuest): ?>
            <!-- Guest composer -->
            <section class="community-composer-card community-composer-card--guest">
                <div class="community-composer-top">
                    <img src="assets/avatars/default.png" alt="" onerror="this.src='assets/avatars/default.png'">
                    <a class="composer-trigger-btn" href="index.php?page=login">Sign in to share what's on your mind…</a>
                </div>
                <div class="community-composer-actions">
                    <a class="composer-action-btn" href="index.php?page=login"><i class="fas fa-image" style="color:#45bd62"></i> Photo</a>
                    <a class="composer-action-btn" href="index.php?page=login"><i class="fas fa-face-smile" style="color:#f7b928"></i> Feeling</a>
                </div>
            </section>
            <?php else: ?>
            <!-- Composer Card (trigger) -->
            <section class="community-composer-card" id="composerCard">
                <div class="community-composer-top">
                    <img src="assets/avatars/<?= htmlspecialchars($_SESSION['avatar'] ?? 'default.png') ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                    <button type="button" class="composer-trigger-btn" id="composerTriggerBtn">What's on your mind, <?= htmlspecialchars(explode(' ', trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Friend'))[0]) ?>?</button>
                </div>
                <div class="community-composer-actions">
                    <button type="button" class="composer-action-btn" id="composerTriggerPhoto"><i class="fas fa-image" style="color:#45bd62"></i> Photo</button>
                    <button type="button" class="composer-action-btn" id="composerTriggerFeeling"><i class="fas fa-face-smile" style="color:#f7b928"></i> Feeling</button>
                    <span class="composer-action-btn composer-privacy-chip" title="Default audience"><i class="fas fa-globe-americas" style="color:#1877f2"></i> <span id="composerPrivacyChipLabel">Public</span></span>
                </div>
            </section>

            <!-- Create Post Modal (Facebook-style) -->
            <div class="create-post-overlay" id="createPostOverlay">
                <div class="create-post-modal">
                    <div class="create-post-header">
                        <h2>Create Post</h2>
                        <button type="button" class="create-post-close" id="createPostClose"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="create-post-body">
                        <div class="create-post-user">
                            <img src="assets/avatars/<?= htmlspecialchars($_SESSION['avatar'] ?? 'default.png') ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                            <div>
                                <strong><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></strong>
                                <button type="button" class="create-post-privacy" id="createPostPrivacyLabel" title="Change who can see this">
                                    <i class="fas fa-globe-americas"></i> Public <i class="fas fa-caret-down" style="font-size:10px;opacity:.7"></i>
                                </button>
                            </div>
                        </div>
                        <textarea class="create-post-textarea" id="createPostTextarea" placeholder="What's on your mind, <?= htmlspecialchars(explode(' ', trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Friend'))[0]) ?>?" maxlength="5000"></textarea>
                        <div class="create-post-photo-preview" id="createPostPhotoPreview" style="display:none">
                            <img id="createPostPhotoImg" src="" alt="">
                            <button type="button" class="create-post-photo-remove" id="createPostPhotoRemove"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="create-post-feeling-bar" id="createPostFeelingBar" style="display:none">
                            <i class="fas fa-face-smile"></i> Feeling <strong id="createPostFeelingText"></strong>
                            <button type="button" class="create-post-feeling-clear" id="createPostFeelingClear"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div class="create-post-footer">
                        <div class="create-post-footer-left">
                            <span class="create-post-footer-label">Add to your post</span>
                            <div class="create-post-footer-icons">
                                <button type="button" class="create-post-icon-btn" id="createPostPhotoBtn" title="Photo"><i class="fas fa-image" style="color:#45bd62"></i></button>
                                <button type="button" class="create-post-icon-btn" id="createPostFeelingBtn" title="Feeling"><i class="fas fa-face-smile" style="color:#f7b928"></i></button>
                                <button type="button" class="create-post-icon-btn" id="createPostPrivacyBtn" title="Privacy"><i class="fas fa-earth-americas" style="color:#1877f2"></i></button>
                            </div>
                        </div>
                        <button type="button" class="create-post-submit" id="createPostSubmit" disabled>Post</button>
                    </div>
                    <!-- Feeling picker (in modal) -->
                    <div class="create-post-feeling-picker" id="createPostFeelingPicker">
                        <div class="feeling-picker-header">How are you feeling?</div>
                        <div class="feeling-picker-grid">
                            <button class="feeling-option" data-feeling="Happy"><span class="feeling-emoji">😊</span><span class="feeling-label">Happy</span></button>
                            <button class="feeling-option" data-feeling="Sad"><span class="feeling-emoji">😢</span><span class="feeling-label">Sad</span></button>
                            <button class="feeling-option" data-feeling="Excited"><span class="feeling-emoji">🎉</span><span class="feeling-label">Excited</span></button>
                            <button class="feeling-option" data-feeling="Loved"><span class="feeling-emoji">❤️</span><span class="feeling-label">Loved</span></button>
                            <button class="feeling-option" data-feeling="Grateful"><span class="feeling-emoji">🙏</span><span class="feeling-label">Grateful</span></button>
                            <button class="feeling-option" data-feeling="Blessed"><span class="feeling-emoji">✨</span><span class="feeling-label">Blessed</span></button>
                            <button class="feeling-option" data-feeling="Angry"><span class="feeling-emoji">😡</span><span class="feeling-label">Angry</span></button>
                            <button class="feeling-option" data-feeling="Silly"><span class="feeling-emoji">🤪</span><span class="feeling-label">Silly</span></button>
                            <button class="feeling-option" data-feeling="Tired"><span class="feeling-emoji">😴</span><span class="feeling-label">Tired</span></button>
                            <button class="feeling-option" data-feeling="Cool"><span class="feeling-emoji">😎</span><span class="feeling-label">Cool</span></button>
                        </div>
                    </div>
                    <!-- Privacy picker (in modal) -->
                    <div class="create-post-privacy-picker" id="createPostPrivacyPicker">
                        <div class="feeling-picker-header">Who can see this?</div>
                        <div class="privacy-option-grid">
                            <button class="privacy-option is-active" data-privacy="public">
                                <span class="privacy-option-icon"><i class="fas fa-globe-americas"></i></span>
                                <span class="privacy-option-text"><strong>Public</strong><small>Anyone on DreamBD</small></span>
                                <i class="fas fa-check privacy-option-check"></i>
                            </button>
                            <button class="privacy-option" data-privacy="friends">
                                <span class="privacy-option-icon"><i class="fas fa-user-group"></i></span>
                                <span class="privacy-option-text"><strong>Friends</strong><small>Only your friends</small></span>
                                <i class="fas fa-check privacy-option-check"></i>
                            </button>
                            <button class="privacy-option" data-privacy="private">
                                <span class="privacy-option-icon"><i class="fas fa-lock"></i></span>
                                <span class="privacy-option-text"><strong>Only me</strong><small>Just for you</small></span>
                                <i class="fas fa-check privacy-option-check"></i>
                            </button>
                        </div>
                    </div>
                    <input type="file" id="createPostPhotoInput" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                </div>
            </div>
            <?php endif; ?>

            <?php include __DIR__ . '/../includes/post-modals.php'; ?>

            <!-- Feed toolbar -->
            <div class="cm-feed-toolbar">
                <div class="cm-feed-toolbar-top">
                    <h2 class="cm-feed-title"><i class="fas fa-stream"></i> Community feed</h2>
                    <span class="cm-feed-count" id="cmFeedCount"><?php echo count($communityPosts); ?> <?php echo count($communityPosts) === 1 ? 'post' : 'posts'; ?></span>
                </div>
                <div class="cm-feed-controls">
                    <div class="cm-feed-search">
                        <i class="fas fa-search"></i>
                        <input type="search" id="cmFeedSearch" placeholder="Search in feed…" autocomplete="off">
                    </div>
                    <label class="cm-feed-sort">
                        <i class="fas fa-arrow-down-wide-short"></i>
                        <select id="cmFeedSort" aria-label="Sort posts">
                            <option value="newest">Newest first</option>
                            <option value="top">Most reactions</option>
                            <option value="discussed">Most discussed</option>
                        </select>
                    </label>
                </div>
                <div class="cm-feed-chips" id="cmFeedChips">
                    <button type="button" class="cm-chip is-active" data-filter="all"><i class="fas fa-globe"></i> All</button>
                    <button type="button" class="cm-chip" data-filter="trending"><i class="fas fa-fire"></i> Trending</button>
                    <button type="button" class="cm-chip" data-filter="friends"><i class="fas fa-user-group"></i> Friends</button>
                    <button type="button" class="cm-chip" data-filter="mine"><i class="fas fa-user"></i> My posts</button>
                </div>
            </div>

            <div class="cm-feed-empty" id="cmFeedEmpty" style="display:none">
                <div class="cm-feed-empty-icon"><i class="fas fa-magnifying-glass"></i></div>
                <h3>No posts found</h3>
                <p>Nothing matches this view yet — try a different filter or start the conversation yourself.</p>
                <button type="button" class="cm-feed-empty-btn" id="cmFeedReset"><i class="fas fa-rotate-left"></i> Reset filters</button>
            </div>

            <!-- Feed Posts -->
            <div class="cm-feed-posts" id="cmFeedPosts">
            <?php foreach ($communityPosts as $post):
                $postAuthorId = (int) $post['user_id'];
                $isOwnPost = $viewerId && $postAuthorId === (int) $viewerId;
                $isFriendAuthor = isset($friendIdSet[$postAuthorId]);
                $pLikes = (int) ($post['like_count'] ?? 0);
                $pComments = (int) ($post['comment_count'] ?? 0);
                $pShares = (int) ($post['share_count'] ?? 0);
                $pTs = strtotime($post['created_at'] ?? 'now') ?: time();
                $pPrivacy = $post['privacy'] ?? 'public';
                $pPrivMeta = $privacyMeta[$pPrivacy] ?? $privacyMeta['public'];
                $pSearchText = trim(preg_replace('/\s+/', ' ', strip_tags(($post['full_name'] ?: $post['username']) . ' ' . ($post['content'] ?? ''))));
            ?>
            <article class="community-post-card"
                data-post-id="<?= (int) $post['id'] ?>"
                data-user-id="<?= $postAuthorId ?>"
                <?= $isOwnPost ? 'data-mine="1"' : '' ?>
                <?= $isFriendAuthor ? 'data-friend="1"' : '' ?>
                data-likes="<?= $pLikes ?>"
                data-comments="<?= $pComments ?>"
                data-shares="<?= $pShares ?>"
                data-ts="<?= $pTs ?>"
                data-search="<?= htmlspecialchars($pSearchText, ENT_QUOTES) ?>">
                <div class="community-post-header">
                    <div class="community-post-author">
                        <a href="index.php?page=profile&user=<?= $postAuthorId ?>" data-no-ajax class="community-author-avatar-link">
                            <img src="assets/avatars/<?= htmlspecialchars($post['avatar'] ?? 'default.png') ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                        </a>
                        <div class="community-author-info">
                            <strong><a href="index.php?page=profile&user=<?= $postAuthorId ?>" data-no-ajax><?= htmlspecialchars($post['full_name'] ?: $post['username']) ?></a></strong>
                            <span class="community-post-time">
                                <?= formatTimeAgo($post['created_at']) ?>
                                <?php if ($pPrivacy !== 'public'): ?>
                                <span class="cm-privacy-badge" title="<?php echo $pPrivMeta['label']; ?>"><i class="fas <?php echo $pPrivMeta['icon']; ?>"></i></span>
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($post['feeling'])): ?>
                            <span class="community-post-feeling">feeling <strong><?= htmlspecialchars($post['feeling']) ?></strong></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="post-menu-container">
                        <button type="button" class="post-menu-trigger" title="More options"><i class="fas fa-ellipsis-h"></i></button>
                        <div class="post-dropdown">
                            <?php if ($isOwnPost): ?>
                            <button class="post-dropdown-item" data-action="edit" data-post-id="<?= (int)$post['id'] ?>"><i class="fas fa-pen"></i> Edit post</button>
                            <button class="post-dropdown-item danger" data-action="delete" data-post-id="<?= (int)$post['id'] ?>"><i class="fas fa-trash-alt"></i> Delete post</button>
                            <?php else: ?>
                            <button class="post-dropdown-item" data-action="save" data-post-id="<?= (int)$post['id'] ?>"><i class="fas fa-bookmark"></i> Save post</button>
                            <div class="post-dropdown-divider"></div>
                            <button class="post-dropdown-item danger" data-action="report" data-post-id="<?= (int)$post['id'] ?>"><i class="fas fa-flag"></i> Report post</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="community-post-content">
                    <p><?= nl2br(htmlspecialchars($post['content'])) ?></p>
                </div>
                <?php if (!empty($post['image_path'])): ?>
                    <div class="community-post-image">
                        <img src="assets/posts/<?= htmlspecialchars($post['image_path']) ?>" alt="" loading="lazy">
                    </div>
                <?php endif; ?>

                <div class="community-post-stats">
                    <div class="community-stat-left" title="View reactions">
                        <div class="community-reaction-icons">
                            <?= renderReactionSummaryHtml($post['reaction_summary'] ?? [], $pLikes) ?>
                        </div>
                    </div>
                    <div class="community-stat-right">
                        <span class="community-comment-trigger" data-post-id="<?= (int)$post['id'] ?>"><?= $pComments ?> comments</span> •
                        <span><?= $pShares ?> shares</span>
                    </div>
                </div>

                <div class="community-post-actions">
                    <div class="community-btn-action-wrap">
                        <button class="community-btn-action <?= ($post['viewer_reaction'] ?? null) ? 'active' : '' ?>" data-action="like" data-reaction="<?= htmlspecialchars($post['viewer_reaction'] ?? '') ?>">
                            <?php if ($post['viewer_reaction']): ?>
                                <span style="margin-right:8px"><?= getEmoji($post['viewer_reaction']) ?></span> <?= ucfirst($post['viewer_reaction']) ?>
                            <?php else: ?>
                                <i class="far fa-thumbs-up"></i> Like
                            <?php endif; ?>
                        </button>
                        <div class="community-reaction-strip">
                            <?php foreach ($communityReactionOptions as $rxn): ?>
                                <button class="community-reaction-btn" data-reaction="<?= htmlspecialchars($rxn['type']) ?>" title="<?= htmlspecialchars($rxn['label']) ?>"><?= $rxn['emoji'] ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="community-btn-action-wrap">
                        <button class="community-btn-action community-comment-trigger" data-action="comment" data-post-id="<?= (int)$post['id'] ?>"><i class="far fa-comment"></i> Comment</button>
                    </div>
                    <div class="community-btn-action-wrap">
                        <button class="community-btn-action" data-action="share"><i class="far fa-share-square"></i> Share</button>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
            </div>

            <?php if (empty($communityPosts)): ?>
            <div class="cm-feed-empty" id="cmFeedEmptyInitial">
                <div class="cm-feed-empty-icon"><i class="fas fa-comments"></i></div>
                <h3>The feed is quiet</h3>
                <p>Be the first to start a conversation with the community.</p>
                <?php if (!$isGuest): ?>
                <button type="button" class="cm-feed-empty-btn" id="cmFeedEmptyCreate"><i class="fas fa-pen-to-square"></i> Create the first post</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Sidebar -->
        <aside class="community-right-sidebar">
            <?php if (!$isGuest): ?>
            <div class="community-panel" id="cmFriendRequestsPanel">
                <div class="community-side-heading">
                    <h3>Friend requests<?php if ($friendRequestCount > 0): ?><span class="cm-heading-badge"><?php echo $friendRequestCount; ?></span><?php endif; ?></h3>
                    <a href="index.php?page=profile#friends" data-page="profile">See all</a>
                </div>
                <div class="community-friend-list">
                    <?php if (empty($friendRequests)): ?>
                        <p class="cm-panel-empty">No new requests</p>
                    <?php endif; ?>
                    <?php foreach ($friendRequests as $request): ?>
                    <article class="community-request-card" data-fr-card data-user-id="<?= (int)$request['user_id'] ?>">
                        <a href="index.php?page=profile&user=<?= (int)$request['user_id'] ?>" data-no-ajax>
                            <img src="assets/avatars/<?php echo htmlspecialchars($request['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                        </a>
                        <div class="community-request-info">
                            <strong><?php echo htmlspecialchars($request['full_name'] ?: $request['username']); ?></strong>
                            <div class="community-request-actions">
                                <button type="button" class="btn-sm btn-primary" data-fr-action="accept"><i class="fas fa-check"></i> Confirm</button>
                                <button type="button" class="btn-sm btn-outline" data-fr-action="reject"><i class="fas fa-times"></i> Delete</button>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($communitySuggestions)): ?>
            <div class="community-panel">
                <div class="community-side-heading">
                    <h3>People you may know</h3>
                </div>
                <div class="community-friend-list" id="cmSuggestList">
                    <?php foreach ($communitySuggestions as $sg): ?>
                    <article class="community-request-card cm-suggest-card" data-sg-card data-user-id="<?= (int)$sg['id'] ?>">
                        <a href="index.php?page=profile&user=<?= (int)$sg['id'] ?>" data-no-ajax>
                            <img src="assets/avatars/<?php echo htmlspecialchars($sg['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                        </a>
                        <div class="community-request-info">
                            <strong><?php echo htmlspecialchars($sg['full_name'] ?: $sg['username']); ?></strong>
                            <span class="cm-suggest-sub">
                                <?php if ((int)($sg['mutual_count'] ?? 0) > 0): ?>
                                    <i class="fas fa-user-group"></i> <?php echo (int)$sg['mutual_count']; ?> mutual friend<?php echo (int)$sg['mutual_count'] > 1 ? 's' : ''; ?>
                                <?php elseif (!empty($sg['bio'])): ?>
                                    <?php echo htmlspecialchars(mb_substr($sg['bio'], 0, 46)); ?>…
                                <?php else: ?>
                                    Suggested for you
                                <?php endif; ?>
                            </span>
                            <div class="community-request-actions">
                                <button type="button" class="btn-sm btn-primary" data-sg-action="add"><i class="fas fa-user-plus"></i> Add friend</button>
                                <button type="button" class="btn-sm btn-outline" data-sg-action="dismiss" title="Remove"><i class="fas fa-xmark"></i></button>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($communityTopPlayers)): ?>
            <div class="community-panel cm-leader-panel">
                <div class="community-side-heading">
                    <h3><i class="fas fa-crown" style="color:#f59e0b"></i> Top contributors</h3>
                </div>
                <div class="cm-leader-list">
                    <?php foreach ($communityTopPlayers as $rank => $tpRow): ?>
                    <a class="cm-leader-row" href="index.php?page=profile&user=<?php echo (int)$tpRow['id']; ?>" data-no-ajax>
                        <span class="cm-leader-rank rank-<?php echo min($rank + 1, 3); ?>"><?php echo $rank + 1; ?></span>
                        <img src="assets/avatars/<?php echo htmlspecialchars($tpRow['avatar'] ?? 'default.png'); ?>" alt="" onerror="this.src='assets/avatars/default.png'">
                        <span class="cm-leader-info">
                            <strong><?php echo htmlspecialchars($tpRow['full_name'] ?: $tpRow['username']); ?></strong>
                            <small><?php echo (int)$tpRow['post_count']; ?> posts · <?php echo (int)$tpRow['reaction_count']; ?> reactions</small>
                        </span>
                        <span class="cm-leader-score"><?php echo (int)$tpRow['leaderboard_score']; ?><small> pts</small></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$isGuest): ?>
            <div class="community-panel">
                <div class="community-side-heading"><h3><i class="fas fa-circle" style="color:#22c55e;font-size:9px"></i> Contacts</h3></div>
                <div class="community-friend-list" id="community-contacts-list">
                    <!-- Loaded via JS -->
                    <div class="cm-panel-loading">Loading contacts…</div>
                </div>
            </div>
            <?php endif; ?>
        </aside>

    </section>
</div>
