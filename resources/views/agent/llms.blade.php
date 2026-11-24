# Pegwatch

> Every live, pending and dead mining tenement in Western Australia, checked against the state register every night, with each change recorded and free email alerts when ground is applied for, dies or is scheduled for release.

The register holds {!! number_format($live) !!} live and {!! number_format($pending) !!} pending tenements{!! $checked ? '; it was last checked '.\App\Support\Format::awst($checked).' AWST' : '' !!}. Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0. Pegwatch is a screening aid, not legal advice or the official register. Holders who are natural persons are named as on the public register and nothing more.

Every page is available as Markdown: send `Accept: text/markdown`, or add `.md` to the path.

## For agents

- [Agent guide]({!! $base !!}/agents.md): the tools, how to read IDs, statuses and dates, and how watches are approved
- [MCP server]({!! $base !!}/mcp): Streamable HTTP, no key; [server card]({!! $base !!}/.well-known/mcp/server-card.json)
- [JSON API]({!! $base !!}/api/v1/status): no key; [OpenAPI description]({!! $base !!}/api/openapi.json)
- [Account MCP server]({!! $base !!}/mcp/account): the same tools plus the person's own watches, after they sign you in ([auth.md]({!! $base !!}/auth.md))
- [Agent skill]({!! $base !!}/.well-known/agent-skills/pegwatch/SKILL.md)

## Pages

- [The map]({!! $base !!}/index.md): today's totals, recent changes and releases coming up. Map views are `{!! $base !!}/#map=zoom/lat/lng`
- [A tenement]({!! $base !!}/tenements/m-27-518.md): `/tenements/{id}` with the ID lowercased and punctuation as hyphens
- [A holder]({!! $base !!}/holders/northern-star-resources-ltd.md): `/holders/{name}` the same way
- [Activity]({!! $base !!}/activity.md): register changes, newest first
- [Weekly digest]({!! $base !!}/digest.md): the week on the register
- [Data and disclaimer]({!! $base !!}/data.md): sources, freshness and what Pegwatch cannot know

## Optional

- [Full guide in one file]({!! $base !!}/llms-full.txt)
- [Activity RSS]({!! $base !!}/activity.rss)
- [Sitemap]({!! $base !!}/sitemap.xml)
