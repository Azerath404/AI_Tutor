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

            // Hàm xử lý gửi tin nhắn (Bản hỗ trợ Streaming - Đã fix lỗi nháy)
            function sendAiQuestion() {
                var questionInput = document.getElementById("ai-question");
                var question = questionInput.value;

                if (!question.trim()) { return; }

                appendMessage("user", question);
                questionInput.value = ""; 
                
                var history = document.getElementById("ai-chat-history");
                
                // Khởi tạo một khung chat trống cho AI trước
                var msgDiv = document.createElement("div");
                msgDiv.style.marginBottom = "10px";
                msgDiv.style.padding = "8px";
                msgDiv.style.borderRadius = "5px";
                msgDiv.style.background = "#f1f0f0";
                msgDiv.style.textAlign = "left";
                
                // ĐÃ SỬA: Thay nháy đơn thành nháy kép escape
                msgDiv.innerHTML = "<strong>AI:</strong> <span class=\"ai-reply-content\">⏳ Đang suy nghĩ...</span>";
                history.appendChild(msgDiv);
                history.scrollTop = history.scrollHeight;

                // ĐÃ SỬA: Thay nháy đơn thành nháy kép
                var replySpan = msgDiv.querySelector(".ai-reply-content");
                var fullText = ""; // Biến cộng dồn các chữ

                // Dùng Fetch API chuẩn mới để đọc Stream
                fetch("'.$ajaxUrlStr.'?question=" + encodeURIComponent(question) + "&course_id=' . $courseId . '")
                .then(async response => {
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder("utf-8");
                    replySpan.innerHTML = ""; 

                    // Vòng lặp đọc liên tục cho đến khi xong
                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        
                        const chunk = decoder.decode(value, { stream: true });
                        const lines = chunk.split("\\n\\n"); // Tách các gói SSE
                        
                        for (let line of lines) {
                            if (line.startsWith("data: ")) {
                                const dataStr = line.substring(6);
                                
                                if (dataStr === "[DONE]") {
                                    break; // AI đã gõ xong
                                }
                                
                                try {
                                    const dataObj = JSON.parse(dataStr);
                                    if(dataObj.error) {
                                        replySpan.innerHTML += "❌ Lỗi: " + dataObj.error;
                                        break;
                                    }
                                    
                                    // Cộng thêm chữ mới vào tổng thể
                                    fullText += dataObj.text;
                                    
                                    // Render Markdown cơ bản
                                    let formattedText = fullText.replace(/\\*\\*(.*?)\\*\\*/g, "<strong>$1</strong>");
                                    formattedText = formattedText.replace(/\\n/g, "<br>");
                                    
                                    replySpan.innerHTML = formattedText;
                                    history.scrollTop = history.scrollHeight; // Cuộn xuống liên tục
                                } catch (e) {
                                    console.error("Lỗi parse gói tin:", e);
                                }
                            }
                        }
                    }
                })
                .catch(error => {
                    replySpan.innerHTML = "❌ Lỗi kết nối máy chủ AI.";
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