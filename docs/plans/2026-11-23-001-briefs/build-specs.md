# Build specs for the top items

Five specs, enough to build from. The Opus review agent wrote them; Gemini 4 Neon's review notes are folded in at the end. They are also in the proposal doc "Pegwatch design and UX proposals", tab "Build specs".

## Spec 1: map hover card and click card (M1, M2, O14, bug B19)

**Trigger.**

1. Hover runs only where `matchMedia('(hover: hover) and (pointer: fine)')` is true. Touch devices use the click card (a bottom sheet on phones).
2. Listen to `mousemove`, one call per animation frame; query `queryRenderedFeatures` on `live-fill`, `pending-fill`, `releases-fill`, a new near-invisible `dead-fill`, `freed-fill` (O6) and `restrictions-fill`.
3. Show the card 120 ms after the cursor rests on a feature. Moving to another feature updates it at once. Hide it 80 ms after the cursor leaves, and on drag, zoom and Escape.
4. Below zoom 8 (the O1 shapes), show one line, "Zoom in to see tenements here"; a click zooms to zoom 9 there.

**Highlight.** `promoteId` on the tenements source; hover sets feature-state `hover`, which raises line width by 1.5 px and fill opacity to 0.35.

**Card.** One `div` in the map container, `pointer-events: none`, `role="tooltip"`, 14 px right of and below the cursor, flipped near edges, 280 to 320 px wide, 13 px text, tabular numbers. When features overlap, the top one is the smallest.

| Row | Content | Source |
| --- | --- | --- |
| 1 | ID (semibold) and a status badge: Live, Pending, Dead, Release due | `fmt_tenid`, `status` |
| 2 | Type in sentence case · mineral field: "Mining lease · Kanowna" | `type_code`, ID prefix (O2) |
| 3 | First holder, then "+2" when there are more; names as registered | `holder`, `holders_n` |
| 4 | Hectares · the one date that matters (rules below) | `ha`, dates |
| 5 | Flags only when present: "Part releases Wed 2 Dec, 8:30am AWST", "Changed 2 days ago: holder change" | release join, `recent_changes` |
| 6 | "+2 more here: E 24/250 (pending), P 27/2206 (live)", at most 3, then "Click to see all" | other features under the cursor |
| 7 | Muted hint: "Click for actions" | static |

**Date rule (row 4).** "Today" is in `Australia/Perth`; parse `YYYY-MM-DD` as a wall-clock date.

- Pending: "Applied 21 Apr 2023 · 3 yr 7 mo ago".
- Live, expiry ahead: "Expires 5 Nov 2030 · in 4 yr", amber under 6 months.
- Live, expiry passed: "Past expiry (22 Apr 2023), still live" (O4).
- Dead: reason and date, "Withdrawn 16 Nov 2026".
- Release: "<ID> releases Wed 2 Dec, 8:30am AWST · in 9 days".
- Restriction: kind · name · purpose.

**Tile properties to add**, in `TileController.php`, only when `z >= 8` (else null), so state-view tiles stay small:

```sql
cardinality(t.holders) as holders_n,
case when t.area_ha < 10 then round(t.area_ha::numeric, 1) else round(t.area_ha::numeric) end as ha,
to_char(t.applied_at, 'YYYY-MM-DD') as applied,
to_char(t.granted_at, 'YYYY-MM-DD') as granted,
case when t.ends_at < timestamp '2999-01-01' then to_char(t.ends_at, 'YYYY-MM-DD') end as ends,
(select to_char(min(r.release_at at time zone 'Australia/Perth'), 'YYYY-MM-DD HH24:MI')
   from release_pending r where r.tenid = t.tenid) as rel_at
```

These are wall-clock `timestamp` columns, so `to_char` is right. Add `holder` and `ha` to the releases and dead layers. Measure the zoom 10 tile before and after; accept up to 40% growth.

**Click.** A click pins the card (pointer events on), adds a close button and the actions Open, Zoom to, Copy ID, TENGRAPH (`Format::TENGRAPH_URL`) and Watch around here. With several features the pinned card lists each as a row; Up and Down move, Enter opens. A click on empty map unpins. Keep the popup's `closeOnClick: false` (the foundation fix for B19).

**Keyboard.** With the canvas focused, draw a 16 px centre crosshair; Enter runs the click at the centre; Escape closes.

**Done when.** Hovering M 27/518 at Kalgoorlie zoom shows the card within 150 ms with "Applied 21 Apr 2023 · 3 yr 7 mo ago" and "+2 more here", and no network request fires per hover.

## Spec 2: honest state view (O1)

1. **Nightly table.** `coverage_lowzoom`: union of current tenements by status and type group (E, P, M, other; L and G excluded), snapped, `ST_MakeValid`, simplified, subdivided, with a GiST index, refilled with `DELETE` and `INSERT` inside the snapshot transaction (a rename holds an exclusive lock across the diff).
2. **Tile layer.** `coverage` in `TileController::LAYERS` for zoom 3 to 8, fields `status` and `type_group`, no feature dropping.
3. **Paint.** Live in the status or type colour at 0.45 opacity, no outline; pending as a light tint; draw order E, P, other, M.
4. **Cross-fade.** Coverage fades from full at zoom 7.5 to none at 8.5; the single-tenement layers do the reverse. Low-zoom line width rises from 0.4 to 0.8 px.
5. **Chip.** Below zoom 8: "Zoomed out: shaded ground is held or applied for. Zoom in to see each tenement."
6. **Done when.** Screenshots of one box at zoom 5 and zoom 7 show the same covered share by eye, and the zoom 4 Goldfields tile stays under 200 KB gzipped.

## Spec 3: tenement page top block (T1, T2, O2, O3, O4)

**Order.** Eyebrow, ID with actions, headline, key facts, release callout, overlaps, map, history.

| Element | Content | Rule |
| --- | --- | --- |
| Eyebrow | "MINING LEASE · PENDING APPLICATION" | Small caps, status dot in the status colour |
| ID and actions | "M 27/518" · TENGRAPH · Copy link · Watch this tenement · Share | Copy ID beside the ID |
| Headline | One sentence (below) | Register words in sentence case; holder names as registered, linked |
| Field | "Kanowna (27)" | O2 |
| Area | "198 ha", or "31 blocks (9,072 ha mapped)" | Mapped hectares in brackets only for blocks, or when they differ by more than 2% |
| Applied | "21 Apr 2023, 8:24am (3 years 7 months ago)" | "Applied" for every status (live pages say "Started" today) |
| Granted | "6 Nov 2020 (28 months after application)" | Live only |
| Expires | "5 Nov 2030 (in 4 years)" | Amber under 6 months; a past date gets the O4 sentence |
| Holders | All names, linked | Natural persons by name only |
| Extract | "Register extract of 19 Nov 2026, 4:11am AWST" | The run's extract time, the same one the footer shows |

**Headlines.**

- Pending: "Pending mining lease application by Yandal Resources Limited, lodged 21 Apr 2023 (3 years 7 months ago). 198 ha in the Kanowna district, unsurveyed."
- Live: "Exploration licence held by <holder> since 6 Nov 2020. Expires 5 Nov 2030 (in 4 years). 31 blocks, 9,072 ha."
- Live past expiry: add "The expiry date passed on 22 Apr 2023, but the register still lists it as live."
- Dead: "<Type> applied for by <holder> on <date> and withdrawn on <date>, after 17 days."

**Overlaps block (O3).** Open when there is one or more; columns Tenement, Type and status, Holder (with a "Same holder" tag), Share of this one, Lodged ("8 years 7 months before this one"); oldest first; "Can overlap" for L, G and special prospecting licences. Footnote: "Lodgement order is from the register. Priority under the Mining Act can depend on marking out, which no dataset shows. Confirm with the Mining Registrar."

## Spec 4: freshness chip, truthful empty states and digest capture (V5, O8, O10)

- **Data.** `Register::latestRun()` gives the extract time and `stale`; `Register::lastCheck()` gives the newest finished run. Show as stale when the run is stale or the extract is over 72 hours old (so weekends stay calm).
- **Chip.** Fresh: grey dot, "Register 22 Nov, 4:11am". Stale: amber dot, "Register 19 Nov · 4 days old". The popover says when Pegwatch last checked and links to `/data`.
- **Footer.** One line: "A screening aid, not advice. Confirm in TENGRAPH and with the Mining Registrar. Data © State of WA (DMPE), CC BY 4.0, changes made." and a "Data and disclaimer" link.
- **Empty states** in `x-event-list`, which gets the run and a context: baseline only ("Pegwatch has its first copy of the register. Changes show here from the next extract."), stale ("No changes since 19 Nov: the department has not published a new extract. We check every night."), fresh but empty ("No changes to M 27/518 since Pegwatch started watching on <date>."), filtered `/activity` (blame the filter).
- **Capture** (guests). Map, activity and holder pages: the digest sign-up, "Get the week's changes every Monday". Tenement pages: "Email me when this changes" (W1). Tag the source as `empty-<context>`.
- **Done when.** On a stale night `/`, `/activity`, `/tenements/m-27-518` and `/holders/yandal-resources-limited` show the stale sentence and a working sign-up, and "second nightly run" is gone from the code.

## Spec 5: watch this area, email first (W1)

1. **Entry points.** "Watch this area" on the map from zoom 8; "Watch around here" in the click card; "Email me when this changes" on tenement pages; the header button opens the same flow.
2. **Draw.** A circle at the map centre, radius the smaller of 25 km and half the view, with a drag handle (1 to 50 km). From a tenement page: its outline plus 2 km.
3. **Panel.** A name prefilled from the nearest town or the mineral field; live counts for the shape from one debounced call; the two free alert types ticked and the Pro types locked (W4); an email field; "Email me when this changes"; "One email after a night with changes. Unsubscribe from any email."
4. **Submit.** Validate like the digest sign-up (same rate limits and honeypot). Create a user with email only and a watch area with `confirmed_at` empty. Send a signed confirm link valid 7 days.
5. **Confirm.** The link opens a page with a button; the POST verifies the email, confirms the area, signs the user in and lands on `/watch-areas` with "Watching Kalgoorlie. Your first email comes after the next extract with changes." Mail scanners that open links confirm nothing.
6. **Later.** Login offers "Email me a login link"; settings offers an optional password. `AlertMatcher` skips unconfirmed areas. Alert emails carry one-click unsubscribe headers and a signed "Stop alerts for this area" link that needs no login.

## Gemini's notes on these specs

- **Spec 1.** Lower the tile full-detail cut-off from zoom 9 to zoom 8. Use `tenid` as `promoteId`. When areas are missing, sort releases first and restrictions last.
- **Spec 2.** Wrap the snapped geometry in `ST_MakeValid`, leave out L and G licences, and use a light tint, not a hatch, for pending ground at low zoom.
- **Spec 3.** Add restriction and pending-action callouts (G5) and dead overlaps (T8); return `type_code` and `special_ind` for overlaps. Use the legal area in both the headline and the facts. Hide "12:00am" on date-only values.
- **Spec 4.** Keep "changes made" in the CC BY footer. On a filtered `/activity` view, blame the filter, not the extract.
- **Spec 5.** Handle existing accounts, the `name` column, and password checks on the security and delete-account pages. `WatchArea` stores circles only today, so a tenement outline needs a schema change.
- **Bugs.** B6: dropping cookies is not enough; Cloudflare skips `.mvt` by default, so a cache rule for `/tiles/*` is needed (Colin sets it). B12: show the run's extract time everywhere and no time for date-only values. B20: MapLibre opens the compact attribution expanded, so collapse it after load and move the scale bar to the top left.
