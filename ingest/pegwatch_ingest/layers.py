"""SLIP layers the ingest pulls, and how each maps onto a staging table.

Layer ids are on the SLIP_Public_Services/Industry_and_Mining MapServer.
Field lists were read from the live service on 2026-11-22.
"""
from dataclasses import dataclass, field

SERVICE_URL = (
    "https://public-services.slip.wa.gov.au/public/rest/services/"
    "SLIP_Public_Services/Industry_and_Mining/MapServer"
)


@dataclass(frozen=True)
class Layer:
    key: str            # staging table suffix: stg_<key>
    layer_id: int
    code: str           # DMIRS dataset code, for logs and attribution
    oid_field: str
    extract_field: str | None = None
    # Optional SQL filter; {since} is replaced with a date literal.
    where: str = "1=1"
    fields: tuple[str, ...] = field(default_factory=tuple)


TENEMENTS = Layer(
    key="tenements", layer_id=3, code="DMIRS-003", oid_field="oid",
    extract_field="extract_da",
    fields=(
        "tenid", "fmt_tenid", "type", "survstatus", "tenstatus", "holdercnt",
        # addr1..9 are deliberately not fetched: individual holders' postal
        # addresses must never be shown or exported (Privacy Act s6D(4)).
        *[f"holder{i}" for i in range(1, 10)],
        "legal_area", "unit_of_me", "special_in", "extract_da",
        "grantdate", "granttime", "startdate", "starttime", "enddate", "endtime",
    ),
)

# Dead tenements: 423k rows in full. Nightly we only need recent deaths
# (for the death reason); the full history is a one-off load (--full-dead).
DEAD = Layer(
    key="dead", layer_id=15, code="DMIRS-026", oid_field="objectid",
    extract_field="extract_date",
    where="enddate >= date '{since}'",
    fields=(
        "tenid", "fmt_tenid", "type", "survstatus", "tenstatus", "deathreason",
        "holdercnt", "holdername", "startdate", "starttime", "enddate", "endtime",
        "grantdate", "granttime", "extract_date",
    ),
)

RELEASE_PENDING = Layer(
    key="release_pending", layer_id=19, code="DMIRS-030", oid_field="objectid",
    fields=("tenid", "fmt_tenid", "ten_type", "rlesid", "rles_order",
            "rles_status", "rles_stamp"),
)

AMALGAMATION_PENDING = Layer(
    key="amalgamation_pending", layer_id=20, code="DMIRS-031", oid_field="objectid",
    fields=("tenid", "fmt_tenid", "ten_type", "amalgid", "amalg_status",
            "start_stamp", "end_stamp", "ge_num"),
)

RESTORATION_PENDING = Layer(
    key="restoration_pending", layer_id=21, code="DMIRS-032", oid_field="objectid",
    fields=("tenid", "fmt_tenid", "ten_type", "restnid", "restn_status",
            "start_stamp", "end_stamp"),
)

_LAND_FIELDS = ("land_id", "type", "status", "purpose", "startdate", "enddate")

FILE_NOTATION = Layer(
    key="file_notation", layer_id=11, code="DMIRS-002", oid_field="objectid",
    extract_field="extract_da",
    fields=("land_id", "fmt_land_i", "type", "status", "official_n", "purpose",
            "startdate", "enddate", "extract_da"),
)
SECTION_19 = Layer(
    key="s19", layer_id=12, code="DMIRS-023", oid_field="objectid",
    extract_field="extract_date",
    fields=(*_LAND_FIELDS, "official_name", "extract_date"),
)
SECTION_57_4 = Layer(
    key="s57_4", layer_id=25, code="DMIRS-044", oid_field="objectid",
    extract_field="extract_date",
    fields=(*_LAND_FIELDS, "official_name", "extract_date"),
)
SECTION_57_2AA = Layer(
    key="s57_2aa", layer_id=26, code="DMIRS-045", oid_field="objectid",
    extract_field="extract_date",
    fields=(*_LAND_FIELDS, "official_name", "extract_date"),
)
CLEARANCE = Layer(
    key="clearance", layer_id=14, code="DMIRS-022", oid_field="oid",
    extract_field="extract_da",
    fields=("category", "name", "date_min_c", "extract_da"),
)

NIGHTLY = (
    TENEMENTS, DEAD, RELEASE_PENDING, AMALGAMATION_PENDING, RESTORATION_PENDING,
    FILE_NOTATION, SECTION_19, SECTION_57_4, SECTION_57_2AA, CLEARANCE,
)

# Fields whose values are epoch milliseconds in the REST output.
DATE_FIELDS = {
    "extract_da", "extract_date", "grantdate", "startdate", "enddate",
    "rles_stamp", "start_stamp", "end_stamp", "date_min_c",
}
