import os

try:
    import PyPDF2
except ImportError:
    PyPDF2 = None


def extract_pdf_content(file_path):
    """Extract text from a PDF, returning (text, error)."""
    if PyPDF2 is None:
        return None, "PyPDF2 not installed"
    try:
        if not os.path.exists(file_path):
            return None, "File not found"
        if not file_path.lower().endswith(".pdf"):
            return None, "Invalid file format"

        with open(file_path, "rb") as pdf_file:
            reader = PyPDF2.PdfReader(pdf_file)
            if not reader.pages:
                return None, "PDF is empty"
            text = "\n".join(page.extract_text() or "" for page in reader.pages)
        return text, None
    except Exception as error:
        return None, f"PDF extraction error: {error}"
