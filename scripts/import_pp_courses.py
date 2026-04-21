#!/usr/bin/env python3
"""
Импорт курсов профпереподготовки (ПП) из CSV → SQL миграция.

Вход:  ПП для ФГОС Практикум - ПП.csv (в корне проекта)
Выход: database/migrations/080_seed_pp_courses.sql

Дедуп экспертов по full_name (снимок из локальной БД вшит ниже).
Неполные строки (без title/hours/price) пропускаются.
"""

import csv
import json
import os
import re
import sys

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CSV_PATH = os.path.join(BASE_DIR, 'ПП для ФГОС Практикум - ПП.csv')
OUTPUT_PATH = os.path.join(BASE_DIR, 'database', 'migrations', '080_seed_pp_courses.sql')

# Снимок существующих экспертов (id → full_name) из локальной БД
EXISTING_EXPERTS = {
    "Акименкова Елена Александровна": 1, "Аюпова Елена Евгеньевна": 2,
    "Баринова Наталия Сергеевна": 3, "Бежан Елена Андреевна": 4,
    "Бекеев Артём Асхатович": 5, "Белова Галина Борисовна": 6,
    "Болотова Марина Михайловна": 7, "Борщук Александр Леонидович": 8,
    "Булдыгерова Наталья Сергеевна": 9, "Васюкина Екатерина Александровна": 10,
    "Вдовина Мария Викторовна": 11, "Винокурова Галина Сергеевна": 12,
    "Воропаева Татьяна Вячеславовна": 13, "Галиева Светлана Юрьевна": 14,
    "Галимзянова Ульяна Викторовна": 15, "Гамова Светлана Николаевна": 16,
    "Гангнус Наталия Андреевна": 17, "Головенко Кирилл Владимирович": 18,
    "Головенко Наталья Сергеевна": 19, "Горохова Ирина Алексеевна": 20,
    "Гринберг Вадим Владимирович": 21, "Громова Марина Владимировна": 22,
    "Грохова Татьяна Владимировна": 23, "Гущина Виктория Александровна": 24,
    "Данилова Елена Юрьевна": 25, "Дульцева Светлана Евгеньевна": 26,
    "Ефимова Мария Ивановна": 27, "Жадаев Дмитрий Николаевич": 28,
    "Забарова Ольга Павловна": 29, "Захарова Оксана Рэмовна": 30,
    "Иванова Маргарита Васильевна": 31, "Иванова Татьяна Николаевна": 32,
    "Калачан Айкуш Жораевна": 33, "Каткова Екатерина Александровна": 34,
    "Киосе Оксана Афанасьевна": 35, "Кишиневская Мария Александровна": 36,
    "Краузе Елена Николаевна": 37, "Кривоногова Ольга Константиновна": 38,
    "Лободина Наталья Викторовна": 39, "Логвина Елизавета Николаевна": 40,
    "Майле Елена Николаевна": 41, "Макинян Лия Арменовна": 42,
    "Матвеев Эдуард Вениаминович": 43, "Минхайдарова Эльнара Даяновна": 45,
    "Мировская Татьяна Викторовна": 46, "Митина Екатерина Сергеевна": 47,
    "Мохова Ксения Сергеевна": 48, "Наумов Александр Анатольевич": 49,
    "Неженец Анастасия Игоревна": 50, "Николаева Анна Валерьевна": 51,
    "Плеханова Людмила Александровна": 52, "Пшеничная Ирина Викторовна": 55,
    "Самойленко Марина Анатольевна": 57, "Седых Юлия Сергеевна": 58,
    "Соловьева Елена Викторовна": 59, "Стэнеску Светлана Алексеевна": 61,
    "Сулейманов Камиль Бейтуллаевич": 62, "Телегин Илья Григорьевич": 63,
    "Тихоненкова Елена Павловна": 64, "Францкевич Алена Романовна": 65,
    "Хатмуллина Ярина Ахатовна": 66, "Хохлова Татьяна Александровна": 67,
    "Худякова Анна Владимировна": 68, "Чудновец Елена Александровна": 69,
    "Чурлик Анна Юрьевна": 70, "Шевченко Екатерина Вячеславовна": 71,
    "Широкова Ксения Юрьевна": 72, "Шуванова Марина Александровна": 73,
    "Щавровская Валентина Владимировна": 74,
}

# course_group (CSV col[2]) → audience_types slugs
GROUP_TO_TYPE_SLUGS = {
    'Дошкольное образование': ['dou'],
    'Школа': ['nachalnaya-shkola', 'srednyaya-starshaya-shkola'],
    'Дополнительное образование': ['dopolnitelnoe-obrazovanie'],
    'Специальное образование': ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola'],
}
TYPE_SLUG_TO_ID = {
    'dou': 1, 'nachalnaya-shkola': 2, 'srednyaya-starshaya-shkola': 3,
    'spo': 4, 'dopolnitelnoe-obrazovanie': 5,
}

# CSV col[7] spec-name → audience_specializations slug
SPEC_NAME_TO_SLUG = {
    'Воспитатель': 'vospitatel',
    'Старший воспитатель': 'starshiy-vospitatel',
    'Младший воспитатель': 'mladshiy-vospitatel',
    'Инструктор по физкультуре': 'instruktor-fizkultura',
    'Учитель': 'uchitel',
    'Логопед/Дефектолог': 'logopediya',
    'Педагог-психолог': 'pedagog-psiholog',
    'Руководитель/заместитель руководителя': 'administratsiya-upravlenie',
    'Социальный педагог': 'socialnaya-pedagogika',
    'Педагог дополнительного образования': 'pedagog-do',
    'Методист': 'metodist',
    'Музыкальный руководитель': 'vospitatel',  # нет отдельной спец., мапим в voспит.
}
SPEC_SLUG_TO_ID = {
    'logopediya': 47, 'pedagog-psiholog': 49, 'socialnaya-pedagogika': 51,
    'metodist': 52, 'administratsiya-upravlenie': 53, 'rabota-s-ovz': 58,
    'vospitatel': 59, 'starshiy-vospitatel': 60, 'mladshiy-vospitatel': 61,
    'instruktor-fizkultura': 62, 'uchitel': 63, 'pedagog-do': 64,
}

TRANSLIT = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd',
    'е': 'e', 'ё': 'yo', 'ж': 'zh', 'з': 'z', 'и': 'i',
    'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n',
    'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't',
    'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch',
    'ш': 'sh', 'щ': 'sch', 'ъ': '', 'ы': 'y', 'ь': '',
    'э': 'e', 'ю': 'yu', 'я': 'ya',
}


def slugify(text, max_len=190):
    s = (text or '').lower().strip()
    s = ''.join(TRANSLIT.get(c, c) for c in s)
    s = re.sub(r'[^a-z0-9]+', '-', s).strip('-')
    if len(s) > max_len:
        s = s[:max_len].rstrip('-')
    return s


def sql_str(val):
    if val is None or val == '':
        return 'NULL'
    s = str(val).replace("\\", "\\\\").replace("'", "\\'")
    s = s.replace("\r\n", "\n").replace("\r", "\n")
    s = s.replace("\n", "\\n")
    return "'" + s + "'"


def parse_price(val):
    if not val:
        return 0
    s = re.sub(r'\s|\xa0', '', str(val)).replace(',', '.')
    try:
        return int(float(s))
    except ValueError:
        return 0


def parse_hours(val):
    if not val:
        return 0
    try:
        return int(float(str(val).replace(',', '.')))
    except ValueError:
        return 0


def parse_modules(text):
    """Парсит 'Модуль 1. ...\\nМодуль 2. ...' → [{number,title}]."""
    if not text:
        return []
    items = []
    pattern = re.compile(r'(?:Модуль|Раздел)\s+(\d+)[\.\)]\s*(.+?)(?=(?:\n(?:Модуль|Раздел)\s+\d+)|\Z)', re.DOTALL)
    for m in pattern.finditer(text):
        num = int(m.group(1))
        title = re.sub(r'\s+', ' ', m.group(2)).strip()
        items.append({'number': num, 'title': title})
    # Финальная аттестация как отдельный пункт
    if re.search(r'Итоговая аттестация', text):
        items.append({'number': len(items) + 1, 'title': 'Итоговая аттестация'})
    return items


def parse_experts(text):
    """Парсит блок экспертов '1. ФИО\\ncred...\\nСтаж X лет' → [{name,cred,exp}]."""
    if not text:
        return []
    # Разбить по '1. '...'2. '...
    parts = re.split(r'(?:^|\n)\s*\d+\.\s+', text)
    parts = [p.strip() for p in parts if p.strip()]
    out = []
    for p in parts:
        lines = [l.strip() for l in p.split('\n') if l.strip()]
        if not lines:
            continue
        name = lines[0]
        # ФИО: 3 слова с заглавной русской буквой. Отрезать хвост, если слиплось.
        m = re.match(r'^([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2})', name)
        if not m:
            continue
        name = m.group(1).strip()
        rest = '\n'.join(lines[1:])
        # Выделить строку "Стаж работы X лет" в experience
        exp = ''
        em = re.search(r'Стаж[^.\n]*?(\d+\s*(?:год|года|лет)[^\n]*)', rest, re.IGNORECASE)
        if em:
            exp = em.group(1).strip().rstrip('.')
            rest = (rest[:em.start()] + rest[em.end():]).strip()
        cred = re.sub(r'\s+', ' ', rest).strip()
        out.append({'name': name, 'cred': cred, 'exp': exp})
    return out


# ===== MAIN =====
new_experts = {}  # full_name → slug (для INSERT)
next_expert_slug_idx = 0
sql_lines = []

sql_lines.append('-- Migration 080: Seed PP (профессиональная переподготовка) courses')
sql_lines.append('-- Auto-generated by scripts/import_pp_courses.py from "ПП для ФГОС Практикум - ПП.csv"')
sql_lines.append('')
sql_lines.append('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;')
sql_lines.append('SET CHARACTER SET utf8mb4;')
sql_lines.append('SET FOREIGN_KEY_CHECKS = 0;')
sql_lines.append('')

courses_to_insert = []
skipped = []
seen_titles = set()

with open(CSV_PATH, 'r', encoding='utf-8') as f:
    reader = csv.reader(f)
    next(reader)  # header
    for idx, row in enumerate(reader, 1):
        if len(row) < 13:
            row = row + [''] * (13 - len(row))
        title = (row[1] or '').strip()
        group = (row[2] or '').strip()
        hours = parse_hours(row[3])
        learning_format = (row[5] or '').strip()
        price = parse_price(row[6])
        spec_raw = (row[7] or '').strip()
        target_audience = (row[8] or '').strip()
        description = (row[9] or '').strip()
        modules_text = (row[10] or '').strip()
        # row[11] = "Курс поможет стать" — результаты обучения (связный текст)
        results_text = (row[11] or '').strip()
        experts_text = (row[12] or '').strip()

        if not title or not hours or not price:
            skipped.append((idx, title or '(без названия)', f'hours={hours} price={price}'))
            continue
        if title in seen_titles:
            skipped.append((idx, title, 'duplicate title'))
            continue
        seen_titles.add(title)

        # course_group: первый из списка через запятую
        course_group = [g.strip() for g in group.split(',') if g.strip()]
        course_group = course_group[0] if course_group else 'Общий'

        # audience types
        type_slugs = set()
        for g in [g.strip() for g in group.split(',') if g.strip()]:
            for s in GROUP_TO_TYPE_SLUGS.get(g, []):
                type_slugs.add(s)

        # specializations
        spec_slugs = set()
        for name in [s.strip() for s in spec_raw.split(',') if s.strip()]:
            slug = SPEC_NAME_TO_SLUG.get(name)
            if slug:
                spec_slugs.add(slug)
        # "Специальное образование" → добавить rabota-s-ovz
        if 'Специальное образование' in group:
            spec_slugs.add('rabota-s-ovz')

        # modules
        modules = parse_modules(modules_text)

        # outcomes: кладём results_text целиком в skills (одним элементом)
        outcomes = {
            'knowledge': [],
            'skills': [results_text] if results_text else [],
            'abilities': [],
        }

        experts = parse_experts(experts_text)
        # регистрируем новых экспертов
        for e in experts:
            if e['name'] not in EXISTING_EXPERTS and e['name'] not in new_experts:
                slug = slugify(e['name'])
                # проверка коллизии
                base = slug
                i = 1
                existing_slugs = set(new_experts.values())
                while slug in existing_slugs:
                    i += 1
                    slug = f'{base}-{i}'
                new_experts[e['name']] = slug

        courses_to_insert.append({
            'title': title,
            'slug': slugify(title),
            'description': description,
            'target_audience': target_audience,
            'course_group': course_group,
            'hours': hours,
            'learning_format': learning_format,
            'price': price,
            'modules': modules,
            'outcomes': outcomes,
            'experts': experts,
            'type_slugs': sorted(type_slugs),
            'spec_slugs': sorted(spec_slugs),
        })

# Дедуп slug для курсов
used_slugs = set()
for c in courses_to_insert:
    base = c['slug']
    s = base
    i = 1
    while s in used_slugs:
        i += 1
        s = f'{base}-{i}'
    c['slug'] = s
    used_slugs.add(s)

# === SQL: новые эксперты ===
sql_lines.append('-- Новые эксперты (INSERT IGNORE по slug — идемпотентно)')
for name, slug in new_experts.items():
    cred, exp = '', ''
    for c in courses_to_insert:
        for e in c['experts']:
            if e['name'] == name:
                cred = e['cred']
                exp = e['exp']
                break
        if cred or exp:
            break
    sql_lines.append(
        f"INSERT IGNORE INTO course_experts (full_name, slug, credentials, experience) VALUES "
        f"({sql_str(name)}, {sql_str(slug)}, {sql_str(cred)}, {sql_str(exp)});"
    )
sql_lines.append('')

# Получить max display_order для курсов
sql_lines.append('-- display_order: задаём пользовательскую переменную с MAX до вставки')
sql_lines.append("SELECT @start_order := COALESCE(MAX(display_order), 0) FROM courses;")
sql_lines.append('')

# === SQL: курсы ===
sql_lines.append('-- Курсы ПП')
for offset, c in enumerate(courses_to_insert, 1):
    modules_json = json.dumps(c['modules'], ensure_ascii=False)
    outcomes_json = json.dumps(c['outcomes'], ensure_ascii=False)
    sql_lines.append(
        f"INSERT IGNORE INTO courses (title, slug, description, target_audience_text, course_group, hours, program_type, learning_format, price, modules_json, outcomes_json, federal_registry_info, is_active, display_order) VALUES "
        f"({sql_str(c['title'])}, {sql_str(c['slug'])}, {sql_str(c['description'])}, "
        f"{sql_str(c['target_audience'])}, {sql_str(c['course_group'])}, {c['hours']}, "
        f"'pp', {sql_str(c['learning_format'])}, {c['price']}, "
        f"{sql_str(modules_json)}, {sql_str(outcomes_json)}, NULL, 1, @start_order + {offset});"
    )
sql_lines.append('')

# === SQL: связи курс↔аудитория↔спец↔эксперт ===
sql_lines.append('-- Связи курсов с типами аудитории, специализациями и экспертами')
for c in courses_to_insert:
    # audience_types — SELECT по slug (без hard-coded id)
    for type_slug in c['type_slugs']:
        sql_lines.append(
            f"INSERT IGNORE INTO course_audience_types (course_id, audience_type_id) "
            f"SELECT c.id, t.id FROM courses c, audience_types t "
            f"WHERE c.slug = {sql_str(c['slug'])} AND t.slug = {sql_str(type_slug)};"
        )
    # specializations — SELECT по slug
    for spec_slug in c['spec_slugs']:
        sql_lines.append(
            f"INSERT IGNORE INTO course_specializations (course_id, specialization_id) "
            f"SELECT c.id, s.id FROM courses c, audience_specializations s "
            f"WHERE c.slug = {sql_str(c['slug'])} AND s.slug = {sql_str(spec_slug)};"
        )
    # experts — SELECT по full_name (и для existing, и для new)
    for order, e in enumerate(c['experts']):
        sql_lines.append(
            f"INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order) "
            f"SELECT c.id, e.id, 'instructor', {order} FROM courses c, course_experts e "
            f"WHERE c.slug = {sql_str(c['slug'])} AND e.full_name = {sql_str(e['name'])};"
        )

sql_lines.append('')
sql_lines.append('SET FOREIGN_KEY_CHECKS = 1;')

with open(OUTPUT_PATH, 'w', encoding='utf-8') as f:
    f.write('\n'.join(sql_lines) + '\n')

print(f'✓ {len(courses_to_insert)} курсов → {OUTPUT_PATH}', file=sys.stderr)
print(f'✓ {len(new_experts)} новых экспертов', file=sys.stderr)
print(f'✗ {len(skipped)} пропущенных строк:', file=sys.stderr)
for s in skipped:
    print(f'   CSV row {s[0]}: {s[1][:60]} ({s[2]})', file=sys.stderr)
