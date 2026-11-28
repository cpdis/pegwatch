@php($base = rtrim(url('/'), '/'))
# Pegwatch auth.md

## Who this is for

AI agents and other automated clients using Pegwatch's [MCP server]({!! $base !!}/mcp) or [JSON API]({!! $base !!}/api/v1/status) on a person's behalf.

## Reading needs no credentials

Search, tenement and holder lookups, the activity feed, releases, data status and watch previews are public. There is no key, no registration and no account. Each client is rate limited (120 requests a minute); a 429 means wait and retry.

## Sign-ups are approved by email

Two operations act for a person, and both are confirmed by the person, not the agent:

- `request_watch` (`POST {!! $base !!}/api/v1/watches`) asks for free alerts on an area, a tenement or a holder.
- `request_digest_subscription` (`POST {!! $base !!}/api/v1/digest/subscriptions`) asks for the weekly digest.

Each sends one email to the address given, with a link that opens a page with one button. Nothing starts until the owner of the address presses it, and every alert email has a one-click stop link. Only send an address the person gave you for this.

## A person's own watches: OAuth 2.1

Listing, starting, changing, stopping and deleting a person's watches needs the person to sign the agent in. Use [{!! $base !!}/mcp/account]({!! $base !!}/mcp/account) (MCP) or `{!! $base !!}/api/v1/me/watches` (API) with a Bearer access token.

- Protected resource metadata (RFC 9728): `{!! $base !!}/.well-known/oauth-protected-resource/mcp/account`. A request without a token gets 401 and a `WWW-Authenticate` header that points there.
- Authorization server metadata (RFC 8414): `{!! $base !!}/.well-known/oauth-authorization-server`.
- Register your client (RFC 7591): `POST {!! $base !!}/oauth/register` with `client_name` and `redirect_uris` (https, or an app scheme such as `claude:` or `cursor:`). Clients are public: no secret.
- Authorization code with PKCE (S256 only), scope `mcp:use`, at `{!! $base !!}/oauth/authorize`; tokens at `{!! $base !!}/oauth/token`. Access tokens last 8 hours and refresh tokens 60 days.

The person signs in to Pegwatch (emailed link or passkey; there is no password to give an agent), sees your client's name and where it returns them, and presses Allow. They can disconnect you at any time under Settings, Connected agents, and every token stops working at once. The token can do what the person can on [My watches]({!! $base !!}/watch-areas) and nothing else: no email, password or sign-in changes.
