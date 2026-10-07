<?php
$db = Database::getInstance()->getConnection();

$settings = [];
try {
    $stmt = $db->query("SELECT `key`, `value` FROM site_settings WHERE `key` LIKE 'payment_%'");
    while ($row = $stmt->fetch()) $settings[$row['key']] = $row['value'];
} catch (Throwable $e) {}

$methods = [
    'bkash'  => ['label' => 'bKash',  'icon' => 'fa-wallet',    'color' => '#e2136e'],
    'nagad'  => ['label' => 'Nagad',  'icon' => 'fa-money-bill', 'color' => '#f6921e'],
    'rocket' => ['label' => 'Rocket', 'icon' => 'fa-rocket',     'color' => '#8c3494'],
];

$supportEmail = 'iam.robi693@gmail.com';
$hasMessages = !empty($_SESSION['user_id']);
?>
<section class="info-page">
    <div class="info-hero-card">
        <span class="info-kicker">Support</span>
        <h1>Contact &amp; Help</h1>
        <p>Questions about an order, a top-up, or your account? Reach us through the options below and we will get back to you as soon as possible.</p>
    </div>

    <div class="info-grid">
        <article class="info-card">
            <div class="info-card-icon"><i class="fas fa-envelope"></i></div>
            <h2>Email support</h2>
            <p>Best for account issues, payment problems, refunds, and report requests.</p>
            <a class="info-card-link" href="mailto:<?php echo htmlspecialchars($supportEmail); ?>"><?php echo htmlspecialchars($supportEmail); ?></a>
        </article>

        <article class="info-card">
            <div class="info-card-icon"><i class="fas fa-comments"></i></div>
            <h2>Messages</h2>
            <p>Open a conversation directly inside DreamBD for quick questions about a purchase or trade.</p>
            <?php if ($hasMessages): ?>
                <a class="info-card-link" href="index.php?page=messages" data-page="messages">Open Messages</a>
            <?php else: ?>
                <a class="info-card-link" href="index.php?page=login" data-page="login">Log in to message us</a>
            <?php endif; ?>
        </article>

        <article class="info-card">
            <div class="info-card-icon"><i class="fas fa-question-circle"></i></div>
            <h2>FAQ &amp; Rules</h2>
            <p>Most common questions about accounts, posts, privacy, buying, and selling are already answered.</p>
            <a class="info-card-link" href="index.php?page=faq" data-page="faq">Read the FAQ</a>
        </article>

        <article class="info-card">
            <div class="info-card-icon"><i class="fas fa-receipt"></i></div>
            <h2>Payments &amp; top-ups</h2>
            <p>Send payment using one of the official numbers below, then keep the transaction ID for your order.</p>
            <div class="contact-pay-list">
                <?php foreach ($methods as $key => $m): ?>
                    <?php $num = $settings["payment_{$key}_number"] ?? ''; if (!$num) continue; ?>
                    <div class="contact-pay-row">
                        <span class="contact-pay-label" style="color:<?php echo $m['color']; ?>"><i class="fas <?php echo $m['icon']; ?>"></i> <?php echo $m['label']; ?></span>
                        <span class="contact-pay-num"><?php echo htmlspecialchars($num); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="info-card">
            <div class="info-card-icon"><i class="fas fa-clock"></i></div>
            <h2>Response time</h2>
            <p>We usually reply within 24 hours. Order and top-up issues are handled first, so include your order number whenever you can.</p>
        </article>

        <article class="info-card">
            <div class="info-card-icon"><i class="fas fa-flag"></i></div>
            <h2>Report a problem</h2>
            <p>Found a scam, a fake listing, or abusive content? Report it from the post or profile, or email us with a link.</p>
        </article>
    </div>
</section>
