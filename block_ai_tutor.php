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
        
        $courseId = $this->page->course->id;

        try {
            $service = new \block_ai_tutor\service();

            // auto_purge_old_logs chỉ chạy tối đa 1 lần/ngày thông qua Moodle cache.
            // Trước đây chạy mọi page load → gây DELETE query không cần thiết.
            $purge_cache = \cache::make('block_ai_tutor', 'purge_flag');
            if ($purge_cache->get('last_purge_day') !== date('Y-m-d')) {
                $service->get_repo()->auto_purge_old_logs(7);
                $purge_cache->set('last_purge_day', date('Y-m-d'));
            }

            global $DB;
            $chunks_exist = $DB->record_exists('block_ai_tutor_chunks', ['courseid' => $courseId]);
            if (!$chunks_exist) {
                $task = new \block_ai_tutor\task\process_course_documents();
                $task->set_custom_data(['courseid' => $courseId]);
                \core\task\manager::queue_adhoc_task($task, true);
            }
        } catch (\Exception $e) { }

        $ajaxUrl = new moodle_url('/blocks/ai_tutor/ajax.php');
        $ajaxUrlStr = $ajaxUrl->out(false); 
        
        $deleteUrl = new moodle_url('/blocks/ai_tutor/delete_history.php');
        $deleteUrlStr = $deleteUrl->out(false);

        $checkReadyUrl = new moodle_url('/blocks/ai_tutor/classes/ajax/check_ready.php');
        $checkReadyUrlStr = $checkReadyUrl->out(false);

        $warmupUrl = new moodle_url('/blocks/ai_tutor/classes/ajax/warmup_model.php');
        $warmupUrlStr = $warmupUrl->out(false);

        $context = context_course::instance($courseId);

        $history_records = [];
        try {
            $repo = isset($service) ? $service->get_repo() : new \block_ai_tutor\repository();
            $history_records = $repo->get_chat_history($USER->id, $courseId, 20);
        } catch (\Exception $e) { }
        
        $historyJson = json_encode($history_records, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        
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
                    height: 400px; 
                    overflow-y: auto; 
                    border: 1px solid #dee2e6; 
                    padding: 12px; 
                    margin-bottom: 10px; 
                    background: #ffffff; 
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
                    background: none; border: none; color: #dc3545; font-size: 0.8em; cursor: pointer;
                }
                .chat-msg {
                    margin-bottom: 12px;
                    padding: 10px 14px;
                    border-radius: 8px;
                    font-size: 13.5px;
                    line-height: 1.6;
                    word-wrap: break-word;
                }
                .msg-user {
                    background: #007bff;
                    color: white;
                    align-self: flex-end;
                    max-width: 85%;
                    border-bottom-right-radius: 2px;
                }
                /* AI Message - Mở rộng full chiều rộng để tránh bị bó hẹp */
                .msg-ai {
                    background: #f8f9fa;
                    color: #212529;
                    align-self: flex-start;
                    border: 1px solid #e9ecef;
                    border-bottom-left-radius: 2px;
                    width: 100%;
                    box-sizing: border-box;
                }
                /* Định dạng Markdown nội dung AI */
                .ai-reply-content ul, .ai-reply-content ol {
                    padding-left: 1.5rem;
                    margin-bottom: 0.5rem;
                }
                .ai-reply-content p { margin-bottom: 0.5rem; }
                .ai-reply-content code {
                    background: #050505; padding: 2px 4px; border-radius: 4px; font-family: monospace;
                }
                .msg-ai pre {
                    background-color: #0d1117 !important;
                    color: #050505;
                    padding: 10px;
                    border-radius: 6px;
                    overflow-x: auto;
                    margin: 8px 0;
                }
                .ai-reply-content pre {
                    background: #1e1e1e !important;
                    color: #050505 !important;
                    padding: 12px !important;
                    border-radius: 6px;
                    white-space: pre !important; /* Quan trọng để giữ định dạng code */
                    overflow-x: auto;
                    width: 100%;
                    display: block;
                }
            </style>

            <div style="padding:2px;">
                <div class="chat-header">
                    <span style="font-weight: bold; font-size: 0.85em; color: #555;">🤖 AI Tutor (Nha Trang University)</span>
                    <button id="ai-btn-clear">🗑️ Xóa lịch sử</button>
                </div>
                <div style="font-size:0.78em; margin-bottom:6px; text-align:right;">
                    <span id="ai-model-status" style="color:#6c757d;">⚪ Đang kiểm tra...</span>
                </div>

                <div id="ai-chat-history">
                    <div style="color: #999; font-style: italic; font-size: 0.85em; text-align: center; width: 100%; margin: auto;">Bắt đầu đặt câu hỏi về bài giảng...</div>
                </div>
                
                <textarea id="ai-question" class="form-control" rows="2" placeholder="Nhập câu hỏi..."></textarea>
                <button id="ai-btn-send" class="btn btn-primary mt-2" style="width:100%; font-weight: bold;">🚀 Gửi câu hỏi</button>
                
                {$adminHtml}
            </div>

            <script>
            (function() {
                marked.use({ breaks: true, gfm: true });
                
                const historyBox = document.getElementById("ai-chat-history");
                const currentHistory = {$historyJson};
                const courseId = "{$courseId}";
                const ajaxUrl = "{$ajaxUrlStr}";
                const deleteUrl = "{$deleteUrlStr}";
                const checkReadyUrl = "{$checkReadyUrlStr}";
                const warmupUrl = "{$warmupUrlStr}";



                let checkAttempts = 0;
                const maxCheckAttempts = 6; // Tối đa 6 lần × 5s = 30 giây

                // ── WARMUP: Gọi ngay khi trang load ─────────────────────────
                const modelStatusEl = document.getElementById('ai-model-status');
                let warmupTimer = null;
                let warmupSeconds = 0;

                function updateModelStatus(status, extraMsg) {
                    if (!modelStatusEl) return;
                    const map = {
                        'already_loaded': ['🟢 Model sẵn sàng',   '#28a745'],
                        'warming_up':     ['🟡 Đang tải model...', '#e6a817'],
                        'queued':         ['🟡 Đang tải model...', '#e6a817'],
                        'error':          ['🔴 Không kết nối AI',  '#dc3545'],
                    };
                    const [label, color] = map[status] || ['⚪ Đang kiểm tra...', '#6c757d'];
                    modelStatusEl.textContent = extraMsg ? label + ' ' + extraMsg : label;
                    modelStatusEl.style.color = color;
                }

                // Poll /api/ps qua warmup endpoint để biết khi nào model thực sự ready.
                // Lý do dùng warmup endpoint thay vì gọi /api/ps trực tiếp:
                //   → /api/ps là Ollama local, không thể gọi từ browser (CORS).
                //   → warmup_model.php đã có logic kiểm tra /api/ps phía server.
                function pollWarmupStatus() {
                    if (warmupTimer) clearInterval(warmupTimer);
                    warmupSeconds = 0;

                    warmupTimer = setInterval(function() {
                        warmupSeconds += 10;
                        // Hiển thị thời gian đã chờ để người dùng biết đang diễn ra
                        const waited = warmupSeconds < 60
                            ? warmupSeconds + 's'
                            : Math.floor(warmupSeconds / 60) + 'm' + (warmupSeconds % 60) + 's';
                        updateModelStatus('warming_up', '(' + waited + ')');

                        // Poll lại server để kiểm tra model đã load xong chưa
                        fetch(warmupUrl)
                            .then(r => r.json())
                            .then(d => {
                                if (d.status === 'already_loaded') {
                                    clearInterval(warmupTimer);
                                    updateModelStatus('already_loaded');
                                }
                                // 'warming_up': tiếp tục poll
                            })
                            .catch(() => { /* Giữ nguyên status, thử lại lần sau */ });

                        // Tối đa 5 phút (300s) thì dừng poll, để user tự thử
                        if (warmupSeconds >= 300) {
                            clearInterval(warmupTimer);
                            updateModelStatus('warming_up', '(thử gửi câu hỏi)');
                        }
                    }, 45000); // Poll mỗi 45s (warmup_model.php blocking tối đa 40s, cần buffer tránh nghẽn request)
                }

                // Gọi warmup ngay khi trang load.
                // warmup_model.php v4 là blocking call (~5-15s):
                //   - Nếu model đã trong RAM: trả về ngay sau ~200ms
                //   - Nếu chưa: chờ Ollama load xong (~5.5s) rồi mới trả về already_loaded
                // → fetch này có thể mất 5-15s, sau đó status sẽ là already_loaded.
                updateModelStatus('warming_up');
                fetch(warmupUrl)
                    .then(r => r.json())
                    .then(d => {
                        if (d.status === 'already_loaded') {
                            updateModelStatus('already_loaded');
                        } else {
                            // Vẫn chưa load xong (>15s) — poll tiếp
                            pollWarmupStatus();
                        }
                    })
                    .catch(() => { updateModelStatus('error'); });
                // Nút Gửi LUÔN enabled — người dùng có thể gửi bất kỳ lúc nào.
                // ────────────────────────────────────────────────────────────



                async function checkIndexStatus() {
                    try {
                        const res = await fetch(checkReadyUrl + '?course_id=' + courseId);
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        const data = await res.json();
                        if (!data.ready) {
                            checkAttempts++;
                            if (checkAttempts >= maxCheckAttempts) {
                                document.getElementById('ai-btn-send').disabled = false;
                                document.getElementById('ai-btn-send').textContent = '🚀 Gửi câu hỏi';
                                return;
                            }
                            document.getElementById('ai-btn-send').disabled = true;
                            document.getElementById('ai-btn-send').textContent = '⏳ Đang lập chỉ mục tài liệu...';
                            setTimeout(checkIndexStatus, 5000);
                        } else {
                            document.getElementById('ai-btn-send').disabled = false;
                            document.getElementById('ai-btn-send').textContent = '🚀 Gửi câu hỏi';
                        }
                    } catch (e) {
                        console.warn('AI Tutor: check_ready thất bại, fallback enable button.', e);
                        document.getElementById('ai-btn-send').disabled = false;
                        document.getElementById('ai-btn-send').textContent = '🚀 Gửi câu hỏi';
                    }
                }
                checkIndexStatus();

                function renderContent(sender, text) {
                    if (sender === 'user') {
                        return '<strong>Bạn:</strong><br>' + document.createTextNode(text).wholeText;
                    }
                    return '<strong>AI:</strong><div class="ai-reply-content">' + marked.parse(text || '') + '</div>';
                }

                function loadInitialHistory() {
                    if (currentHistory && currentHistory.length > 0) {
                        historyBox.innerHTML = ''; 
                        currentHistory.forEach(log => {
                            const msgDiv = document.createElement("div");
                            msgDiv.className = "chat-msg " + (log.role === "user" ? "msg-user" : "msg-ai");
                            msgDiv.innerHTML = renderContent(log.role, log.message);
                            historyBox.appendChild(msgDiv);
                        });
                        historyBox.querySelectorAll("pre code").forEach(el => hljs.highlightElement(el));
                        historyBox.scrollTop = historyBox.scrollHeight;
                    }
                }

                function sendAiQuestion() {
                    const questionInput = document.getElementById("ai-question");
                    const question = questionInput.value.trim();
                    if (!question) return;

                    // Hiển thị tin nhắn User
                    const userDiv = document.createElement("div");
                    userDiv.className = "chat-msg msg-user";
                    userDiv.innerHTML = renderContent('user', question);
                    historyBox.appendChild(userDiv);
                    questionInput.value = ""; 

                    // Tạo khung tin nhắn AI đang chờ
                    const aiDiv = document.createElement("div");
                    aiDiv.className = "chat-msg msg-ai";
                    aiDiv.innerHTML = '<strong>AI:</strong><div class="ai-reply-content">⏳ Đang xử lý tài liệu...</div>';
                    historyBox.appendChild(aiDiv);
                    historyBox.scrollTop = historyBox.scrollHeight;

                    const replyContent = aiDiv.querySelector(".ai-reply-content");
                    let fullText = ""; 

                    const fetchUrl = ajaxUrl + "?question=" + encodeURIComponent(question) + "&course_id=" + courseId;
                    
                    fetch(fetchUrl).then(async response => {
                        const reader = response.body.getReader();
                        const decoder = new TextDecoder("utf-8");
                        replyContent.innerHTML = ""; 
                        replyContent.dataset.status = "streaming";

                        while (true) {
                            const { done, value } = await reader.read();
                            if (done) {
                                replyContent.dataset.status = "done";
                                break;
                            }
                            
                            const chunk = decoder.decode(value, { stream: true });
                            const lines = chunk.split("\\n\\n"); 
                            
                            for (let line of lines) {
                                if (line.startsWith("data: ")) {
                                    const dataStr = line.substring(6).trim();
                                    if (dataStr === "[DONE]") continue;
                                    
                                    try {
                                        const dataObj = JSON.parse(dataStr);
                                        if(dataObj.text) {
                                            fullText += dataObj.text;
                                            replyContent.innerHTML = marked.parse(fullText);
                                            // Highlight code trong lúc stream
                                            replyContent.querySelectorAll("pre code").forEach(el => {
                                                if (!el.dataset.highlighted) {
                                                    hljs.highlightElement(el);
                                                    el.dataset.highlighted = "true";
                                                }
                                            });
                                            historyBox.scrollTop = historyBox.scrollHeight;
                                        }
                                    } catch (e) { }
                                }
                            }
                        }
                    }).catch(err => {
                        replyContent.innerHTML = "❌ Lỗi kết nối máy chủ AI.";
                        replyContent.dataset.status = "error";
                    });
                }

                document.getElementById("ai-btn-send").onclick = sendAiQuestion;
                document.getElementById("ai-question").onkeypress = e => {
                    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); sendAiQuestion(); }
                };

                document.getElementById("ai-btn-clear").onclick = () => {
                    if (confirm("Xóa lịch sử trò chuyện môn này?")) {
                        fetch(deleteUrl + "?course_id=" + courseId)
                        .then(r => r.json()).then(d => {
                            if (d.success) historyBox.innerHTML = '<div style="text-align:center; color:#999;">Đã xóa lịch sử.</div>';
                        });
                    }
                };

                loadInitialHistory();
            })();
            </script>
        HTML;
        
        return $this->content;
    }
}