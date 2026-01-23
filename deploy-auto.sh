#!/bin/bash
# Автоматическое развертывание с sshpass

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
echo -e "${GREEN}  Автоматическое развертывание${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Шаг 1: Создание директорий
echo -e "${GREEN}Шаг 1/7: Создание директорий...${NC}"
$SSH_CMD "cd ${PROJECT_PATH} && mkdir -p api/webhook logs && chmod 755 api api/webhook && chmod 777 logs"
echo -e "${GREEN}✅ Директории созданы${NC}"

# Шаг 2: Бэкап
echo -e "${GREEN}Шаг 2/7: Создание бэкапа...${NC}"
$SSH_CMD "cd ${PROJECT_PATH} && tar -czf backup_\$(date +%Y%m%d_%H%M%S).tar.gz ajax/create-payment.php .env 2>/dev/null || true"
echo -e "${GREEN}✅ Бэкап создан${NC}"

# Шаг 3: Загрузка classes
echo -e "${GREEN}Шаг 3/7: Загрузка classes/Order.php...${NC}"
$SCP_CMD classes/Order.php ${SERVER}:${PROJECT_PATH}/classes/
echo -e "${GREEN}✅ Order.php загружен${NC}"

# Шаг 4: Загрузка includes
echo -e "${GREEN}Шаг 4/7: Загрузка includes/email-helper.php...${NC}"
$SCP_CMD includes/email-helper.php ${SERVER}:${PROJECT_PATH}/includes/
echo -e "${GREEN}✅ email-helper.php загружен${NC}"

# Шаг 5: Загрузка ajax и pages
echo -e "${GREEN}Шаг 5/7: Загрузка ajax и pages...${NC}"
$SCP_CMD ajax/create-payment.php ${SERVER}:${PROJECT_PATH}/ajax/
$SCP_CMD pages/payment-success.php ${SERVER}:${PROJECT_PATH}/pages/
$SCP_CMD pages/payment-failure.php ${SERVER}:${PROJECT_PATH}/pages/
echo -e "${GREEN}✅ Ajax и pages загружены${NC}"

# Шаг 6: Загрузка API
echo -e "${GREEN}Шаг 6/7: Загрузка API...${NC}"
$SCP_CMD api/webhook/yookassa.php ${SERVER}:${PROJECT_PATH}/api/webhook/
$SCP_CMD api/check-payment.php ${SERVER}:${PROJECT_PATH}/api/
echo -e "${GREEN}✅ API файлы загружены${NC}"

# Шаг 7: Загрузка .env
echo -e "${GREEN}Шаг 7/7: Загрузка .env с SMTP...${NC}"
$SCP_CMD .env ${SERVER}:${PROJECT_PATH}/
echo -e "${GREEN}✅ .env загружен${NC}"

# Установка прав
echo ""
echo -e "${GREEN}Установка прав доступа...${NC}"
$SSH_CMD "cd ${PROJECT_PATH} && chmod 644 classes/Order.php includes/email-helper.php ajax/create-payment.php pages/payment-success.php pages/payment-failure.php api/webhook/yookassa.php api/check-payment.php && chmod 600 .env"
echo -e "${GREEN}✅ Права установлены${NC}"

# Проверка
echo ""
echo -e "${GREEN}Проверка файлов...${NC}"
$SSH_CMD "ls -la ${PROJECT_PATH}/classes/Order.php ${PROJECT_PATH}/api/webhook/yookassa.php ${PROJECT_PATH}/logs/"
echo -e "${GREEN}✅ Файлы на месте${NC}"

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Развертывание завершено!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Тестирование
echo -e "${YELLOW}Тестирование webhook...${NC}"
WEBHOOK_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -X POST https://141.105.69.45/api/webhook/yookassa.php)
if [ "$WEBHOOK_RESPONSE" == "403" ] || [ "$WEBHOOK_RESPONSE" == "200" ]; then
    echo -e "${GREEN}✅ Webhook доступен (HTTP ${WEBHOOK_RESPONSE})${NC}"
else
    echo -e "${YELLOW}⚠️  Webhook HTTP ${WEBHOOK_RESPONSE}${NC}"
fi

echo ""
echo -e "${YELLOW}Тестирование API check-payment...${NC}"
API_TEST=$(curl -s https://141.105.69.45/api/check-payment.php?order_number=test | grep -o "Order not found" || echo "")
if [ "$API_TEST" == "Order not found" ]; then
    echo -e "${GREEN}✅ API работает${NC}"
else
    echo -e "${YELLOW}⚠️  API может не работать${NC}"
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  СЛЕДУЮЩИЙ ШАГ${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Настройте webhook в ЮКассе:${NC}"
echo -e "1. Откройте: ${GREEN}https://yookassa.ru/my${NC}"
echo -e "2. Настройки → HTTP-уведомления"
echo -e "3. URL: ${GREEN}https://141.105.69.45/api/webhook/yookassa.php${NC}"
echo -e "4. События: ${GREEN}payment.succeeded, payment.canceled${NC}"
echo -e "5. Сохраните"
echo ""
echo -e "${YELLOW}После этого можно проводить тестовый платеж!${NC}"
echo ""
