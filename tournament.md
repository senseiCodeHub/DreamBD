# Tournament Flow

Complete, testable map of every tournament-related feature: who can do what, in what
order, what the system does to the database, and how ৳ moves. Written against the code
in this repo — every rule below is enforced somewhere named in
[Feature → code index](#feature--code-index).

Surfaces involved:

| Surface | File | Role |
|---|---|---|
| Tournaments page (list, teams, clubs, player market, leaderboard) | `pages/tournaments.php` | server-rendered UI + modals |
| Tournament room (bracket, chat, room card, results) | `pages/tournament-room.php` | live room for one tournament |
| Single tournament view / SSE stream | `pages/tournament.php`, `handlers/tournament_stream.php` | live state |
| AJAX API | `handlers/tournament_handler.php` | every mutation goes through here |
| Domain logic | `includes/functions.php`, `includes/tournament_flow.php`, `includes/tournament_bracket.php` | escrow, brackets, teams, clubs, market |
| Schema bootstrap | `database/config.php` | idempotent migrations + player-card backfill |

Entry point for everything below: `index.php?page=tournaments`.

---

## Roles & permissions

| Capability | Guest | Member | Team captain | Club owner / manager | Tournament agent |
|---|:--:|:--:|:--:|:--:|:--:|
| Browse tournaments, market, leaderboard, clubs | ✅ | ✅ | ✅ | ✅ | ✅ |
| Join a tournament (solo) | ❌ | ✅ | ✅ | ✅ | ✅ |
| Register a **team** for a tournament | ❌ | ❌ | ✅ own team | ❌ | ✅ own team |
| Create / add members / delete a team | ❌ | ✅ own | ✅ own | ✅ own | ✅ own |
| Create / join / leave a club | ❌ | ✅ | ✅ | ✅ | ✅ |
| Edit club, hire player into club, fire member | ❌ | ❌ | ❌ | ✅ | ❌ |
| Create tournament (needs `role = agent`) | ❌ | ❌ | ❌ | ❌ | ✅ |
| Check in, report score, confirm, dispute | ❌ | ✅ if registered | ✅ for own team | ❌ | ✅ (as participant) |
| Generate bracket, resolve match, update status, cancel, submit results | ❌ | ❌ | ❌ | ❌ | ✅ owner agent only |
| Set own asking price / release own card | ❌ | ✅ | ✅ | ✅ | ✅ |
| List a card for auction, bid, buy a free agent | ❌ | ✅ (owner / buyer) | ✅ | ✅ | ✅ |
| Admin (`admin.php`) | — | — | — | — | outside this flow |

Room access (`userCanAccessTournamentRoom`): the owning agent, a confirmed solo
participant, or a member of a confirmed participating team. Everyone else sees
"You do not have access to this tournament room."

---

## A. Tournament lifecycle

### A1. Agent creates a tournament

1. **UI** — Tournaments → *New tournament* (`createTournamentForm`) → handler `agent_create_tournament`.
2. `createAgentAccount` must already have set `users.role = 'agent'` (Become Agent flow).
3. `createTournamentByAgent()`:
   - checks `role = 'agent'` under a row lock and that `balance ≥ prize_money`;
   - debits the prize from the agent's balance and credits `tournaments.prize_escrow`;
   - writes an `agent_transactions` debit row (`tournament_prize`);
   - inserts the tournament with `status = 'upcoming'`, `fee_escrow = 0`,
     `prize_money`, `entry_fee`, `max_teams`, `starts_at`, `checkin_minutes` (5–180),
     `bracket_type`, `best_of` (1–9).
4. Failure ⇒ no tournament row, no debit (single transaction).

### A2. Players register

**UI** — *Join* button on a tournament card → `joinTournamentModal` → `register`.

| Choice in the modal | Payload | Function |
|---|---|---|
| Solo player (+ optional team name) | `team_id` stripped by JS | `registerForTournament()` |
| With team (only shown if you have teams) | `team_id` = selected team | `joinTournamentWithTeam()` |

Rules enforced for both paths:

- Tournament must be `upcoming` or `live`, otherwise "Registration closed."
- **One registration per user per tournament** (DB unique key `uniq_tn_user` **and** an
  explicit check), and one registration per team (`uniq_tn_team`).
- `max_teams` counts confirmed registrations; exceeding ⇒ "Tournament is full."
- `entry_fee > 0` ⇒ balance is charged under a row lock; insufficient balance ⇒ reject
  with no partial write.
- Money: `player.balance -= fee` → `tournaments.fee_escrow += fee`, plus a
  `transactions` row with `type = 'entry_fee'`, `purpose = 'tournament_entry'`.
- Team registration charges the **captain** and links `tournament_participants.team_id`.
- Rejoining a cancelled registration reactivates it and charges the fee again.

**Leave** — `unregister` → `unregisterFromTournament()`:
allowed only **before the bracket exists**; refunds the fee from `fee_escrow` back to the
player (ledger row `type = 'refund'`) and sets `status = 'cancelled'`.

### A3. Check-in

**UI** — *Check in* on the tournament / room. Handler `check_in` → `tnCheckin()`.

- Window: opens at `starts_at − checkin_minutes`, closes at `starts_at`
  (`tnCheckinState()` → `upcoming` / `open` / `closed`).
- `tnCheckin()` is idempotent and only flips `checked_in = 1` for a `confirmed`
  registration; it works before the window opens (safe — the flag is only used by the
  deadline sweep below).

### A4. Deadline sweep (no cron)

`enforceTournamentDeadlines()` runs on every handler entry, on `page=tournament`,
`page=tournament-room`, and in the SSE stream. For every `upcoming`/`live` tournament
whose `starts_at` has passed **and that has no bracket yet**:

- every `confirmed` participant with `checked_in = 0` is refunded the entry fee from
  `fee_escrow` (balance credited **only when the escrow refund succeeded**, ledger row
  `purpose = 'tournament_checkin'`), the registration becomes `cancelled`, and the player
  is notified.
- Checked-in participants are untouched. The sweep is idempotent.

### A5. Bracket generation

**UI** — agent action *Generate bracket* → `generate_bracket` → `bracketBuild()`.

- Entrants = confirmed participants (solo ⇒ user id, team ⇒ team id).
- Minimums: single elimination ≥ 2, double elimination ≥ 4, round robin ≥ 3.
- Single: standard seed order `[1,8,4,5,2,7,3,6]`; double: winners bracket + losers
  bracket + grand final (bracket reset supported); round robin: group stage, every pair once.
- On success `status` flips `upcoming → live`; regenerating is rejected.
- From this moment the roster is locked: `unregister` refuses with
  "The bracket has started — leaving is disabled."

### A6. Playing matches

| Step | Who | Action | Effect |
|---|---|---|---|
| Report | any side in the match (team member for a team slot) | `report_score` | stores `rep1/rep2`, `report_state = reported`, notifies the opponent |
| Confirm | the opponent only | `confirm_score` | resolves the match, cascades winner/loser slots |
| Dispute | the opponent | `dispute_score` | `report_state = disputed` + note, notifies the agent |
| Manual resolve | owning agent | `advance_winner` | sets scores + winner, resolves disputes |
| Walkover | system | `bracketResolveWalkovers()` | unfinished slots auto-resolve |

Rules: no draws (`A match cannot end in a draw`), the reporter cannot confirm their own
report, a completed match cannot be re-resolved, and only the owning agent may advance.
After every resolution `bracketFinalize()` runs: money-free tournaments complete
immediately; tournaments with escrow pending send the agent a one-time
"submit results to settle prizes" notification.

### A7. Results & prize settlement

**UI** — tournament room → *Results* form → `submit_tournament_results`.

- Only the owning agent, one shot per tournament (`escrow_released` guard).
- **Prize cap**: sum of `prize_amount` must be ≤ `fee_escrow + prize_escrow`, otherwise
  rejected with the exact shortfall.
- Rows are saved to `tournament_results` (scope `team` or `player`, placement, points,
  label, prize, notes), `status → completed`.
- Each prize is paid from escrow (`tnEscrowPayPrize`: prize pool first, then entry fees)
  to the credited player (team results credit the captain) with a
  `transactions` row `purpose = 'tournament_prize'` and a `prize_credited` notification.
- `updateTournamentLeaderboard()` writes/updates `tournament_leaderboard`
  (points, prize, tournaments played, best rank).
- Leftover escrow (unspent fees + unspent prize) is released to the agent in one atomic
  claim (`tnEscrowReleaseToAgent`) with an `agent_transactions` credit.

### A8. Cancellation & refunds

**UI** — agent action *Cancel* → `cancel_tournament` → `cancelTournament()` (owner only,
never for `completed` tournaments):

- every confirmed participant with `fee_paid` gets the entry fee back from `fee_escrow`
  (ledger + notification);
- remaining escrow (prize pool) is released to the agent;
- all registrations → `cancelled`, tournament → `cancelled`;
- a second cancel attempt is rejected.

---

## B. Teams

1. **Create** — `create_team` → `createTeam()`; the creator becomes `captain` in
   `team_members` (`role`: captain / co-captain / member).
2. **Members** — Team Manage modal → `get_team_members`, `add_member`, `remove_member`:
   - only the **captain** can add;
   - only **captain or co-captain** can remove;
   - duplicate members are rejected.
3. **Use the team** — join a tournament with it (see A2), see it in the tournament's team
   list (`getTournamentTeams`), and the whole team gains room access.
4. **Delete** — `delete_team` (**captain only**) removes membership rows, the team's own
   participant/match/result rows (scoped to `team1_kind/team2_kind = 'team'` so a team id
   that collides with somebody's solo user id cannot wipe their match), and the team.

---

## C. Clubs

1. **Create** — `create_club` → `createClub()` (tag 2–10 chars, unique); the creator is
   added as `owner`.
2. **Join / leave** — `join_club` (idempotent, rejects duplicates), `leave_club`
   (the owner cannot leave: "Transfer ownership first").
3. **Edit** — `update_club` (owner only) for name, tag, colour, description, region, logo.
4. **Members** — `get_club_members` renders owner → manager → player → sub; the club
   detail view gives owner/manager a **Remove** button (`fire_player` by player user id).
5. **Standings** — `get_club_standings` sums member leaderboard points + trophies.
6. **Hiring** — see D5.

---

## D. Player Market & Auction

Surface: `?page=tournaments#hire` → **Player Market** with three tabs:
**Free Agents**, **Live Auctions**, **My Players**.

Every user has a player card (`players` row, `user_id` unique). Existing accounts were
backfilled once (`site_settings.player_market_seeded`) and new accounts get one lazily
through `ensurePlayerProfile()`. A card is either:

- `free_agent` + `owner_id NULL` — for sale at its asking price, or
- `active` + `owner_id = someone` — owned (and possibly listed on auction).

### D1. Set an asking price (how a player becomes buyable)

| | |
|---|---|
| **Who** | the player themself, or the current owner (`setPlayerMarketValue()` otherwise returns *"You cannot price this player."*) |
| **UI** | My Players → **Value** → `#valueModal` → `set_player_value` |
| **Effect** | `players.market_value` updated (clamped 0–10,000,000) |
| **Used by** | the Buy button on free-agent cards and the player detail page |

### D2. Adding a player to an auction (who lists)

| | |
|---|---|
| **Who** | the **owner** of the card only (`players.owner_id === user`) — a player cannot auction their own card while somebody else owns it, and a non-owner gets *"Not your player."* |
| **UI** | My Players → **Auction** → `#auctionModal` (base price + duration 6/12/24/48h) → `list_player_auction` |
| **Requires** | `base_price > 0`, **no other active auction** for that card, an existing card |
| **Effect** | `player_auctions` row (`status = active`, `current_price = base_price`, `end_time = now + duration`), `players.status → active`, `players.base_price = price` |
| **Blocked** | while listed the card cannot be released, hired, or bought (see D5/D6) |

### D3. Bidding (who can bid)

| Rule | Enforced by |
|---|---|
| Must be logged in | handler (`Please log in.`) |
| Auction must be active and not expired | `placeBid()` |
| **The seller cannot bid on their own auction** (no shill bidding) | `placeBid()` → *"You cannot bid on your own auction."* |
| Amount ≥ `current_price + min_increment` (increment default ৳50) | `placeBid()` → *"Bid too low. Min: ৳…"* |
| Bidder's balance must cover the amount | `placeBid()` → *"Insufficient balance."* |
| **Nothing is charged at bid time** — the money moves only at settlement | `transactions` written in D4 |

Every bid updates `current_price` and appends to `auction_bids`; the Live Auctions tab
shows current bid, base price, total bids and time left. The card also shows an
`ON AUCTION` badge in My Players.

### D4. Settlement (who actually buys)

Settlement is lazy — `settleExpiredAuctions()` runs on page load of the tournaments page
and on every handler entry (no cron), selecting `status = active AND end_time <= NOW()`.

For each expired auction, inside one transaction:

1. Highest bid is taken (`ORDER BY amount DESC`).
2. The winner's balance is **locked and verified**:
   - if it cannot cover the price → auction becomes `cancelled`, the seller keeps the
     player and is notified (an outbid-but-broke bidder can never free the card);
3. otherwise the auction is claimed atomically (`status = active → completed`,
   `winner_id`, `final_price`) so a concurrent sweep cannot settle it twice;
4. money: winner `balance -= amount` (`transactions` `player_purchase` /
   `purpose = player_auction`), seller `balance += 95%` (`transactions` `player_sale`,
   the description states the 5% platform fee);
5. ownership: `players.owner_id = winner`, `status = active`, `base_price = 0`;
6. `player_transfers` row of type `auction` + `auction` notifications to both sides.

Auction with no bids settles as `cancelled`.

### D5. Buying a player directly (who buys)

| | |
|---|---|
| **Who can buy** | any logged-in user **except** the card's user and its current owner (*"You cannot buy your own player."*) |
| **What can be bought** | only `status = free_agent` cards (a listed/auctioned card is `active`, so it cannot be double-sold) at its `market_value` (`price > 0`) |
| **UI** | Free Agents → **Buy**, or player detail → *Buy* → `#buyModal` → `buy_player` |
| **Effect** | buyer `balance -= price` (+ ledger `player_purchase`), previous owner credited when there was one (+ ledger `player_sale`), `owner_id = buyer`, `status = active`, `current_club_id = NULL`, `player_transfers` row `direct_sale`, `transfer` notifications to both |

> Open decision: when a card has **no previous owner** the buyer is debited and no user
> receives the money (it leaves the user economy like the 5% auction fee). It is ledgered
> but does not land in a platform account — that account does not exist yet.

### D6. Squad management (My Players tab)

| Action | Who | Rule |
|---|---|---|
| **Value** | card's player or owner | sets the asking price (D1) |
| **Auction** | owner only, and only when the card is **not** already listed | D2 |
| **Hire to CLUb** | club `owner`/`manager` only, and only when the card is **not** listed | adds the player's user to `club_members` as `player`, sets `current_club_id`, `player_transfers` type `hire` |
| **Release** | owner only, and only when the card is **not** listed | `owner_id = NULL`, `status = free_agent`, `player_transfers` type `release` |
| **Fire** (club view) | club `owner`/`manager` | removes the club membership, clears `current_club_id` |

The My Players tab lists **both** cards you own and your own card (so a free agent can
price themself). Free Agents excludes your own card — you manage it in My Players.

---

## E. Money rules (single source of truth)

Escrow model (`includes/tournament_flow.php`, ADR-006):

```
register    player.balance -= fee   → tournaments.fee_escrow  += fee
refund      tournaments.fee_escrow -= fee  → player.balance += fee   (always solvent)
prize       prize_escrow first, then fee_escrow  → winner.balance
cancel      all confirmed fees refunded from fee_escrow; prize_escrow → agent
complete    leftover (fee_escrow + prize_escrow) → agent balance + agent_transactions
```

Invariants the tests assert:

- A tournament never refunds money it does not hold (escrow-first, legacy fallback
  debits the agent only for pre-v2 rows).
- No path writes a `refund`/`prize` ledger row without the matching balance change.
- Platform sinks are exactly: agent activation fee, 5% auction fee, direct-buy money for
  unowned cards — everything else is conserved.

Other money surfaces on this page: agent activation fee (`become_agent`), agent funding
(`add_funds` / bKash OTP flow) — all ledgered in `transactions` / `agent_transactions`.

---

## F. Background jobs (no cron)

| Job | Trigger points |
|---|---|
| `enforceTournamentDeadlines()` | every handler request; `page=tournament`; `page=tournament-room`; SSE stream |
| `settleExpiredAuctions()` | every handler request; tournaments page load |
| `bracketFinalize()` | after every match resolution |

All three are idempotent (guarded by state transitions / atomic claims).

---

## G. Notifications produced

| Type | When | Lands on |
|---|---|---|
| `tournament` | registration, team registration, score reported, dispute, nudge to submit results | player / opponent / agent |
| `refund` | no-show refund, cancel refund | player |
| `tournament_result` | bracket finished / result published | participants |
| `prize_credited` | prize paid from escrow | winner |
| `auction` | auction settled or cancelled | seller + winner |
| `transfer` | direct buy/sell | buyer + previous owner |

`hydrateNotification()` maps `transfer`/`auction` to the market (`index.php?page=tournaments#hire`).

---

## Feature → code index

| Feature | Function | Handler action | UI entry |
|---|---|---|---|
| Become agent / fund | `createAgentAccount`, `addAgentFunds` | `become_agent`, `add_funds`, `bkash_*` | Become Agent modal |
| Create tournament | `createTournamentByAgent` | `agent_create_tournament` | New tournament modal |
| Join (solo/team) | `registerForTournament`, `joinTournamentWithTeam` | `register` | Join modal |
| Leave | `unregisterFromTournament` | `unregister` | My registrations |
| Check in | `tnCheckin` | `check_in` | Tournament card / room |
| Bracket build | `bracketBuild` | `generate_bracket` | Agent actions |
| Report / confirm / dispute | `bracketReportScore`, `bracketConfirmScore`, `bracketDisputeScore` | `report_score`, `confirm_score`, `dispute_score` | Bracket card |
| Resolve | `bracketAgentResolve` | `advance_winner` | Agent bracket controls |
| Status | direct SQL in handler | `update_tournament_status` | Agent actions |
| Results + prizes | `saveTournamentResults` | `submit_tournament_results` | Room results form |
| Cancel + refund | `cancelTournament` | `cancel_tournament` | Agent actions |
| Teams | `createTeam`, `addTeamMember`, `removeTeamMember`, `deleteTeam` | `create_team`, `add_member`, `remove_member`, `delete_team`, `get_teams`, `get_team_members` | Team modal / Manage modal |
| Clubs | `createClub`, `joinClub`, `leaveClub`, `updateClub` | `create_club`, `join_club`, `leave_club`, `update_club`, `get_club`, `get_my_clubs`, `get_club_members` | Clubs section |
| Ask price | `setPlayerMarketValue` | `set_player_value` | My Players → Value |
| List for auction | `listPlayerForAuction` | `list_player_auction` | My Players → Auction |
| Bid | `placeBid` | `place_bid` | Live Auctions → Bid |
| Settle | `settleExpiredAuctions` | `settle_auctions` (also automatic) | — |
| Buy | `buyPlayerDirect` | `buy_player` | Free Agents → Buy |
| Release / hire / fire | `releasePlayer`, `hirePlayerToClub`, `firePlayerFromClub` | `release_player`, `hire_player`, `fire_player` | My Players / club view |
| Squad list | `getMyPlayers`, `ensurePlayerProfile` | `get_my_players` | My Players tab |
| Market data | `getMarketPlayers`, `getActiveAuctions`, `getPlayerDetail` | `get_market_players`, `get_active_auctions`, `get_player_detail` | Market tabs |
| Leaderboard | `getLeaderboard`, `getClubStandings` | `get_leaderboard`, `get_club_standings` | Leaderboard tab |
| Room + chat | `userCanAccessTournamentRoom`, `getTournamentRoomMessages`, `createTournamentChatMessage` | `get_tournament_room`, `get_tournament_chat`, `send_tournament_chat` | Room page |

---

## H. How to verify

All three suites are CLI-only (HTTP 403 otherwise) and clean up after themselves:

```bash
php tests/tournament_qa.php   # 196 assertions: escrow, brackets, refunds, market, money conservation
php tests/http_flow.php       # 96 assertions: boots php -S and drives every handler action over real HTTP
php tests/page_smoke.php      # renders tournaments + tournament-room as agent and guest, checks markup + JS
```

`tests/http_flow.php` also proves the permission gates (guest, non-owner, non-agent,
seller-bidding, self-buy, release-while-listed) return graceful JSON instead of errors.

---

## I. Open decisions (recorded, not invented)

1. **Platform account** — money that leaves the user economy (5% auction fee, direct buy
   of an unowned card, agent activation fee) is ledgered but has no platform wallet to
   land in.
2. **Default asking price** — new cards start at `market_value = 0`, so they cannot be
   bought until someone sets a price (Value). Auctions always need an explicit base price.
3. **Check-in window** — the flag can be set before the window opens; only the deadline
   sweep cares, and it runs at `starts_at`.
