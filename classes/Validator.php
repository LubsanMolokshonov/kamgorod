<?php
/**
 * Validator Class
 * Handles form validation and sanitization
 */

class Validator {
    private $data;
    private $errors = [];
    private $rules = [];

    public function __construct($data) {
        $this->data = $data;
    }

    /**
     * Mark fields as required
     */
    public function required($fields) {
        if (!is_array($fields)) {
            $fields = [$fields];
        }

        foreach ($fields as $field) {
            if (!isset($this->data[$field]) || trim($this->data[$field]) === '') {
                $this->errors[] = "Поле '{$field}' обязательно для заполнения";
            }
        }

        return $this;
    }

    /**
     * Validate email format
     */
    public function email($field) {
        if (!isset($this->data[$field])) {
            return $this;
        }

        $email = $this->data[$field];

        // Check basic format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "Некорректный email адрес";
            return $this;
        }

        // Check for Cyrillic characters
        if (preg_match('/[А-Яа-яЁё]/u', $email)) {
            $this->errors[] = "Email не может содержать кириллические символы";
            return $this;
        }

        return $this;
    }

    /**
     * Validate max length
     */
    public function maxLength($field, $max) {
        if (!isset($this->data[$field])) {
            return $this;
        }

        if (mb_strlen($this->data[$field]) > $max) {
            $this->errors[] = "Поле '{$field}' не должно превышать {$max} символов";
        }

        return $this;
    }

    /**
     * Validate min length
     */
    public function minLength($field, $min) {
        if (!isset($this->data[$field])) {
            return $this;
        }

        if (mb_strlen($this->data[$field]) < $min) {
            $this->errors[] = "Поле '{$field}' должно содержать минимум {$min} символов";
        }

        return $this;
    }

    /**
     * Validate numeric value
     */
    public function numeric($field) {
        if (!isset($this->data[$field])) {
            return $this;
        }

        if (!is_numeric($this->data[$field])) {
            $this->errors[] = "Поле '{$field}' должно быть числом";
        }

        return $this;
    }

    /**
     * Validate phone number (basic)
     */
    public function phone($field) {
        if (!isset($this->data[$field]) || empty($this->data[$field])) {
            return $this;
        }

        $phone = preg_replace('/[^0-9]/', '', $this->data[$field]);

        if (strlen($phone) < 10 || strlen($phone) > 11) {
            $this->errors[] = "Некорректный номер телефона";
        }

        return $this;
    }

    /**
     * Validate date format
     */
    public function date($field) {
        if (!isset($this->data[$field]) || empty($this->data[$field])) {
            return $this;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $this->data[$field]);

        if (!$date || $date->format('Y-m-d') !== $this->data[$field]) {
            $this->errors[] = "Некорректный формат даты";
        }

        return $this;
    }

    /**
     * Check if validation passes
     */
    public function passes() {
        return empty($this->errors);
    }

    /**
     * Check if validation fails
     */
    public function fails() {
        return !$this->passes();
    }

    /**
     * Get all errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Get first error
     */
    public function getFirstError() {
        return $this->errors[0] ?? null;
    }

    /**
     * Sanitize string
     */
    public static function sanitize($value) {
        if (is_array($value)) {
            return array_map([self::class, 'sanitize'], $value);
        }

        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize and get data
     */
    public function getData() {
        return self::sanitize($this->data);
    }
}
