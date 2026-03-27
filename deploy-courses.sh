#!/bin/bash
# Развертывание раздела "Курсы"

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

SERVER="root@141.105.69.45"
PROJECT_PATH="/var/www/html"
PASSWORD="1uf_d7C23o"

SSH_CMD="sshpass -p '${PASSWORD}' ssh -o StrictHostKeyChecking=no ${SERVER}"
SCP_CMD="sshpass -p '${PASSWORD}' scp -o StrictHostKeyChecking=no"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Развертывание раздела КУРСЫ${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Шаг 1: Создание директорий
echo -e "${GREEN}Шаг 1/8: Создание директорий на сервере...${NC}"
$SSH_CMD "cd ${PROJECT_PATH} && mkdir -p assets/images/experts database/migrations"
echo -e "${GREEN}✅ Директории созданы${NC}"

# Шаг 2: Бэкап модифицируемых файлов
echo -e "${GREEN}Шаг 2/8: Бэкап существующих файлов...${NC}"
$SSH_CMD "cd ${PROJECT_PATH} && tar -czf backup_courses_\$(date +%Y%m%d_%H%M%S).tar.gz .htaccess includes/header.php includes/seo-url.php config/config.php migrate.php sitemap.php classes/AudienceCategory.php 2>/dev/null || true"
echo -e "${GREEN}✅ Бэкап создан${NC}"

# Шаг 3: Миграции
echo -e "${GREEN}Шаг 3/8: Загрузка миграций БД...${NC}"
$SCP_CMD database/migrations/047_create_courses_schema.sql ${SERVER}:${PROJECT_PATH}/database/migrations/
$SCP_CMD database/migrations/048_add_course_specializations.sql ${SERVER}:${PROJECT_PATH}/database/migrations/
$SCP_CMD database/migrations/049_seed_courses_data.sql ${SERVER}:${PROJECT_PATH}/database/migrations/
echo -e "${GREEN}✅ Миграции загружены${NC}"

# Шаг 4: PHP-классы
echo -e "${GREEN}Шаг 4/8: Загрузка PHP-классов...${NC}"
$SCP_CMD classes/Course.php ${SERVER}:${PROJECT_PATH}/classes/
$SCP_CMD classes/CourseExpert.php ${SERVER}:${PROJECT_PATH}/classes/
echo -e "${GREEN}✅ Классы загружены${NC}"

# Шаг 5: Страницы и AJAX
echo -e "${GREEN}Шаг 5/8: Загрузка страниц и AJAX...${NC}"
$SCP_CMD courses.php ${SERVER}:${PROJECT_PATH}/
$SCP_CMD pages/course-detail.php ${SERVER}:${PROJECT_PATH}/pages/
$SCP_CMD ajax/course-enrollment.php ${SERVER}:${PROJECT_PATH}/ajax/
echo -e "${GREEN}✅ Страницы загружены${NC}"

# Шаг 6: Стили и ресурсы
echo -e "${GREEN}Шаг 6/8: Загрузка CSS и изображений...${NC}"
$SCP_CMD assets/css/courses.css ${SERVER}:${PROJECT_PATH}/assets/css/
$SCP_CMD assets/images/experts/placeholder.svg ${SERVER}:${PROJECT_PATH}/assets/images/experts/
echo -e "${GREEN}✅ Стили и ресурсы загружены${NC}"

# Шаг 7: Модифицированные файлы (конфиг, маршруты, интеграция)
echo -e "${GREEN}Шаг 7/8: Загрузка модифицированных файлов...${NC}"
$SCP_CMD config/config.php ${SERVER}:${PROJECT_PATH}/config/
$SCP_CMD .htaccess ${SERVER}:${PROJECT_PATH}/
$SCP_CMD includes/seo-url.php ${SERVER}:${PROJECT_PATH}/includes/
$SCP_CMD includes/header.php ${SERVER}:${PROJECT_PATH}/includes/
$SCP_CMD migrate.php ${SERVER}:${PROJECT_PATH}/
$SCP_CMD sitemap.php ${SERVER}:${PROJECT_PATH}/
$SCP_CMD classes/AudienceCategory.php ${SERVER}:${PROJECT_PATH}/classes/
echo -e "${GREEN}✅ Модифицированные файлы загружены${NC}"

# Шаг 8: Запуск миграций
echo -e "${GREEN}Шаг 8/8: Запуск миграций БД...${NC}"
$SSH_CMD "cd ${PROJECT_PATH} && php migrate.php"
echo -e "${GREEN}✅ Миграции применены${NC}"

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Развертывание завершено!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Проверьте:${NC}"
echo -e "  - https://fgos.pro/kursy/"
echo -e "  - https://fgos.pro/kursy/ (любой курс → детальная страница)"
echo -e "  - https://fgos.pro/sitemap.xml (курсы в sitemap)"
