<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/seeded-shuffle.php';

/**
 * Контент детальной страницы олимпиады.
 *
 * Предгенерированный hero/about/FAQ хранится в olympiad_page_content. Пока строка
 * отсутствует, устарела или не проходит валидацию, страница использует безопасный
 * fallback из существующих полей olympiads и проверенного FAQ-пула.
 */
class OlympiadPageContent
{
    public const CONTENT_VERSION = 1;
    public const ABOUT_MIN_LENGTH = 1400;
    public const ABOUT_MAX_LENGTH = 1600;
    public const HERO_MIN_LENGTH = 180;
    public const HERO_MAX_LENGTH = 320;

    private Database $db;

    public function __construct(PDO $pdo)
    {
        $this->db = new Database($pdo);
    }

    /** @return array{title:string,hero_text:string,about_html:string,faq:array,benefits:array,steps:array,is_generated:bool,source_hash:string} */
    public function get(array $olympiad): array
    {
        try {
            $questionRows = $this->db->query(
                'SELECT question_text FROM olympiad_questions WHERE olympiad_id = ? ORDER BY display_order, id',
                [(int)($olympiad['id'] ?? 0)]
            );
            $olympiad['_question_texts'] = array_column($questionRows, 'question_text');
        } catch (Throwable $e) {
            $olympiad['_question_texts'] = [];
        }
        $sourceHash = self::sourceHash($olympiad);
        $stored = null;

        try {
            $stored = $this->db->queryOne(
                'SELECT content_version, hero_text, about_html, faq_json, source_hash
                 FROM olympiad_page_content WHERE olympiad_id = ?',
                [(int)($olympiad['id'] ?? 0)]
            );
        } catch (Throwable $e) {
            // Совместимый rollout: код может быть развёрнут до миграции.
            $stored = null;
        }

        $faq = [];
        $isGenerated = false;
        if ($stored
            && (int)$stored['content_version'] === self::CONTENT_VERSION
            && hash_equals($sourceHash, (string)$stored['source_hash'])) {
            $decodedFaq = json_decode((string)$stored['faq_json'], true);
            if (self::isValidHero((string)$stored['hero_text'])
                && self::isValidAbout((string)$stored['about_html'])
                && self::isValidFaq($decodedFaq)) {
                $hero = self::normalizeWhitespace((string)$stored['hero_text']);
                $about = self::sanitizeAboutHtml((string)$stored['about_html']);
                $faq = self::sanitizeFaq($decodedFaq);
                $isGenerated = true;
            }
        }

        if (!$isGenerated) {
            $hero = self::fallbackHero($olympiad);
            $about = self::fallbackAbout($olympiad);
            $faq = self::buildFaq($olympiad, 10);
        }

        return [
            'title' => self::normalizeTitle((string)($olympiad['title'] ?? '')),
            'hero_text' => $hero,
            'about_html' => $about,
            'faq' => $faq,
            'benefits' => self::buildVariantBlocks($olympiad, self::benefitPools()),
            'steps' => self::buildVariantBlocks($olympiad, self::stepPools()),
            'is_generated' => $isGenerated,
            'source_hash' => $sourceHash,
        ];
    }

    public static function sourceHash(array $olympiad): string
    {
        $source = [
            'id' => (int)($olympiad['id'] ?? 0),
            'title' => (string)($olympiad['title'] ?? ''),
            'description' => (string)($olympiad['description'] ?? ''),
            'seo_content' => (string)($olympiad['seo_content'] ?? ''),
            'subject' => (string)($olympiad['subject'] ?? ''),
            'grade' => (string)($olympiad['grade'] ?? ''),
            'target_audience' => (string)($olympiad['target_audience'] ?? ''),
            'updated_at' => (string)($olympiad['updated_at'] ?? ''),
            'question_texts' => array_values(array_map(
                static fn($text): string => self::normalizeWhitespace(strip_tags((string)$text)),
                (array)($olympiad['_question_texts'] ?? [])
            )),
        ];
        return hash('sha256', json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function normalizeTitle(string $title): string
    {
        $title = self::normalizeWhitespace(strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        do {
            $before = $title;
            $title = preg_replace('/^всероссийская\s+олимпиада\s*[:—–-]?\s*/iu', '', $title) ?? $title;
            $title = preg_replace('/^олимпиада\s*[:—–-]?\s*/iu', '', $title) ?? $title;
        } while ($title !== $before);
        return trim($title, " \t\n\r\0\x0B«»\"");
    }

    public static function sentenceCount(string $text): int
    {
        $text = self::normalizeWhitespace(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '') {
            return 0;
        }
        preg_match_all('/[.!?]+(?=\s|$)/u', $text, $matches);
        return max(1, count($matches[0]));
    }

    public static function visibleLength(string $html): int
    {
        return mb_strlen(self::normalizeWhitespace(strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }

    public static function isValidHero(string $text): bool
    {
        $length = self::visibleLength($text);
        $sentences = self::sentenceCount($text);
        return $length >= self::HERO_MIN_LENGTH && $length <= self::HERO_MAX_LENGTH
            && $sentences >= 2 && $sentences <= 3
            && !self::hasPlaceholder($text);
    }

    public static function isValidAbout(string $html): bool
    {
        $length = self::visibleLength($html);
        return $length >= self::ABOUT_MIN_LENGTH && $length <= self::ABOUT_MAX_LENGTH
            && !self::hasPlaceholder($html)
            && self::sanitizeAboutHtml($html) === trim($html);
    }

    public static function isValidFaq($faq): bool
    {
        if (!is_array($faq) || count($faq) !== 10) {
            return false;
        }
        $keys = [];
        $questions = [];
        foreach ($faq as $item) {
            if (!is_array($item)) {
                return false;
            }
            $key = trim((string)($item['key'] ?? ''));
            $question = self::sanitizePlainText((string)($item['question'] ?? ''));
            $answer = self::sanitizePlainText((string)($item['answer'] ?? ''));
            if ($key === '' || $question === '' || $answer === '' || self::hasPlaceholder($question . $answer)) {
                return false;
            }
            if (isset($keys[$key]) || isset($questions[mb_strtolower($question)])) {
                return false;
            }
            $sentences = self::sentenceCount($answer);
            if ($sentences < 2 || $sentences > 3) {
                return false;
            }
            $keys[$key] = true;
            $questions[mb_strtolower($question)] = true;
        }
        return true;
    }

    /** @return array<int,array{key:string,q:string,a:string}> */
    public static function buildFaq(array $olympiad, int $count = 10): array
    {
        $pool = self::faqPool();
        $count = max(1, min(10, $count));
        $seed = 'olympiad-faq:' . (int)($olympiad['id'] ?? 0) . ':v' . self::CONTENT_VERSION;
        $mandatoryGroups = ['process', 'result', 'repeat', 'diploma', 'payment', 'audience', 'documents', 'support'];
        $chosen = [];
        $chosenKeys = [];

        foreach ($mandatoryGroups as $group) {
            $groupItems = array_values(array_filter($pool, static fn(array $item): bool => $item['group'] === $group));
            $item = seededShuffle($groupItems, $seed . ':' . $group)[0] ?? null;
            if ($item) {
                $chosen[] = $item;
                $chosenKeys[$item['key']] = true;
            }
        }

        foreach (seededShuffle($pool, $seed . ':rest') as $item) {
            if (count($chosen) >= $count) {
                break;
            }
            if (!isset($chosenKeys[$item['key']])) {
                $chosen[] = $item;
                $chosenKeys[$item['key']] = true;
            }
        }
        $chosen = array_slice(seededShuffle($chosen, $seed . ':final'), 0, $count);

        $vars = self::templateVars($olympiad);
        return array_map(static function (array $item) use ($vars): array {
            return [
                'key' => $item['key'],
                'q' => self::sanitizePlainText(strtr($item['question'], $vars)),
                'a' => self::sanitizePlainText(strtr($item['answer'], $vars)),
            ];
        }, $chosen);
    }

    /** @return array<string,array{title:string,variants:array<int,string>}> */
    public static function benefitPools(): array
    {
        return [
            'remote' => ['title' => 'Дистанционный формат', 'variants' => [
                'Пройдите «{title}» онлайн из любого региона — нужен только доступ в интернет.',
                'Участвуйте в олимпиаде «{title}» дистанционно, без поездок и привязки к месту.',
                'Тест по теме «{title}» доступен онлайн: проходите его дома, в школе или в дороге.',
                'Для участия в «{title}» не нужно приезжать на площадку — весь процесс проходит онлайн.',
                'Проверить знания по теме «{title}» можно дистанционно из любой точки России.',
                'Олимпиада «{title}» проходит полностью онлайн и подходит аудитории: {audience}.',
                'Выберите удобное место и пройдите «{title}» в дистанционном формате.',
                'Участие в «{title}» организовано онлайн — без очной регистрации и поездок.',
                'Задания олимпиады «{title}» открываются в браузере и доступны дистанционно.',
                'Пройти тестирование «{title}» можно онлайн в привычной и спокойной обстановке.',
            ]],
            'fast' => ['title' => 'Быстрый результат', 'variants' => [
                'Результат по олимпиаде «{title}» появится сразу после ответа на последний вопрос.',
                'После завершения теста «{title}» система сразу покажет количество верных ответов.',
                'Не нужно ждать проверки: итог олимпиады «{title}» рассчитывается автоматически.',
                'Узнайте результат участия в «{title}» сразу после отправки ответов.',
                'Итог тестирования «{title}» доступен без ожидания ручной проверки.',
                'Система мгновенно подсчитает результат олимпиады «{title}» и определит место.',
                'После прохождения «{title}» вы сразу увидите баллы и итоговый статус.',
                'Ответы в олимпиаде «{title}» проверяются автоматически, поэтому результат не задерживается.',
                'Завершите тест «{title}» и сразу переходите к просмотру результата.',
                'Баллы за олимпиаду «{title}» по направлению «{subject}» рассчитываются автоматически.',
            ]],
            'document' => ['title' => 'Официальный документ', 'variants' => [
                'По результату «{title}» можно оформить именной электронный диплом для портфолио.',
                'Участник олимпиады «{title}» из аудитории «{audience}» может дополнить портфолио дипломом.',
                'После прохождения «{title}» доступно оформление электронного диплома с результатом.',
                'Итог олимпиады «{title}» можно подтвердить именным дипломом в электронном виде.',
                'Для участия в «{title}» предусмотрен электронный диплом, который удобно хранить и скачивать.',
                'Оформите диплом по олимпиаде «{title}» и используйте его в профессиональном портфолио.',
                'Электронный диплом «{title}» содержит имя участника и достигнутый результат.',
                'Результат участия в «{title}» можно зафиксировать в именном дипломе.',
                'После теста «{title}» участнику доступно оформление персонального диплома.',
                'Диплом по олимпиаде «{title}» формируется электронно и остаётся доступен для скачивания.',
            ]],
            'free' => ['title' => 'Бесплатное участие', 'variants' => [
                'Пройти задания олимпиады «{title}» и узнать результат можно бесплатно.',
                'Участие в «{title}» не требует оплаты — решение об оформлении диплома принимается отдельно.',
                'Тестирование «{title}» доступно бесплатно, без регистрационного взноса.',
                'Проверяйте знания в олимпиаде «{title}» бесплатно и оплачивайте только выбранный документ.',
                'За прохождение «{title}» платить не нужно — участие и результат бесплатны.',
                'Олимпиада «{title}» открыта для бесплатного прохождения; аудитория: {audience}.',
                'Начните «{title}» без оплаты и сначала познакомьтесь с заданиями.',
                'Бесплатный формат «{title}» позволяет проверить знания без обязательной покупки диплома.',
                'Участник проходит «{title}» бесплатно; оформление диплома остаётся добровольным.',
                'Доступ к тесту «{title}» предоставляется бесплатно, без скрытых условий участия.',
            ]],
        ];
    }

    /** @return array<string,array{title:string,variants:array<int,string>}> */
    public static function stepPools(): array
    {
        return [
            'registration' => ['title' => 'Регистрация', 'variants' => [
                'Укажите имя и email, чтобы начать олимпиаду «{title}» и сохранить результат.',
                'Заполните короткую форму участника «{title}» — потребуются имя и электронная почта.',
                'Зарегистрируйтесь для прохождения «{title}» ({grade}), указав email и ФИО.',
                'Создайте заявку на «{title}» за несколько минут с помощью имени и email.',
                'Перед стартом «{title}» внесите ФИО и адрес электронной почты в форму.',
                'Оставьте основные данные участника, чтобы перейти к заданиям «{title}».',
                'Начните участие в «{title}» с простой регистрации по имени и email.',
                'Для доступа к тесту «{title}» заполните данные участника в короткой форме.',
                'Введите ФИО и email — после этого откроются вопросы олимпиады «{title}».',
                'Регистрация на «{title}» занимает несколько минут и нужна для сохранения итога.',
            ]],
            'test' => ['title' => 'Прохождение теста', 'variants' => [
                'Ответьте на 10 вопросов по теме «{title}» в удобном темпе.',
                'Пройдите тест из 10 заданий, подготовленных для олимпиады «{title}».',
                'Выполните десять вопросов олимпиады «{title}» без ограничения по времени.',
                'Проверьте знания по направлению «{subject}» в десяти вопросах теста «{title}».',
                'Последовательно ответьте на 10 тематических вопросов олимпиады «{title}».',
                'Тест «{title}» включает десять заданий с вариантами ответа.',
                'Выберите ответы на десять вопросов по содержанию олимпиады «{title}».',
                'Пройдите задания «{title}»: система предложит 10 вопросов по заявленной теме.',
                'Оцените знания по теме «{title}», выполнив десять тестовых заданий.',
                'В рамках «{title}» вас ждут 10 вопросов по направлению «{subject}».',
            ]],
            'result' => ['title' => 'Результат', 'variants' => [
                'После завершения «{title}» сразу посмотрите баллы и достигнутое место.',
                'Система проверит ответы «{title}» и без задержки покажет итог.',
                'Узнайте число верных ответов и результат олимпиады «{title}» сразу после теста.',
                'Итог прохождения «{title}» появится на экране после отправки ответов.',
                'Получите автоматический расчёт баллов по «{title}» в направлении «{subject}».',
                'Завершите «{title}», чтобы сразу увидеть результат и статус участника.',
                'Баллы и место в олимпиаде «{title}» определяются автоматически.',
                'После последнего вопроса «{title}» откроется страница с итогами.',
                'Проверьте результат «{title}» сразу — ручной проверки ждать не требуется.',
                'Система мгновенно подведёт итог вашего участия в «{title}».',
            ]],
            'diploma' => ['title' => 'Диплом', 'variants' => [
                'При желании оформите именной диплом по олимпиаде «{title}» для портфолио.',
                'После результата «{title}» выберите оформление электронного диплома.',
                'Подтвердите участие в «{title}» персональным дипломом в электронном формате.',
                'Оформите диплом «{title}» с именем участника и достигнутым результатом.',
                'Добавьте итог олимпиады «{title}» для аудитории «{audience}» в портфолио с помощью диплома.',
                'Электронный диплом по «{title}» можно оформить после завершения тестирования.',
                'Сохраните достижение в «{title}», оформив персональный диплом.',
                'После прохождения «{title}» участнику доступен именной электронный документ.',
                'Полученный в «{title}» результат можно отразить в дипломе для портфолио.',
                'Завершите участие в «{title}» и при необходимости оформите диплом онлайн.',
            ]],
        ];
    }

    /** @return array<int,array{key:string,group:string,question:string,answer:string}> */
    public static function faqPool(): array
    {
        return [
            ['key'=>'process_online','group'=>'process','question'=>'Как проходит олимпиада «{title}»?','answer'=>'Олимпиада проходит онлайн и включает 10 тестовых вопросов по теме «{title}». После регистрации можно выполнять задания в удобном темпе без очного посещения площадки.'],
            ['key'=>'process_start','group'=>'process','question'=>'Как начать участие в олимпиаде «{title}»?','answer'=>'Для начала укажите имя и email в регистрационной форме, затем перейдите к вопросам. Результат сохранится после завершения всех заданий олимпиады «{title}».'],
            ['key'=>'process_time','group'=>'process','question'=>'Сколько времени занимает олимпиада «{title}»?','answer'=>'Тест состоит из 10 вопросов, поэтому большинство участников проходит его за несколько минут. Жёсткого лимита нет, и над ответами можно подумать в спокойном темпе.'],
            ['key'=>'result_when','group'=>'result','question'=>'Когда станет известен результат олимпиады «{title}»?','answer'=>'Система проверяет ответы автоматически сразу после завершения теста. На итоговой странице отображаются баллы и достигнутое участником место.'],
            ['key'=>'result_place','group'=>'result','question'=>'Как определяется место участника в «{title}»?','answer'=>'Место зависит от количества правильных ответов в тесте. Итоговый статус и баллы рассчитываются автоматически по действующим правилам олимпиады.'],
            ['key'=>'result_save','group'=>'result','question'=>'Сохранится ли результат олимпиады «{title}»?','answer'=>'После завершения теста результат привязывается к данным участника и отображается на итоговой странице. Оформленные документы также остаются доступными в личном кабинете.'],
            ['key'=>'repeat_allowed','group'=>'repeat','question'=>'Можно ли пройти олимпиаду «{title}» повторно?','answer'=>'Да, тест можно пройти ещё раз, если вы хотите улучшить результат или повторно проверить знания. Каждая завершённая попытка оценивается автоматически.'],
            ['key'=>'repeat_questions','group'=>'repeat','question'=>'Изменятся ли вопросы при повторном прохождении «{title}»?','answer'=>'Набор заданий может формироваться из доступных вопросов олимпиады заново. Поэтому повторная попытка помогает проверить не только запомнившиеся ответы, но и понимание темы.'],
            ['key'=>'repeat_best','group'=>'repeat','question'=>'Какой результат «{title}» используется для диплома?','answer'=>'Перед оформлением документа участник видит результат завершённой попытки. Если попыток несколько, следует выбрать подходящий результат на странице оформления.'],
            ['key'=>'diploma_available','group'=>'diploma','question'=>'Можно ли получить диплом за олимпиаду «{title}»?','answer'=>'После прохождения теста можно оформить именной электронный диплом с результатом. Оформление документа добровольное и не влияет на бесплатное участие.'],
            ['key'=>'diploma_format','group'=>'diploma','question'=>'В каком формате выдаётся диплом «{title}»?','answer'=>'Диплом формируется в электронном формате и доступен для скачивания. Его можно сохранить, распечатать и добавить в профессиональное или учебное портфолио.'],
            ['key'=>'diploma_data','group'=>'diploma','question'=>'Какие данные указываются в дипломе «{title}»?','answer'=>'В документе указываются имя участника, название олимпиады и достигнутый результат. Перед оформлением важно проверить правильность введённых персональных данных.'],
            ['key'=>'payment_participation','group'=>'payment','question'=>'Участие в олимпиаде «{title}» бесплатное?','answer'=>'Да, пройти тест и узнать результат можно бесплатно. Оплата возникает только при добровольном оформлении именного диплома.'],
            ['key'=>'payment_diploma','group'=>'payment','question'=>'За что взимается оплата после олимпиады «{title}»?','answer'=>'Само тестирование остаётся бесплатным для участника. Оплачивается только выбранное оформление электронного диплома после получения результата.'],
            ['key'=>'payment_methods','group'=>'payment','question'=>'Как оплатить диплом по олимпиаде «{title}»?','answer'=>'Доступные способы оплаты показываются на странице оформления документа. Платёж проводится через подключённый защищённый сервис, после чего диплом становится доступен участнику.'],
            ['key'=>'audience_who','group'=>'audience','question'=>'Кому подойдёт олимпиада «{title}»?','answer'=>'Олимпиада рассчитана на аудиторию направления «{audience}» и посвящена теме «{subject}». Она помогает проверить знания и определить темы, которые стоит повторить.'],
            ['key'=>'audience_grade','group'=>'audience','question'=>'Для какого класса или уровня предназначена «{title}»?','answer'=>'Уровень олимпиады указан в описании и метках страницы: {grade}. Перед началом стоит проверить, соответствует ли заявленная сложность подготовке участника.'],
            ['key'=>'audience_teacher','group'=>'audience','question'=>'Подойдёт ли «{title}» педагогу?','answer'=>'Тематика олимпиады связана с направлением «{subject}» и может быть полезна педагогам соответствующего профиля. Конкретную аудиторию и уровень подготовки можно проверить в метках над названием.'],
            ['key'=>'questions_count','group'=>'content','question'=>'Сколько вопросов содержит олимпиада «{title}»?','answer'=>'В тест входит 10 вопросов с вариантами ответа. Задания относятся к заявленной теме и проверяют основные знания по направлению «{subject}».'],
            ['key'=>'questions_topic','group'=>'content','question'=>'Какие темы проверяет олимпиада «{title}»?','answer'=>'Вопросы соответствуют предмету «{subject}» и названию олимпиады. Перед прохождением полезно повторить ключевые понятия и практические ситуации по этой теме.'],
            ['key'=>'questions_limit','group'=>'content','question'=>'Есть ли ограничение времени в тесте «{title}»?','answer'=>'Жёсткого ограничения времени на прохождение нет. Участник может внимательно прочитать каждый вопрос и выбрать ответ в удобном темпе.'],
            ['key'=>'technical_device','group'=>'technical','question'=>'С какого устройства можно пройти «{title}»?','answer'=>'Страница олимпиады работает в современном браузере на компьютере, планшете или смартфоне. Для стабильного сохранения ответов потребуется подключение к интернету.'],
            ['key'=>'technical_account','group'=>'technical','question'=>'Нужен ли личный кабинет для участия в «{title}»?','answer'=>'Начать тест можно после заполнения регистрационной формы участника. Личный кабинет удобен для хранения результатов и повторного скачивания оформленных документов.'],
            ['key'=>'technical_problem','group'=>'technical','question'=>'Что делать, если страница «{title}» работает некорректно?','answer'=>'Сначала обновите страницу и проверьте интернет-соединение, не закрывая вкладку без необходимости. Если проблема повторяется, обратитесь в поддержку и укажите название олимпиады.'],
            ['key'=>'documents_portfolio','group'=>'documents','question'=>'Подходит ли диплом «{title}» для портфолио?','answer'=>'Электронный диплом можно приложить к личному профессиональному или учебному портфолио. Решение о зачёте документа принимает организация, в которую он предоставляется.'],
            ['key'=>'documents_verify','group'=>'documents','question'=>'Как проверить данные в дипломе «{title}»?','answer'=>'Перед оформлением внимательно проверьте имя участника и результат на итоговой странице. Если после оформления обнаружена ошибка, обратитесь в поддержку с данными заказа.'],
            ['key'=>'group_participation','group'=>'group','question'=>'Можно ли пройти «{title}» группой или классом?','answer'=>'Для организованной группы предусмотрена отдельная форма оформления на странице олимпиады. В ней можно передать сведения об участниках и получить помощь по групповому участию.'],
            ['key'=>'support_contact','group'=>'support','question'=>'Куда обратиться с вопросом по олимпиаде «{title}»?','answer'=>'Контакты службы поддержки размещены в нижней части сайта и на странице контактов. В обращении укажите название олимпиады и кратко опишите ситуацию.'],
            ['key'=>'support_data_error','group'=>'support','question'=>'Можно ли исправить данные участника после «{title}»?','answer'=>'Если ошибка обнаружена до оформления диплома, исправьте данные на доступном этапе формы. По уже оформленному документу потребуется обращение в поддержку с информацией о заказе.'],
            ['key'=>'subject_match','group'=>'content','question'=>'Соответствуют ли задания «{title}» заявленной теме?','answer'=>'Вопросы подбираются по направлению «{subject}» и уровню, указанному на странице. Они проверяют понимание основных понятий и умение применять знания в типовых ситуациях.'],
        ];
    }

    public static function sanitizeAboutHtml(string $html): string
    {
        $html = trim(strip_tags($html, '<p><h3><ul><li><strong><em>'));
        $html = preg_replace('/<(\/?)(p|h3|ul|li|strong|em)\b[^>]*>/iu', '<$1$2>', $html) ?? '';
        return trim($html);
    }

    private static function fallbackHero(array $olympiad): string
    {
        $vars = self::templateVars($olympiad);
        $description = self::sanitizePlainText((string)($olympiad['description'] ?? ''));
        if ($description !== '' && !preg_match('/[.!?]$/u', $description)) {
            $description .= '.';
        }
        if (self::sentenceCount($description) < 2) {
            $description .= ' Олимпиада «' . $vars['{title}'] . '» помогает проверить знания по направлению «' . $vars['{subject}'] . '» и сразу увидеть результат.';
        }
        if (self::visibleLength($description) < self::HERO_MIN_LENGTH) {
            $description .= ' Задания доступны онлайн и подходят аудитории: ' . $vars['{audience}'] . '.';
        }
        return self::normalizeWhitespace($description);
    }

    private static function fallbackAbout(array $olympiad): string
    {
        $html = trim((string)($olympiad['seo_content'] ?? ''));
        if ($html !== '') {
            return self::sanitizeAboutHtml($html);
        }
        $description = self::sanitizePlainText((string)($olympiad['description'] ?? ''));
        return $description === '' ? '' : '<p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    /** @return array<int,array{key:string,q:string,a:string}> */
    private static function sanitizeFaq(array $faq): array
    {
        return array_map(static fn(array $item): array => [
            'key' => preg_replace('/[^a-z0-9_-]/', '', (string)($item['key'] ?? '')) ?? '',
            'q' => self::sanitizePlainText((string)($item['question'] ?? $item['q'] ?? '')),
            'a' => self::sanitizePlainText((string)($item['answer'] ?? $item['a'] ?? '')),
        ], $faq);
    }

    private static function sanitizePlainText(string $text): string
    {
        return self::normalizeWhitespace(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function hasPlaceholder(string $text): bool
    {
        return (bool)preg_match('/\{[a-z_]+\}|\[[A-ZА-Я_]+\]|CHANGE_ME/iu', $text);
    }

    /** @return array<string,string> */
    private static function templateVars(array $olympiad): array
    {
        $title = self::normalizeTitle((string)($olympiad['title'] ?? ''));
        $subject = self::sanitizePlainText((string)($olympiad['subject'] ?? ''));
        $audience = self::audienceLabel((string)($olympiad['target_audience'] ?? ''));
        $grade = self::sanitizePlainText((string)($olympiad['grade'] ?? ''));
        return [
            '{title}' => $title !== '' ? $title : 'выбранной теме',
            '{subject}' => $subject !== '' ? $subject : ($title !== '' ? $title : 'олимпиады'),
            '{audience}' => $audience,
            '{grade}' => $grade !== '' ? $grade : 'уровень указан на странице',
        ];
    }

    private static function audienceLabel(string $value): string
    {
        return [
            'pedagogues_dou' => 'педагоги дошкольного образования',
            'pedagogues_school' => 'педагоги школ',
            'pedagogues_ovz' => 'педагоги, работающие с детьми с ОВЗ',
            'students' => 'школьники',
            'preschoolers' => 'дошкольники',
            'logopedists' => 'логопеды и дефектологи',
        ][$value] ?? 'педагоги и обучающиеся соответствующего уровня';
    }

    /** @param array<string,array{title:string,variants:array<int,string>}> $pools */
    private static function buildVariantBlocks(array $olympiad, array $pools): array
    {
        $vars = self::templateVars($olympiad);
        $id = (int)($olympiad['id'] ?? 0);
        $blocks = [];
        foreach ($pools as $key => $config) {
            $variants = $config['variants'];
            $selected = seededShuffle($variants, 'olympiad-block:' . $id . ':v' . self::CONTENT_VERSION . ':' . $key)[0];
            $blocks[$key] = [
                'title' => $config['title'],
                'text' => self::normalizeWhitespace(strtr($selected, $vars)),
            ];
        }
        return $blocks;
    }

    /** @return array<string,array{title:string,text:string}> */
    public static function buildBenefits(array $olympiad): array
    {
        return self::buildVariantBlocks($olympiad, self::benefitPools());
    }

    /** @return array<string,array{title:string,text:string}> */
    public static function buildSteps(array $olympiad): array
    {
        return self::buildVariantBlocks($olympiad, self::stepPools());
    }
}
