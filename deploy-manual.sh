#!/bin/bash
# Ручное развертывание с пошаговыми инструкциями

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

SERVER="root@141.105.69.45"
PROJECT_PATH="/var/www/html"
PASSWORD="1uf_d7C23o"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Развертывание интеграции ЮКассы${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Пароль для SSH: ${PASSWORD}${NC}"
echo -e "${YELLOW}Вводите этот пароль когда будет запрос${NC}"
echo ""
echo "Нажмите Enter для продолжения..."
read

# Шаг 1: Создание директорий и бэкапа
echo ""
echo -e "${GREEN}Шаг 1/6: Создание директорий на сервере...${NC}"
ssh ${SERVER} << ENDSSH
cd ${PROJECT_PATH}
mkdir -p api/webhook logs
chmod 755 api api/webhook
chmod 777 logs
tar -czf backup_\$(date +%Y%m%d_%H%M%S).tar.gz ajax/create-payment.php .env 2>/dev/null || true
echo "✅ Директории созданы, бэкап выполнен"
ENDSSH

# Шаг 2: Загрузка классов
echo ""
echo -e "${GREEN}Шаг 2/6: Загрузка classes/Order.php...${NC}"
scp classes/Order.php ${SERVER}:${PROJECT_PATH}/classes/
echo "✅ Order.php загружен"

# Шаг 3: Загрузка includes
echo ""
echo -e "${GREEN}Шаг 3/6: Загрузка includes/email-helper.php...${NC}"
scp includes/email-helper.php ${SERVER}:${PROJECT_PATH}/includes/
echo "✅ email-helper.php загружен"

# Шаг 4: Загрузка ajax и pages
echo ""
echo -e "${GREEN}Шаг 4/6: Загрузка ajax и pages...${NC}"
scp ajax/create-payment.php ${SERVER}:${PROJECT_PATH}/ajax/
scp pages/payment-success.php ${SERVER}:${PROJECT_PATH}/pages/
scp pages/payment-failure.php ${SERVER}:${PROJECT_PATH}/pages/
echo "✅ Ajax и pages загружены"

# Шаг 5: Загрузка API
echo ""
echo -e "${GREEN}Шаг 5/6: Загрузка API файлов...${NC}"
scp api/webhook/yookassa.php ${SERVER}:${PROJECT_PATH}/api/webhook/
scp api/check-payment.php ${SERVER}:${PROJECT_PATH}/api/
echo "✅ API файлы загружены"

# Шаг 6: Загрузка .env
echo ""
echo -e "${GREEN}Шаг 6/6: Загрузка .env с SMTP настройками...${NC}"
scp .env ${SERVER}:${PROJECT_PATH}/
echo "✅ .env загружен"

# Установка прав
echo ""
echo -e "${GREEN}Установка прав доступа...${NC}"
ssh ${SERVER} << ENDSSH
cd ${PROJECT_PATH}
chmod 644 classes/Order.php
chmod 644 includes/email-helper.php
chmod 644 ajax/create-payment.php
chmod 644 pages/payment-success.php
chmod 644 pages/payment-failure.php
chmod 644 api/webhook/yookassa.php
chmod 644 api/check-payment.php
chmod 600 .env
echo "✅ Права установлены"
ENDSSH

# Проверка файлов
echo ""
echo -e "${GREEN}Проверка загруженных файлов...${NC}"
ssh ${SERVER} "ls -la ${PROJECT_PATH}/classes/Order.php ${PROJECT_PATH}/api/webhook/yookassa.php"

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Развертывание завершено!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Тестирование
echo -e "${YELLOW}Тестирование webhook...${NC}"
WEBHOOK_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -X POST https://141.105.69.45/api/webhook/yookassa.php)
if [ "$WEBHOOK_RESPONSE" == "403" ] || [ "$WEBHOOK_RESPONSE" == "200" ]; then
    echo -e "${GREEN}✅ Webhook доступен (код: ${WEBHOOK_RESPONSE})${NC}"
else
    echo -e "${YELLOW}⚠️  Webhook ответил: ${WEBHOOK_RESPONSE}${NC}"
fi

echo ""
echo -e "${YELLOW}Следующий шаг: Настройте webhook в ЮКассе${NC}"
echo -e "URL: ${GREEN}https://141.105.69.45/api/webhook/yookassa.php${NC}"
echo ""
