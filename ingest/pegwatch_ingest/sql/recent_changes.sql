-- Each tenement's latest event in the last 30 days, leaving out deaths that a
-- reappearance reversed. Events of one run tie on created_at; the later insert
-- (the more specific event, e.g. released over shrink) wins.
delete from recent_changes;
insert into recent_changes (tenid, event_type, detected_at)
select distinct on (tenid) tenid, event_type, created_at
from tenement_events
where created_at >= now() - interval '30 days' and not (details ? 'reversed_by_run')
order by tenid, created_at desc, id desc;
