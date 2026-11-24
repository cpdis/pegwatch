{{-- The agent guide in Markdown: the /agents page, the Agent Skill (SKILL.md) and llms-full.txt. Plain text, so no escaping of URLs. --}}
@php($base = rtrim(url('/'), '/'))
Pegwatch tracks every live, pending and dead mining tenement in Western Australia. It reloads the state register (DMIRS, through SLIP) every night, records each change (new applications, grants, deaths, holder changes, area changes, scheduled compulsory releases) and sends free email alerts for a watched area, tenement or holder.

Everything a person can look up on the site, an agent can look up too, through the same queries:

- **MCP server** (Streamable HTTP, no key): `{!! $base !!}/mcp`. Card: `{!! $base !!}/.well-known/mcp/server-card.json`.
- **JSON API** (no key): `{!! $base !!}/api/v1`. OpenAPI 3.1: `{!! $base !!}/api/openapi.json`.
- **Markdown pages**: any page as Markdown with `Accept: text/markdown`, or by adding `.md` to its path (`{!! $base !!}/tenements/m-27-518.md`; the map's home is `{!! $base !!}/index.md`).
- **In the browser (WebMCP)**: pages register tools on `document.modelContext` to search, open pages and drive the map.

## Tools

The MCP tools and API endpoints are the same operations:

| MCP tool | API | What it does |
|---|---|---|
| `search_register` | `GET /api/v1/search?q=` | Tenements by ID, holders by name, groups of companies with matching names, and towns. |
| `get_tenement` | `GET /api/v1/tenements/{id}` | One tenement (live, pending or dead): holders, dates, area, history, overlaps, releases, restrictions, pending actions. |
| `tenements_at_point` | `GET /api/v1/tenements/at?lat=&lng=` | Who holds the ground at a point, what died there recently, releases due there. |
| `find_tenements` | `GET /api/v1/tenements?bbox=` | Live and pending tenements in a box under the map's filters, with counts. |
| `get_holder` | `GET /api/v1/holders/{holder}` | A holder's portfolio, dead tenements, activity by month, companies with matching names. |
| `list_activity` | `GET /api/v1/activity` | The change feed, newest first, filtered by type, tenement kind, mineral field, holder, dates or box. |
| `list_upcoming_releases` | `GET /api/v1/releases` | Compulsory releases still to come, soonest first. |
| `get_data_status` | `GET /api/v1/status` | When the register was last checked and how fresh it is. Call it before saying anything is current. |
| `get_digest_issue` | `GET /api/v1/digest/latest` | The weekly digest: ground left open by deaths, biggest applications, releases due. |
| `preview_watch` | `GET /api/v1/watches/preview` | What a watch on a circle, tenement or holder would cover, before asking for one. |
| `request_watch` | `POST /api/v1/watches` | Ask for free email alerts. Sends a confirm link to the address; nothing starts until its owner presses it. |
| `request_digest_subscription` | `POST /api/v1/digest/subscriptions` | Ask for the weekly digest. Double opt-in, like `request_watch`. |

Lists take arrays or comma-separated strings. A box (`bbox`) is `west,south,east,north` in WGS84 degrees. Lists that page return `next_offset` (null when there is no more).

## Reading the data

- **IDs.** A tenement ID is type, mineral field and number: `E 24/250` is an exploration licence in field 24. Any spelling works (`e24/250`, `e-24-250`). Common types: E exploration, P prospecting, M mining lease, L miscellaneous licence, G general purpose lease, R retention licence.
- **Status.** LIVE (granted), PENDING (applied for), DEAD (surrendered, expired, forfeited, refused, withdrawn or converted). Dead tenements keep their pages.
- **Dates.** Register dates are Perth (AWST, UTC+8) dates: `2024-03-01`, or with a time and `+08:00`. Times when Pegwatch saw a change are ISO 8601 with `+08:00`. 31 Dec 2999 on the register means no date and is returned as null.
- **Freshness.** The register extract is taken about 4am AWST and checked about 7pm AWST, so "today's" changes appear the same evening. Check `get_data_status` when timing matters.
- **Priority.** Lodgement order comes from the register. Priority under the Mining Act can depend on marking out, which no dataset shows; confirm with the Mining Registrar before acting on it.
- **People.** Holders who are natural persons are named as the register names them and nothing more. Pegwatch does not hold their addresses; do not try to find them.
- **Source and licence.** Tenement data is from the WA Department of Mines, Petroleum and Exploration through SLIP (CC BY 4.0). Place names are from the ABS (CC BY 4.0). It is not legal advice and not the official register; the Mineral Titles Online record is.

## Watches and approvals

Looking things up never needs approval. Asking for alerts does: `request_watch` and `request_digest_subscription` only email a confirm link to the address given, and nothing is sent after that unless its owner presses it. Only use an address the person gave you for this. The free plan has one area (a circle up to 50 km, or a tenement and 2 km around it) and one holder watch; confirming a new one replaces the old. Alerts are deaths and scheduled releases.

Managing a person's existing watches (listing, renaming, stopping) needs them signed in: their alert emails carry one-click stop links, and the site's [My watches]({!! $base !!}/watch-areas) page does the rest.

## Good first calls

- "Who holds the ground at -30.75, 121.47?" → `tenements_at_point`.
- "What has Northern Star applied for this month?" → `get_holder` for the portfolio, or `list_activity` with `holder` and `types=application`.
- "What ground near Leonora is up for release?" → `search_register` for the town's centre, then `list_upcoming_releases` with a box around it.
- "Is M 27/518 still alive, and what's next for it?" → `get_tenement`.
