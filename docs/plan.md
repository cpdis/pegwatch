# WA Tenement Tracker Plan

Nov 22, 2026 · @Colin Dismuke

## Summary

Revised after a pressure test on 22 Nov 2026: the data holds, but two of the three selling points do not. Free area alerts are the only real gap. "Open ground" cannot be computed reliably from open data, and [NextMaps](https://www.nextmaps.com.au/pricing) (A$50 per user per month) already advertises alerts that trace subsidiaries to ASX parents. Treat this as a small side business, likely 30 to 100 payers in year one, which Colin has decided is worth building (22 Nov 2026). Claude writes the code, so Colin needs no Laravel knowledge.

- **Product:** a WA map of every live, pending and dead tenement, with free email alerts when a tenement near you is applied for, dies, shrinks or has ground scheduled for release.
- **Wedge:** free area alerts (NextMaps and Detector Maps both charge for alerts), shared team watchlists, and field exports. Not "open ground", and not an ASX feed.
- **Who pays:** serious prospectors (about A$89 a year) and consultants or junior explorers (about A$49 a month per team). Retail investors get a free digest, as an audience channel only.
- **Build:** a new repo, a nightly ingest from the public SLIP service, PostGIS diffing by tenement ID and by geometry, PMTiles for the map. Nothing has to be requested from any agency.
- **First step:** a two-week data spike plus a demand test with pre-sales. The free product is built alongside it; 100 digest signups and 20 pre-sold annual plans in 4 weeks decide how much goes into the paid plans.

## Pressure test findings

Nine claims were tested: two held, three failed and four need changing. Data figures come from my own queries of the live SLIP service on 22 Nov 2026. Two Fable 5.2 reviewers covered mining law and competitors, but vendor, legislation and regulator sites were blocked from the cloud, so those findings rest on search excerpts and should be opened once in a browser.

| Claim in the plan | Result | What I found | Change made |
| --- | --- | --- | --- |
| Data is open, CC BY 4.0, no key | Holds | Catalogue API confirms CC BY 4.0 for tenements and release pending. 30,613 records: 23,746 live (the plan said 22,084) and 6,867 pending | Count corrected |
| Refreshed daily | Partly | The live layer's extract was 19 Nov, 12:10pm AWST, and had not changed by Sunday 22 Nov, so Friday's refresh did not appear. Newest application in it is dated 18 Nov | Promise "as of extract date", never same-day |
| About 290 applications and 230 deaths a month | Holds | 292 and 233 in the last 30 days | None |
| A death means ground is freed | Fails | Of the 233 deaths, 146 (63%) were still at least 95% covered by another live or pending tenement, and 72 (31%) were essentially uncovered. 13 of 14 expiries were fully covered. Most "deaths" are withdrawn overlapping applications or conversions | Alerts say "tenement recorded dead" and show the share with no other tenement over it |
| Release pending shows ground from dead tenements | Fails | The layer covers only parts of live exploration licences due for compulsory surrender under s65. All 11 rows are E licences, released at 8:30am, 2 to 3 weeks ahead; notice numbers suggest about 13 a month | Described correctly; kept as a strong alert |
| Open ground can be computed | Fails as worded | See the Open ground section below | Renamed, turned into a screening layer with flags, moved out of Pro's headline |
| Hand-mapping the top 200 holders gives an ASX feed | Weak | 3,581 distinct first-named holders. The top 200 hold 50% of tenements and are mostly majors (BHP, FMG, Northern Star); the top 1,000 hold 84%. Investors care about the small holders in the tail | Dropped as a paid feature |
| A$29 a month for Pro | Wrong buyer | Hobbyists pay A$12 to A$30 a year for apps; professionals already accept A$50 to A$200 a month | Two plans instead of one |
| 7 weeks to paid launch | Optimistic | The plan lacked a geometry diff and coverage check. Claude writes the code, so Laravel experience is not the limit; Colin's review time and account setup are | About 8 weeks to paid plans, with the demand test running alongside |

## Open ground: what can and cannot be claimed

No product can say ground is "open to apply for" from open data alone, so the layer becomes "No recorded tenement, as at extract date", with flags. Section numbers below come from search excerpts of the Mining Act 1978 and Mining Regulations 1981 and need one check against the current text.

- **Wrong starting point.** Dead polygons minus live ones misses Crown land that never had a tenement. The base is the State minus live and pending tenements, by geometry.
- **Pegging is invisible.** Priority goes to the first applicant to meet the initial requirement (s105A). For prospecting licences and mining leases that is marking out on the ground, which no dataset shows. The layer is also at least a day behind the register.
- **Pending applications don't close ground.** You can apply over one and sit second in line. Show it as "contested", not closed.
- **Former holders are barred for 3 months** after surrender, forfeiture or expiry (s69 for exploration licences), and a successful forfeiture plaintiff has 14 days' priority.
- **Compulsory surrender shrinks a live polygon.** No dead record appears, so only a geometry diff and the release pending layer catch it. Applications in the first 15 minutes after release go to ballot.
- **Not everything blocks.** Miscellaneous licences and general purpose leases can overlap other tenements, and special prospecting licences for gold can sit on someone else's ground. File Notation Areas are notations, not bans.
- **Exploration licences are applied for by one-minute graticular block**, prospecting licences by pegged polygon up to 200 ha. The two need different views.
- **Missing layers.** Private land consent (s29), pre-1899 grants where the owner holds the minerals, and land tenure are not open data. DBCA's parks and reserves layer is CC BY-NC 4.0, so a paid product cannot use it without permission. Native title is a process on grant, not a bar to applying.
- **Liability.** Calling ground "truly open" invites a misleading-conduct claim if someone pegs on it. Every view links to TENGRAPH and says to confirm with the Mining Registrar.

## Data

The data holds up: every layer needed is open, CC BY 4.0, queryable without a key, and refreshed about daily. I queried the live SLIP ArcGIS service from the cloud on 22 Nov 2026; figures below are from those queries.

| Layer | Records | What it gives | Refresh seen |
| --- | --- | --- | --- |
| [Mining Tenements (DMIRS-003)](https://catalogue.data.wa.gov.au/dataset/mining-tenements-dmirs-003) | 30,613 (23,746 live, 6,867 pending) | Polygon, type, status, up to 9 holders with addresses, area, application date+time, grant date, expiry date | extract\_da 19 Nov 2026 |
| [Tenements DEAD (DMIRS-026)](https://catalogue.data.wa.gov.au/dataset/tenements-dead-dmirs-026) | 422,894 | Every dead tenement with polygon, death reason (Surrendered, Withdrawn, Expired, Forfeited, Refused), end date | extract 20 Nov 2026 |
| [Release pending (DMIRS-030)](https://catalogue.data.wa.gov.au/dataset/tenements-release-pending) | 11 | Parts of live exploration licences due for compulsory surrender under s65, with release ID and exact date and time. Not a queue for dead tenements | live |
| Amalgamation (031) / Restoration pending (032) | 208 / 14 | Pending amalgamations and restorations | live |
| Section 19, s57, File Notation Areas | 80 / 4,480 | Land closed or restricted for mining | live |

**Access.** Use the ArcGIS REST endpoint ([layer 3](https://public-services.slip.wa.gov.au/public/rest/services/SLIP_Public_Services/Industry_and_Mining/MapServer/3)), 10,000 rows per page, SQL filters and stats work. Bulk GeoJSON, GPKG and SHP downloads exist at data-downloads.slip.wa.gov.au, but that host is blocked from this cloud sandbox (fine from a normal server). No sign-up, no API key, nothing to request.

**Volume, last 30 days.** 292 new applications (130 E, 113 P, 35 L, 13 M). 233 deaths: 124 withdrawn, 88 surrendered, 14 expired, 4 forfeited, 3 refused. 888 live tenements have an expiry date in the next 90 days, but most get extended, so expiry alone is a weak signal.

**How we detect change.** The service has no change feed, so the app diffs a nightly snapshot: new tenids = new application, a tenid moving from 003 to 026 = death with reason, holder fields changing = transfer, new rows in 030 = release scheduled. It must also diff geometry, because a partial surrender shrinks a live polygon without creating a dead record, and it must check each death against the other tenements over the same ground before calling anything freed.

**Licence.** CC BY 4.0 on the WA catalogue (data.gov.au's mirror still says CC BY 3.0 AU). Commercial use and alerts are allowed with attribution to the department.

**Caveats.**

- The department is now DMPE (Department of Mines, Petroleum and Exploration) after the July 2025 split; dataset codes still say DMIRS.
- Holder names and postal addresses are in the data, including individual prospectors. Show company holders in full; show individuals' names but not their addresses.
- The Mining Notices site (emits.dmp.wa.gov.au) and its RSS were blocked from the sandbox, so I could not check them. They are a useful same-day cross-check, not a dependency.
- Cadence is "frequent" in the catalogue; I saw extracts on 19 and 20 Nov. Log the extract date for two weeks before promising a time of day.
- On 22 Nov the live layer's extract was still 19 Nov, so at least one business day's refresh was missing. Applications appear about a day after lodgement at best.
- The same service also has s57(4) areas (12), s57(2aa) zones (2) and General Clearance Areas (25), which the first plan missed.
- Attribution: no official wording found. Use "© State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0, changes made" and confirm on the dataset page.

## Alerts and features

Every alert comes from diffing tonight's snapshot against last night's, so all of them are buildable from the open layers alone.

| Alert | Trigger in the data | Who wants it | Volume (30 days) |
| --- | --- | --- | --- |
| Ground freed near my area | tenid moves to DEAD with reason Surrendered, Withdrawn, Expired, Forfeited or Refused, polygon intersects watch area | Prospectors, explorers | 233 |
| Release scheduled | New row in Release pending with date and time | Prospectors racing to apply | 11 now open |
| New pegging near my area | New pending tenid, polygon within X km of watch area or my tenements | Explorers watching neighbours | 292 |
| Company moved | Holder watch: any application, grant, transfer or death for a named holder | Consultants, investors | varies |
| Transfer | holder1..9 changed on a live tenement | Investors, deal hunters | to measure |
| Grant | PENDING to LIVE with grant date | Applicants, investors | to measure |
| Expiry coming | enddate within 30/60/90 days with no new extension | Ground hunters | 888 in 90 days |

**Correction to the first and last rows.** "Ground freed" becomes "Tenement recorded dead", sent with the share of its area that has no other live or pending tenement over it. In the last 30 days that share was near zero for 63% of deaths. Conversions to mining leases and amalgamations are filtered out. Add an eighth alert, "Tenement shrank", from the geometry diff.

**Features beyond alerts:**

- **Open ground layer:** renamed "No recorded tenement". PostGIS computes the State minus live and pending tenements, stamped with the extract date, with flags for release pending, died under 3 months ago, contested by a pending application, s19, s57(4) and File Notation Areas. It is a screening aid, not a statement that ground can be applied for.
- **Holder pages:** every holder's portfolio, area held, new applications per month, and a timeline. ASX ticker mapping is dropped from the paid plan: the top 200 holders cover only half of tenements, the register cannot show joint ventures or unrelated-name subsidiaries, and ASX's terms restrict commercial use of its announcements.
- **Daily and weekly digest:** a WA-wide summary of what was pegged, what died, and who was most active.
- **Compliance dates for my tenements:** grant anniversary, from which rent and the Form 5 report (60 days after each anniversary) fall due. Confirm the exact rules against the Mining Regulations before shipping.
- **Exports:** CSV, GeoJSON and KML for a watch area, for use in QGIS or a GPS unit in the field.

## Competitors and pricing

The market is already served at low prices, so free core alerts are the entry ticket, not a feature. Prices are from search results of vendor pages on 22 Nov 2026; the vendor sites themselves were blocked from my sandbox, so open them once before deciding.

| Product | Price (AUD) | Alerts | Notes |
| --- | --- | --- | --- |
| [NextMaps](https://www.nextmaps.com.au/pricing) | Free map; Premium $50/user/mo; Pro $200/user/mo | Area, holder and tenement-ID watchlists by email: applications, transfers, surrenders, expiries, releases ([alerts](https://www.nextmaps.com.au/alerts)) | Closest rival. Also a [compliance manager](https://www.nextmaps.com.au/tenement-compliance), a tenement marketplace, AI prospectivity reports and a free [weekly Substack](https://nextmaps.substack.com/p/wa-mining-weekly-june-1-7) |
| [Detector Maps](https://apps.apple.com/us/app/-/id6741421159) | Explorer $9.99/mo or $99.99/yr; Pro $34.99/mo or $249.99/yr | Daily tenement alerts in Pro, freed area mapped | Phone app for detectorists, all states, LiDAR hillshade offline |
| [GroundSleuth](https://www.mining-technology.com/products/groundsleuth/) (Global Tenements) | Quote only | Daily map of tenure changes around marked areas | Enterprise, sold with tenement management software |
| PX4, Datamine LandTracker | Quote only | Own-portfolio compliance | Tenement management for companies, not ground hunting |
| [DMPE Mining Notices](https://www.wa.gov.au/service/natural-resources/mineral-resources/access-mining-notices) + RSS | Free | Applications as lodged; surrenders and withdrawals at 4:30pm each business day | No map, no area filter. TENGRAPH itself sends no alerts |

**Where the gaps are:** NextMaps charges per user and puts alerts behind Premium. Detector Maps is a phone app for detectorists. Free area alerts are the one gap that survived the pressure test. NextMaps' site (search excerpts only) advertises ground release zones, a pegging grid, alerts when ground in an area opens up, and "New Ground by ASX company" tracing subsidiaries to listed parents, polled every 15 minutes from the department's feeds. Its paid tiers appear to date from mid 2026, it is run by a Perth geologist, and it could make basic alerts free overnight. Datamine LandTracker also sells "newly available land" alerts to companies. Detector Maps added daily tenement alerts to Pro in July 2026 and has about 750 Australian ratings. NextMaps' terms forbid pooling alerts across a team, which leaves shared team watchlists open.

## Free vs Pro

Give away the area alerts that NextMaps and Detector Maps charge for, then sell two plans: Prospector at A$89 a year and Team at A$49 a month per team, not per user. A$29 a month sat between the two buyers: too dear for hobbyists who pay A$12 to A$30 a year for apps, and cheaper than professionals need.

| Feature | Free | Prospector (A$89/yr) | Team (A$49/mo) |
| --- | --- | --- | --- |
| Map of live, pending, dead tenements | Yes | Yes | Yes |
| Tenement and holder pages | Yes | With full history | With full history |
| Watch areas | 1 area, daily digest | Unlimited | Unlimited, shared across the team |
| Alert types | Tenement dead, release scheduled | All types, incl. applications, shrinkage, grants, transfers, expiry and release countdowns | All types |
| Holder watch | 1 holder | Unlimited | Unlimited |
| "No recorded tenement" layer with flags | View only | View, area-of-interest stats | View, stats |
| Exports | No | KML and GPX for GPS and detector apps | Plus CSV, GeoJSON and an API |
| Weekly WA digest email, with company names | Yes | Yes | Yes |

**Market size, honestly.** 4,081 Miner's Rights were issued in 2024-25, APLA has roughly 1,200 members (2013 figure), and the data shows 3,581 distinct first-named holders. My estimate for a part-time solo founder is 30 to 100 payers in year one, about A$5,000 to A$35,000 a year. These figures are from search excerpts and estimates, not verified pages.

**ABN and privacy.** Get a free sole-trader ABN before taking payments; GST registration starts at A$75,000 turnover. Selling access to individuals' names and addresses can remove the Privacy Act small-business exemption (s6D(4)), so never show or export postal addresses, and keep individual holders out of exports. Keep the digest factual, with no rankings or "signals" about ASX companies, to stay clear of financial advice.

## Tech

Start a new repo with the same stack; cpdis/perth-builds holds only a README (one commit, 19 Nov), so there is no code to reuse and nothing to untangle. A separate repo keeps a separate Laravel Cloud app, database, domain and billing, which is what "two separate sites" needs.

**Stack:** Laravel + Inertia + React starter kit, MapLibre, PostGIS, Laravel Cloud (Sydney region), Stripe or Laravel Cashier for Pro.

**Nightly pipeline (GitHub Actions, about 7pm AWST):**

1. Page through SLIP layers 3, 15 (only rows with enddate in the last 3 days after the first full load), 19, 20, 21, 11 and 12 with the REST API, 10,000 rows a call. Unlike City of Perth, SLIP answered my cloud requests with no block.
2. Load into staging tables in PostGIS with ogr2ogr (GDAL is preinstalled on GitHub runners).
3. Diff against yesterday in SQL: new, dead, changed holder, newly granted, newly scheduled for release. Write one `tenement_events` row per change.
4. Rebuild the "no recorded tenement" layer (State minus live and pending tenements, ST\_Difference) and its flags, and compute each death's uncovered share.
5. Build PMTiles with tippecanoe and upload to R2 or S3, so the map never queries the database.
6. Call a token-protected endpoint on the app, which matches events to watch areas and queues emails.

**Why not run it all in Laravel Cloud:** its managed queue jobs stop at 90 seconds and builds can't install GDAL or tippecanoe. The app itself only serves pages, matches alerts and sends mail.

**Data size:** about 30,000 current and 423,000 dead polygons. A first full load of the dead layer is the only big pull, and it runs once.

**Colin's first Laravel steps:** create the repo from the starter kit, deploy it to Laravel Cloud, add a Postgres database and enable PostGIS. I'll walk through each step when the build starts.

## Domain and name

Recommend **PegWatch** at pegwatch.au, with pegwatch.com as a redirect. "Pegging" is the word WA prospectors use, and the name says what it does. RDAP showed both as unregistered on 22 Nov 2026; .au direct needs only Australian residency, while .com.au needs an ABN.

| Name | Domain | Status on 22 Nov 2026 |
| --- | --- | --- |
| PegWatch | pegwatch.au | Not registered (RDAP 404) |
| PegWatch | pegwatch.com | Not registered (RDAP 404) |
| TenementWatch | tenementwatch.au | Not registered (RDAP 404) |
| GroundWatch, FreeGround, PegAlert, GroundOpen | .au | Unchecked: the auDA lookup rate-limited me |

Check again from your browser at rdap.cctld.au before buying, since auDA blocks repeated lookups from cloud IPs.

## Roadmap, risks, open questions

**Roadmap (straight run, each step gates the next):**

1. **Weeks 1 to 2, data spike:** a script pulls all layers nightly into PostGIS, logs extract dates, and prints ID and geometry diffs. Pass mark: 10 straight nights where applications and deaths match the department's Mining Notices, and a measured answer to how often the refresh skips a day.
2. **Weeks 1 to 4, demand test:** a one-page site on pegwatch.au with the free weekly digest and a pre-sale of Prospector annual plans, promoted through APLA and prospecting forums and Facebook groups. Pass mark: 100 signups and 20 pre-sold plans. If it misses, ship the free product anyway and keep the paid plans minimal.
3. **Weeks 2 to 5, free product:** map, tenement and holder pages, one watch area, dead-tenement and release alerts. Claude writes the code; Colin creates the accounts (Laravel Cloud, domain, Stripe, mail) and reviews.
4. **Weeks 6 to 8, paid plans:** all alert types, unlimited areas, KML and GPX export, Stripe. Team plan with shared watchlists once two consultants ask.
5. **Later:** the "no recorded tenement" layer with flags, compliance calendar, a phone-friendly field view.

**Risks:**

- **NextMaps cuts its price or makes alerts free.** This is the biggest risk, since free alerts are our only clear gap. Team sharing and price are the fallback.
- **Open ground is wrong.** Applications lodged today may not be in the layer until tomorrow. Label it "as of last night" and link to the department for live checks.
- **Personal data.** Individual holders' addresses are in the data; don't publish them.
- **Schema or service changes** after the department rename. The nightly job should fail loudly and email Colin.
- **Ballots and timing rules.** Who gets ground when two people apply at once is set by the Mining Act; describe, don't advise.
- **The market is small.** Even a good outcome is tens of payers, so the demand gate matters more than the build.
- **NextMaps already covers most features** and polls the department's feeds every 15 minutes. Compete on free alerts, price and team sharing, not on feature count.
- **Refresh gaps.** The open layer can sit days stale; alerts must carry the extract date. The department's Mining Notices RSS is the faster source to test in the spike.
- **Unverified facts.** Competitor features and Mining Act section numbers in this plan come from search excerpts. Open the NextMaps site and the Act once before committing.

**Open questions for Colin:**

- Decided 22 Nov 2026: a side business of roughly 30 to 100 payers is worth building.
- Are A$89 a year and A$49 a month per team the prices you want to test?
- Are you happy to get a free sole-trader ABN before the pre-sale?
- NextMaps' free tier is confirmed from Colin's screenshot of its pricing page (22 Nov 2026): map, search, 15 map-agent questions a day and three trial outputs, with no watchlists or alerts. Still to confirm on its site: the "New Ground by ASX company" feature and what Premium's alerts include.

**Sources:** [DMIRS-003 catalogue](https://catalogue.data.wa.gov.au/dataset/mining-tenements-dmirs-003), [SLIP Industry and Mining service](https://public-services.slip.wa.gov.au/public/rest/services/SLIP_Public_Services/Industry_and_Mining/MapServer), [Access Mining Notices](https://www.wa.gov.au/service/natural-resources/mineral-resources/access-mining-notices), [NextMaps pricing](https://www.nextmaps.com.au/pricing), [Detector Maps app](https://apps.apple.com/us/app/-/id6741421159), [GroundSleuth](https://www.mining-technology.com/products/groundsleuth/).
