#!/usr/bin/env python3
"""Подготавливает SQL и конфигурацию изолированной QA-копии без подключения к БД."""
import argparse
import json
import re
from pathlib import Path


def sql_value(value):
    if value is None:
        return 'NULL'
    if isinstance(value, (int, float)):
        return str(value)
    if value == '':
        return "''"
    return 'CONVERT(0x' + str(value).encode().hex() + ' USING utf8mb4)'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--snapshot', required=True)
    parser.add_argument('--output', default='editorial/reviews/seo-catalog-20261021')
    args = parser.parse_args()
    snapshot = json.loads(Path(args.snapshot).read_text())
    out = Path(args.output); out.mkdir(parents=True, exist_ok=True)
    with (out / 'bootstrap.sql').open('w') as target:
        target.write('SET FOREIGN_KEY_CHECKS=0;\n')
        for ddl in snapshot['_schema']:
            if not ddl.startswith('CREATE TABLE '):
                raise ValueError('Ожидалась схема таблицы')
            target.write(ddl + ';\n')
        for table, rows in snapshot.items():
            if table.startswith('_') or not isinstance(rows, list) or not rows:
                continue
            if not re.fullmatch(r'[a-z_][a-z0-9_]*', table):
                raise ValueError('Некорректное имя таблицы')
            for row in rows:
                if not all(re.fullmatch(r'[a-z_][a-z0-9_]*', key) for key in row):
                    raise ValueError('Некорректное имя поля')
                target.write('INSERT INTO `' + table + '` (`' + '`,`'.join(row) + '`) VALUES ('
                             + ','.join(sql_value(value) for value in row.values()) + ');\n')
        target.write('SET FOREIGN_KEY_CHECKS=1;\n')
    (out / 'qa-config.php').write_text("""<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }
define('DB_HOST','pedagogy_catalog_qa_db');
define('DB_NAME','catalog_qa');
define('DB_USER','root');
define('DB_PASS','local-catalog-qa');
define('SITE_URL','http://localhost:8093');
define('EMAIL_SENDING_ENABLED',false);
if (!defined('COMPETITION_CATALOG_FACTS_ENABLED')) define('COMPETITION_CATALOG_FACTS_ENABLED',true);
""")
    (out / 'qa.ini').write_text('auto_prepend_file=/var/www/html/editorial/reviews/seo-catalog-20261021/qa-config.php\nopcache.enable=0\nopcache.enable_cli=0\nsession.save_path=/tmp/catalog-qa-sessions\n')
    print('Подготовлены bootstrap.sql, qa-config.php и qa.ini. База данных не изменялась.')


if __name__ == '__main__':
    main()
