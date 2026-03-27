#!/usr/bin/env python3
"""
Import courses from Excel → SQL migration file.

Usage: python3 scripts/import_courses.py

Reads: Контент/Курсы на ФГОС-практикум.xlsx
Writes: database/migrations/049_seed_courses_data.sql
"""

import openpyxl
import json
import re
import os
import sys

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
XLSX_PATH = os.path.join(BASE_DIR, 'Контент', 'Курсы на ФГОС-практикум.xlsx')
OUTPUT_PATH = os.path.join(BASE_DIR, 'database', 'migrations', '049_seed_courses_data.sql')

# =====================================================
# Mappings
# =====================================================

# Группа → audience_type slugs
GROUP_TO_AUDIENCE_TYPES = {
    'Дошкольное образование': ['dou'],
    'Школа': ['nachalnaya-shkola', 'srednyaya-starshaya-shkola'],
    'Специальное образование': ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola'],  # + spec rabota-s-ovz
    'Дополнительное образование': ['dopolnitelnoe-obrazovanie'],
}

# ЦА → specialization slug
TARGET_TO_SPEC = {
    'Воспитатель': 'vospitatel',
    'Старший воспитатель': 'starshiy-vospitatel',
    'Младший воспитатель': 'mladshiy-vospitatel',
    'Инструктор по физкультуре': 'instruktor-fizkultura',
    'Учитель': 'uchitel',
    'Классный руководитель': 'klassnoe-rukovodstvo',
    'Логопед/Дефектолог': 'logopediya',
    'Педагог-психолог': 'pedagog-psiholog',
    'Руководитель/заместитель руководителя': 'administratsiya-upravlenie',
    'Социальный педагог': 'socialnaya-pedagogika',
    'Педагог дополнительного образования': 'pedagog-do',
}

# =====================================================
# Helpers
# =====================================================

TRANSLIT = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd',
    'е': 'e', 'ё': 'yo', 'ж': 'zh', 'з': 'z', 'и': 'i',
    'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n',
    'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't',
    'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch',
    'ш': 'sh', 'щ': 'sch', 'ъ': '', 'ы': 'y', 'ь': '',
    'э': 'e', 'ю': 'yu', 'я': 'ya',
}


def generate_slug(title):
    slug = title.lower().strip()
    slug = ''.join(TRANSLIT.get(c, c) for c in slug)
    slug = re.sub(r'[^a-z0-9]+', '-', slug)
    slug = slug.strip('-')
    if len(slug) > 120:
        slug = slug[:120].rstrip('-')
    return slug


def sql_escape(val):
    """Escape single quotes and newlines for SQL string literals."""
    if val is None:
        return 'NULL'
    s = str(val).replace("\\", "\\\\").replace("'", "\\'")
    s = s.replace("\r\n", "\\n").replace("\n", "\\n").replace("\r", "\\n")
    return f"'{s}'"


def parse_price(val):
    """Parse price from various formats (6750.0, '6 750', etc.)."""
    if val is None:
        return 0
    s = str(val).replace(' ', '').replace('\xa0', '').replace(',', '.')
    try:
        return int(float(s))
    except ValueError:
        return 0


def parse_hours(val):
    if val is None:
        return 72
    try:
        return int(float(val))
    except (ValueError, TypeError):
        return 72


def parse_modules(text):
    """Parse modules text into JSON array."""
    if not text:
        return []
    lines = text.strip().split('\n')
    modules = []
    current_module = None

    for line in lines:
        line = line.strip()
        if not line:
            continue
        # Match "Модуль N. Title" or "Модуль N Title"
        m = re.match(r'^Модуль\s+(\d+)[.\s]+(.+)', line, re.IGNORECASE)
        if m:
            if current_module:
                modules.append(current_module)
            current_module = {
                'number': int(m.group(1)),
                'title': m.group(2).strip().rstrip('.')
            }
        elif current_module:
            # Append continuation lines to current module title
            current_module['title'] += ' ' + line.rstrip('.')

    if current_module:
        modules.append(current_module)

    # If no modules found via pattern, split by newlines
    if not modules:
        for i, line in enumerate(lines):
            line = line.strip()
            if line:
                modules.append({'number': i + 1, 'title': line})

    return modules


def parse_outcomes(text):
    """Parse outcomes text into JSON structure with knowledge/skills/abilities."""
    if not text:
        return {}
    result = {'knowledge': [], 'skills': [], 'abilities': []}
    current_section = None

    lines = text.strip().split('\n')
    for line in lines:
        line = line.strip().rstrip(';').rstrip('.')
        if not line:
            continue

        lower = line.lower()
        if lower.startswith('знани'):
            current_section = 'knowledge'
            # Check if there's content on the same line after ":"
            rest = re.sub(r'^знани[яйе]\s*', '', line, flags=re.IGNORECASE).strip()
            if rest:
                result['knowledge'].append(rest)
            continue
        elif lower.startswith('умени'):
            current_section = 'skills'
            rest = re.sub(r'^умени[яйе]\s*', '', line, flags=re.IGNORECASE).strip()
            if rest:
                result['skills'].append(rest)
            continue
        elif lower.startswith('навык'):
            current_section = 'abilities'
            rest = re.sub(r'^навык[иов]*\s*', '', line, flags=re.IGNORECASE).strip()
            if rest:
                result['abilities'].append(rest)
            continue

        if current_section and line:
            result[current_section].append(line)

    # Remove empty sections
    return {k: v for k, v in result.items() if v}


def parse_experts(text):
    """Parse experts info. Returns list of dicts with name, credentials, experience."""
    if not text:
        return []

    experts = []
    lines = text.strip().split('\n')
    current_expert = None

    for line in lines:
        line = line.strip()
        if not line:
            continue

        # Detect "Стаж работы N лет" — append to current expert
        stazh_match = re.match(r'^Стаж\s+работы\s+(.+)$', line, re.IGNORECASE)
        if stazh_match and current_expert:
            current_expert['experience'] = stazh_match.group(1).strip()
            continue

        # Detect new expert: line with a name (Cyrillic name pattern at start)
        # Pattern: Last First Middle, credentials...
        name_match = re.match(r'^([А-ЯЁ][а-яё]+\s+[А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+)?)\s*[,.]?\s*(.*)$', line)
        if name_match:
            if current_expert:
                experts.append(current_expert)
            current_expert = {
                'full_name': name_match.group(1).strip(),
                'credentials': name_match.group(2).strip().rstrip(',').strip() if name_match.group(2) else '',
                'experience': ''
            }
        elif current_expert:
            # Continuation of credentials
            if current_expert['credentials']:
                current_expert['credentials'] += ' ' + line
            else:
                current_expert['credentials'] = line

    if current_expert:
        experts.append(current_expert)

    return experts


def parse_groups(group_str):
    """Parse composite group string into list of individual groups."""
    if not group_str:
        return []
    # Split by comma, handle "Школа , Дошкольное образование" etc.
    parts = [g.strip() for g in group_str.split(',')]
    return [p for p in parts if p]


def get_audience_types_for_groups(groups):
    """Map groups to unique audience_type slugs."""
    slugs = set()
    for g in groups:
        for slug in GROUP_TO_AUDIENCE_TYPES.get(g, []):
            slugs.add(slug)
    return sorted(slugs)


def has_special_education(groups):
    return 'Специальное образование' in groups


# =====================================================
# Main
# =====================================================

def main():
    print(f"Reading {XLSX_PATH}...")
    wb = openpyxl.load_workbook(XLSX_PATH, data_only=True)
    ws = wb.active

    courses = []
    all_experts = {}  # full_name → expert data
    slug_counter = {}

    for row in ws.iter_rows(min_row=2, values_only=True):
        title = row[1]
        if not title or not str(title).strip():
            continue

        title = str(title).strip()
        group_str = str(row[2]).strip() if row[2] else ''
        hours = parse_hours(row[3])
        program_type = 'kpk'  # All courses are КПК
        learning_format = str(row[5]).strip() if row[5] else 'Заочная с применением дистанционных образовательных технологий'
        price = parse_price(row[6])
        target_audience_nav = str(row[7]).strip() if row[7] else ''
        target_audience_text = str(row[8]).strip() if row[8] else ''
        offer = str(row[9]).strip() if row[9] else ''
        modules_text = str(row[10]).strip() if row[10] else ''
        outcomes_text = str(row[11]).strip() if row[11] else ''
        experts_text = str(row[12]).strip() if row[12] else ''
        federal_registry = str(row[13]).strip() if row[13] and str(row[13]).strip() not in ('None', '') else None

        # Generate unique slug
        slug = generate_slug(title)
        if slug in slug_counter:
            slug_counter[slug] += 1
            slug = f"{slug}-{slug_counter[slug]}"
        else:
            slug_counter[slug] = 0

        # Parse complex fields
        modules = parse_modules(modules_text)
        outcomes = parse_outcomes(outcomes_text)
        experts = parse_experts(experts_text)

        # Track unique experts
        course_expert_names = []
        for exp in experts:
            name = exp['full_name']
            if name not in all_experts:
                all_experts[name] = exp
            else:
                # Merge credentials/experience if richer
                if len(exp.get('credentials', '')) > len(all_experts[name].get('credentials', '')):
                    all_experts[name]['credentials'] = exp['credentials']
                if exp.get('experience') and not all_experts[name].get('experience'):
                    all_experts[name]['experience'] = exp['experience']
            course_expert_names.append(name)

        # Parse groups and target audience
        groups = parse_groups(group_str)
        audience_type_slugs = get_audience_types_for_groups(groups)
        is_special_ed = has_special_education(groups)

        # Parse ЦА → specialization slugs
        spec_slugs = set()
        for ta in target_audience_nav.split(','):
            ta = ta.strip()
            if ta in TARGET_TO_SPEC:
                spec_slugs.add(TARGET_TO_SPEC[ta])
        if is_special_ed:
            spec_slugs.add('rabota-s-ovz')

        courses.append({
            'title': title,
            'slug': slug,
            'description': offer,
            'target_audience_text': target_audience_text,
            'course_group': group_str,
            'hours': hours,
            'program_type': program_type,
            'learning_format': learning_format,
            'price': price,
            'modules_json': json.dumps(modules, ensure_ascii=False) if modules else None,
            'outcomes_json': json.dumps(outcomes, ensure_ascii=False) if outcomes else None,
            'federal_registry_info': federal_registry,
            'audience_type_slugs': audience_type_slugs,
            'spec_slugs': sorted(spec_slugs),
            'expert_names': course_expert_names,
        })

    print(f"Parsed {len(courses)} courses, {len(all_experts)} unique experts")

    # Generate SQL
    sql_lines = []
    sql_lines.append("-- Migration 049: Seed Courses Data")
    sql_lines.append("-- Auto-generated from Курсы на ФГОС-практикум.xlsx")
    sql_lines.append(f"-- {len(courses)} courses, {len(all_experts)} experts")
    sql_lines.append("")
    sql_lines.append("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;")
    sql_lines.append("SET CHARACTER SET utf8mb4;")
    sql_lines.append("SET FOREIGN_KEY_CHECKS = 0;")
    sql_lines.append("")

    # 1. Insert experts
    sql_lines.append("-- =====================================================")
    sql_lines.append("-- ЭКСПЕРТЫ")
    sql_lines.append("-- =====================================================")
    expert_slugs = {}
    expert_slug_counter = {}
    for name, data in sorted(all_experts.items()):
        eslug = generate_slug(name)
        if eslug in expert_slug_counter:
            expert_slug_counter[eslug] += 1
            eslug = f"{eslug}-{expert_slug_counter[eslug]}"
        else:
            expert_slug_counter[eslug] = 0
        expert_slugs[name] = eslug

        sql_lines.append(
            f"INSERT INTO course_experts (full_name, slug, credentials, experience) VALUES "
            f"({sql_escape(name)}, {sql_escape(eslug)}, {sql_escape(data.get('credentials', ''))}, "
            f"{sql_escape(data.get('experience', ''))});"
        )
    sql_lines.append("")

    # 2. Insert courses
    sql_lines.append("-- =====================================================")
    sql_lines.append("-- КУРСЫ")
    sql_lines.append("-- =====================================================")
    for i, c in enumerate(courses):
        sql_lines.append(
            f"INSERT INTO courses (title, slug, description, target_audience_text, course_group, "
            f"hours, program_type, learning_format, price, modules_json, outcomes_json, "
            f"federal_registry_info, is_active, display_order) VALUES ("
            f"{sql_escape(c['title'])}, {sql_escape(c['slug'])}, {sql_escape(c['description'])}, "
            f"{sql_escape(c['target_audience_text'])}, {sql_escape(c['course_group'])}, "
            f"{c['hours']}, {sql_escape(c['program_type'])}, {sql_escape(c['learning_format'])}, "
            f"{c['price']}, "
            f"{sql_escape(c['modules_json']) if c['modules_json'] else 'NULL'}, "
            f"{sql_escape(c['outcomes_json']) if c['outcomes_json'] else 'NULL'}, "
            f"{sql_escape(c['federal_registry_info']) if c['federal_registry_info'] else 'NULL'}, "
            f"1, {i});"
        )
    sql_lines.append("")

    # 3. Link all courses to audience_category "Педагогам" (id=1)
    sql_lines.append("-- =====================================================")
    sql_lines.append("-- ПРИВЯЗКА К КАТЕГОРИИ АУДИТОРИИ (все → Педагогам)")
    sql_lines.append("-- =====================================================")
    sql_lines.append("INSERT IGNORE INTO course_audience_categories (course_id, category_id)")
    sql_lines.append("SELECT id, 1 FROM courses WHERE is_active = 1;")
    sql_lines.append("")

    # 4. Link courses to audience_types
    sql_lines.append("-- =====================================================")
    sql_lines.append("-- ПРИВЯЗКА К ТИПАМ АУДИТОРИИ")
    sql_lines.append("-- =====================================================")
    for c in courses:
        if c['audience_type_slugs']:
            for at_slug in c['audience_type_slugs']:
                sql_lines.append(
                    f"INSERT IGNORE INTO course_audience_types (course_id, audience_type_id) "
                    f"SELECT c.id, at.id FROM courses c CROSS JOIN audience_types at "
                    f"WHERE c.slug = {sql_escape(c['slug'])} AND at.slug = {sql_escape(at_slug)};"
                )
    sql_lines.append("")

    # 5. Link courses to specializations
    sql_lines.append("-- =====================================================")
    sql_lines.append("-- ПРИВЯЗКА К СПЕЦИАЛИЗАЦИЯМ")
    sql_lines.append("-- =====================================================")
    for c in courses:
        if c['spec_slugs']:
            for spec_slug in c['spec_slugs']:
                sql_lines.append(
                    f"INSERT IGNORE INTO course_specializations (course_id, specialization_id) "
                    f"SELECT c.id, s.id FROM courses c CROSS JOIN audience_specializations s "
                    f"WHERE c.slug = {sql_escape(c['slug'])} AND s.slug = {sql_escape(spec_slug)};"
                )
    sql_lines.append("")

    # 6. Link courses to experts
    sql_lines.append("-- =====================================================")
    sql_lines.append("-- ПРИВЯЗКА КУРСОВ К ЭКСПЕРТАМ")
    sql_lines.append("-- =====================================================")
    for c in courses:
        for j, name in enumerate(c['expert_names']):
            eslug = expert_slugs.get(name, '')
            if eslug:
                sql_lines.append(
                    f"INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, display_order) "
                    f"SELECT c.id, e.id, {j} FROM courses c CROSS JOIN course_experts e "
                    f"WHERE c.slug = {sql_escape(c['slug'])} AND e.slug = {sql_escape(eslug)};"
                )
    sql_lines.append("")
    sql_lines.append("SET FOREIGN_KEY_CHECKS = 1;")

    # Write output
    with open(OUTPUT_PATH, 'w', encoding='utf-8') as f:
        f.write('\n'.join(sql_lines))

    print(f"Written {OUTPUT_PATH}")
    print(f"  {len(courses)} courses")
    print(f"  {len(all_experts)} experts")


if __name__ == '__main__':
    main()
