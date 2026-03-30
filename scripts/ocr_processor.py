import sys
import pytesseract
from pdf2image import convert_from_path

# Cấu hình đường dẫn tesseract nếu cần
pytesseract.pytesseract.tesseract_cmd = r'C:\Program Files\Tesseract-OCR\tesseract.exe'
POPPLER_PATH = r'D:\Study\DATN\poppler-25.12.0\Library\bin'
def ocr_pdf(file_path):
    try:
        # Chuyển PDF thành danh sách ảnh
        images = convert_from_path(file_path, poppler_path=POPPLER_PATH)
        full_text = ""
        for img in images:
            # Nhận diện chữ từ ảnh (Hỗ trợ tiếng Việt)
            text = pytesseract.image_to_string(img, lang='vie+eng')
            full_text += text + "\n"
        return full_text
    except Exception as e:
        return str(e)

if __name__ == "__main__":
    pdf_path = sys.argv[1]
    print(ocr_pdf(pdf_path))