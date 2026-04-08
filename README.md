🎓 Moodle AI Tutor Plugin (Block)
AI Tutor là một plugin dạng Block dành cho nền tảng Moodle LMS, được thiết kế để trở thành trợ lý học tập thông minh cho sinh viên. Hệ thống sử dụng mô hình ngôn ngữ lớn (LLM) chạy cục bộ để giải đáp thắc mắc dựa trên chính nội dung tài liệu mà giảng viên cung cấp trong khóa học.

✨ Tính năng nổi bật
Local LLM Integration: Kết nối với Ollama API để chạy các mô hình (Llama 3.2, Mistral...) ngay trên hạ tầng nội bộ, đảm bảo quyền riêng tư và bảo mật dữ liệu.

Event-driven RAG: Tự động hóa việc nạp tri thức. Hệ thống lắng nghe sự kiện Moodle (thêm/sửa/xóa tài liệu) để cập nhật cơ sở dữ liệu tri thức ngay lập tức.

Hybrid OCR Extraction: Trích xuất văn bản thông minh. Kết hợp giữa PdfParser (tốc độ cao) và Tesseract OCR (xử lý ảnh quét) để đọc mọi loại tài liệu PDF.

Real-time Streaming: Giao tiếp qua Server-Sent Events (SSE) giúp phản hồi của AI hiển thị dưới dạng luồng (streaming) giống như ChatGPT, giảm thời gian chờ đợi.

Contextual Memory: AI có khả năng ghi nhớ lịch sử hội thoại trong phiên làm việc để hiểu các câu hỏi nối tiếp.

Enterprise Deploy: Hỗ trợ CLI script để triển khai Block hàng loạt cho hàng ngàn khóa học chỉ với một câu lệnh.

📂 Cấu trúc thư mục dự án
Plaintext
ai_tutor/
├── classes/                    # Tầng xử lý logic cốt lõi (Core Logic)
│   ├── task/                   # Các tác vụ chạy ngầm (Scheduled/Ad-hoc Tasks)
│   │   └── process_course_documents.php
│   ├── document_parser.php     # Xử lý trích xuất văn bản và Hybrid OCR
│   ├── llm_client.php          # Client giao tiếp API Ollama (xử lý Buffer)
│   ├── observer.php            # Bộ lắng nghe sự kiện (Event Observer) từ Moodle
│   ├── rag_engine.php          # Công cụ truy vấn ngữ cảnh (Full-text Search SQL)
│   ├── repository.php          # Quản lý tương tác CSDL và Logs
│   └── service.php             # Service layer điều phối luồng RAG & LLM
├── cli/                        # Công cụ dòng lệnh dành cho Admin
│   └── force_add_ai_tutor.php  # Script triển khai plugin hàng loạt
├── db/                         # Cấu trúc CSDL và cấu hình hệ thống
│   ├── events.php              # Đăng ký các sự kiện Moodle cần lắng nghe
│   ├── install.xml             # Định nghĩa bảng biểu (Chunks, Logs, Deps)
│   ├── tasks.php               # Cấu hình các tác vụ Ad-hoc/Cron
│   └── upgrade.php             # Xử lý nâng cấp phiên bản CSDL
├── lang/                       # Đa ngôn ngữ (Tiếng Anh/Tiếng Việt)
│   └── en/
├── scripts/                    # Kịch bản bổ trợ ngoài PHP
│   └── ocr_processor.py        # Xử lý OCR hình ảnh bằng Python & Tesseract
├── vendor/                     # Các thư viện phụ thuộc (Composer)
├── ajax.php                    # Controller xử lý luồng Streaming SSE
├── block_ai_tutor.php          # File khởi tạo và cấu hình hiển thị Block
├── manage_links.php            # Giao diện quản lý liên kết môn học (Cross-course)
├── settings.php                # Trang cấu hình Admin (Ollama URL, Model, v.v.)
└── version.php                 # Thông tin phiên bản Plugin
🛠 Yêu cầu hệ thống
Moodle: Phiên bản 4.x trở lên.

PHP: 8.0 hoặc cao hơn (yêu cầu thư viện curl, mbstring, xml).

Database: MariaDB 10.4+ hoặc MySQL 8.0+ (hỗ trợ Full-text Search).

Ollama: Đã cài đặt và vận hành (mặc định: http://localhost:11434).

Python 3: (Tùy chọn) Để chạy tính năng OCR nếu tài liệu là ảnh quét.

🚀 Hướng dẫn cài đặt
Tải mã nguồn: Copy thư mục ai_tutor vào đường dẫn {moodle_root}/blocks/.

Cài đặt thư viện: Chạy composer install trong thư mục ai_tutor để cài đặt các thư viện cần thiết (như Smalot/PdfParser).

Cài đặt Plugin:

Truy cập vào trang quản trị Moodle: Site administration > Notifications.

Nhấn Upgrade Moodle database now để hệ thống tạo các bảng cần thiết.

Cấu hình AI:

Vào Site administration > Plugins > Blocks > AI Tutor.

Điền Ollama URL và chọn Model (ví dụ: llama3.2).

Cấu hình OCR (Nếu dùng): Đảm bảo máy chủ đã cài đặt Tesseract OCR và thư viện Python pytesseract.

📖 Hướng dẫn sử dụng
Dành cho giảng viên
Bật chế độ chỉnh sửa khóa học, thêm Block AI Tutor.

Tài liệu PDF upload lên khóa học sẽ tự động được AI nạp vào kho tri thức (thông qua Cron/Ad-hoc task).

Dành cho sinh viên
Nhấp vào biểu tượng Chat trên Block AI Tutor ở thanh bên phải.

Đặt câu hỏi liên quan đến bài học. AI sẽ trích dẫn nguồn tài liệu cụ thể trong câu trả lời.

⚖️ Giấy phép
Dự án được phát triển phục vụ mục đích nghiên cứu và giáo dục tại Trường Đại học Nha Trang. Vui lòng tuân thủ các quy định về bảo mật dữ liệu nội bộ khi triển khai.

Author: Huỳnh Ngọc Long - NTU Student.