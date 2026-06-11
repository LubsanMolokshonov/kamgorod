<?php
/**
 * Единый guard авторизации админки.
 *
 * Подключать ПЕРВОЙ строкой каждой страницы admin/**, до любой обработки
 * POST/GET и до вывода. Если админ не залогинен — Admin::verifySession()
 * редиректит на /admin/login.php и завершает выполнение (exit), поэтому
 * никакой код страницы (включая мутации БД) не выполняется.
 *
 * Исключения: admin/login.php и admin/logout.php — подключать НЕ нужно.
 *
 * Также делает доступными CSRF-хелперы (generateCSRFToken/validateCSRFToken)
 * из includes/session.php для форм и обработчиков POST.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../includes/session.php';

// session_start (с guard) + проверка сессии + редирект/exit для гостей.
Admin::verifySession();
