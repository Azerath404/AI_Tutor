<?php
class block_ai_tutor extends block_base{

    public function init() {
        // Set the title of the block
        $this->title = 'AI Tutor';
    }

    public function get_content(){
        // If content is already set, return it
        if($this->content !== null){
            return $this->content;
        }

        $this->content = new stdClass();

        // Set the content of the block
        $this->content->text = '<h4>Xin chào!</h4><p>Tôi là trợ lý học tập của bạn.</p>';
        // Set the footer of the block
        $this->content->footer = 'Powered by Gemini';
        return $this->content;
    }
}