import json
import sys
import re
import os
from datetime import datetime

try:
    import PyPDF2
    PDF_SUPPORT = True
except ImportError:
    PDF_SUPPORT = False

def extract_pdf_content(file_path):
    """Extracts raw text content from the target PDF file."""
    if not PDF_SUPPORT: return None, "PyPDF2 not installed"
    try:
        if not os.path.exists(file_path): return None, "File not found"
        if not file_path.lower().endswith('.pdf'): return None, "Invalid file format"
            
        text = ""
        with open(file_path, 'rb') as file:
            reader = PyPDF2.PdfReader(file)
            if len(reader.pages) == 0: return None, "PDF is empty"
            for page in reader.pages:
                text += page.extract_text() + "\n"
        return text, None
    except Exception as e:
        return None, f"PDF extraction error: {str(e)}"

# --- NEW DATE CONVERSION HELPER ---
def format_date_to_html(date_str):
    """Converts a parsed date string into the strict YYYY-MM-DD format required by HTML5 inputs."""
    if not date_str: 
        return None
        
    # Remove 'st', 'nd', 'rd', 'th' suffix from day digits so Python datetime can read it cleanly
    clean_str = re.sub(r'(?<=\d)(st|nd|rd|th)\b', '', date_str, flags=re.IGNORECASE)
    clean_str = ' '.join(clean_str.split())
    
    # Supported human-written variations matching the regular expressions below
    formats = [
        "%d %B %Y",  # e.g., 22 April 2026
        "%d %b %Y",  # e.g., 22 Apr 2026
        "%d/%m/%Y",  # e.g., 22/04/2026
        "%d-%m-%Y",  # e.g., 22-04-2026
        "%d/%m/%y",  # e.g., 22/04/26
        "%d-%m-%y"   # e.g., 22-04-26
    ]
    
    for fmt in formats:
        try:
            return datetime.strptime(clean_str, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue
            
    # Fallback to raw string if conversion mapping matches nothing
    return date_str

def extract_assignment_info(pdf_text):
    """Extract assignment information from PDF text using line-by-line pattern matching."""
    if not pdf_text or not isinstance(pdf_text, str):
        return None, "No text content to process"
    
    info = {
        "title": None,
        "due_date": None,
        "course_code": None,
        "assessment_type": "Assignment", # Added back to support modern assignment tables
        "subtasks": [],
        "description": None,
        "confidence": 0
    }
    
    # Matches "BIE20303", "BIE 20303", or any 3-4 letters followed by digits
    course_pattern = r"\b[A-Z]{3,4}\s*\d{3,5}\b" 
    
    # FIXED: Pattern updated to cleanly catch ordinal indicators like 'nd' in "22nd April 2026"
    date_pattern = r"(?:due\s*date|due|deadline|submit|date|before)[\s:]*(\d{1,2}[-/]\d{1,2}[-/]\d{2,4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]+\s+\d{4})"
    
    # Title pattern looking for Assignments, Projects, Labs, Quizzes, or Tests
    title_pattern = r"(Assignment\s*\d*|Group Project|Project|Lab\s*\d*|Quiz\s*\d*|Test\s*\d*)"
    
    # Subtask pattern matches: a) b) i) ii) 1. 2. • - *
    subtask_pattern = r"^\s*(?:[a-z]\)|\d+\.|[•\-\*]|i{1,3}\))\s+(.+)"

    lines = pdf_text.split("\n")
    lower_text = pdf_text.lower()
    
    # Dynamically resolve default assessment type 
    if "quiz" in lower_text: info["assessment_type"] = "Quiz"
    elif "test " in lower_text or "exam" in lower_text: info["assessment_type"] = "Test"
    elif "lab " in lower_text or "laboratory" in lower_text: info["assessment_type"] = "Lab"
    elif "project" in lower_text: info["assessment_type"] = "Project"
    
    for i, line in enumerate(lines):
        
        # --- Extract Course Code ---
        if not info["course_code"]:
            course_match = re.search(course_pattern, line, re.IGNORECASE)
            if course_match:
                info["course_code"] = course_match.group().upper().replace(" ", "")
                info["confidence"] += 25
                
        # --- Extract Title ---
        if not info["title"]:
            title_match = re.search(title_pattern, line, re.IGNORECASE)
            if title_match:
                info["title"] = title_match.group().strip()
                info["confidence"] += 25
                
        # --- Extract Due Date using Context Window ---
        if not info["due_date"]:
            nearby_text = "\n".join(lines[i:i+4])
            date_match = re.search(date_pattern, nearby_text, re.IGNORECASE)
            if date_match:
                raw_extracted_date = date_match.group(1).strip()
                # FIXED: Formats raw extraction to standard HTML format here
                info["due_date"] = format_date_to_html(raw_extracted_date)
                info["confidence"] += 25
                
        # --- Extract Subtasks ---
        subtask_match = re.search(subtask_pattern, line, re.IGNORECASE)
        if subtask_match:
            info["subtasks"].append(subtask_match.group(1).strip())

    if info["subtasks"]:
        info["confidence"] += 25
        
    if not info["title"] and lines:
        non_empty = [l.strip() for l in lines if l.strip()]
        if non_empty:
            info["title"] = non_empty[0][:100]

    info["description"] = pdf_text[:500].strip()
    
    return info, None

def process_pdf(file_path):
    """Main function to process PDF and extract assignment info."""
    text, error = extract_pdf_content(file_path)
    if error:
        return None, error
    
    info, error = extract_assignment_info(text)
    if error:
        return None, error
    
    return info, None

# --- PRESERVED STATUS CHECKER ---
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

# --- PRESERVED DASHBOARD ENGINES ---
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
            
    elif len(sys.argv) > 1 and sys.argv[1] == '--extract-pdf':
        if len(sys.argv) == 3:
            file_path = sys.argv[2]
            info, error = process_pdf(file_path)
            if error:
                print(json.dumps({"success": False, "error": error}))
            else:
                print(json.dumps({"success": True, "data": info}))
        else:
            print(json.dumps({"error": "Missing file path"}))
            
    else:
        generate_dashboard_data()