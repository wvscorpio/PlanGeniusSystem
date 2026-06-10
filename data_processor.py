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

def extract_assignment_info(pdf_text):
    if not pdf_text or not isinstance(pdf_text, str):
        return None, "No text content to process"
    
    info = {
        "title": None,
        "due_date": None,
        "course_code": None,
        "assessment_type": "Assignment", # Default
        "subtasks": [],
        "description": None,
        "confidence": 0
    }
    
    course_pattern = r"\b[A-Z]{3,4}\s*\d{3,5}\b" 
    date_pattern = r"(?:due|deadline|submit)[\s:]*(\d{1,2}[-/]\d{1,2}[-/]\d{2,4}|\d{1,2}\s+[A-Za-z]+\s+\d{4})"
    title_pattern = r"(Assignment\s*\d*|Group Project|Project|Lab\s*\d*|Quiz\s*\d*|Test\s*\d*)"
    subtask_pattern = r"^\s*(?:[a-z]\)|\d+\.|[•\-\*]|i{1,3}\))\s+(.+)"

    lines = pdf_text.split("\n")
    lower_text = pdf_text.lower()
    
    # Extract Assessment Type based on global keywords
    if "quiz" in lower_text: info["assessment_type"] = "Quiz"
    elif "test " in lower_text or "exam" in lower_text: info["assessment_type"] = "Test"
    elif "lab " in lower_text or "laboratory" in lower_text: info["assessment_type"] = "Lab"
    elif "project" in lower_text: info["assessment_type"] = "Project"

    for i, line in enumerate(lines):
        if not info["course_code"]:
            course_match = re.search(course_pattern, line, re.IGNORECASE)
            if course_match:
                info["course_code"] = course_match.group().upper().replace(" ", "")
                info["confidence"] += 25
                
        if not info["title"]:
            title_match = re.search(title_pattern, line, re.IGNORECASE)
            if title_match:
                info["title"] = title_match.group().strip()
                info["confidence"] += 25
                
        if not info["due_date"]:
            nearby_text = "\n".join(lines[i:i+4])
            date_match = re.search(date_pattern, nearby_text, re.IGNORECASE)
            if date_match:
                info["due_date"] = date_match.group(1).strip()
                info["confidence"] += 25
                
        subtask_match = re.search(subtask_pattern, line, re.IGNORECASE)
        if subtask_match:
            info["subtasks"].append(subtask_match.group(1).strip())

    if info["subtasks"]: info["confidence"] += 25
        
    if not info["title"] and lines:
        non_empty = [l.strip() for l in lines if l.strip()]
        if non_empty: info["title"] = non_empty[0][:100]

    info["description"] = pdf_text[:500].strip()
    return info, None

def process_pdf(file_path):
    text, error = extract_pdf_content(file_path)
    if error: return None, error
    return extract_assignment_info(text)

if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == '--extract-pdf':
        if len(sys.argv) == 3:
            file_path = sys.argv[2]
            info, error = process_pdf(file_path)
            if error:
                print(json.dumps({"success": False, "error": error}))
            else:
                print(json.dumps({"success": True, "data": info}))
        else:
            print(json.dumps({"success": False, "error": "Missing file path"}))