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
        $ajaxUrl = new moodle_url('/blocks/ai_tutor/ajax.php');
        $ajaxUrlStr = $ajaxUrl->out(false); 
    
        $courseId = $this->page->course->id;
        $userId = $USER->id;
        
        // Bắt đầu nhúng giao diện HTML + CSS + JS
        $this->content->text = '
            <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
            <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

            <style>
                #ai-chat-history {
                    height: 350px; 
                    overflow-y: auto; 
                    border: 1px solid #dee2e6; 
                    padding: 15px; 
                    margin-bottom: 10px; 
                    background: #f8f9fa; 
                    border-radius: 8px;
                    scroll-behavior: smooth;
                    display: flex;
                    flex-direction: column;
                }
                .chat-msg {
                    margin-bottom: 15px;
                    padding: 12px 16px;
                    border-radius: 8px;
                    max-width: 90%;
                    word-wrap: break-word;
                    line-height: 1.5;
                    font-size: 14px;
                }
                .msg-user {
                    background: #007bff;
                    color: white;
                    align-self: flex-end;
                    border-bottom-right-radius: 2px;
                }
                .msg-ai {
                    background: white;
                    color: #333;
                    align-self: flex-start;
                    border: 1px solid #e9ecef;
                    border-bottom-left-radius: 2px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                    width: 100%;
                }
                /* Định dạng bảng Code của AI */
                .msg-ai pre {
                    background-color: #0d1117 !important;
                    color: #c9d1d9;
                    padding: 12px;
                    border-radius: 6px;
                    overflow-x: auto;
                    margin: 10px 0;
                }
                .msg-ai code {
                    font-family: Consolas, Monaco, monospace;
                    font-size: 13px;
                }
                .msg-ai p { margin-bottom: 10px; }
                .msg-ai p:last-child { margin-bottom: 0; }
                .msg-ai ul, .msg-ai ol { margin-left: 20px; margin-bottom: 10px; }
                .msg-ai table { border-collapse: collapse; width: 100%; margin-bottom: 10px; }
                .msg-ai th, .msg-ai td { border: 1px solid #ddd; padding: 6px; }
            </style>

            <div style="padding:5px;">
                <div id="ai-chat-history">
                    <div style="color: #666; font-style: italic; font-size: 0.9em; text-align: center; width: 100%;">Bắt đầu trò chuyện với AI Tutor...</div>
                </div>
                
                <textarea id="ai-question" class="form-control" rows="2" placeholder="Hỏi gì đi (Nhấn Enter để gửi)..."></textarea>
                <button id="ai-btn-send" class="btn btn-primary mt-2" style="width:100%">🚀 Gửi câu hỏi</button>
            </div>

            <script>
            // Cấu hình Marked.js để ngắt dòng chuẩn xác
            marked.use({
                breaks: true,
                gfm: true
            });

            // Hàm thêm tin nhắn của User
            function appendMessage(sender, text) {
                var history = document.getElementById("ai-chat-history");
                var msgDiv = document.createElement("div");
                msgDiv.className = "chat-msg msg-user";
                msgDiv.innerHTML = "<strong>Bạn:</strong><br>" + text;
                
                history.appendChild(msgDiv);
                history.scrollTop = history.scrollHeight;
            }

            // Hàm xử lý gửi tin nhắn AI (Streaming)
            function sendAiQuestion() {
                var questionInput = document.getElementById("ai-question");
                var question = questionInput.value;

                if (!question.trim()) { return; }

                appendMessage("user", question);
                questionInput.value = ""; 
                
                var history = document.getElementById("ai-chat-history");
                
                // Khởi tạo bong bóng chat của AI
                var msgDiv = document.createElement("div");
                msgDiv.className = "chat-msg msg-ai";
                msgDiv.innerHTML = "<strong>AI:</strong><div class=\"ai-reply-content\" style=\"margin-top:5px;\">⏳ Đang suy nghĩ...</div>";
                history.appendChild(msgDiv);
                history.scrollTop = history.scrollHeight;

                var replySpan = msgDiv.querySelector(".ai-reply-content");
                var fullText = ""; 

                // Gửi request
                fetch("'.$ajaxUrlStr.'?question=" + encodeURIComponent(question) + "&course_id=' . $courseId . '")
                .then(async response => {
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder("utf-8");
                    replySpan.innerHTML = ""; 

                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        
                        const chunk = decoder.decode(value, { stream: true });
                        const lines = chunk.split("\\n\\n"); 
                        
                        for (let line of lines) {
                            if (line.startsWith("data: ")) {
                                const dataStr = line.substring(6);
                                
                                if (dataStr === "[DONE]") {
                                    break; 
                                }
                                
                                try {
                                    const dataObj = JSON.parse(dataStr);
                                    if(dataObj.error) {
                                        replySpan.innerHTML += "<br><span style=\'color:red\'>❌ Lỗi: " + dataObj.error + "</span>";
                                        break;
                                    }
                                    
                                    // 1. Cộng dồn text
                                    fullText += dataObj.text;
                                    
                                    // 2. Dịch Markdown sang HTML bằng Marked.js
                                    replySpan.innerHTML = marked.parse(fullText);
                                    
                                    // 3. Quét các khối <code> để tô màu bằng Highlight.js
                                    replySpan.querySelectorAll("pre code").forEach((block) => {
                                        hljs.highlightElement(block);
                                    });

                                    // 4. Auto-scroll
                                    history.scrollTop = history.scrollHeight; 
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

            document.getElementById("ai-btn-send").addEventListener("click", sendAiQuestion);

            document.getElementById("ai-question").addEventListener("keypress", function(event) {
                if (event.key === "Enter" && !event.shiftKey) {
                    event.preventDefault(); 
                    sendAiQuestion();
                }
            });
            </script>
        ';
        
        return $this->content;
    }
}