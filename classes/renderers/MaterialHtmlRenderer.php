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
    public function render(array $data): string
    {
        $html = '';

        if (!empty($data['title'])) {
            $html .= '<h1>' . $this->esc($data['title']) . '</h1>';
        }
        if (!empty($data['section'])) {
            $html .= '<p><strong>Раздел:</strong> ' . $this->esc($data['section']) . '</p>';
        }
        if (!empty($data['intro'])) {
            $html .= '<p>' . $this->esc($data['intro']) . '</p>';
        }
        if (!empty($data['goal'])) {
            $html .= '<p><strong>Цель:</strong> ' . $this->esc($data['goal']) . '</p>';
        }

        if (!empty($data['goals']) && is_array($data['goals'])) {
            $html .= '<h2>Цели</h2>' . $this->renderList($data['goals']);
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
        if (!empty($data['answer_key']) && is_array($data['answer_key'])) {
            $html .= '<h2>Ключи к заданиям</h2>' . $this->renderAnswerKey($data['answer_key']);
        }

        // Тестовые вопросы
        if (!empty($data['questions']) && is_array($data['questions'])) {
            $html .= '<h2>Вопросы</h2>' . $this->renderQuestions($data['questions']);
        }

        // Презентация — слайды (PPTX рендерится отдельно, тут текстовое превью)
        if (!empty($data['slides']) && is_array($data['slides'])) {
            $html .= '<h2>Слайды</h2>' . $this->renderSlides($data['slides']);
        }

        // КТП
        if (!empty($data['rows']) && is_array($data['rows'])) {
            $html .= '<h2>Тематическое планирование</h2>' . $this->renderKtpRows($data['rows']);
        }

        // Доп. вопросы для обсуждения
        if (!empty($data['discussion_questions']) && is_array($data['discussion_questions'])) {
            $html .= '<h2>Вопросы для обсуждения</h2>' . $this->renderList($data['discussion_questions']);
        }

        if (!empty($data['homework'])) {
            $html .= '<h2>Домашнее задание</h2><p>' . $this->esc($data['homework']) . '</p>';
        }
        if (!empty($data['reflection'])) {
            $html .= '<h2>Рефлексия</h2><p>' . $this->esc($data['reflection']) . '</p>';
        }

        return $html;
    }

    private function renderList(array $items): string
    {
        $html = '<ul>';
        foreach ($items as $item) {
            $html .= '<li>' . $this->esc((string)$item) . '</li>';
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
                $html .= '<p>' . nl2br($this->esc($body)) . '</p>';
            }
        }
        return $html;
    }

    private function renderTasks(array $tasks): string
    {
        $html = '<ol>';
        foreach ($tasks as $t) {
            $html .= '<li>';
            if (!empty($t['instruction'])) {
                $html .= '<strong>' . $this->esc($t['instruction']) . '</strong>';
            }
            if (!empty($t['content'])) {
                $html .= '<div>' . nl2br($this->esc($t['content'])) . '</div>';
            }
            if (!empty($t['type'])) {
                $html .= '<div style="color:#888; font-size:smaller;">тип: '
                      . $this->esc($t['type']) . '</div>';
            }
            $html .= '</li>';
        }
        return $html . '</ol>';
    }

    private function renderAnswerKey(array $keys): string
    {
        $html = '<ol>';
        foreach ($keys as $k) {
            $html .= '<li>' . $this->esc((string)($k['answer'] ?? '')) . '</li>';
        }
        return $html . '</ol>';
    }

    private function renderQuestions(array $questions): string
    {
        $html = '<ol>';
        foreach ($questions as $q) {
            $html .= '<li>';
            $html .= '<div>' . $this->esc($q['text'] ?? '') . '</div>';
            if (!empty($q['options']) && is_array($q['options'])) {
                $html .= '<ul>';
                foreach ($q['options'] as $idx => $opt) {
                    $letter = chr(65 + $idx);
                    $isCorrect = in_array($idx, (array)($q['correct'] ?? []), true);
                    $html .= '<li>' . $letter . ') ' . $this->esc((string)$opt)
                          . ($isCorrect ? ' <strong>[верно]</strong>' : '') . '</li>';
                }
                $html .= '</ul>';
            }
            if (!empty($q['explanation'])) {
                $html .= '<div style="color:#555; font-size:smaller;"><em>Пояснение:</em> '
                      . $this->esc($q['explanation']) . '</div>';
            }
            $html .= '</li>';
        }
        return $html . '</ol>';
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
        $html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';
        $html .= '<thead><tr><th>№</th><th>Тема</th><th>Часов</th><th>УУД</th><th>Деятельность</th><th>Контроль</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr>'
                  . '<td>' . $this->esc((string)($r['lesson_num'] ?? '')) . '</td>'
                  . '<td>' . $this->esc((string)($r['topic'] ?? '')) . '</td>'
                  . '<td>' . $this->esc((string)($r['hours'] ?? '')) . '</td>'
                  . '<td>' . $this->esc((string)($r['uud'] ?? '')) . '</td>'
                  . '<td>' . $this->esc((string)($r['activity'] ?? '')) . '</td>'
                  . '<td>' . $this->esc((string)($r['control'] ?? '')) . '</td>'
                  . '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private function esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
