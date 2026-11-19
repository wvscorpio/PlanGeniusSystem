import json
import re
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from utils.date_utils import format_date_to_display, format_date_to_iso
from utils.pdf_utils import extract_pdf_content


def format_time_str(time_str):
    """Convert common time strings to a 12-hour display format."""
    if not time_str:
        return None

    clean_time = time_str.strip().replace(".", ":")
    for time_format in ("%I:%M %p", "%I:%M%p", "%I %p", "%I%p", "%H:%M", "%H"):
        try:
            return datetime.strptime(clean_time.upper(), time_format).strftime("%I:%M %p")
        except ValueError:
            continue
    return time_str


def get_default_milestones():
    return [
        "Read Assignment Requirements",
        "Complete Implementation",
        "Review Solution",
        "Submit Assignment",
    ]


def clean_task_description(text):
    if not text:
        return ""
    cleaned = re.sub(r"^\[(x|X|\s)?\]\s*", "", text.strip())
    cleaned = re.sub(r"^(?:[-*•>]|(?:\d+|[a-zA-Z]|\b[ivx]+\b)[.)])\s*", "", cleaned)
    cleaned = re.sub(
        r"\s+(?:before\s+or\s+at|due\s+by|due\s+on|due\s+at|deadline[:\s])\s*$",
        "",
        cleaned,
        flags=re.IGNORECASE,
    )
    cleaned = re.sub(r"\s+Section\s+\d+:.*$", "", cleaned, flags=re.IGNORECASE)
    return cleaned.strip()


def extract_milestones(pdf_text):
    """Extract actionable student subtasks while skipping informational sections."""
    if not pdf_text or not pdf_text.strip():
        return get_default_milestones()

    task_action_verbs = {
        "read", "submit", "list", "relate", "refer", "design", "implement",
        "develop", "create", "build", "write", "calculate", "solve", "construct",
        "configure", "test", "analyze", "draw", "draft", "setup", "evaluate",
        "compare", "identify", "prepare", "model", "compute", "produce", "code",
        "modify", "debug",
    }
    info_section_keywords = (
        "objective", "objectives", "learning outcome", "learning outcomes",
        "reference", "references", "overview", "background", "instructor",
        "duration", "marking criteria", "rubric", "prerequisite", "prerequisites",
        "formatting", "documents",
    )
    task_section_keywords = (
        "instruction", "instructions", "task", "tasks", "exercise", "exercises",
        "practical", "deliverables",
    )
    bullet_pattern = re.compile(r"^(?:[-*•>]|(?:\d+|[a-zA-Z]|\b[ivx]+\b)[.)])\s*(.+)$")
    extracted_subtasks = []
    current_section = "general"

    for line in (line.strip() for line in pdf_text.splitlines()):
        if not line:
            continue
        line_lower = line.lower()
        if len(line_lower) < 50:
            if any(re.search(rf"\b{re.escape(keyword)}\b", line_lower) for keyword in info_section_keywords):
                current_section = "info"
                continue
            if any(re.search(rf"\b{re.escape(keyword)}\b", line_lower) for keyword in task_section_keywords):
                current_section = "task"
                continue
        if current_section == "info" or re.search(r"^section\s*\d+\s*:", line_lower):
            continue

        bullet_match = bullet_pattern.match(line)
        content = bullet_match.group(1).strip() if bullet_match else line
        words = content.split()
        if not words:
            continue

        first_word = re.sub(r"[^a-zA-Z]", "", words[0]).lower()
        actionable = first_word in task_action_verbs
        if current_section == "task" and bullet_match:
            actionable = actionable or any(verb in line_lower for verb in task_action_verbs)

        if actionable and len(content) <= 250:
            cleaned_task = clean_task_description(content)
            if cleaned_task and not cleaned_task.lower().endswith("consists of:") and cleaned_task not in extracted_subtasks:
                extracted_subtasks.append(cleaned_task)

    return extracted_subtasks or get_default_milestones()


def extract_assignment_info(pdf_text):
    """Extract assignment information, dates, and subtasks from PDF text."""
    if not pdf_text or not isinstance(pdf_text, str):
        return None, "No text content to process"

    info = {
        "title": None,
        "due_date": None,
        "iso_date": "",
        "display_date": "",
        "due_time": None,
        "course_code": None,
        "assessment_type": "Assignment",
        "subtasks": [],
        "description": None,
        "confidence": 0,
    }
    lines = [line.strip() for line in pdf_text.splitlines() if line.strip()]
    full_text = "\n".join(lines)
    lower_text = full_text.lower()

    if "practical" in lower_text or "lab" in lower_text:
        info["assessment_type"] = "Lab"
    elif "quiz" in lower_text:
        info["assessment_type"] = "Quiz"
    elif "test " in lower_text or "exam" in lower_text or "midterm" in lower_text:
        info["assessment_type"] = "Test"
    elif "project" in lower_text:
        info["assessment_type"] = "Project"

    course_match = re.search(r"\b([A-Z]{3,4}\s*\d{3,5})\b", full_text)
    if course_match:
        info["course_code"] = course_match.group(1).upper().replace(" ", "")
        info["confidence"] += 20

    title_patterns = (
        r"^\s*((?:Practical|Lab|Assignment|Project|Exercise|Quiz)\s*\d*[.:\-\s]*[^\n]+)",
        r"^\s*([^\n]*(?:Practical|Assignment|Project|Lab Work)\s*\d*[.:\-\s]*[^\n]+)",
    )
    for line in lines:
        if info["course_code"] and info["course_code"] in line.replace(" ", ""):
            if not re.search(r"\b(Practical|Lab|Assignment|Project|Exercise|Quiz)\b", line, re.I):
                continue
        for pattern in title_patterns:
            title_match = re.search(pattern, line, re.IGNORECASE)
            if title_match and len(title_match.group(1).strip()) > 3:
                info["title"] = title_match.group(1).strip()
                info["confidence"] += 20
                break
        if info["title"]:
            break

    date_regex = (
        r"\b(\d{1,2}(?:st|nd|rd|th)?\s+(?:Jan|Feb|Mar|Mac|Apr|May|Mei|Jun|Jul|Aug|Ogos|Sep|Oct|Okt|Nov|Dec|Dis)[a-z]*\s+\d{4}|"
        r"\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4}|\d{4}[-/.]\d{1,2}[-/.]\d{1,2})\b"
    )
    time_regex = r"\b(\d{1,2}(?:[:.]\d{2})?\s*(?:AM|PM|am|pm))\b"
    parsed_deadlines = []
    for date_match in re.finditer(date_regex, full_text, re.IGNORECASE):
        raw_date = date_match.group(1)
        iso_date = format_date_to_iso(raw_date)
        if not iso_date:
            continue
        window = full_text[max(0, date_match.start() - 30):min(len(full_text), date_match.start() + 80)]
        time_match = re.search(time_regex, window, re.IGNORECASE)
        parsed_deadlines.append((iso_date, format_time_str(time_match.group(1)) if time_match else None))

    if parsed_deadlines:
        iso_date, due_time = sorted(parsed_deadlines, key=lambda item: item[0])[0]
        info["iso_date"] = iso_date
        info["display_date"] = format_date_to_display(iso_date)
        info["due_date"] = iso_date
        info["due_time"] = due_time
        info["confidence"] += 30

    info["subtasks"] = extract_milestones(pdf_text)
    if info["subtasks"]:
        info["confidence"] += 20
    if not info["title"] and lines:
        info["title"] = lines[0][:100]
    info["description"] = full_text[:500].strip()
    return info, None


def process_pdf(file_path):
    text, error = extract_pdf_content(file_path)
    if error:
        return None, error
    return extract_assignment_info(text)


def main(argv=None):
    args = sys.argv[1:] if argv is None else argv
    if len(args) != 2 or args[0] != "--extract-pdf":
        print(json.dumps({"error": "Missing file path"}))
        return 2

    info, error = process_pdf(args[1])
    if error:
        print(json.dumps({"success": False, "error": error}))
    else:
        print(json.dumps({"success": True, "data": info}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
