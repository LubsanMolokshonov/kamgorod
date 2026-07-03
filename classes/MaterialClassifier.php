<?php
/**
 * Классификация материала по параметрам генерации (ai_params_json).
 *
 * Выводит из свободного текста «class» и «program»:
 *  - коды program_compliance (SET в таблице materials);
 *  - ступень образования → слаг audience_types для привязки аудитории.
 *
 * Чистые статические функции без БД — используются и в пайплайне генерации
 * (MaterialGenerator), и в бэкфилле (scripts/backfill-material-classification.php).
 */
class MaterialClassifier
{
    /** Метка программы из формы генератора → код SET program_compliance */
    private const PROGRAM_LABEL_MAP = [
        'ФОП ДО'     => 'fop_do',
        'ФОП НОО'    => 'fop_noo',
        'ФОП ООО'    => 'fop_ooo',
        'ФОП СОО'    => 'fop_soo',
        'ФАОП (ОВЗ)' => 'faop_ovz',
        'ФАОП'       => 'faop_ovz',
        'ФГОС 2021'  => 'fgos_2021',
        'ФГОС 2026'  => 'fgos_2026',
        // Редкие legacy-метки: ступень указана прямо в названии стандарта
        'ФГОС НОО'   => 'fop_noo',
        'ФГОС ООО'   => 'fop_ooo',
        'ФГОС СОО'   => 'fop_soo',
        'ФГОС ДО'    => 'fop_do',
    ];

    /** Ступень → код ФОП для program_compliance */
    private const STAGE_PROGRAM_MAP = [
        'do'  => 'fop_do',
        'noo' => 'fop_noo',
        'ooo' => 'fop_ooo',
        'soo' => 'fop_soo',
    ];

    /** Ступень → слаг audience_types (категория pedagogi) */
    private const STAGE_AUDIENCE_MAP = [
        'do'  => 'dou',
        'noo' => 'nachalnaya-shkola',
        'ooo' => 'srednyaya-starshaya-shkola',
        'soo' => 'srednyaya-starshaya-shkola',
        'spo' => 'spo',
    ];

    /**
     * Ступень образования из параметров генерации: do|noo|ooo|soo|spo|null.
     * Приоритет — поле «class» (конкретнее), фолбэк — метка программы.
     */
    public static function deriveStage(array $params): ?string
    {
        $class = mb_strtolower(trim((string)($params['class'] ?? '')));

        if ($class !== '') {
            // СПО: «3 курс», «студенты», «спо»
            if (preg_match('/курс|спо|студент|колледж|техникум/u', $class)) {
                return 'spo';
            }
            // ДОУ: «старшая группа», «подготовительная группа», «дошкольники», «детский сад»
            if (preg_match('/групп|дошкол|доу|сад|ясл/u', $class)) {
                return 'do';
            }
            // Возраст «5-6 лет», «6-7 лет» → ДОУ до 7 лет включительно
            if (preg_match('/(\d{1,2})[^\d]*лет/u', $class, $m)) {
                $age = (int)$m[1];
                if ($age <= 7)  { return 'do'; }
                if ($age <= 10) { return 'noo'; }
                return $age <= 15 ? 'ooo' : 'soo';
            }
            // Класс: «3 класс», «3-5 класс с овз», «3класс», голое «8», возраст «13-14»
            if (preg_match('/(\d{1,2})/u', $class, $m)) {
                $n = (int)$m[1];
                $isAge = !preg_match('/класс|кл\b/u', $class) && $n >= 12; // «13-14» без слова «класс» — возраст
                if ($isAge) {
                    return $n <= 15 ? 'ooo' : 'soo';
                }
                if ($n >= 1 && $n <= 4)   { return 'noo'; }
                if ($n >= 5 && $n <= 9)   { return 'ooo'; }
                if ($n >= 10 && $n <= 11) { return 'soo'; }
            }
        }

        // Фолбэк: ступень из метки программы
        $code = self::programLabelToCode((string)($params['program'] ?? ''));
        $byProgram = ['fop_do' => 'do', 'fop_noo' => 'noo', 'fop_ooo' => 'ooo', 'fop_soo' => 'soo'];
        return $byProgram[$code] ?? null;
    }

    /**
     * Коды program_compliance: прямое соответствие метке программы
     * + код ФОП по ступени (метка «ФГОС 2026» сама по себе ступень не задаёт).
     *
     * @return string[] например ['fgos_2026', 'fop_noo']
     */
    public static function derivePrograms(array $params): array
    {
        $codes = [];
        $direct = self::programLabelToCode((string)($params['program'] ?? ''));
        if ($direct !== null) {
            $codes[] = $direct;
        }
        $stage = self::deriveStage($params);
        if ($stage !== null && isset(self::STAGE_PROGRAM_MAP[$stage])) {
            $codes[] = self::STAGE_PROGRAM_MAP[$stage];
        }
        return array_values(array_unique($codes));
    }

    /** Слаг audience_types (внутри категории pedagogi) для ступени материала */
    public static function audienceTypeSlug(array $params): ?string
    {
        $stage = self::deriveStage($params);
        return $stage !== null ? (self::STAGE_AUDIENCE_MAP[$stage] ?? null) : null;
    }

    private static function programLabelToCode(string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        foreach (self::PROGRAM_LABEL_MAP as $needle => $code) {
            if (mb_stripos($label, $needle) !== false) {
                return $code;
            }
        }
        return null;
    }
}
