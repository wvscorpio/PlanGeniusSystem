import json
import re
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from utils.date_utils import format_date_to_display, format_date_to_iso
from utils.pdf_utils import extract_pdf_content


_WEEKDAYS = (
    "Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|"
    "Isnin|Selasa|Rabu|Khamis|Jumaat|Sabtu|Ahad"
)
_MONTHS = "Jan|Feb|Mar|Mac|Apr|May|Mei|Jun|Jul|Aug|Ogos|Sep|Oct|Okt|Nov|Dec|Dis"
_DATE_TOKEN = (
    rf"(?:\d{{1,2}}(?:st|nd|rd|th)?[./-](?:\d{{1,2}}|{_MONTHS})[./-]\d{{2,4}}"
    rf"|\d{{4}}[./-]\d{{1,2}}[./-]\d{{1,2}}"
    rf"|(?:{_MONTHS})\s+\d{{1,2}},?\s+\d{{2,4}})"
)
_DATE_PATTERN = re.compile(
    rf"(?:(?:{_WEEKDAYS})\b[,.]?\s*)?{_DATE_TOKEN}(?:\s*\(({_WEEKDAYS})\))?",
    re.IGNORECASE,
)
_COURSE_PATTERN = re.compile(r"\b([A-Z]{2,4}\s*\d{3,6})\b", re.IGNORECASE)
_TIME_RANGE_PATTERN = re.compile(
    r"(?<!\d)(\d{1,2}(?::\d{2}|\.\d{2})\s*(?:AM|PM)?|\d{1,2}\s*(?:AM|PM))\s*(?:-|–|—|\bto\b)\s*"
    r"(\d{1,2}(?::\d{2}|\.\d{2})\s*(?:AM|PM)?|\d{1,2}\s*(?:AM|PM))(?!\d)",
    re.IGNORECASE,
)
_LOCATION_STOP = (
    r"(?=\s+(?:\d{1,2}(?::|\.)\d{2}|\d{1,2}\s*(?:AM|PM)|\d+\s*H(?:our)?s?\b|"
    r"(?:date|tarikh|time|masa)\s*[:])|$)"
)
_LABELED_LOCATION_PATTERN = re.compile(
    r"\b(?:location|venue|hall|tempat|lokasi)\s*[:\-]?\s*([^\n,;]+?)" + _LOCATION_STOP,
    re.IGNORECASE,
)
_LOCATION_PATTERN = re.compile(
    r"\b((?:Dewan(?:\s+Kuliah)?|DK\s*\d*|Induk|Lab(?:oratory)?|Hall|Bilik|Online)"
    r"\s+[A-Z0-9][^\n,;]*?)" + _LOCATION_STOP,
    re.IGNORECASE,
)


def format_time_str(time_str):
    if not time_str:
        return ""
    clean_time = time_str.strip().upper().replace(".", ":")
    for time_format in ("%I:%M %p", "%I:%M%p", "%I %p", "%I%p", "%H:%M", "%H"):
        try:
            return datetime.strptime(clean_time, time_format).strftime("%I:%M %p")
        except ValueError:
            continue
    return ""


def parse_time_slot(time_str):
    if not time_str:
        return ""
    range_match = _TIME_RANGE_PATTERN.search(time_str.strip().upper())
    if not range_match:
        return format_time_str(time_str)

    start_raw, end_raw = range_match.groups()
    start_time = format_time_str(start_raw)
    end_time = format_time_str(end_raw)
    if not start_time or not end_time:
        return ""

    # If only one endpoint has a meridiem, use it to disambiguate the other endpoint.
    if re.search(r"(?:AM|PM)\b", end_raw, re.IGNORECASE) and not re.search(r"(?:AM|PM)\b", start_raw, re.IGNORECASE):
        start_time = format_time_str(f"{start_raw} {end_raw.strip()[-2:]}")
    elif re.search(r"(?:AM|PM)\b", start_raw, re.IGNORECASE) and not re.search(r"(?:AM|PM)\b", end_raw, re.IGNORECASE):
        end_time = format_time_str(f"{end_raw} {start_raw.strip()[-2:]}")
    return f"{start_time} - {end_time}"


def _record_context(lines, index):
    end = index + 1
    while end < len(lines) and end <= index + 6 and not _COURSE_PATTERN.search(lines[end]):
        end += 1
    return " ".join(lines[max(0, index - 1):end])


def _course_title(line, course_match, context):
    title_source = line[course_match.end():].strip(" \t:-–")
    if not title_source:
        return ""
    cut_points = [match.start() for pattern in (_DATE_PATTERN, _TIME_RANGE_PATTERN) if (match := pattern.search(title_source))]
    label_match = re.search(
        r"\b(?:section|sec|seksyen|group|kumpulan|date|tarikh|day/date|time|masa|location|venue|hall|lokasi|tempat)\b",
        title_source,
        re.IGNORECASE,
    )
    if label_match:
        cut_points.append(label_match.start())
    if cut_points:
        title_source = title_source[:min(cut_points)]

    section_match = re.search(r"(?<!\w)[A-Z]?\d{1,2}[A-Z]?\s*$", title_source, re.IGNORECASE)
    if section_match:
        title_source = title_source[:section_match.start()]
    return re.sub(r"\s+", " ", title_source).strip(" :-–").upper()


def _extract_section(context, line, course_match):
    explicit = re.search(
        r"\b(?:section|sec\.?|seksyen|group|kumpulan)\s*[:#-]?\s*([A-Z0-9]+)\b",
        context,
        re.IGNORECASE,
    )
    if explicit:
        return explicit.group(1)

    row_prefix = line[course_match.end():]
    date_match = _DATE_PATTERN.search(row_prefix)
    if date_match:
        before_date = row_prefix[:date_match.start()]
        trailing_section = re.search(r"(?<!\w)([A-Z]?\d{1,2}[A-Z]?)[\s:]*$", before_date, re.IGNORECASE)
        if trailing_section:
            return trailing_section.group(1)
    return ""


def _extract_location(context):
    labeled_match = _LABELED_LOCATION_PATTERN.search(context)
    if labeled_match:
        return re.sub(r"\s+", " ", labeled_match.group(1).strip())
    location_match = _LOCATION_PATTERN.search(context)
    if location_match:
        return re.sub(r"\s+", " ", location_match.group(1).strip())
    return ""


def extract_exam_data(pdf_text):
    """Extract exam timetable rows with ISO and display dates."""
    if not pdf_text or not pdf_text.strip():
        return [], "No text extracted from PDF"

    lines = [line.strip() for line in pdf_text.splitlines() if line.strip()]
    exams = []
    for index, line in enumerate(lines):
        course_match = _COURSE_PATTERN.search(line)
        if not course_match:
            continue

        context = _record_context(lines, index)
        raw_date_match = _DATE_PATTERN.search(context)
        raw_date = raw_date_match.group(0).strip() if raw_date_match else ""
        iso_date = format_date_to_iso(raw_date)
        time_match = _TIME_RANGE_PATTERN.search(context)
        time_slot = parse_time_slot(time_match.group(0)) if time_match else ""
        code = re.sub(r"\s+", "", course_match.group(1)).upper()
        title = _course_title(line, course_match, context)
        course = f"{code} - {title}" if title else code

        exams.append({
            "course": course,
            "section": _extract_section(context, line, course_match),
            "raw_date": raw_date,
            "iso_date": iso_date,
            "display_date": format_date_to_display(iso_date),
            "day": iso_date,
            "time_slot": time_slot,
            "location": _extract_location(context),
        })
    return exams, None


def process_pdf(file_path):
    text, error = extract_pdf_content(file_path)
    if error:
        return None, error
    return extract_exam_data(text)


def main(argv=None):
    args = sys.argv[1:] if argv is None else argv
    if len(args) != 2 or args[0] != "--extract-exam":
        print(json.dumps({"error": "Missing file path"}))
        return 2

    exams, error = process_pdf(args[1])
    if error:
        print(json.dumps({"success": False, "error": error}))
    else:
        print(json.dumps({"success": True, "exams": exams}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
