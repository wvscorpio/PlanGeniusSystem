import json
import sys
from datetime import datetime

from processor.assignment_processor import extract_assignment_info, extract_milestones, process_pdf
from utils.date_utils import format_date_to_display, format_date_to_iso
from processor.exam_processor import extract_exam_data, parse_time_slot
from utils.pdf_utils import extract_pdf_content


format_date_to_html = format_date_to_iso


def parse_day(date_str):
    return format_date_to_iso(date_str) or date_str


def check_status(start_date_str, end_date_str):
    try:
        start_date = datetime.strptime(start_date_str, "%Y-%m-%d").date()
        end_date = datetime.strptime(end_date_str, "%Y-%m-%d").date()
        today = datetime.now().date()
        if today > end_date:
            return "Expired"
        if start_date <= today <= end_date:
            return "Active"
        return "Upcoming"
    except (TypeError, ValueError):
        return "Unknown"


def generate_dashboard_data():
    data = {
        "stats": {
            "total_assignments": {"value": 0, "label": "completed"},
            "upcoming_exams": {"value": 0, "label": "In the next month"},
            "completion_rate": {"value": 0, "label": "%", "progress": 0},
            "study_hours": {"value": 0, "label": "Estimated total"},
        },
        "upcoming_assignments": [],
        "upcoming_exams_list": [],
    }
    print(json.dumps(data))


def main(argv=None):
    args = sys.argv[1:] if argv is None else argv
    if args and args[0] == "--check-status":
        if len(args) == 3:
            print(json.dumps({"status": check_status(args[1], args[2])}))
        else:
            print(json.dumps({"error": "Missing dates"}))
        return 0

    if args and args[0] == "--extract-pdf":
        if len(args) == 2:
            from processor.assignment_processor import main as assignment_main
            return assignment_main(args)
        print(json.dumps({"error": "Missing file path"}))
        return 2

    if args and args[0] == "--extract-exam":
        if len(args) == 2:
            from processor.exam_processor import main as exam_main
            return exam_main(args)
        print(json.dumps({"error": "Missing file path"}))
        return 2

    generate_dashboard_data()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
