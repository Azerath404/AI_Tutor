# 🤖 Moodle AI Tutor - Trợ lý học tập thích ứng thế hệ mới

<p align="center">
  <img src="https://img.shields.io/badge/Moodle-4.x%2B-orange?style=for-the-badge&logo=moodle" alt="Moodle Version">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php" alt="PHP Version">
  <img src="https://img.shields.io/badge/Ollama-Local%20LLM-white?style=for-the-badge&logo=ollama" alt="Ollama">
  <img src="https://img.shields.io/badge/License-Academic-blue?style=for-the-badge" alt="License">
</p>

---

## 🌟 Giới thiệu
**AI Tutor** là một Block Plugin đột phá cho Moodle LMS, giúp chuyển đổi các tài liệu học thuật tĩnh thành một "kho tri thức sống". Sinh viên có thể tương tác, đặt câu hỏi và nhận câu trả lời tức thì dựa trên chính tài liệu mà giảng viên đã tải lên khóa học.

> [!IMPORTANT]
> **Quyền riêng tư là ưu tiên hàng đầu:** Hệ thống vận hành hoàn toàn cục bộ (Local), dữ liệu học thuật không bao giờ rời khỏi máy chủ của nhà trường.

---

## 🚀 Tính năng cốt lõi

### 🧠 Trí tuệ nhân tạo cục bộ (Local LLM)
* Tích hợp **Ollama API** để vận hành các mô hình mạnh mẽ như Llama 3.2, Mistral.
* Không tốn chi phí API Cloud, bảo mật dữ liệu tuyệt đối.

### ⚡ Kiến trúc RAG hướng sự kiện (Event-driven RAG)
* **Real-time Sync:** Tự động nạp dữ liệu ngay khi giảng viên thêm/sửa tài liệu nhờ Moodle Event Observer.
* **Ad-hoc Tasks:** Xử lý các tác vụ nặng (OCR, Chunking) dưới nền, không gây lag cho người dùng.

### 🔍 Trích xuất văn bản lai (Hybrid OCR)
* Kết hợp **PdfParser** (tốc độ cao) và **Tesseract OCR** (xử lý ảnh quét).
* Đảm bảo AI "đọc" được cả những file PDF cũ hoặc tài liệu chỉ có hình ảnh.

### 💬 Trải nghiệm người dùng mượt mà
* **Streaming Response:** Phản hồi hiển thị dạng luồng (từng chữ) qua SSE (Server-Sent Events).
* **Contextual Memory:** Ghi nhớ ngữ cảnh hội thoại để hỗ trợ các câu hỏi tiếp nối.

---

## 🛠 Kiến trúc hệ thống



```text
ai_tutor/
├── 📂 classes/             # Tầng xử lý logic (Core)
│   ├── ⚙️ task/            # Ad-hoc & Scheduled tasks
│   ├── 📄 document_parser  # Hybrid OCR Engine
│   ├── 🤖 llm_client       # Ollama API Connection
│   ├── 📡 observer         # Event Listening
│   └── 🏗️ rag_engine       # Full-text Search Engine
├── 📂 db/                  # Database Schema (XMLDB)
├── 📂 scripts/             # Python OCR Processor


├── 📂 lang/               # Đa ngôn ngữ (en, vi)
└── 📄 ajax.php             # SSE Streaming Controller
