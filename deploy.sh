#!/bin/bash
# Скрипт автоматического развертывания интеграции ЮКассы
# Использование: ./deploy.sh

set -e  # Остановка при ошибке

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Конфигурация
SERVER_IP="141.105.69.45"
SERVER_USER="root"
PROJECT_PATH="/var/www/html"  # ИЗМЕНИТЕ на реальный путь к проекту!

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Развертывание интеграции ЮКассы${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Проверка, что .env настроен
echo -e "${YELLOW}Проверка конфигурации...${NC}"

if grep -q "your_email@gmail.com" .env; then
    echo -e "${RED}❌ ОШИБКА: SMTP не настроен в .env!${NC}"
    echo -e "${YELLOW}Пожалуйста, отредактируйте .env и укажите реальные SMTP данные.${NC}"
    echo -e "${YELLOW}После этого запустите скрипт снова.${NC}"
    exit 1
fi

if grep -q "test_shop_id" .env; then
    echo -e "${RED}❌ ОШИБКА: ЮКасса ключи не настроены в .env!${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Конфигурация проверена${NC}"
echo ""

# Подтверждение от пользователя
echo -e "${YELLOW}Сервер: ${SERVER_USER}@${SERVER_IP}${NC}"
echo -e "${YELLOW}Путь проекта на сервере: ${PROJECT_PATH}${NC}"
echo ""
read -p "Продолжить развертывание? (y/n): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}Развертывание отменено${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}📤 Загрузка файлов на сервер...${NC}"

# Создание директорий на сервере
echo -e "${YELLOW}Создание директорий...${NC}"
ssh ${SERVER_USER}@${SERVER_IP} "cd ${PROJECT_PATH} && mkdir -p api/webhook logs && chmod 755 api api/webhook && chmod 777 logs"

# Создание бэкапа на сервере
echo -e "${YELLOW}Создание бэкапа...${NC}"
ssh ${SERVER_USER}@${SERVER_IP} "cd ${PROJECT_PATH} && tar -czf backup_\$(date +%Y%m%d_%H%M%S).tar.gz ajax/create-payment.php .env 2>/dev/null || true"

# Загрузка файлов
echo -e "${YELLOW}Загрузка новых файлов...${NC}"

scp classes/Order.php ${SERVER_USER}@${SERVER_IP}:${PROJECT_PATH}/classes/
scp includes/email-helper.php ${SERVER_USER}@${SERVER_IP}:${PROJECT_PATH}/includes/
scp ajax/create-payment.php ${SERVER_USER}@${SERVER_IP}:${PROJECT_PATH}/ajax/
scp pages/payment-success.php ${SERVER_USER}@${SERVER_IP}:${PROJECT_PATH}/pages/
scp pages/payment-failure.php ${SERVER_USER}@${SERVER_IP}:${PROJECT_PATH}/pages/
scp api/webhook/yookassa.php ${SERVER_USER}@${SERVER_IP}:${PROJECT_PATH}/api/webhook/
scp api/check-payment.php ${SERVER_USER}@${SERVER_IP}:${PROJECT_PATH}/api/
scp .env ${SERVER_USER}@${SERVER_IP}:${PROJECT_PATH}/

echo -e "${GREEN}✅ Файлы загружены${NC}"
echo ""

# Установка прав доступа
echo -e "${YELLOW}Установка прав доступа...${NC}"
ssh ${SERVER_USER}@${SERVER_IP} "cd ${PROJECT_PATH} && \
    chmod 644 classes/Order.php && \
    chmod 644 includes/email-helper.php && \
    chmod 644 ajax/create-payment.php && \
    chmod 644 pages/payment-success.php && \
    chmod 644 pages/payment-failure.php && \
    chmod 644 api/webhook/yookassa.php && \
    chmod 644 api/check-payment.php && \
    chmod 600 .env"

echo -e "${GREEN}✅ Права доступа установлены${NC}"
echo ""

# Проверка файлов
echo -e "${YELLOW}Проверка файлов на сервере...${NC}"
ssh ${SERVER_USER}@${SERVER_IP} "cd ${PROJECT_PATH} && \
    ls -la classes/Order.php && \
    ls -la api/webhook/yookassa.php && \
    ls -la logs/"

echo -e "${GREEN}✅ Файлы на месте${NC}"
echo ""

# Тестирование webhook
echo -e "${YELLOW}Тестирование webhook...${NC}"
WEBHOOK_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -X POST https://${SERVER_IP}/api/webhook/yookassa.php)

if [ "$WEBHOOK_RESPONSE" == "403" ]; then
    echo -e "${GREEN}✅ Webhook доступен (403 - ожидаемый ответ)${NC}"
elif [ "$WEBHOOK_RESPONSE" == "200" ]; then
    echo -e "${GREEN}✅ Webhook доступен (200)${NC}"
else
    echo -e "${RED}⚠️  Webhook ответил: ${WEBHOOK_RESPONSE} (ожидалось 403 или 200)${NC}"
fi

# Тестирование API
echo -e "${YELLOW}Тестирование API check-payment...${NC}"
API_RESPONSE=$(curl -s https://${SERVER_IP}/api/check-payment.php?order_number=test | grep -o "Order not found" || echo "Error")

if [ "$API_RESPONSE" == "Order not found" ]; then
    echo -e "${GREEN}✅ API работает${NC}"
else
    echo -e "${RED}⚠️  API возможно не работает${NC}"
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Развертывание завершено!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Следующие шаги:${NC}"
echo ""
echo -e "1. ${YELLOW}Настройте webhook в ЮКассе:${NC}"
echo -e "   URL: https://${SERVER_IP}/api/webhook/yookassa.php"
echo -e "   События: payment.succeeded, payment.canceled"
echo ""
echo -e "2. ${YELLOW}Проверьте логи:${NC}"
echo -e "   ssh ${SERVER_USER}@${SERVER_IP} 'tail -f ${PROJECT_PATH}/logs/payment.log'"
echo ""
echo -e "3. ${YELLOW}Проведите тестовый платеж:${NC}"
echo -e "   Используйте минимальную сумму для первого теста!"
echo ""
echo -e "4. ${YELLOW}Мониторинг:${NC}"
echo -e "   - logs/payment.log"
echo -e "   - logs/webhook.log"
echo -e "   - logs/email.log"
echo ""
echo -e "${GREEN}Полная инструкция: см. DEPLOYMENT.md${NC}"
echo ""
