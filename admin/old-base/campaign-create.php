<?php
/**
 * Создание/редактирование кампании.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../classes/OldBaseCampaign.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Создать рассылку';
$additionalJS = ['/admin/old-base/campaign-create.js'];

$current = Admin::verifySession();

$campaign = new OldBaseCampaign($db);

$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$existing = $editId ? $campaign->find($editId) : null;
if ($editId && !$existing) {
    http_response_code(404);
    die('Кампания не найдена');
}

$error = null;
$savedId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $error = 'Невалидный CSRF-токен';
    } else {
        try {
            $audienceType = $_POST['audience_type'] ?? 'all';
            $audienceFilter = ['type' => $audienceType];
            if ($audienceType === 'specific_emails') {
                $emailsRaw = trim($_POST['audience_emails'] ?? '');
                $audienceFilter['emails'] = array_values(array_filter(
                    array_map('trim', preg_split('/[\s,;]+/', $emailsRaw)),
                    'strlen'
                ));
            } elseif (in_array($audienceType, ['opened_in','clicked_in','converted_in','exclude_recipients_of'], true)) {
                $audienceFilter['campaign_ids'] = array_map('intval', $_POST['audience_campaign_ids'] ?? []);
                if ($audienceType === 'exclude_recipients_of') {
                    $audienceFilter['base'] = $_POST['audience_base'] ?? 'all';
                }
            }

            $rampJson = trim($_POST['ramp_schedule'] ?? '');
            $ramp = $rampJson ? json_decode($rampJson, true) : OldBaseCampaign::defaultRampSchedule();
            if (!is_array($ramp) || !$ramp) {
                throw new \InvalidArgumentException('Некорректный ramp_schedule');
            }

            $data = [
                'code'              => $_POST['code'] ?? '',
                'name'              => $_POST['name'] ?? '',
                'subject'           => $_POST['subject'] ?? '',
                'from_name'         => $_POST['from_name'] ?? null,
                'from_email'        => $_POST['from_email'] ?? null,
                'html_body'         => $_POST['html_body'] ?? '',
                'plain_body'        => $_POST['plain_body'] ?? null,
                'cta_url'           => $_POST['cta_url'] ?? null,
                'auto_utm'          => !empty($_POST['auto_utm']),
                'audience_filter'   => $audienceFilter,
                'start_date'        => $_POST['start_date'] ?? date('Y-m-d'),
                'send_window_start' => $_POST['send_window_start'] ?? '10:00:00',
                'send_window_end'   => $_POST['send_window_end'] ?? '18:00:00',
                'timezone'          => $_POST['timezone'] ?? 'Europe/Moscow',
                'ramp_schedule'     => $ramp,
                'created_by'        => $current['id'],
            ];

            if ($existing) {
                $campaign->update($editId, $data);
                $savedId = $editId;
            } else {
                $savedId = $campaign->create($data);
            }

            header('Location: /admin/old-base/campaign-view.php?id=' . $savedId);
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$csrf = generateCSRFToken();

$cur = $existing ?: [
    'code' => '',
    'name' => '',
    'subject' => '',
    'from_name' => 'Команда Педпортала',
    'from_email' => '',
    'html_body' => "<p>Здравствуйте, {{name}}!</p>\n<p>Текст письма…</p>\n<p><a href=\"{{cta_url}}\">Перейти</a></p>\n<p style=\"font-size:11px;color:#888;\">Если вы не хотите получать письма, <a href=\"{{unsubscribe_url}}\">отпишитесь</a>.</p>",
    'plain_body' => '',
    'cta_url' => '',
    'auto_utm' => 1,
    'audience_filter' => json_encode(['type' => 'all']),
    'start_date' => date('Y-m-d'),
    'send_window_start' => '10:00:00',
    'send_window_end' => '18:00:00',
    'timezone' => 'Europe/Moscow',
    'ramp_schedule' => json_encode(OldBaseCampaign::defaultRampSchedule()),
];

$audienceFilterArr = is_string($cur['audience_filter']) ? (json_decode($cur['audience_filter'], true) ?: ['type'=>'all']) : $cur['audience_filter'];
$rampJson = is_string($cur['ramp_schedule']) ? $cur['ramp_schedule'] : json_encode($cur['ramp_schedule']);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><?= $existing ? '✏️ Редактировать рассылку' : '➕ Новая рассылка' ?></h1>
</div>

<style>
.wys-toolbar { display:flex; flex-wrap:wrap; gap:4px; align-items:center; padding:6px; background:#f3f4f6; border:1px solid #d1d5db; border-bottom:none; border-radius:6px 6px 0 0; }
.wys-btn { padding:4px 10px; background:#fff; border:1px solid #d1d5db; border-radius:4px; cursor:pointer; font-size:13px; line-height:1.2; }
.wys-btn:hover { background:#e5e7eb; }
.wys-placeholder { padding:4px 6px; border:1px solid #d1d5db; border-radius:4px; font-size:12px; }
.wys-editor { min-height:280px; padding:14px; border:1px solid #d1d5db; border-radius:0 0 6px 6px; background:#fff; font-family:Arial,sans-serif; font-size:14px; line-height:1.5; overflow-y:auto; }
.wys-editor:focus { outline:2px solid #93c5fd; }
.wys-source { width:100%; font-family:monospace; font-size:12px; padding:8px; border:1px solid #d1d5db; border-radius:0 0 6px 6px; }
</style>

<?php if ($error): ?>
    <div class="content-card" style="padding:16px;border-left:4px solid #ef4444;background:#fef2f2;margin-bottom:16px;">
        <strong>Ошибка:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<form method="POST" id="campaignForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div class="content-card" style="padding:20px;margin-bottom:16px;">
        <h3>Основные параметры</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Название</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($cur['name']) ?>" style="width:100%;padding:6px 10px;">
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Код (slug, латиница)</label>
                <input type="text" name="code" required pattern="[a-z0-9_-]{3,64}" value="<?= htmlspecialchars($cur['code']) ?>" style="width:100%;padding:6px 10px;font-family:monospace;" <?= $existing ? 'readonly' : '' ?>>
            </div>
            <div style="grid-column:1/3;">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Тема письма (доступен <code>{{name}}</code>)</label>
                <input type="text" name="subject" required value="<?= htmlspecialchars($cur['subject']) ?>" style="width:100%;padding:6px 10px;">
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">From name</label>
                <input type="text" name="from_name" value="<?= htmlspecialchars($cur['from_name'] ?? '') ?>" style="width:100%;padding:6px 10px;">
                <p style="font-size:11px;color:#888;">Если пусто — используется дефолт UNISENDER_SENDER_NAME</p>
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">From email</label>
                <input type="email" name="from_email" value="<?= htmlspecialchars($cur['from_email'] ?? '') ?>" style="width:100%;padding:6px 10px;">
                <p style="font-size:11px;color:#888;">Если пусто — UNISENDER_SENDER_EMAIL (info@fgos.pro)</p>
            </div>
        </div>
    </div>

    <div class="content-card" style="padding:20px;margin-bottom:16px;">
        <h3>Тело письма</h3>
        <p style="color:#666;font-size:13px;">Плейсхолдеры: <code>{{name}}</code>, <code>{{email}}</code>, <code>{{cta_url}}</code>, <code>{{unsubscribe_url}}</code></p>

        <div class="wys-wrap" style="margin-bottom:12px;">
            <div class="wys-toolbar">
                <button type="button" class="wys-btn" data-cmd="bold" title="Жирный"><b>Ж</b></button>
                <button type="button" class="wys-btn" data-cmd="italic" title="Курсив"><i>К</i></button>
                <button type="button" class="wys-btn" data-cmd="formatBlock" data-val="h2" title="Заголовок">H2</button>
                <button type="button" class="wys-btn" data-cmd="formatBlock" data-val="p" title="Абзац">¶</button>
                <button type="button" class="wys-btn" data-cmd="insertUnorderedList" title="Список">• ☰</button>
                <button type="button" class="wys-btn" data-cmd="createLink" title="Ссылка">🔗</button>
                <button type="button" class="wys-btn" data-action="cta" title="Вставить кнопку CTA">CTA-кнопка</button>
                <select class="wys-placeholder" title="Вставить плейсхолдер">
                    <option value="">Плейсхолдер…</option>
                    <option value="{{name}}">{{name}}</option>
                    <option value="{{email}}">{{email}}</option>
                    <option value="{{cta_url}}">{{cta_url}}</option>
                    <option value="{{unsubscribe_url}}">{{unsubscribe_url}}</option>
                </select>
                <button type="button" class="wys-btn wys-toggle" data-action="toggle" style="margin-left:auto;" title="Переключить режим">&lt;/&gt; HTML</button>
            </div>
            <div id="wysiwygEditor" class="wys-editor" contenteditable="true"></div>
            <textarea name="html_body" id="htmlSource" rows="14" class="wys-source" style="display:none;"><?= htmlspecialchars($cur['html_body']) ?></textarea>
        </div>
        <div style="margin-bottom:12px;">
            <label style="display:block;font-weight:600;margin-bottom:4px;">Plain-text (опционально)</label>
            <textarea name="plain_body" rows="6" style="width:100%;font-family:monospace;font-size:12px;padding:8px;"><?= htmlspecialchars($cur['plain_body'] ?? '') ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:3fr 1fr;gap:16px;">
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">CTA URL (для <code>{{cta_url}}</code>)</label>
                <input type="text" name="cta_url" value="<?= htmlspecialchars($cur['cta_url'] ?? '') ?>" placeholder="https://fgos.pro/kursy/..." style="width:100%;padding:6px 10px;">
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">UTM авто</label>
                <label><input type="checkbox" name="auto_utm" value="1" <?= !empty($cur['auto_utm']) ? 'checked' : '' ?>> добавлять UTM</label>
            </div>
        </div>
    </div>

    <div class="content-card" style="padding:20px;margin-bottom:16px;">
        <h3>Аудитория</h3>
        <div id="audienceBlock">
            <?php
            $audType = $audienceFilterArr['type'] ?? 'all';
            $audOptions = [
                'all' => 'Вся активная база',
                'never_sent' => 'Никогда не получали писем',
                'specific_emails' => 'Конкретные email (тест)',
                'opened_in' => 'Открывшие письма выбранных кампаний',
                'clicked_in' => 'Кликнувшие в выбранных кампаниях',
                'exclude_recipients_of' => 'Исключить получателей выбранных кампаний',
            ];
            ?>
            <select name="audience_type" id="audienceType" style="padding:6px 10px;">
                <?php foreach ($audOptions as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $audType === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                <?php endforeach; ?>
            </select>

            <div id="audienceEmails" style="margin-top:12px;<?= $audType === 'specific_emails' ? '' : 'display:none;' ?>">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Email-адреса (через запятую/пробел/перенос)</label>
                <textarea name="audience_emails" rows="3" style="width:100%;font-family:monospace;padding:8px;"><?= htmlspecialchars(implode("\n", $audienceFilterArr['emails'] ?? [])) ?></textarea>
            </div>

            <div id="audienceCampaigns" style="margin-top:12px;<?= in_array($audType, ['opened_in','clicked_in','converted_in','exclude_recipients_of'], true) ? '' : 'display:none;' ?>">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Кампании</label>
                <select name="audience_campaign_ids[]" multiple size="5" style="width:100%;padding:6px 10px;">
                    <?php foreach ($campaign->listAll() as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $audienceFilterArr['campaign_ids'] ?? [], true) ? 'selected' : '' ?>>
                            #<?= (int)$c['id'] ?> — <?= htmlspecialchars($c['name']) ?> [<?= $c['status'] ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="audienceBase" style="margin-top:8px;<?= $audType === 'exclude_recipients_of' ? '' : 'display:none;' ?>">
                    <label>Базовый набор для исключения:
                        <select name="audience_base" style="padding:6px 10px;">
                            <option value="all" <?= ($audienceFilterArr['base'] ?? 'all') === 'all' ? 'selected' : '' ?>>Вся активная база</option>
                            <option value="never_sent" <?= ($audienceFilterArr['base'] ?? '') === 'never_sent' ? 'selected' : '' ?>>Кто не получал писем</option>
                        </select>
                    </label>
                </div>
            </div>

            <div style="margin-top:16px;">
                <button type="button" id="previewBtn" class="btn btn-secondary btn-sm">Посчитать получателей</button>
                <span id="previewResult" style="margin-left:12px;color:#666;"></span>
            </div>
            <div id="overlapWarning" style="display:none;margin-top:10px;padding:10px 12px;background:#fffbeb;border-left:4px solid #f59e0b;color:#92400e;font-size:13px;border-radius:4px;"></div>
        </div>
    </div>

    <div class="content-card" style="padding:20px;margin-bottom:16px;">
        <h3>Расписание</h3>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Дата старта</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($cur['start_date']) ?>" style="padding:6px 10px;">
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Окно: с</label>
                <input type="time" name="send_window_start" value="<?= htmlspecialchars(substr($cur['send_window_start'], 0, 5)) ?>" style="padding:6px 10px;">
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Окно: до</label>
                <input type="time" name="send_window_end" value="<?= htmlspecialchars(substr($cur['send_window_end'], 0, 5)) ?>" style="padding:6px 10px;">
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Timezone</label>
                <input type="text" name="timezone" value="<?= htmlspecialchars($cur['timezone']) ?>" style="padding:6px 10px;width:100%;">
            </div>
        </div>
        <div style="margin-top:16px;">
            <label style="display:block;font-weight:600;margin-bottom:4px;">Ramp schedule (JSON массив <code>{day,quota}</code>)</label>
            <textarea name="ramp_schedule" id="rampSchedule" rows="6" style="width:100%;font-family:monospace;font-size:12px;padding:8px;"><?= htmlspecialchars($rampJson) ?></textarea>
            <button type="button" id="rampDefaultBtn" class="btn btn-secondary btn-sm" style="margin-top:6px;">Заполнить дефолтом (30 дней)</button>
        </div>
    </div>

    <div style="display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary">Сохранить</button>
        <a href="/admin/old-base/index.php" class="btn btn-secondary">Отмена</a>
    </div>
</form>

<script>
window._defaultRamp = <?= json_encode(OldBaseCampaign::defaultRampSchedule()) ?>;
window._csrfToken = <?= json_encode($csrf) ?>;
window._editCampaignId = <?= (int)$editId ?>;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
