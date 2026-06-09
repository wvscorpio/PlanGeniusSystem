import json
import sys
from datetime import datetime

def check_status(start_date_str, end_date_str):
    try:
        start_date = datetime.strptime(start_date_str, '%Y-%m-%d')
        end_date = datetime.strptime(end_date_str, '%Y-%m-%d')
        today = datetime.now()
        
        if today > end_date:
            return "Expired"
        elif start_date <= today <= end_date:
            return "Active"
        else:
            return "Upcoming"
    except Exception as e:
        return "Unknown"

def generate_dashboard_data():
    data = {
        "stats": {
            "total_assignments": {"value": 0, "label": "completed"},
            "upcoming_exams": {"value": 0, "label": "In the next month"},
            "completion_rate": {"value": 0, "label": "%", "progress": 0},
            "study_hours": {"value": 0, "label": "Estimated total"}
        },
        "upcoming_assignments": [],
        "upcoming_exams_list": []
    }
    print(json.dumps(data))

if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == '--check-status':
        if len(sys.argv) == 4:
            status = check_status(sys.argv[2], sys.argv[3])
            print(json.dumps({"status": status}))
        else:
            print(json.dumps({"error": "Missing dates"}))
    else:
        generate_dashboard_data()
