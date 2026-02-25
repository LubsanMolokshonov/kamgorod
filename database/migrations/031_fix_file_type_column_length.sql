-- Расширяем колонку file_type для поддержки длинных MIME-типов (напр. .docx = 71 символ)
ALTER TABLE publications MODIFY COLUMN file_type VARCHAR(255);
