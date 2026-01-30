<?php
/**
 * FileUploader Class
 * Handles file uploads with validation for publications
 */

class FileUploader {
    private $uploadDir;
    private $allowedTypes;
    private $maxSize;
    private $errors = [];

    /**
     * Constructor
     * @param string $uploadDir Base upload directory
     * @param array $allowedTypes Allowed MIME types
     * @param int $maxSize Max file size in bytes (default 10MB)
     */
    public function __construct($uploadDir = null, $allowedTypes = null, $maxSize = null) {
        $this->uploadDir = $uploadDir ?? __DIR__ . '/../uploads/publications/';
        $this->allowedTypes = $allowedTypes ?? [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        $this->maxSize = $maxSize ?? 10 * 1024 * 1024; // 10MB

        // Ensure upload directory exists
        $this->ensureDirectoryExists($this->uploadDir);
    }

    /**
     * Upload a file
     * @param array $file $_FILES array element
     * @param string|null $customName Custom filename (without extension)
     * @return array ['success' => bool, 'path' => string, 'filename' => string, 'original_name' => string, 'size' => int, 'type' => string, 'error' => string]
     */
    public function upload($file, $customName = null) {
        $this->errors = [];

        // Validate file
        if (!$this->validate($file)) {
            return [
                'success' => false,
                'error' => implode(', ', $this->errors)
            ];
        }

        // Generate filename
        $extension = $this->getExtension($file['name']);
        $filename = $customName
            ? $this->sanitizeFilename($customName) . '.' . $extension
            : $this->generateFilename($extension);

        // Create subdirectory by year/month
        $subDir = date('Y') . '/' . date('m') . '/';
        $fullDir = $this->uploadDir . $subDir;
        $this->ensureDirectoryExists($fullDir);

        // Full path
        $relativePath = $subDir . $filename;
        $fullPath = $this->uploadDir . $relativePath;

        // Move file
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return [
                'success' => false,
                'error' => 'Ошибка при сохранении файла'
            ];
        }

        return [
            'success' => true,
            'path' => $relativePath,
            'filename' => $filename,
            'original_name' => $file['name'],
            'size' => $file['size'],
            'type' => $file['type']
        ];
    }

    /**
     * Validate uploaded file
     * @param array $file $_FILES array element
     * @return bool Valid
     */
    public function validate($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = $this->getUploadErrorMessage($file['error']);
            return false;
        }

        // Check file size
        if ($file['size'] > $this->maxSize) {
            $maxMB = round($this->maxSize / 1024 / 1024, 1);
            $this->errors[] = "Файл слишком большой. Максимальный размер: {$maxMB} МБ";
            return false;
        }

        // Check MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $this->allowedTypes)) {
            $this->errors[] = 'Неподдерживаемый тип файла. Разрешены: PDF, DOC, DOCX';
            return false;
        }

        // Additional check by extension
        $extension = strtolower($this->getExtension($file['name']));
        $allowedExtensions = ['pdf', 'doc', 'docx'];

        if (!in_array($extension, $allowedExtensions)) {
            $this->errors[] = 'Неподдерживаемое расширение файла';
            return false;
        }

        return true;
    }

    /**
     * Delete a file
     * @param string $path Relative path from upload directory
     * @return bool Success
     */
    public function delete($path) {
        $fullPath = $this->uploadDir . $path;

        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }

        return false;
    }

    /**
     * Check if file exists
     * @param string $path Relative path from upload directory
     * @return bool Exists
     */
    public function exists($path) {
        return file_exists($this->uploadDir . $path);
    }

    /**
     * Get file size in human readable format
     * @param int $bytes Size in bytes
     * @return string Formatted size
     */
    public static function formatSize($bytes) {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 1) . ' ' . $units[$pow];
    }

    /**
     * Get file extension from filename
     * @param string $filename Filename
     * @return string Extension (lowercase)
     */
    private function getExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Generate unique filename
     * @param string $extension File extension
     * @return string Filename
     */
    private function generateFilename($extension) {
        return sprintf(
            'pub_%s_%s.%s',
            date('Ymd_His'),
            bin2hex(random_bytes(4)),
            $extension
        );
    }

    /**
     * Sanitize filename
     * @param string $filename Filename
     * @return string Sanitized filename
     */
    private function sanitizeFilename($filename) {
        // Remove path
        $filename = basename($filename);

        // Transliterate Cyrillic
        $transliteration = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
            'Е' => 'E', 'Ё' => 'YO', 'Ж' => 'ZH', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
            'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'TS', 'Ч' => 'CH',
            'Ш' => 'SH', 'Щ' => 'SCH', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
            'Э' => 'E', 'Ю' => 'YU', 'Я' => 'YA'
        ];

        $filename = strtr($filename, $transliteration);

        // Replace non-alphanumeric with underscore
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Remove multiple underscores
        $filename = preg_replace('/_+/', '_', $filename);

        return trim($filename, '_');
    }

    /**
     * Ensure directory exists
     * @param string $dir Directory path
     */
    private function ensureDirectoryExists($dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Get upload error message
     * @param int $errorCode PHP upload error code
     * @return string Error message
     */
    private function getUploadErrorMessage($errorCode) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, установленный сервером',
            UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер',
            UPLOAD_ERR_PARTIAL => 'Файл загружен частично',
            UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
            UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
            UPLOAD_ERR_CANT_WRITE => 'Ошибка записи файла на диск',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением PHP'
        ];

        return $messages[$errorCode] ?? 'Неизвестная ошибка загрузки';
    }

    /**
     * Get last errors
     * @return array Errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Get allowed file types as string for accept attribute
     * @return string Allowed types
     */
    public function getAllowedTypesString() {
        return '.pdf,.doc,.docx';
    }

    /**
     * Get max size in MB
     * @return float Max size in MB
     */
    public function getMaxSizeMB() {
        return round($this->maxSize / 1024 / 1024, 1);
    }
}
