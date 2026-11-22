"""Paged reads from the SLIP ArcGIS REST service."""
from __future__ import annotations

import logging
import time
from collections.abc import Iterator

import requests

from .layers import SERVICE_URL, Layer

log = logging.getLogger(__name__)

PAGE_SIZE = 2000  # the service allows 10,000, but big polygon pages time out
USER_AGENT = "pegwatch-ingest/0.1 (+https://github.com/cpdis/pegwatch)"


class SlipError(RuntimeError):
    pass


class SlipClient:
    def __init__(self, base_url: str = SERVICE_URL, session: requests.Session | None = None,
                 timeout: int = 180, retries: int = 5):
        self.base_url = base_url.rstrip("/")
        self.session = session or requests.Session()
        self.session.headers["User-Agent"] = USER_AGENT
        self.timeout = timeout
        self.retries = retries

    def _get(self, path: str, params: dict) -> dict:
        url = f"{self.base_url}/{path}"
        delay = 5
        for attempt in range(1, self.retries + 1):
            try:
                r = self.session.get(url, params=params, timeout=self.timeout)
                r.raise_for_status()
                data = r.json()
                if "error" in data:
                    raise SlipError(f"{url}: {data['error']}")
                return data
            except (requests.RequestException, ValueError, SlipError) as exc:
                if attempt == self.retries:
                    raise SlipError(f"{url} failed after {attempt} tries: {exc}") from exc
                log.warning("SLIP %s attempt %d failed: %s; retrying in %ds", path, attempt, exc, delay)
                time.sleep(delay)
                delay *= 2
        raise AssertionError("unreachable")

    def count(self, layer: Layer, where: str) -> int:
        data = self._get(f"{layer.layer_id}/query",
                         {"where": where, "returnCountOnly": "true", "f": "json"})
        return int(data["count"])

    def layer_info(self, layer: Layer) -> dict:
        return self._get(str(layer.layer_id), {"f": "json"})

    def features(self, layer: Layer, where: str, page_size: int = PAGE_SIZE) -> Iterator[dict]:
        """Yield GeoJSON features in objectid order, in WGS84."""
        offset = 0
        out_fields = ",".join((layer.oid_field, *layer.fields))
        while True:
            data = self._get(f"{layer.layer_id}/query", {
                "where": where,
                "outFields": out_fields,
                "outSR": 4326,
                "orderByFields": layer.oid_field,
                "resultOffset": offset,
                "resultRecordCount": page_size,
                "f": "geojson",
            })
            feats = data.get("features", [])
            yield from feats
            offset += len(feats)
            # exceededTransferLimit is set on GeoJSON as properties.exceededTransferLimit
            more = data.get("exceededTransferLimit") or (
                data.get("properties") or {}).get("exceededTransferLimit")
            if not feats or (not more and len(feats) < page_size):
                break
            log.info("%s: %d rows so far", layer.code, offset)
