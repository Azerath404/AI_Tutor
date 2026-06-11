import asyncio
import time
import json
import os
from playwright.async_api import async_playwright

# --- CẤU HÌNH THÔNG SỐ TEST ---
MOODLE_URL = "http://localhost/moodle/course/view.php?id=3"
LOGIN_URL = "http://localhost/moodle/login/index.php"
USERNAME = "admin"  # Thay bằng user của Long
PASSWORD = "Azer@th64131209"        # Thay bằng pass của Long

# Selector (Long hãy kiểm tra chính xác ID trong mã nguồn Block của mình)
BLOCK_SELECTOR = ".block_ai_tutor" 
INPUT_SELECTOR = "#ai-question"
SEND_BUTTON = "#ai-btn-send"
RESPONSE_SELECTOR = ".ai-reply-content"

PROMPTS = [
    "Dựa trên tài liệu Chương 2 về Hàm, ở phần Đệ quy, hãy cho biết ví dụ về hàm tính giai thừa int GiaiThua(int n) được viết như thế nào trong slide? Cho biết cả 2 cách viết có điều kiện if khác nhau ra sao.",
    "Trong tài liệu KTLT_C02.3_HamNangCao.pdf, phần hàm trả về tham chiếu có đưa ra một số chú ý về lỗi SAI khi sử dụng biến cục bộ. Hãy chỉ ra đoạn code ví dụ bị sai đó và cách sửa đúng.",
    "Hãy tìm lỗi sai trong đoạn lệnh sau đây được trích từ phần bài tập của chương con trỏ nâng cao: int x[3][12]; int *ptr[12]; ptr = x;",
    "Trong chương Xử lý tập tin (file KTLT_C05_TapTin.pdf), bài tập thực hành số 15 và 16 yêu cầu viết chương trình xử lý những bài toán cụ thể nào và ghi kết quả đi đâu?",
    "Theo chương Kỹ thuật lập trình đệ quy, khi phân tích giải thuật và khử đệ quy, chúng ta thường sử dụng những công cụ hay cấu trúc nào để đưa bài toán đệ quy về bài toán không sử dụng đệ quy?"
]

async def run_benchmark():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        # Quan trọng: Tạo context để giữ Session đăng nhập
        context = await browser.new_context()
        page = await context.new_page()

        print("--- 1. ĐĂNG NHẬP MOODLE ---")
        await page.goto(LOGIN_URL)
        await page.fill("#username", USERNAME)
        await page.fill("#password", PASSWORD)
        
        # Click đăng nhập và đợi quá trình xác thực hoàn tất
        await asyncio.gather(
            page.click("#loginbtn"),
            page.wait_for_load_state("networkidle") 
        )

        print(f"--- 2. ĐI THẲNG VÀO KHÓA HỌC ID=3 ---")
        # Thay vì tìm trong My Courses, ta ép URL để tránh sai sót điều hướng
        await page.goto(MOODLE_URL)
        await page.wait_for_load_state("domcontentloaded")

        results = []
        for i, prompt in enumerate(PROMPTS):
            # Kiểm tra xem Block AI Tutor đã xuất hiện chưa
            try:
                await page.wait_for_selector(BLOCK_SELECTOR, timeout=5000)
            except:
                print("Lỗi: Không tìm thấy Block AI Tutor trong khóa học này!")
                break

            run_type = "Cold Start" if i == 0 else "Hot Start"
            start_time = time.perf_counter()

            # Lấy số lượng reply hiện tại
            num_replies_before = await page.evaluate("document.querySelectorAll('.ai-reply-content').length")

            start_time = time.perf_counter()

            # Thực hiện gửi Prompt
            await page.fill(INPUT_SELECTOR, prompt)
            await page.click(SEND_BUTTON)

            # Chờ DOM render div chứa 'Đang xử lý tài liệu...'
            await page.wait_for_function(
                f"document.querySelectorAll('.ai-reply-content').length > {num_replies_before}"
            )

            # Đợi cho đến khi nhận được toàn bộ câu trả lời (kết thúc luồng stream)
            await page.wait_for_function(
                '''() => {
                    const els = document.querySelectorAll('.ai-reply-content');
                    if (els.length === 0) return false;
                    const last = els[els.length - 1];
                    return last.dataset.status === 'done' || last.dataset.status === 'error';
                }''',
                timeout=300000 # Đợi tối đa 300s (5 phút) cho Cold Start
            )
            
            end_time = time.perf_counter()
            latency = end_time - start_time

            results.append({
                "run": i + 1,
                "type": run_type,
                "latency": round(latency, 2),
                "prompt": prompt
            })
            print(f"Lượt {i+1} ({run_type}): {latency:.2f}s")
            
            # Xóa nội dung cũ để chuẩn bị cho lượt test tiếp theo (nếu cần)
            # await page.evaluate(f"document.querySelector('{RESPONSE_SELECTOR}').innerText = ''")
            await asyncio.sleep(3)

        # --- 3. XUẤT KẾT QUẢ ---
        output_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "results")
        os.makedirs(output_dir, exist_ok=True)
        output_path = os.path.join(output_dir, "benchmark.json")
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(results, f, ensure_ascii=False, indent=4)

        await browser.close()


if __name__ == "__main__":
    asyncio.run(run_benchmark())