import re
from datetime import datetime


_MALAY_MONTHS = {
    "mac": "Mar",
    "mei": "May",
    "ogos": "Aug",
    "okt": "Oct",
    "dis": "Dec",
    "januari": "January",
    "februari": "February",
    "april": "April",
    "jun": "June",
    "julai": "July",
    "ogos": "August",
    "september": "September",
    "oktober": "October",
    "november": "November",
    "disember": "December",
}

_DAY_NAMES = (
    "mon|tue|wed|thu|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|"
    "saturday|sunday|isnin|selasa|rabu|khamis|jumaat|sabtu|ahad"
)


def format_date_to_iso(date_str):
    """Parse a supported date string and return YYYY-MM-DD, or an empty string."""
    if not isinstance(date_str, str) or not date_str.strip():
        return ""

    clean_date = date_str.strip()
    clean_date = re.sub(r"(?<=\d)(st|nd|rd|th)\b", "", clean_date, flags=re.IGNORECASE)
    clean_date = re.sub(rf"\b(?:{_DAY_NAMES})\b", " ", clean_date, flags=re.IGNORECASE)
    clean_date = re.sub(r"[()]", " ", clean_date).replace(",", " ")

    for malay, english in _MALAY_MONTHS.items():
        clean_date = re.sub(rf"\b{malay}\b", english, clean_date, flags=re.IGNORECASE)

    clean_date = re.sub(r"\s+", " ", clean_date).strip()
    formats = (
        "%Y-%m-%d", "%Y/%m/%d", "%Y.%m.%d",
        "%d/%m/%Y", "%d-%m-%Y", "%d.%m.%Y",
        "%d/%m/%y", "%d-%m-%y", "%d.%m.%y",
        "%d %B %Y", "%d %b %Y", "%d-%B-%Y", "%d-%b-%Y",
        "%b %d %Y", "%B %d %Y",
    )

    for date_format in formats:
        try:
            return datetime.strptime(clean_date, date_format).strftime("%Y-%m-%d")
        except ValueError:
            continue
    return ""


def format_date_to_display(date_str):
    """Return a human-readable date, or an empty string when parsing fails."""
    iso_date = format_date_to_iso(date_str)
    if not iso_date:
        return ""
    return datetime.strptime(iso_date, "%Y-%m-%d").strftime("%B %d, %Y")
