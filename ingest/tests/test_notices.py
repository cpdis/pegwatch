from pathlib import Path

from pegwatch_ingest import notices


def test_parse_feed():
    items = notices.parse((Path(__file__).parent / "fixture_rss.xml").read_text())
    assert len(items) == 2
    first = items[0]
    assert first["fmt_tenid"] == "E 16/697"
    assert first["received_at"].isoformat() == "2026-11-20T15:37:19+08:00"
    assert first["area_text"] == "7 BL"
    assert first["shire"] == "COOLGARDIE SHIRE"
    assert first["applicants"] == ["COSMO HOLDINGS (WA) PTY LTD"]
