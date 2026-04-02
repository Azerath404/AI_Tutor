<?php
class block_ai_tutor extends block_base {
    public function init() {
        $this->title = get_string('pluginname', 'block_ai_tutor');
    }

    public function has_config() {
        return true;
    }

    public function get_content() {
        global $USER, $CFG;
        
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;
        
        // Khởi tạo service để thực hiện tự động dọn dẹp log cũ
        try {
            $service = new \block_ai_tutor\service();
            $service->get_repo()->auto_purge_old_logs(7); 
        } catch (\Exception $e) { }

        $ajaxUrl = new moodle_url('/blocks/ai_tutor/ajax.php');
        $ajaxUrlStr = $ajaxUrl->out(false); 
        
        $deleteUrl = new moodle_url('/blocks/ai_tutor/delete_history.php');
        $deleteUrlStr = $deleteUrl->out(false);

        $courseId = $this->page->course->id;
        $context = context_course::instance($courseId);

        // --- LẤY LỊCH SỬ CHAT ---
        $history_records = [];
        try {
            if (!isset($service)) {
                $repo = new \block_ai_tutor\repository();
            } else {
                $repo = $service->get_repo();
            }
            $history_records = $repo->get_chat_history($USER->id, $courseId, 20);
        } catch (\Exception $e) { }
        
        $historyJson = json_encode($history_records, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        // --- XỬ LÝ NÚT THIẾT LẬP ---
        $adminHtml = '';
        if (has_capability('moodle/course:update', $context)) {
            $manageUrl = new moodle_url('/blocks/ai_tutor/manage_links.php', array('courseid' => $courseId));
            $adminHtml = '
                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                    <a href="' . $manageUrl->out(false) . '" class="btn btn-sm btn-outline-secondary w-100" style="font-size: 0.8em;">
                        ⚙ Thiết lập môn tiên quyết
                    </a>
                </div>';
        }

        $this->content->text = <<<HTML
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
                .chat-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 8px;
                }
                #ai-btn-clear {
                    background: none;
                    border: none;
                    color: #dc3545;
                    font-size: 0.8em;
                    cursor: pointer;
                    text-decoration: none;
                    padding: 0;
                }
                #ai-btn-clear:hover { text-decoration: underline; }
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
                .msg-ai pre {
                    background-color: #0d1117 !important;
                    color: #c9d1d9;
                    padding: 12px;
                    border-radius: 6px;
                    overflow-x: auto;
                    margin: 10px 0;
                }
            </style>

            <div style="padding:5px;">
                <div class="chat-header">
                    <span style="font-weight: bold; font-size: 0.9em; color: #444;">Trợ lý AI</span>
                    <button id="ai-btn-clear" title="Xóa toàn bộ hội thoại môn này">🗑️ Xóa lịch sử</button>
                </div>
                
                <div id="ai-chat-history">
                    <div style="color: #666; font-style: italic; font-size: 0.9em; text-align: center; width: 100%;">Bắt đầu trò chuyện với AI Tutor...</div>
                </div>
                
                <textarea id="ai-question" class="form-control" rows="2" placeholder="Hỏi gì đi (Nhấn Enter để gửi)..."></textarea>
                <button id="ai-btn-send" class="btn btn-primary mt-2" style="width:100%">🚀 Gửi câu hỏi</button>
                
                {$adminHtml}
            </div>

            <script>
            (function() {
                // Sử dụng phạm vi cục bộ để tránh lỗi SyntaxError: Identifier already declared
                marked.use({ breaks: true, gfm: true });
                
                const currentHistory = {$historyJson};
                const courseId = "{$courseId}";
                const ajaxUrl = "{$ajaxUrlStr}";
                const deleteUrl = "{$deleteUrlStr}";

                function loadInitialHistory() {
                    const historyBox = document.getElementById("ai-chat-history");
                    if (currentHistory && currentHistory.length > 0) {
                        historyBox.innerHTML = ''; 
                        currentHistory.forEach(log => {
                            const msgDiv = document.createElement("div");
                            const sender = log.role;
                            const text = log.message;

                            msgDiv.className = "chat-msg " + (sender === "user" ? "msg-user" : "msg-ai");
                            
                            let content = '';
                            if (sender === 'user') {
                                const temp = document.createElement('div');
                                temp.textContent = text;
                                content = temp.innerHTML;
                            } else {
                                content = marked.parse(text || '');
                            }

                            const senderName = sender === "user" ? "Bạn" : "AI";
                            msgDiv.innerHTML = `<strong>\${senderName}:</strong><br>\${content}`;
                            historyBox.appendChild(msgDiv);
                        });

                        historyBox.querySelectorAll("pre code").forEach((block) => {
                            hljs.highlightElement(block);
                        });
                        historyBox.scrollTop = historyBox.scrollHeight;
                    }
                }

                function appendMessage(sender, text) {
                    const historyBox = document.getElementById("ai-chat-history");
                    const msgDiv = document.createElement("div");
                    msgDiv.className = "chat-msg " + (sender === "user" ? "msg-user" : "msg-ai");
                    msgDiv.innerHTML = "<strong>" + (sender === "user" ? "Bạn" : "AI") + ":</strong><br>";
                    msgDiv.appendChild(document.createTextNode(text));
                    historyBox.appendChild(msgDiv);
                    historyBox.scrollTop = historyBox.scrollHeight;
                }

                function sendAiQuestion() {
                    const questionInput = document.getElementById("ai-question");
                    const question = questionInput.value;
                    if (!question.trim()) { return; }

                    appendMessage("user", question);
                    questionInput.value = ""; 
                    
                    const historyBox = document.getElementById("ai-chat-history");
                    const msgDiv = document.createElement("div");
                    msgDiv.className = "chat-msg msg-ai";
                    msgDiv.innerHTML = "<strong>AI:</strong><div class=\"ai-reply-content\" style=\"margin-top:5px;\">⏳ Đang suy nghĩ...</div>";
                    historyBox.appendChild(msgDiv);
                    historyBox.scrollTop = historyBox.scrollHeight;

                    const replySpan = msgDiv.querySelector(".ai-reply-content");
                    let fullText = ""; 

                    const fetchUrl = ajaxUrl + "?question=" + encodeURIComponent(question) + "&course_id=" + courseId;
                    fetch(fetchUrl)
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
                                    if (dataStr === "[DONE]") break;
                                    
                                    try {
                                        const dataObj = JSON.parse(dataStr);
                                        if(dataObj.error) {
                                            replySpan.innerHTML += "<br><span style='color:red'>❌ Lỗi: " + dataObj.error + "</span>";
                                            break;
                                        }
                                        fullText += dataObj.text;
                                        replySpan.innerHTML = marked.parse(fullText);
                                        replySpan.querySelectorAll("pre code").forEach((block) => {
                                            hljs.highlightElement(block);
                                        });
                                        historyBox.scrollTop = historyBox.scrollHeight; 
                                    } catch (e) { }
                                }
                            }
                        }
                    })
                    .catch(error => {
                        replySpan.innerHTML = "❌ Lỗi kết nối máy chủ AI.";
                    });
                }

                // Gán sự kiện
                document.getElementById("ai-btn-send").onclick = sendAiQuestion;
                
                document.getElementById("ai-question").onkeypress = function(event) {
                    if (event.key === "Enter" && !event.shiftKey) {
                        event.preventDefault(); 
                        sendAiQuestion();
                    }
                };

                document.getElementById("ai-btn-clear").onclick = function() {
                    if (confirm("Bạn có chắc chắn muốn xóa toàn bộ lịch sử trò chuyện trong môn này không?")) {
                        fetch(deleteUrl + "?course_id=" + courseId)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById("ai-chat-history").innerHTML = '<div style="color: #666; font-style: italic; font-size: 0.9em; text-align: center; width: 100%;">Đã xóa lịch sử. Bắt đầu phiên mới...</div>';
                            }
                        });
                    }
                };

                // Khởi tạo
                loadInitialHistory();
            })();
            </script>
        HTML;
        
        return $this->content;
    }
}