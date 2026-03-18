<?php
// Tự động tìm và nạp các file của Smalot/PdfParser
spl_autoload_register(function($class){
    // Namespace của thư viện
    $prefix = 'Smalot\\PdfParser\\';

    // THư mục chứa mã nguồn thư viện
    $base_dir = __DIR__ . '/Smalot/PdfParser/';

    // Check Moodle có gọi class của Smalot không
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return; // Không phải class của Smalot -> skip

    // Lấy phần tên class phía sau prefix
    $relative_class = substr($class, $len);
    // Ghép đường dẫn file tương ứng
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // Nếu file tồn tại thì require vào
    if(file_exists($file)) {
        require_once($file);
    }
});