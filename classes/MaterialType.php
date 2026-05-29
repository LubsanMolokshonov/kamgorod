<?php
/**
 * MaterialType — типы материалов ФОП (техкарта, конспект, рабочий лист, тест,
 * презентация, классный час, КТП-фрагмент). Хранит шаблон промпта для ИИ
 * (плейсхолдеры {subject}, {class}, {topic}, {duration}, {features},
 * {program}, {questions_count}, {slides_count}, {hours}).
 *
 * Плейсхолдер {stage} (ступень ДО/НОО/ООО/СОО) — ВЫЧИСЛЯЕМЫЙ: подставляется
 * автоматически в renderPrompt() через deriveStage() по классу/программе.
 * В форму как отдельное поле НЕ выводится (см. material-generator-form.php).
 */

class MaterialType
{
    private $db;

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
    }

    public function getAll(): array
    {
        return $this->db->query(
            "SELECT * FROM material_types WHERE is_active = 1 ORDER BY display_order ASC"
        );
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->queryOne("SELECT * FROM material_types WHERE id = ?", [$id]);
        return $row ?: null;
    }

    public function getBySlug(string $slug): ?array
    {
        $row = $this->db->queryOne(
            "SELECT * FROM material_types WHERE slug = ? AND is_active = 1",
            [$slug]
        );
        return $row ?: null;
    }

    public function getWithCounts(): array
    {
        return $this->db->query(
            "SELECT mt.*, COUNT(m.id) AS materials_count
             FROM material_types mt
             LEFT JOIN materials m ON mt.id = m.material_type_id AND m.status = 'published'
             WHERE mt.is_active = 1
             GROUP BY mt.id
             ORDER BY mt.display_order ASC"
        );
    }

    /**
     * Подставить параметры в шаблон промпта.
     * Незаполненные плейсхолдеры заменяются на «—», чтобы ИИ не получил «{class}».
     */
    public function renderPrompt(int $id, array $params): ?string
    {
        $type = $this->getById($id);
        if (!$type || empty($type['ai_prompt_template'])) {
            return null;
        }
        $template = (string)$type['ai_prompt_template'];

        // Вычисляемый плейсхолдер {stage} — ступень образования (ДО/НОО/ООО/СОО).
        // Промпты адресны по возрасту, поэтому ступень определяем явно, а не оставляем
        // ИИ угадывать. Источник — поле «Класс/группа» и выбранная программа.
        if (!isset($params['stage']) || $params['stage'] === '') {
            $params['stage'] = self::deriveStage($params['class'] ?? '', $params['program'] ?? '');
        }

        $replacements = [];
        preg_match_all('/\{([a-z_]+)\}/i', $template, $matches);
        foreach (array_unique($matches[1]) as $key) {
            $value = $params[$key] ?? null;
            $replacements['{' . $key . '}'] = ($value === null || $value === '') ? '—' : (string)$value;
        }
        return strtr($template, $replacements);
    }

    /**
     * Определяет ступень образования (ДО / НОО / ООО / СОО) по тексту класса/группы
     * и/или выбранной программе. Используется как плейсхолдер {stage} в промптах.
     */
    public static function deriveStage(string $class, string $program = ''): string
    {
        $haystack = mb_strtolower($class . ' ' . $program);
        $prog = mb_strtolower($program);

        // Сначала пробуем по программе — она однозначна (всё в lowercase).
        if (str_contains($prog, 'фоп до') || str_contains($haystack, 'дошкол') || str_contains($haystack, 'групп')) {
            return 'ДО (дошкольное образование)';
        }
        if (str_contains($prog, 'ноо')) return 'НОО (начальное общее, 1–4 класс)';
        if (str_contains($prog, 'ооо')) return 'ООО (основное общее, 5–9 класс)';
        if (str_contains($prog, 'соо')) return 'СОО (среднее общее, 10–11 класс)';

        // Дошкольные маркеры в классе/группе.
        if (preg_match('/дошкол|младш(ая|яя)\s*групп|старш(ая|яя)\s*групп|подготовит|\bдоо\b|\bдо\b|ясли/u', $haystack)) {
            return 'ДО (дошкольное образование)';
        }

        // По номеру класса.
        if (preg_match('/(\d{1,2})\s*клас/u', $haystack, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 4)  return 'НОО (начальное общее, 1–4 класс)';
            if ($n >= 5 && $n <= 9)  return 'ООО (основное общее, 5–9 класс)';
            if ($n >= 10 && $n <= 11) return 'СОО (среднее общее, 10–11 класс)';
        }

        return '';
    }

    public function create(array $data): int
    {
        return $this->db->insert('material_types', [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? '',
            'icon' => $data['icon'] ?? 'fa-file',
            'output_format' => $data['output_format'] ?? 'pdf',
            'token_cost_default' => $data['token_cost_default'] ?? 10,
            'ai_prompt_template' => $data['ai_prompt_template'] ?? '',
            'ai_model_key' => $data['ai_model_key'] ?? 'default',
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1,
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = [
            'name', 'slug', 'description', 'icon', 'output_format',
            'token_cost_default', 'ai_prompt_template', 'ai_model_key',
            'display_order', 'is_active',
        ];
        $update = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        if (empty($update)) {
            return 0;
        }
        return $this->db->update('material_types', $update, 'id = ?', [$id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('material_types', 'id = ?', [$id]);
    }
}
