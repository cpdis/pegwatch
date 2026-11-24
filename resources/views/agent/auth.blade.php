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

## A person's own watches

Listing, renaming and stopping a person's existing watches needs them signed in on the site ([My watches]({!! $base !!}/watch-areas), by emailed link). There is no agent credential for this yet.
