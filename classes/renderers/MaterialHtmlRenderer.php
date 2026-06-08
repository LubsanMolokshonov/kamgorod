<?php
/**
 * MaterialHtmlRenderer — конвертирует структурированный JSON-ответ ИИ
 * в семантический HTML. Распознаёт типовые поля материалов ФОП:
 *
 *   - title          — заголовок материала
 *   - intro          — вступление
 *   - goals          — массив целей (список)
 *   - uud            — массив УУД по типам (personal/regulative/cognitive/communicative)
 *   - equipment      — список оборудования
 *   - stages         — этапы урока [{name, duration_min, teacher_activity, student_activity, narrative, methods}]
 *   - tasks          — задания рабочего листа [{number, type, instruction, content}]
 *   - answer_key     — ключи [{number, answer}]
 *   - questions      — вопросы теста [{number, type, text, options, correct, explanation}]
 *   - slides         — слайды презентации [{number, title, bullets, notes}]
 *   - structure      — разделы сценария [{name, duration_min, narrative}]
 *   - discussion_questions — вопросы для обсуждения (список)
 *   - rows           — строки КТП [{lesson_num, topic, hours, uud, activity, control}]
 *   - section        — название раздела КТП
 *   - homework       — домашнее задание
 *   - reflection     — рефлексия
 *
 * Неизвестные поля рендерятся как пары "ключ: значение" в конце документа,
 * чтобы редакция увидела сырые данные и могла улучшить шаблон.
 *
 * HTML — простой, без CSS-классов, чтобы один и тот же фрагмент годился
 * и для mPDF (PdfRenderer), и для inline-показа в превью, и для DOCX.
 */

class MaterialHtmlRenderer
{
    /**
     * @param array $data        структурированный ответ ИИ
     * @param bool  $withAnswers показывать ли ключи/правильные ответы (версия учителя).
     *                           Для бланка ученика — false: вопросы без отметок,
     *                           ключи в отдельный раздел «Ключи для учителя» в конце.
     */
    public function render(array $data, bool $withAnswers = true): string
    {
        $html = '';

        if (!empty($data['title'])) {
            $html .= '<h1>' . $this->esc($data['title']) . '</h1>';
        }
        if (!empty($data['section'])) {
            $html .= '<p><strong>Раздел:</strong> ' . $this->esc($data['section']) . '</p>';
        }
        if (!empty($data['lesson_type'])) {
            $html .= '<p><strong>Тип урока:</strong> ' . $this->esc($data['lesson_type']) . '</p>';
        }
        if (!empty($data['umk'])) {
            $html .= '<p><strong>УМК:</strong> ' . $this->esc($this->stringifyAnswer($data['umk'])) . '</p>';
        }
        if (!empty($data['key_concepts'])) {
            $html .= '<p><strong>Основные понятия:</strong> '
                  . $this->esc($this->stringifyAnswer($data['key_concepts'])) . '</p>';
        }
        if (!empty($data['intro'])) {
            $html .= '<p>' . $this->esc($this->dedupeParagraphs((string)$data['intro'])) . '</p>';
        }
        if (!empty($data['goal'])) {
            $html .= '<p><strong>Цель:</strong> ' . $this->esc($data['goal']) . '</p>';
        }

        if (!empty($data['goals']) && is_array($data['goals'])) {
            $html .= '<h2>Цели</h2>' . $this->renderList($data['goals']);
        }
        if (!empty($data['objectives']) && is_array($data['objectives'])) {
            $html .= '<h2>Задачи</h2>' . $this->renderList($data['objectives']);
        }

        // Планируемые результаты по ФГОС: предметные / метапредметные / личностные
        if (!empty($data['planned_results']) && is_array($data['planned_results'])) {
            $html .= '<h2>Планируемые результаты</h2>';
            $prLabels = [
                'subject'     => 'Предметные',
                'metasubject' => 'Метапредметные',
                'personal'    => 'Личностные',
            ];
            foreach ($prLabels as $key => $label) {
                if (!empty($data['planned_results'][$key])) {
                    $html .= '<p><strong>' . $label . ':</strong></p>'
                          . $this->renderList((array)$data['planned_results'][$key]);
                }
            }
        }

        if (!empty($data['uud']) && is_array($data['uud'])) {
            $html .= '<h2>УУД</h2>';
            $labels = [
                'personal' => 'Личностные',
                'regulative' => 'Регулятивные',
                'cognitive' => 'Познавательные',
                'communicative' => 'Коммуникативные',
            ];
            foreach ($labels as $key => $label) {
                if (!empty($data['uud'][$key])) {
                    $html .= '<p><strong>' . $label . ':</strong></p>'
                          . $this->renderList((array)$data['uud'][$key]);
                }
            }
        }

        if (!empty($data['equipment']) && is_array($data['equipment'])) {
            $html .= '<h2>Оборудование</h2>' . $this->renderList($data['equipment']);
        }

        if (!empty($data['instructions'])) {
            $html .= '<p><em>' . $this->esc($data['instructions']) . '</em></p>';
        }

        // Пояснительная записка к контрольной: время, макс. балл, шкала «5/4/3»
        if (!empty($data['note']) && is_array($data['note'])) {
            $n = $data['note'];
            $rows = [];
            if (!empty($n['time']))      { $rows[] = '<strong>Время выполнения:</strong> ' . $this->esc($this->stringifyAnswer($n['time'])); }
            if (!empty($n['max_score'])) { $rows[] = '<strong>Максимальный балл:</strong> ' . $this->esc($this->stringifyAnswer($n['max_score'])); }
            if (!empty($n['scale']))     { $rows[] = '<strong>Шкала оценивания:</strong> ' . $this->esc($this->stringifyAnswer($n['scale'])); }
            if ($rows) {
                $html .= '<div class="md-note"><h2>Пояснительная записка</h2><p>'
                      . implode('<br/>', $rows) . '</p></div>';
            }
        }

        // Правила психологической безопасности (классный час по острым темам)
        if (!empty($data['safety_rules'])) {
            $html .= '<h2>Правила безопасности</h2>';
            $html .= is_array($data['safety_rules'])
                ? $this->renderList($data['safety_rules'])
                : '<p>' . $this->esc((string)$data['safety_rules']) . '</p>';
        }

        // Этапы урока — таблица для техкарт, последовательные блоки для конспектов
        if (!empty($data['stages']) && is_array($data['stages'])) {
            $html .= '<h2>Этапы урока</h2>';
            if ($this->hasField($data['stages'], 'teacher_activity')) {
                $html .= $this->renderStagesTable($data['stages']);
            } else {
                $html .= $this->renderStagesNarrative($data['stages']);
            }
        }

        // Сценарий мероприятия (классный час)
        if (!empty($data['structure']) && is_array($data['structure'])) {
            $html .= '<h2>Ход мероприятия</h2>' . $this->renderStagesNarrative($data['structure']);
        }

        // Задания рабочего листа
        if (!empty($data['tasks']) && is_array($data['tasks'])) {
            $html .= '<h2>Задания</h2>' . $this->renderTasks($data['tasks']);
        }

        // Тестовые вопросы (бланк ученика — без отметок правильных ответов)
        if (!empty($data['questions']) && is_array($data['questions'])) {
            $html .= '<h2>Вопросы</h2>' . $this->renderQuestions($data['questions']);
        }

        // Ключи и правильные ответы — отдельным разделом в конце (только для версии учителя).
        // Это позволяет отрезать страницу при выдаче бланка ученику.
        if ($withAnswers) {
            $answerKey = $this->buildAnswerKey($data);
            if ($answerKey !== '') {
                $html .= '<h2>Ключи для учителя</h2>'
                      . '<p><em>Этот раздел не печатайте ученику.</em></p>'
                      . $answerKey;
            }
        }

        // Презентация — слайды (PPTX рендерится отдельно, тут текстовое превью)
        if (!empty($data['slides']) && is_array($data['slides'])) {
            $html .= '<h2>Слайды</h2>' . $this->renderSlides($data['slides']);
        }

        // КТП
        if (!empty($data['rows']) && is_array($data['rows'])) {
            if (!empty($data['education_area'])) {
                $html .= '<p><strong>Образовательная область:</strong> '
                      . $this->esc($this->stringifyAnswer($data['education_area'])) . '</p>';
            }
            $html .= '<h2>Тематическое планирование</h2>' . $this->renderKtpRows($data['rows']);
        }

        // Доп. вопросы для обсуждения
        if (!empty($data['discussion_questions']) && is_array($data['discussion_questions'])) {
            $html .= '<h2>Вопросы для обсуждения</h2>' . $this->renderList($data['discussion_questions']);
        }

        // Критерии оценивания (формирующее оценивание)
        if (!empty($data['criteria'])) {
            $html .= '<h2>Критерии оценивания</h2>';
            $html .= is_array($data['criteria'])
                ? $this->renderList($data['criteria'])
                : '<p>' . $this->esc((string)$data['criteria']) . '</p>';
        }

        // Диагностика эффективности (классный час, внеурочное)
        if (!empty($data['diagnostics'])) {
            $html .= '<h2>Диагностика эффективности</h2>';
            $html .= is_array($data['diagnostics'])
                ? $this->renderList($data['diagnostics'])
                : '<p>' . $this->esc((string)$data['diagnostics']) . '</p>';
        }

        // Домашнее задание не выводим для ДОО: в детском саду домашних заданий нет (СанПиН),
        // даже если модель его всё-таки вернула.
        $isDo = str_contains((string)($data['_stage'] ?? ''), 'ДО');
        if (!empty($data['homework']) && !$isDo) {
            $html .= '<h2>Домашнее задание</h2>' . $this->renderHomework($data['homework']);
        }
        if (!empty($data['reflection'])) {
            $html .= '<h2>Рефлексия</h2><p>'
                  . nl2br($this->esc($this->stringifyAnswer($data['reflection']))) . '</p>';
        }

        // Встроенный рабочий лист ученика (review-модель иногда добавляет его к конспекту) —
        // выводим приложением, чтобы материал не терялся.
        if (!empty($data['student_worksheet']) && is_array($data['student_worksheet'])) {
            $sw = $data['student_worksheet'];
            $html .= '<h2>' . $this->esc($sw['title'] ?? 'Рабочий лист ученика') . '</h2>';
            if (!empty($sw['tasks']) && is_array($sw['tasks'])) {
                $html .= $this->renderTasks($sw['tasks']);
            }
        }

        // Фолбэк: если ИИ вернул содержание под нераспознанными ключами (частый случай у
        // КТП/материалов ДОО), оно иначе потерялось бы и файл скачивался бы пустым.
        // Выводим неизвестные непустые поля парами «ключ: значение», чтобы контент уцелел.
        $html .= $this->renderUnknownFields($data);

        return $html;
    }

    /**
     * Известные верхнеуровневые ключи, которые render() уже обрабатывает явно.
     * Всё, чего здесь нет (и что не является внутренним полем вида _image_abs или
     * технической подсказкой image_prompt/slug), дампится как «ключ: значение».
     */
    private const KNOWN_KEYS = [
        'title', 'section', 'lesson_type', 'umk', 'key_concepts', 'intro', 'goal',
        'goals', 'objectives', 'planned_results', 'uud', 'equipment', 'instructions',
        'note', 'safety_rules', 'stages', 'structure', 'tasks', 'questions', 'answer_key',
        'slides', 'rows', 'education_area', 'discussion_questions', 'criteria',
        'diagnostics', 'homework', 'reflection', 'student_worksheet',
    ];

    /**
     * Человекочитаемые подписи для типовых нераспознанных полей (чтобы дамп не выглядел
     * техническим). Неизвестные ключи показываем как есть.
     */
    private function renderUnknownFields(array $data): string
    {
        $skip = array_flip(self::KNOWN_KEYS);
        $labels = [
            'stage'            => 'Ступень',
            'age'             => 'Возраст',
            'age_group'        => 'Возрастная группа',
            'group'            => 'Группа',
            'target_results'   => 'Целевые ориентиры',
            'integration'      => 'Интеграция образовательных областей',
            'materials'        => 'Материалы',
            'preliminary_work' => 'Предварительная работа',
            'vocabulary'       => 'Словарная работа',
            'subject'          => 'Предмет',
            'topic'            => 'Тема',
            'duration'         => 'Длительность',
            'description'      => 'Описание',
            'summary'          => 'Краткое содержание',
            'content'          => 'Содержание',
            'course'           => 'Ход занятия',
            'plan'             => 'План',
        ];
        $rows = '';
        foreach ($data as $key => $value) {
            if (isset($skip[$key]) || (is_string($key) && $key !== '' && $key[0] === '_')) {
                continue;
            }
            if ($key === 'image_prompt' || $key === 'slug') {
                continue;
            }
            $label = $labels[$key] ?? ucfirst((string)$key);
            if (is_array($value)) {
                // Список значений или объект — выводим как подзаголовок + список/текст.
                $str = $this->stringifyAnswer($value);
                if (trim($str) === '') {
                    // Возможно, это массив объектов-этапов с narrative — выводим повествованием.
                    if ($this->hasField($value, 'narrative') || $this->hasField($value, 'name')) {
                        $rows .= '<h3>' . $this->esc($label) . '</h3>' . $this->renderStagesNarrative($value);
                        continue;
                    }
                    continue;
                }
                $rows .= '<p><strong>' . $this->esc($label) . ':</strong> ' . $this->esc($str) . '</p>';
            } else {
                $str = trim((string)$value);
                if ($str === '') {
                    continue;
                }
                $rows .= '<p><strong>' . $this->esc($label) . ':</strong> '
                       . nl2br($this->esc($str)) . '</p>';
            }
        }
        return $rows;
    }

    /**
     * Рабочий лист для печати — две ЧЁТКО разделённые части:
     *   1) «Материалы для учителя»: вводная часть, критерии и ключи/ответы;
     *   2) «Материалы для ученика»: задания — с НОВОЙ страницы и с местом для ФИО.
     * Учитель распечатывает обе части себе, ученику выдаёт лист со второй части.
     * Используется PdfRenderer для типа «рабочий лист» (rabochiy-list); в отличие от
     * общего render(), где блок «Ключи для учителя» просто шёл в конце за заданиями,
     * здесь разделение явное — заголовками-баннерами и разрывом страницы.
     */
    public function renderWorksheet(array $data): string
    {
        $html = '';

        if (!empty($data['title'])) {
            $html .= '<h1>' . $this->esc($data['title']) . '</h1>';
        }

        // ── Часть 1. Материалы для учителя ──────────────────────────────
        $html .= '<h2 class="md-part md-part-teacher">Материалы для учителя</h2>';

        if (!empty($data['umk'])) {
            $html .= '<p><strong>УМК:</strong> ' . $this->esc($this->stringifyAnswer($data['umk'])) . '</p>';
        }
        if (!empty($data['intro'])) {
            $html .= '<p>' . $this->esc($this->dedupeParagraphs((string)$data['intro'])) . '</p>';
        }
        if (!empty($data['instructions'])) {
            $html .= '<p><em>' . $this->esc($this->stringifyAnswer($data['instructions'])) . '</em></p>';
        }

        // Критерии оценивания — методический ориентир учителя.
        if (!empty($data['criteria'])) {
            $html .= '<h3>Критерии оценивания</h3>';
            $html .= is_array($data['criteria'])
                ? $this->renderList($data['criteria'])
                : '<p>' . $this->esc((string)$data['criteria']) . '</p>';
        }

        // Ключи и правильные ответы.
        $answerKey = $this->buildAnswerKey($data);
        if ($answerKey !== '') {
            $html .= '<h3>Ключи и ответы</h3>' . $answerKey;
        }

        // ── Часть 2. Материалы для ученика (с новой страницы) ───────────
        $html .= '<h2 class="md-part md-part-student">Материалы для ученика</h2>';
        $html .= '<table class="md-signbar"><tr>'
              . '<td class="md-signlabel" style="width:23%;">Фамилия, имя:</td><td class="md-signline" style="width:40%;"></td>'
              . '<td class="md-signlabel" style="width:9%;">Класс:</td><td class="md-signline" style="width:9%;"></td>'
              . '<td class="md-signlabel" style="width:8%;">Дата:</td><td class="md-signline" style="width:11%;"></td>'
              . '</tr></table>';

        if (!empty($data['tasks']) && is_array($data['tasks'])) {
            $html .= '<h3>Задания</h3>' . $this->renderTasks($data['tasks']);
        }

        return $html;
    }

    /**
     * Домашнее задание: строка, список или дифференцированный объект
     * ({base_level, advanced_level} и т.п.) — выводим читаемо с подписями уровней.
     */
    private function renderHomework($hw): string
    {
        if (is_array($hw)) {
            $labels = [
                'base_level'     => 'Базовый уровень',
                'base'           => 'Базовый уровень',
                'advanced_level' => 'Повышенный уровень',
                'advanced'       => 'Повышенный уровень',
                'creative'       => 'Творческое (по желанию)',
            ];
            // Ассоциативный объект уровней?
            $isAssoc = array_keys($hw) !== range(0, count($hw) - 1);
            if ($isAssoc) {
                $rows = [];
                foreach ($hw as $k => $v) {
                    $label = $labels[$k] ?? ucfirst((string)$k);
                    $rows[] = '<strong>' . $this->esc($label) . ':</strong> '
                            . nl2br($this->esc($this->stringifyAnswer($v)));
                }
                return '<p>' . implode('<br/>', $rows) . '</p>';
            }
            return $this->renderList($hw);
        }
        return '<p>' . nl2br($this->esc($this->stringifyAnswer($hw))) . '</p>';
    }

    /**
     * Схлопывает подряд идущие одинаковые абзацы/предложения — защита от
     * жалобы методистов «вводный абзац продублирован дважды слово в слово».
     */
    private function dedupeParagraphs(string $text): string
    {
        // Делим по двойному переводу строки или по точке с пробелом-переводом
        $chunks = preg_split('/\n\s*\n+/', trim($text));
        if (!$chunks || count($chunks) < 2) {
            // Возможен дубль одного предложения подряд без переноса
            $sentences = preg_split('/(?<=[.!?])\s+/', trim($text));
            $seen = [];
            $out = [];
            foreach ($sentences as $s) {
                $key = mb_strtolower(trim($s));
                if ($key !== '' && in_array($key, $seen, true)) {
                    continue;
                }
                $seen[] = $key;
                $out[] = $s;
            }
            return implode(' ', $out);
        }
        $seen = [];
        $out = [];
        foreach ($chunks as $c) {
            $key = mb_strtolower(trim($c));
            if ($key === '' || in_array($key, $seen, true)) {
                continue;
            }
            $seen[] = $key;
            $out[] = trim($c);
        }
        return implode("\n\n", $out);
    }

    private function renderList(array $items): string
    {
        $html = '<ul>';
        foreach ($items as $item) {
            // stringifyAnswer — на случай если ИИ вернёт вложенный массив вместо строки
            // (иначе (string)array даёт «Array»).
            $html .= '<li>' . $this->esc($this->stringifyAnswer($item)) . '</li>';
        }
        return $html . '</ul>';
    }

    private function hasField(array $items, string $field): bool
    {
        foreach ($items as $i) {
            if (is_array($i) && array_key_exists($field, $i)) {
                return true;
            }
        }
        return false;
    }

    private function renderStagesTable(array $stages): string
    {
        $html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';
        $html .= '<thead><tr>'
              . '<th>Этап</th><th>Мин</th><th>Деятельность учителя</th><th>Деятельность учеников</th>'
              . '</tr></thead><tbody>';
        foreach ($stages as $s) {
            $html .= '<tr>'
                  . '<td>' . $this->esc($s['name'] ?? '') . '</td>'
                  . '<td>' . $this->esc((string)($s['duration_min'] ?? '')) . '</td>'
                  . '<td>' . $this->esc($s['teacher_activity'] ?? '') . '</td>'
                  . '<td>' . $this->esc($s['student_activity'] ?? '') . '</td>'
                  . '</tr>';
            if (!empty($s['methods']) && is_array($s['methods'])) {
                $html .= '<tr><td colspan="4"><em>Методы: '
                      . $this->esc(implode(', ', $s['methods'])) . '</em></td></tr>';
            }
        }
        return $html . '</tbody></table>';
    }

    private function renderStagesNarrative(array $stages): string
    {
        $html = '';
        foreach ($stages as $i => $s) {
            $title = ($s['name'] ?? ('Этап ' . ($i + 1)))
                   . (isset($s['duration_min']) ? ' (' . (int)$s['duration_min'] . ' мин)' : '');
            $html .= '<h3>' . $this->esc($title) . '</h3>';
            $body = $s['narrative'] ?? '';
            if ($body) {
                // Дедуп — методисты жаловались на дублирование текста хода урока
                $html .= '<p>' . nl2br($this->esc($this->dedupeParagraphs((string)$body))) . '</p>';
            }
        }
        return $html;
    }

    /**
     * Задания рабочего листа. Тип задания НЕ подписываем (он виден из формулировки),
     * готовых ответов не показываем (это бланк ученика), а под типы write/draw/match
     * рисуем место для работы.
     */
    private function renderTasks(array $tasks): string
    {
        $html = '<ol class="md-tasks">';
        foreach ($tasks as $t) {
            $type = strtolower(trim((string)($t['type'] ?? '')));
            $html .= '<li>';
            // Пометка повышенного уровня — «звёздочное» задание для сильных учеников
            $badge = $this->isAdvanced($t['level'] ?? '')
                ? '<span class="md-level md-level-adv" title="Задание повышенного уровня">★</span> '
                : '';
            if (!empty($t['instruction'])) {
                $html .= $badge . '<strong>' . $this->esc($t['instruction']) . '</strong>';
            } elseif ($badge !== '') {
                $html .= $badge;
            }
            $html .= $this->renderTaskBody($type, $t['content'] ?? null);
            $html .= '</li>';
        }
        return $html . '</ol>';
    }

    /**
     * Тело задания в зависимости от типа: сопоставление — два столбца,
     * письменный ответ — линии, рисунок — рамка, остальное — текст с пропусками.
     */
    private function renderTaskBody(string $type, $content): string
    {
        if ($type === 'match') {
            return $this->renderMatch($content);
        }
        if ($type === 'write') {
            // Линии для развёрнутого письменного ответа — с запасом, чтобы
            // ученику хватило места написать несколько предложений от руки.
            return '<div class="md-writelines">'
                 . str_repeat('<div class="md-writeline"></div>', 7)
                 . '</div>';
        }
        if ($type === 'draw') {
            return '<div class="md-drawbox"><span>Место для рисунка</span></div>';
        }
        // fill / choose / прочее — выводим текст задания (с пропусками "____" / вариантами).
        if (is_string($content) && trim($content) !== '') {
            return '<div class="md-taskcontent">' . nl2br($this->esc($content)) . '</div>';
        }
        // Если у choose/fill пусто содержимое (модель не вернула варианты/текст) — даём линии
        // под ответ, чтобы задание не осталось без поля для работы ученика.
        if ($type === 'choose' || $type === 'fill') {
            return '<div class="md-writelines">'
                 . str_repeat('<div class="md-writeline"></div>', 3)
                 . '</div>';
        }
        return '';
    }

    /**
     * Сопоставление: левый столбец (буквы) и правый (цифры), соединять линиями.
     * content = {left:[...], right:[...]} или (на всякий) массив пар.
     *
     * Правый столбец перемешиваем детерминированно — иначе при выводе left[i]
     * напротив right[i] правильное соответствие «подсказано» порядком строк,
     * и задание оказывается уже решённым (жалоба методистов). Правильный
     * порядок остаётся только в answer_key.
     */
    private function renderMatch($content): string
    {
        $left = [];
        $right = [];
        if (is_array($content)) {
            $left  = array_values((array)($content['left'] ?? []));
            $right = array_values((array)($content['right'] ?? []));
        }
        if (empty($left) && empty($right)) {
            return '';
        }
        $right = $this->deterministicShuffle($right);
        $rows = max(count($left), count($right));
        $html = '<table class="md-match" style="width:100%; border-collapse:collapse; margin:6px 0;">';
        for ($i = 0; $i < $rows; $i++) {
            $l = isset($left[$i]) ? ($this->letter($i) . ') ' . $this->esc((string)$left[$i])) : '';
            $r = isset($right[$i]) ? ($this->esc((string)($i + 1)) . ') ' . $this->esc((string)$right[$i])) : '';
            $html .= '<tr>'
                  . '<td style="padding:6px 12px; width:45%;">' . $l . '</td>'
                  . '<td style="width:10%; text-align:center; color:#94a3b8;">—</td>'
                  . '<td style="padding:6px 12px; width:45%;">' . $r . '</td>'
                  . '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * Детерминированное перемешивание: без random, чтобы один и тот же материал
     * рендерился одинаково при каждом скачивании. Для ≤2 элементов — реверс,
     * для большего — циклический сдвиг на (n div 2), гарантированно ломающий
     * исходное построчное соответствие.
     */
    private function deterministicShuffle(array $items): array
    {
        $n = count($items);
        if ($n < 2) {
            return $items;
        }
        if ($n === 2) {
            return array_reverse($items);
        }
        $shift = intdiv($n, 2);
        return array_merge(array_slice($items, $shift), array_slice($items, 0, $shift));
    }

    /**
     * Вопросы теста — бланк ученика: без отметок правильных ответов и пояснений.
     * Для open-вопроса (без вариантов) добавляем линии под ответ.
     */
    private function renderQuestions(array $questions): string
    {
        $html = '<ol class="md-questions">';
        $currentBlock = null;
        $n = 0; // сквозная нумерация, чтобы сохранять её при разрыве списка на блоки
        foreach ($questions as $q) {
            $n++;
            $type = strtolower(trim((string)($q['type'] ?? '')));
            // Блоки контрольной: от простого к сложному (А — тесты, В — краткий ответ, С — развёрнутый).
            // Разрываем <ol> и продолжаем нумерацию через <li value> — иначе вложенный </ol>
            // ломает разметку и mPDF сбрасывает счётчик.
            $block = trim((string)($q['block'] ?? ''));
            if ($block !== '' && $block !== $currentBlock) {
                $html .= '</ol><h3 class="md-block">Блок ' . $this->esc($block) . '</h3>'
                       . '<ol class="md-questions" start="' . $n . '">';
                $currentBlock = $block;
            }
            $star = $this->isAdvanced($q['level'] ?? '')
                ? ' <span class="md-level md-level-adv" title="Повышенный уровень">★</span>'
                : '';
            $html .= '<li value="' . $n . '">';
            $html .= '<div>' . $this->esc($q['text'] ?? '') . $star . '</div>';
            // Маркеры выбора — ASCII-безопасные «[ ]» (множественный) / «( )» (одиночный):
            // символы ☐/○ отсутствуют в шрифте freesans, и mPDF рисовал их пустым «тофу»-квадратом.
            $hasOptions = !empty($q['options']) && is_array($q['options'])
                && count(array_filter($q['options'], fn($o) => trim((string)$o) !== '')) > 0;
            if ($hasOptions) {
                $marker = ($type === 'multiple') ? '[ ]' : '( )';
                $html .= '<ul style="list-style:none; padding-left:4px;">';
                $li = 0;
                foreach ($q['options'] as $opt) {
                    if (trim((string)$opt) === '') {
                        continue; // пропускаем пустые варианты, чтобы не было «висящих» строк
                    }
                    $html .= '<li>' . $marker . ' ' . $this->letter($li) . ') ' . $this->esc((string)$opt) . '</li>';
                    $li++;
                }
                $html .= '</ul>';
            } else {
                // open ИЛИ вопрос выбора, у которого модель не вернула варианты —
                // даём линии под ответ, чтобы вопрос не остался без поля для ответа.
                $html .= '<div class="md-writelines">'
                       . str_repeat('<div class="md-writeline"></div>', 5)
                       . '</div>';
            }
            $html .= '</li>';
        }
        return $html . '</ol>';
    }

    /**
     * Собирает раздел «Ключи для учителя» из answer_key и/или правильных ответов вопросов.
     */
    private function buildAnswerKey(array $data): string
    {
        $items = [];

        // Ключи рабочего листа
        if (!empty($data['answer_key']) && is_array($data['answer_key'])) {
            foreach ($data['answer_key'] as $k) {
                $num = $k['number'] ?? null;
                $ans = trim($this->stringifyAnswer($k['answer'] ?? ''));
                $expl = trim($this->stringifyAnswer($k['explanation'] ?? ''));
                if ($ans === '' && $expl === '' && !isset($k['correct'])) {
                    continue;
                }
                $prefix = ($num !== null ? $this->esc((string)$num) . '. ' : '');
                $parts = [];
                if (isset($k['correct'])) {
                    $letters = $this->correctLetters($k['correct']);
                    if ($letters !== '') {
                        $parts[] = $letters;
                    }
                }
                if ($ans !== '') {
                    $parts[] = $this->esc($ans);
                }
                if (empty($parts) && $expl === '') {
                    continue;
                }
                $line = $prefix . implode(' — ', $parts);
                if ($expl !== '') {
                    $line .= ' <span style="color:#555;">(' . $this->esc($expl) . ')</span>';
                }
                $items[] = $line;
            }
        }

        if (empty($items)) {
            return '';
        }
        // Список без авто-нумерации: номер вопроса уже есть в начале каждой строки
        // ($num. …). Иначе <ol> добавлял свой счётчик и номер задваивался («5. 5.»).
        return '<ul class="md-answerkey" style="list-style:none; padding-left:0; margin-left:0;">'
             . implode('', array_map(fn($l) => '<li>' . $l . '</li>', $items))
             . '</ul>';
    }

    /**
     * Переводит индексы правильных вариантов в буквы (0→A, 1→B…).
     * Устойчив к тому, что ИИ вернёт вложенный массив ([[0],[1]] вместо [0,1])
     * или нечисловые значения — иначе в ключах появлялось слово «Array».
     */
    private function correctLetters($correct): string
    {
        $flat = [];
        foreach ((array)$correct as $item) {
            if (is_array($item)) {
                foreach ($item as $sub) {
                    if (is_scalar($sub) && is_numeric($sub)) {
                        $flat[] = (int)$sub;
                    }
                }
            } elseif (is_scalar($item) && is_numeric($item)) {
                $flat[] = (int)$item;
            }
        }
        if (empty($flat)) {
            return '';
        }
        $letters = array_map(fn($i) => $this->letter($i), $flat);
        return 'Верно: ' . $this->esc(implode(', ', $letters));
    }

    /**
     * Безопасно приводит значение ответа/пояснения к строке.
     * Массив (плоский или пары [левое, правое]) превращается в читаемый текст,
     * чтобы в «Ключах для учителя» не выводилось буквальное «Array».
     */
    private function stringifyAnswer($value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    // Пара сопоставления [left, right] → «left → right»
                    $pair = array_map(fn($v) => is_scalar($v) ? (string)$v : '', $item);
                    $pair = array_values(array_filter($pair, fn($v) => $v !== ''));
                    $parts[] = implode(' → ', $pair);
                } elseif (is_scalar($item)) {
                    $parts[] = (string)$item;
                }
            }
            return implode(', ', array_filter($parts, fn($v) => $v !== ''));
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Индекс → буква варианта (0→A … 25→Z), с защитой от выхода за диапазон.
     */
    private function letter(int $i): string
    {
        return ($i >= 0 && $i < 26) ? chr(65 + $i) : (string)($i + 1);
    }

    private function renderSlides(array $slides): string
    {
        $html = '<div class="md-slides">';
        foreach ($slides as $i => $s) {
            $num = (int)($s['number'] ?? ($i + 1));
            $html .= '<article class="md-slide">';
            $html .= '<div class="md-slide__bar">'
                  . '<span class="md-slide__num">' . $num . '</span>'
                  . '<h3 class="md-slide__title">' . $this->esc($s['title'] ?? '') . '</h3>'
                  . '</div>';
            $html .= '<div class="md-slide__body">';
            // Иллюстрация слайда (если сгенерирована) — слева текст, справа картинка.
            $imgUrl = trim((string)($s['_image_url'] ?? ''));
            if ($imgUrl !== '') {
                $html .= '<div class="md-slide__media"><img src="' . $this->esc($imgUrl)
                       . '" alt="" style="max-width:280px; width:100%; border-radius:8px;"></div>';
            }
            if (!empty($s['bullets']) && is_array($s['bullets'])) {
                $html .= '<ul class="md-slide__bullets">';
                foreach ($s['bullets'] as $b) {
                    $html .= '<li>' . $this->esc($b) . '</li>';
                }
                $html .= '</ul>';
            }
            if (!empty($s['notes'])) {
                $html .= '<p class="md-slide__notes"><em>Заметки докладчика:</em> '
                      . $this->esc($s['notes']) . '</p>';
            }
            $html .= '</div></article>';
        }
        return $html . '</div>';
    }

    private function renderKtpRows(array $rows): string
    {
        $hasResults = $this->hasField($rows, 'planned_results');
        $html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';
        $html .= '<thead><tr><th>№</th><th>Тема</th><th>Часов</th>'
              . ($hasResults ? '<th>Планируемые результаты</th>' : '')
              . '<th>УУД</th><th>Деятельность</th><th>Контроль</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr>'
                  . '<td>' . $this->esc((string)($r['lesson_num'] ?? '')) . '</td>'
                  . '<td>' . $this->esc((string)($r['topic'] ?? '')) . '</td>'
                  . '<td>' . $this->esc((string)($r['hours'] ?? '')) . '</td>'
                  . ($hasResults ? '<td>' . $this->esc($this->stringifyAnswer($r['planned_results'] ?? '')) . '</td>' : '')
                  . '<td>' . $this->esc($this->stringifyAnswer($r['uud'] ?? '')) . '</td>'
                  . '<td>' . $this->esc($this->stringifyAnswer($r['activity'] ?? '')) . '</td>'
                  . '<td>' . $this->esc($this->stringifyAnswer($r['control'] ?? '')) . '</td>'
                  . '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /**
     * Признак задания/вопроса повышенного уровня. Терпим к вариантам, которые
     * иногда возвращает ИИ: advanced / increased / повышенный / high.
     */
    private function isAdvanced($level): bool
    {
        $l = mb_strtolower(trim((string)$level));
        return in_array($l, ['advanced', 'increased', 'повышенный', 'high', 'продвинутый'], true);
    }

    private function esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
