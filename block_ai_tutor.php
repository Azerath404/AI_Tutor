<?php
class block_ai_tutor extends block_base {
    public function init() {
        $this->title = get_string('pluginname', 'block_ai_tutor');
    }

    public function has_config() {
        return true;
    }

    public function get_content() {
        global $USER;
        
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;
        
        // URL AJAX send request to block's ajax.php
        // Sử dụng phương thức của Moodle để lấy URL chính xác kể cả khi cài trong thư mục con
        $ajaxUrl = new moodle_url('/blocks/ai_tutor/ajax.php');
        $ajaxUrlStr = $ajaxUrl->out(false); 
    
        $courseId = $this->page->course->id;
        $userId = $USER->id;
        $this->content->text = '
            <div style="padding:5px;">
                <div id="ai-chat-history" style="height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; background: #fff; border-radius: 5px;">
                    <div style="color: #666; font-style: italic; font-size: 0.9em; text-align: center;">Bắt đầu trò chuyện với AI Tutor...</div>
                </div>
                
                <textarea id="ai-question" class="form-control" rows="2" placeholder="Hỏi gì đi (Nhấn Enter để gửi)..."></textarea>
                <button id="ai-btn-send" class="btn btn-primary mt-2" style="width:100%">🚀 Gửi câu hỏi</button>
            </div>

            <script>
            // Hàm thêm tin nhắn vào khung chat
            function appendMessage(sender, text) {
                var history = document.getElementById("ai-chat-history");
                var msgDiv = document.createElement("div");
                msgDiv.style.marginBottom = "10px";
                msgDiv.style.padding = "8px";
                msgDiv.style.borderRadius = "5px";
                
                if (sender === "user") {
                    msgDiv.style.background = "#e3f2fd";
                    msgDiv.style.textAlign = "right";
                    msgDiv.innerHTML = "<strong>Bạn:</strong> " + text;
                } else {
                    msgDiv.style.background = "#f1f0f0";
                    msgDiv.style.textAlign = "left";
                    msgDiv.innerHTML = "<strong>AI:</strong> " + text;
                }
                
                history.appendChild(msgDiv);
                history.scrollTop = history.scrollHeight; // Tự cuộn xuống dưới
            }

            // Hàm xử lý gửi tin nhắn
            function sendAiQuestion() {
                var question = document.getElementById("ai-question").value;

                if (!question.trim()) {
                    alert("Bạn chưa nhập câu hỏi!");
                    return;
                }

                // 1. Hiện câu hỏi của User ngay lập tức
                appendMessage("user", question);
                document.getElementById("ai-question").value = ""; // Xóa ô nhập
                
                // 2. Hiện trạng thái đang nhập
                var history = document.getElementById("ai-chat-history");
                var loadingDiv = document.createElement("div");
                loadingDiv.id = "ai-typing-indicator";
                loadingDiv.innerHTML = "<em>⏳ AI đang soạn tin...</em>";
                history.appendChild(loadingDiv);
                history.scrollTop = history.scrollHeight;

                // Gọi sang file ajax.php
                fetch("'.$ajaxUrlStr.'?question=" + encodeURIComponent(question) + "&course_id=' . $courseId . '")
                .then(response => response.json())
                .then(data => {
                    // Xóa loading
                    var loadingIndicator = document.getElementById("ai-typing-indicator");
                    if(loadingIndicator) loadingIndicator.remove();
                    
                    // Lấy nội dung trả lời từ cấu trúc JSON của Ollama
                    if (data.response !== undefined) {
                        var text = data.response;
                        
                        // Format cơ bản (Markdown simple)
                        text = text.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
                        text = text.replace(/\n/g, "<br>");
                        
                        appendMessage("ai", text);
                    } else {
                        // Xử lý hiển thị lỗi
                        var errorMsg = "Không có phản hồi từ LLaMA";
                        if (data.error) {
                            errorMsg = (typeof data.error === "object" && data.error.message) ? data.error.message : JSON.stringify(data.error);
                        }
                        
                        appendMessage("ai", "❌ Lỗi: " + errorMsg);
                        console.log("Dữ liệu lỗi:", data);
                    }
                })
                .catch(error => {
                    var loadingIndicator = document.getElementById("ai-typing-indicator");
                    if(loadingIndicator) loadingIndicator.remove();
                    appendMessage("ai", "❌ Lỗi kết nối server.");
                    console.error(error);
                });
            }

            // Sự kiện Click nút Gửi
            document.getElementById("ai-btn-send").addEventListener("click", sendAiQuestion);

            // Sự kiện nhấn Enter trong ô input
            document.getElementById("ai-question").addEventListener("keypress", function(event) {
                if (event.key === "Enter" && !event.shiftKey) {
                    event.preventDefault(); // Ngăn xuống dòng
                    sendAiQuestion();
                }
            });
            </script>
        ';
        
        return $this->content;
    }
}