"""
AI Tutor OCR Processor — Multiprocessing Edition
Tesseract + pdf2image với xử lý đa luồng theo từng trang PDF.

Thay đổi so với phiên bản cũ:
  - Thêm ocr_page(): hàm OCR 1 trang riêng lẻ, dùng được bởi Pool worker.
  - Thêm ocr_pdf_parallel(): OCR tất cả trang song song bằng multiprocessing.Pool.
  - Thêm --workers / --dpi CLI arguments để PHP có thể điều chỉnh từ bên ngoài.
  - Fallback về xử lý tuần tự nếu multiprocessing không khởi tạo được (Windows edge case).
  - Tất cả kết quả được ghép lại theo đúng thứ tự trang (không bị lộn).

Giao tiếp với PHP (document_parser.php):
  python ocr_processor.py <pdf_path> [--workers N] [--dpi DPI] [--lang LANG]
  Output: văn bản UTF-8 ra stdout, lỗi ra stderr.
"""

import sys
import os
import argparse
import traceback
from multiprocessing import Pool, cpu_count

import pytesseract
from pdf2image import convert_from_path
from PIL import Image

# ─── Cấu hình đường dẫn cố định ────────────────────────────────────────────
pytesseract.pytesseract.tesseract_cmd = r'C:\Program Files\Tesseract-OCR\tesseract.exe'
POPPLER_PATH = r'D:\Study\DATN\poppler-25.12.0\Library\bin'


# ─── Hàm OCR 1 trang (phải là top-level function để Pool có thể pickle) ────
def ocr_page(args: tuple) -> tuple[int, str]:
    """
    OCR một trang ảnh đơn lẻ.
    Phải là top-level function (không phải lambda, không phải nested function)
    vì multiprocessing.Pool dùng pickle để truyền function sang worker process.

    Args:
        args: (page_index, image_bytes, lang, dpi)
            - page_index : int  — chỉ số trang (để giữ thứ tự khi ghép lại)
            - image_path : str  — đường dẫn ảnh tạm trên disk
            - lang       : str  — ngôn ngữ Tesseract (vd: 'vie+eng')

    Returns:
        (page_index, text) — tuple để sort lại theo đúng thứ tự trang.
    """
    page_index, image_path, lang = args
    try:
        img = Image.open(image_path)
        # PSM 6: giả định text là block đồng nhất — tốt cho tài liệu học thuật
        custom_config = r'--oem 3 --psm 6'
        text = pytesseract.image_to_string(img, lang=lang, config=custom_config)
        img.close()
        return (page_index, text)
    except Exception as e:
        # Worker process ghi lỗi vào stderr của nó, process cha nhận qua pipe
        print(f"[OCR Worker] Trang {page_index} lỗi: {e}", file=sys.stderr)
        return (page_index, "")


def save_pages_to_temp(images, temp_dir: str) -> list[str]:
    """
    Lưu danh sách PIL Image ra file tạm để có thể truyền path cho worker.
    Pool worker không thể nhận PIL Image trực tiếp qua pickle một cách hiệu quả
    với ảnh lớn (>300 DPI) nên dùng disk làm trung gian.
    """
    paths = []
    for i, img in enumerate(images):
        path = os.path.join(temp_dir, f"page_{i:04d}.png")
        img.save(path, format="PNG")
        paths.append(path)
    return paths


def ocr_pdf_parallel(file_path: str, workers: int, dpi: int, lang: str) -> str:
    """
    OCR toàn bộ file PDF bằng multiprocessing.Pool.

    Quy trình:
      1. pdf2image chuyển tất cả trang → danh sách PIL Image (I/O bound, 1 thread đủ)
      2. Lưu từng trang ra file .png tạm (để worker process đọc được)
      3. Pool.map() phân phối các trang cho N worker processes
      4. Thu thập kết quả, sort theo page_index, ghép thành text
      5. Dọn dẹp file tạm

    Args:
        file_path : đường dẫn file PDF
        workers   : số worker processes (mặc định = số CPU logic / 2)
        dpi       : độ phân giải render PDF → ảnh (150 đủ cho tài liệu in, 200 cho scan)
        lang      : ngôn ngữ Tesseract

    Returns:
        Toàn bộ nội dung text đã ghép theo thứ tự trang.
    """
    import tempfile
    import shutil

    temp_dir = tempfile.mkdtemp(prefix="ai_tutor_ocr_")
    try:
        # Bước 1: Chuyển PDF → ảnh (render tất cả trang 1 lần)
        print(f"[OCR] Đang render PDF ({dpi} DPI)...", file=sys.stderr)
        images = convert_from_path(
            file_path,
            dpi=dpi,
            poppler_path=POPPLER_PATH,
            thread_count=2,   # pdf2image cũng hỗ trợ multi-thread render
        )
        total_pages = len(images)
        print(f"[OCR] Tổng số trang: {total_pages}, workers: {workers}", file=sys.stderr)

        # Bước 2: Lưu ảnh ra disk để worker process đọc
        image_paths = save_pages_to_temp(images, temp_dir)
        # Giải phóng PIL images khỏi RAM sau khi đã lưu ra disk
        del images

        # Bước 3: Tạo danh sách args cho từng trang
        task_args = [(i, image_paths[i], lang) for i in range(total_pages)]

        # Bước 4: Chạy song song
        # Dùng 'spawn' context trên Windows để tránh lỗi fork không có
        # (Windows không có os.fork(), Pool mặc định dùng spawn)
        results = []
        try:
            with Pool(processes=workers) as pool:
                results = pool.map(ocr_page, task_args)
        except Exception as pool_err:
            # Fallback: xử lý tuần tự nếu Pool thất bại
            print(f"[OCR] Pool thất bại ({pool_err}), fallback tuần tự...", file=sys.stderr)
            results = [ocr_page(args) for args in task_args]

        # Bước 5: Ghép kết quả theo thứ tự trang
        results.sort(key=lambda x: x[0])
        full_text = "\n".join(text for _, text in results)

        print(f"[OCR] Hoàn thành. Tổng ký tự: {len(full_text)}", file=sys.stderr)
        return full_text

    finally:
        # Dọn dẹp thư mục tạm dù có lỗi hay không
        shutil.rmtree(temp_dir, ignore_errors=True)


def ocr_pdf_sequential(file_path: str, dpi: int, lang: str) -> str:
    """
    Fallback: OCR tuần tự (giống phiên bản cũ) — dùng khi workers=1 hoặc lỗi.
    """
    images = convert_from_path(file_path, dpi=dpi, poppler_path=POPPLER_PATH)
    full_text = ""
    for i, img in enumerate(images):
        text = pytesseract.image_to_string(img, lang=lang, config=r'--oem 3 --psm 6')
        full_text += text + "\n"
        print(f"[OCR] Trang {i + 1}/{len(images)} xong", file=sys.stderr)
    return full_text


# ─── Entry point (được PHP gọi qua shell_exec / proc_open) ──────────────────
if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="AI Tutor OCR Processor")
    parser.add_argument("pdf_path", help="Đường dẫn đến file PDF cần xử lý")
    parser.add_argument(
        "--workers",
        type=int,
        default=0,
        help="Số worker processes (0 = tự động = max(1, cpu_count//2))",
    )
    parser.add_argument(
        "--dpi",
        type=int,
        default=150,
        help="Độ phân giải render PDF (150=nhanh, 200=cân bằng, 300=chất lượng cao)",
    )
    parser.add_argument(
        "--lang",
        type=str,
        default="vie+eng",
        help="Ngôn ngữ Tesseract (vd: vie+eng, vie, eng)",
    )
    args = parser.parse_args()

    # Xác định số worker tự động nếu không truyền vào
    if args.workers <= 0:
        # Dùng tối đa nửa số CPU để không chiếm hết tài nguyên máy chủ
        args.workers = max(1, cpu_count() // 2)

    if not os.path.isfile(args.pdf_path):
        print(f"Lỗi: Không tìm thấy file '{args.pdf_path}'", file=sys.stderr)
        sys.exit(1)

    try:
        if args.workers == 1:
            # Không cần Pool overhead nếu chỉ có 1 worker
            result = ocr_pdf_sequential(args.pdf_path, args.dpi, args.lang)
        else:
            result = ocr_pdf_parallel(args.pdf_path, args.workers, args.dpi, args.lang)

        # Ghi kết quả ra stdout để PHP đọc
        print(result)

    except Exception as e:
        print(f"Lỗi nghiêm trọng: {e}", file=sys.stderr)
        traceback.print_exc(file=sys.stderr)
        sys.exit(1)