#!/usr/bin/env python3
"""
Импорт курсов профпереподготовки (ПП, «СКОЛКОВО, 3 поток») из XLSX → SQL миграция.

Вход:  курсы/ПП добавить на сайт.xlsx  (строки 2–21)
Выход: database/migrations/166_seed_pp_courses_skolkovo.sql

Правила (согласованы с заказчиком):
- Пропускаем строки без title/hours/price  → строка 19 (ИЗО) отсеивается.
- Пропускаем дубли уже существующих курсов ПП по slug (SKIP_SLUGS): строки 6, 7.
- Специализации сайдбара — ТОЛЬКО по столбцу J «ЦА (для навигации на сайте)».
- course_specializations вставляем с ПИНОМ audience_type_id (иначе размножение
  из-за миграции 162: один slug = до 23 строк в audience_specializations).
- Эксперты: INSERT IGNORE по slug только новых; assignment матчим по slug.
- Идемпотентно: INSERT IGNORE везде.
"""

import io
import json
import os
import re
import sys
import zipfile
from xml.etree import ElementTree as ET

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# XLSX/OUTPUT можно переопределить аргументами: script.py <xlsx> <output.sql>
XLSX_PATH = sys.argv[1] if len(sys.argv) > 1 else os.path.join(
    BASE_DIR, 'курсы', 'ПП добавить на сайт.xlsx')
OUTPUT_PATH = sys.argv[2] if len(sys.argv) > 2 else os.path.join(
    BASE_DIR, 'database', 'migrations', '166_seed_pp_courses_skolkovo.sql')

# --- Slug'и курсов ПП, которые уже есть в БД — эти строки xlsx пропускаем ---
SKIP_SLUGS = {
    # строка 7 — точный дубль курса id 84
    'pedagogika-i-metodika-fizicheskoy-kultury-i-sporta-trener-prepodavatel',
    # строка 6 — «...младшего воспитателя ДОО...» ≈ курс id 86 («...ДОУ...»)
    'doshkolnaya-pedagogika-i-psihologiya-psihologo-pedagogicheskaya-deyatelnost-mladshego-vospitatelya-doo-v-usloviyah-realizatsii-fgos',
}

# --- Эксперты, уже присутствующие в БД (full_name) — их НЕ вставляем повторно ---
# (снимок SELECT full_name FROM course_experts; на 2026-09-02, 109 строк)
EXISTING_EXPERTS = {
    "Акименкова Елена Александровна", "Аюпова Елена Евгеньевна",
    "Баринова Наталия Сергеевна", "Бежан Елена Андреевна",
    "Бекеев Артём Асхатович", "Белова Галина Борисовна",
    "Болотова Марина Михайловна", "Борщук Александр Леонидович",
    "Булдыгерова Наталья Сергеевна", "Васюкина Екатерина Александровна",
    "Вдовина Мария Викторовна", "Винокурова Галина Сергеевна",
    "Воропаева Татьяна Вячеславовна", "Галиева Светлана Юрьевна",
    "Галимзянова Ульяна Викторовна", "Гамова Светлана Николаевна",
    "Гангнус Наталия Андреевна", "Головенко Кирилл Владимирович",
    "Головенко Наталья Сергеевна", "Горохова Ирина Алексеевна",
    "Гринберг Вадим Владимирович", "Громова Марина Владимировна",
    "Грохова Татьяна Владимировна", "Гущина Виктория Александровна",
    "Данилова Елена Юрьевна", "Дульцева Светлана Евгеньевна",
    "Ефимова Мария Ивановна", "Жадаев Дмитрий Николаевич",
    "Забарова Ольга Павловна", "Захарова Оксана Рэмовна",
    "Иванова Маргарита Васильевна", "Иванова Татьяна Николаевна",
    "Калачан Айкуш Жораевна", "Каткова Екатерина Александровна",
    "Киосе Оксана Афанасьевна", "Кишиневская Мария Александровна",
    "Краузе Елена Николаевна", "Кривоногова Ольга Константиновна",
    "Лободина Наталья Викторовна", "Логвина Елизавета Николаевна",
    "Майле Елена Николаевна", "Макинян Лия Арменовна",
    "Матвеев Эдуард Вениаминович", "Минхайдарова Эльнара Даяновна",
    "Мировская Татьяна Викторовна", "Митина Екатерина Сергеевна",
    "Мохова Ксения Сергеевна", "Наумов Александр Анатольевич",
    "Неженец Анастасия Игоревна", "Николаева Анна Валерьевна",
    "Плеханова Людмила Александровна", "Пшеничная Ирина Викторовна",
    "Самойленко Марина Анатольевна", "Седых Юлия Сергеевна",
    "Соловьева Елена Викторовна", "Стэнеску Светлана Алексеевна",
    "Сулейманов Камиль Бейтуллаевич", "Телегин Илья Григорьевич",
    "Тихоненкова Елена Павловна", "Францкевич Алена Романовна",
    "Хатмуллина Ярина Ахатовна", "Хохлова Татьяна Александровна",
    "Худякова Анна Владимировна", "Чудновец Елена Александровна",
    "Чурлик Анна Юрьевна", "Шевченко Екатерина Вячеславовна",
    "Широкова Ксения Юрьевна", "Шуванова Марина Александровна",
    "Щавровская Валентина Владимировна",
    # + эксперты из миграций 100/110 и импорта 080 (по full_name)
    "Кораблёва Анастасия Владимировна", "Токаева Татьяна Эдуардовна",
    "Черткова Татьяна Владимировна", "Калужская Мария Владимировна",
    "Герасимова Кристина Александровна", "Просандеева Тамара Ирановна",
    "Димитриади Николай Ахиллесович", "Шурмина Ирина Юрьевна",
    "Губанова Елена Владимировна", "Женина Лариса Викторовна",
    "Шачкова Марина Петровна", "Михайлова Елена Александровна",
    "Тохтуева Любовь Александровна", "Ершов Михаил Георгиевич",
    "Зайлеева Айгуль Раифовна", "Канцур Анна Германовна",
    "Тетерина Наталья Николаевна", "Янонис Мария Александровна",
    "Никитина Марина Владимировна", "Бурдина Светлана Викторовна",
    "Расторгуев Максим Владимирович", "Бредковский Станислав Николаевич",
    "Шобохонова Марина Владимировна", "Акмаев Владислав Антонович",
    "Горюнова Людмила Вячеславовна", "Макарова Екатерина Вячеславовна",
    "Пономаренко Анастасия Александровна", "Сидоренко Екатерина Сергеевна",
    "Четина Анжела", "Фрейманис Инга Федоровна",
    "Антонова Кира Евгеньевна", "Антонов Артём Валерьевич",
    "Добромильский Виктор Викторович", "Давыдова Мария Николаевна",
}

# --- Группа (столбец E) → audience_types.slug ---
GROUP_TO_TYPE_SLUGS = {
    'Дошкольное образование': ['dou'],
    'Школа': ['nachalnaya-shkola', 'srednyaya-starshaya-shkola'],
    'Дополнительное образование': ['dopolnitelnoe-obrazovanie'],
    'Специальное образование': ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola'],
}

# --- ЦА (столбец J) → [(spec_slug, audience_type_id для пина)] ---
# audience_type_id канонической строки в audience_specializations (см. исследование).
CA_TO_SPECS = {
    'Логопед/Дефектолог': [('logopediya', 1), ('defektologiya', 1), ('rabota-s-ovz', 1)],
    'Учитель': [('uchitel', 2)],
    'Воспитатель': [('vospitatel', 1)],
    'Старший воспитатель': [('starshiy-vospitatel', 1)],
    'Младший воспитатель': [('mladshiy-vospitatel', 1)],
    'Педагог дополнительного образования': [('pedagog-do', 5)],
    'Педагог-психолог': [('pedagog-psiholog', 1)],
}

TRANSLIT = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'yo',
    'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
    'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
    'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch', 'ъ': '',
    'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya',
}


def slugify(text, max_len=190):
    s = (text or '').lower().strip()
    s = ''.join(TRANSLIT.get(c, c) for c in s)
    s = re.sub(r'[^a-z0-9]+', '-', s).strip('-')
    return s[:max_len].rstrip('-') if len(s) > max_len else s


def sql_str(val):
    if val is None or val == '':
        return 'NULL'
    s = str(val).replace("\\", "\\\\").replace("'", "\\'")
    s = s.replace("\r\n", "\n").replace("\r", "\n").replace("\n", "\\n")
    return "'" + s + "'"


def parse_price(val):
    if not val:
        return 0
    s = re.sub(r'[\s\xa0]', '', str(val)).replace(',', '.')
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
    """'Модуль 1. ...\\nМодуль 2. ...' → [{number,title}]. Хвост 'Итоговая
    аттестация', приклеенный к последнему модулю, выносим отдельным пунктом."""
    if not text:
        return []
    items = []
    pattern = re.compile(
        r'(?:Модуль|Раздел)\.?\s*(\d+)[.)]\s*(.+?)(?=(?:\n\s*(?:Модуль|Раздел)\.?\s*\d+)|\Z)',
        re.DOTALL)
    for m in pattern.finditer(text):
        num = int(m.group(1))
        title = re.sub(r'\s+', ' ', m.group(2)).strip().rstrip('.').strip()
        items.append({'number': num, 'title': title})
    has_final = False
    if items:
        # отрезаем "Итоговая аттестация" от последнего модуля, если приклеено
        last = items[-1]['title']
        m = re.search(r'\s*Итогова[яйе]\s+аттестаци[яйи]\.?\s*$', last, re.IGNORECASE)
        if m:
            items[-1]['title'] = last[:m.start()].strip().rstrip('.').strip()
            has_final = True
    if not has_final and re.search(r'Итогова[яйе]\s+аттестаци', text, re.IGNORECASE):
        has_final = True
    # если после чистки последний title стал пустым — убираем
    items = [it for it in items if it['title']]
    if has_final:
        next_num = (items[-1]['number'] + 1) if items else 1
        items.append({'number': next_num, 'title': 'Итоговая аттестация'})
    # перенумеровать подряд (в xlsx встречаются пропуски/повторы номеров)
    for i, it in enumerate(items, 1):
        it['number'] = i
    return items


NAME_RE = re.compile(r'^[А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2}$')
STAJ_RE = re.compile(r'^Стаж\s+работы\b', re.IGNORECASE)


def parse_experts(text):
    """Блоки экспертов разделены пустой строкой. Внутри блока:
    строка 1 = ФИО (2–3 слова с заглавной), опц. 'Стаж работы N лет',
    остальное = регалии."""
    if not text:
        return []
    # нормализация: строки из одних пробелов → пусто
    lines = [ln.strip() for ln in text.replace('\r\n', '\n').replace('\r', '\n').split('\n')]
    blocks, cur = [], []
    for ln in lines:
        if ln == '':
            if cur:
                blocks.append(cur)
                cur = []
        else:
            cur.append(ln)
    if cur:
        blocks.append(cur)

    out, seen = [], set()
    for blk in blocks:
        if not blk:
            continue
        name = blk[0].strip()
        mn = re.match(r'^([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2})', name)
        if not mn:
            continue
        name = mn.group(1).strip()
        if name in seen:
            continue
        seen.add(name)
        rest = blk[1:]
        exp = ''
        cred_lines = []
        for r in rest:
            if not exp and STAJ_RE.match(r):
                em = re.search(r'(\d+\s*(?:год|года|лет)\b[^\n]*)', r, re.IGNORECASE)
                exp = (em.group(1).strip().rstrip('.') if em else r).strip()
            else:
                cred_lines.append(r)
        cred = re.sub(r'\s+', ' ', ' '.join(cred_lines)).strip()
        out.append({'name': name, 'slug': slugify(name), 'cred': cred, 'exp': exp})
    return out


# ---------- чтение xlsx ----------
def read_xlsx(path):
    z = zipfile.ZipFile(path)
    NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
    ns = {'m': NS}
    ss = []
    for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si', ns):
        ss.append(''.join(t.text or '' for t in si.iter(f'{{{NS}}}t')))
    sheet = ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
    rows = {}
    for row in sheet.iter(f'{{{NS}}}row'):
        rn = int(row.get('r'))
        rd = {}
        for c in row.findall('m:c', ns):
            ref = c.get('r')
            col = ''.join(ch for ch in ref if ch.isalpha())
            t = c.get('t')
            v = c.find('m:v', ns)
            rd[col] = (ss[int(v.text)] if t == 's' else v.text) if v is not None else ''
        rows[rn] = rd
    return rows


def g(rd, col):
    return (rd.get(col, '') or '').strip()


# ---------- MAIN ----------
def main():
    rows = read_xlsx(XLSX_PATH)
    courses = []
    skipped = []
    seen_titles = set()

    for rn in range(2, 100):
        if rn not in rows:
            continue
        rd = rows[rn]
        title = re.sub(r'\s+', ' ', g(rd, 'B')).strip()
        if not title:
            continue
        hours = parse_hours(g(rd, 'F'))
        price = parse_price(g(rd, 'I'))
        group = g(rd, 'E')
        slug = slugify(title)

        if not hours or not price:
            skipped.append((rn, title, f'hours={hours} price={price}'))
            continue
        if slug in SKIP_SLUGS:
            skipped.append((rn, title, 'дубль существующего курса (SKIP_SLUGS)'))
            continue
        if title in seen_titles:
            skipped.append((rn, title, 'дубль title внутри батча'))
            continue
        seen_titles.add(title)

        group_parts = [p.strip() for p in group.split(',') if p.strip()]
        course_group = group_parts[0] if group_parts else 'Общий'

        type_slugs = []
        for p in group_parts:
            for s in GROUP_TO_TYPE_SLUGS.get(p, []):
                if s not in type_slugs:
                    type_slugs.append(s)

        spec_pairs = []  # (spec_slug, audience_type_id)
        for token in [t.strip() for t in g(rd, 'J').split(',') if t.strip()]:
            for pair in CA_TO_SPECS.get(token, []):
                if pair not in spec_pairs:
                    spec_pairs.append(pair)

        modules = parse_modules(g(rd, 'M'))
        results_text = g(rd, 'N')
        outcomes = {
            'knowledge': [],
            'skills': [results_text] if results_text else [],
            'abilities': [],
        }
        experts = parse_experts(g(rd, 'O'))

        courses.append({
            'row': rn,
            'title': title,
            'slug': slug,
            'description': g(rd, 'L'),
            'target_audience_text': g(rd, 'K'),
            'course_group': course_group,
            'hours': hours,
            'learning_format': g(rd, 'H').rstrip('.').strip() or
                               'заочная с применением дистанционных образовательных технологий',
            'price': price,
            'modules': modules,
            'outcomes': outcomes,
            'type_slugs': type_slugs,
            'spec_pairs': spec_pairs,
            'experts': experts,
        })

    # дедуп slug курсов внутри батча
    used = set()
    for c in courses:
        base, s, i = c['slug'], c['slug'], 1
        while s in used:
            i += 1
            s = f'{base}-{i}'
        c['slug'] = s
        used.add(s)

    # собрать новых экспертов (по slug, не в БД)
    new_experts = {}  # slug -> {name, cred, exp}
    for c in courses:
        for e in c['experts']:
            if e['name'] in EXISTING_EXPERTS:
                continue
            if e['slug'] in new_experts:
                # обогатить, если раньше был без регалий
                if not new_experts[e['slug']]['cred'] and e['cred']:
                    new_experts[e['slug']] = e
                continue
            new_experts[e['slug']] = e

    # ---------- генерация SQL ----------
    L = []
    L.append('-- Migration 166: Seed PP courses «СКОЛКОВО, 3 поток»')
    L.append('-- Auto-generated by scripts/import_pp_courses_skolkovo.py')
    L.append('--   из "курсы/ПП добавить на сайт.xlsx".')
    L.append('-- Идемпотентна: INSERT IGNORE по уникальным slug + связки NOT EXISTS.')
    L.append('-- course_specializations пинуется по audience_type_id (миграция 162'
             ' размножила slug\'и в audience_specializations).')
    L.append('')
    L.append('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;')
    L.append('SET CHARACTER SET utf8mb4;')
    L.append('SET FOREIGN_KEY_CHECKS = 0;')
    L.append('')

    L.append('-- 1. Новые эксперты (INSERT IGNORE по slug — идемпотентно)')
    for slug, e in new_experts.items():
        L.append(
            "INSERT IGNORE INTO course_experts (full_name, slug, credentials, experience) VALUES "
            f"({sql_str(e['name'])}, {sql_str(slug)}, {sql_str(e['cred'])}, {sql_str(e['exp'])});"
        )
    L.append('')

    L.append('-- 2. display_order: от текущего максимума')
    L.append('SELECT @start_order := COALESCE(MAX(display_order), 0) FROM courses;')
    L.append('')

    L.append('-- 3. Курсы ПП')
    for n, c in enumerate(courses, 1):
        mj = json.dumps(c['modules'], ensure_ascii=False)
        oj = json.dumps(c['outcomes'], ensure_ascii=False)
        L.append(
            "INSERT IGNORE INTO courses (title, slug, description, target_audience_text, "
            "course_group, hours, program_type, learning_format, price, modules_json, "
            "outcomes_json, federal_registry_info, is_active, display_order) VALUES "
            f"({sql_str(c['title'])}, {sql_str(c['slug'])}, {sql_str(c['description'])}, "
            f"{sql_str(c['target_audience_text'])}, {sql_str(c['course_group'])}, {c['hours']}, "
            f"'pp', {sql_str(c['learning_format'])}, {c['price']}, "
            f"{sql_str(mj)}, {sql_str(oj)}, NULL, 1, @start_order + {n});"
        )
    L.append('')

    L.append('-- 4. Связи: уровни, специализации, эксперты')
    for c in courses:
        cs = sql_str(c['slug'])
        L.append(f'-- {c["title"]}')
        for ts in c['type_slugs']:
            L.append(
                "INSERT IGNORE INTO course_audience_types (course_id, audience_type_id) "
                f"SELECT c.id, t.id FROM courses c JOIN audience_types t ON t.slug = {sql_str(ts)} "
                f"WHERE c.slug = {cs};"
            )
        for spec_slug, at_id in c['spec_pairs']:
            L.append(
                "INSERT IGNORE INTO course_specializations (course_id, specialization_id) "
                "SELECT c.id, s.id FROM courses c JOIN audience_specializations s "
                f"ON s.slug = {sql_str(spec_slug)} AND s.audience_type_id = {at_id} "
                f"WHERE c.slug = {cs};"
            )
        for order, e in enumerate(c['experts']):
            L.append(
                "INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order) "
                f"SELECT c.id, e.id, 'instructor', {order} FROM courses c JOIN course_experts e "
                f"ON e.slug = {sql_str(e['slug'])} WHERE c.slug = {cs};"
            )
        L.append('')

    L.append('SET FOREIGN_KEY_CHECKS = 1;')

    with io.open(OUTPUT_PATH, 'w', encoding='utf-8') as f:
        f.write('\n'.join(L) + '\n')

    # ---------- отчёт ----------
    print(f'✓ {len(courses)} курсов → {OUTPUT_PATH}', file=sys.stderr)
    print(f'✓ {len(new_experts)} новых экспертов', file=sys.stderr)
    print(f'✗ {len(skipped)} пропущено:', file=sys.stderr)
    for s in skipped:
        print(f'   xlsx-строка {s[0]}: {s[1][:70]} ({s[2]})', file=sys.stderr)
    print('', file=sys.stderr)
    print('Курсы к добавлению:', file=sys.stderr)
    for c in courses:
        print(f"  [{c['row']:>2}] {c['slug']}", file=sys.stderr)
        print(f"        группа={c['course_group']!r} часы={c['hours']} цена={c['price']} "
              f"модулей={len(c['modules'])} экспертов={len(c['experts'])}", file=sys.stderr)
        print(f"        уровни={c['type_slugs']}  спец={[p[0] for p in c['spec_pairs']]}",
              file=sys.stderr)


if __name__ == '__main__':
    main()
