# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary: **Bangladeshi creators & sellers** — people running small shops and trades inside a social platform, whose main job is listing and selling products (seller upload, cart, P2P trades) while staying connected to their community.

Open decision: tournament players and community-only users exist in the product but were not confirmed as primary audiences.

## Product Purpose

DreamBD is an all-in-one social home: community feed, real-time messaging, a marketplace with wallet/topup, and real-money esports tournaments in one platform, so creators and sellers can sell, connect, and compete without leaving the app. Success means users completing those jobs entirely inside DreamBD (no numeric success metric confirmed yet).

## Positioning

One platform instead of several: social + shop + escrow-backed real-money tournaments under a single BD account with one ৳ wallet and agent-mediated payments. A neighboring product could copy any single surface, but not the integrated all-in-one home for BD users.

## Operating Context

- Bangladesh market; mobile browsers on low-end Android over mobile data are the baseline environment.
- Money moves in ৳ through agent-mediated topup (agent dashboard, result submission) with admin oversight; escrow holds tournament entry and prize funds until check-in/refund flows resolve.
- Real-time is part of the experience: SSE-driven presence, LIVE badges, live tournament counters and standings.
- Three roles with distinct workflows: member, agent, admin (`admin.php`).
- Support surfaces ship with the product: rules, FAQ, contact, how-it-works.

## Capabilities and Constraints

Capabilities (confirmed in code):

- Social: community feed, profiles, friends, search, realtime messages, notifications.
- Marketplace: product listing / seller upload, cart, P2P trades.
- Wallet: balance, topup, agent dashboard with result submission, admin escrow.
- Tournaments: multiple formats with brackets, team registration, check-in, refunds, live standings, tournament room, leaderboard.
- Platform: auth (register / verify / login / reset), admin panel.

Constraints confirmed for future work to preserve:

- Mobile-first; low-end Android and mobile data are the baseline, not the edge case.
- Bilingual Bangla + English copy throughout.
- BD payment rails: ৳ amounts, bKash/Nagad-style topup via agents.
- Real-time live feel (LIVE badges, presence, live counters) must be maintained.
- Voice: youth-oriented, casual, competitive.
- Technical: plain PHP (no framework), server-rendered, MySQL, front-controller routing (`index.php?page=...`), XAMPP locally.

Open decisions recorded, not invented: primary-audience scope for tournament/community-only users; numeric success metrics.

## Brand Commitments

- Name: **DreamBD**; descriptor: "Social home" (logo lockup, nav, footer).
- Voice: youth, casual, competitive — production copy includes "Compete. Conquer. Rise Up." and "Join tournaments, build your team, and battle for glory and prizes."
- Logo: cloud-style DreamBD mark used in header and footer.

## Evidence on Hand

- Full runnable codebase: 25 surfaces under `pages/`, styles/scripts under `assets/`, database `dream`.
- Live DB content is test-only: two sample tournaments, empty leaderboard. No real user content yet.
- No testimonials, press, case studies, or benchmarks exist; future work must not fabricate them.

## Product Principles

1. One home, not many apps — social, shop, and arena stay integrated.
2. Low-end mobile first — performance and small screens before desktop polish.
3. Bangla and English are both first-class.
4. Money must feel live and trustworthy — ৳ rails, escrow, real-time state.
5. Voice stays young, casual, competitive.

## Accessibility & Inclusion

- Bangla script must render legibly alongside English (bilingual requirement).
- Low-end devices and variable connectivity make performance an inclusion issue.
