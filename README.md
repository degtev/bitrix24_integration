# Bitrix24API PHP Client


## Подключение

Просто поместите файл `Bitrix24API.php` в ваш проект и подключите его через `require_once` или автозагрузчик.

```php
require_once __DIR__ . '/Bitrix24API.php';

use helpers\Bitrix24API;

$api = new Bitrix24API(
    baseUrl: 'https://yourcompany.bitrix24.ru',
    userId: '1',
    webhook: 'your_webhook_code'
);
```

## Примеры использования

### Создание лида

```php
$leadId = $api->addLead([
    'TITLE' => 'Новый лид с сайта',
    'NAME'  => 'Иван',
    'PHONE' => [['VALUE' => '+79991234567', 'VALUE_TYPE' => 'MOBILE']],
    'EMAIL' => [['VALUE' => 'test@example.com', 'VALUE_TYPE' => 'WORK']],
]);
```

### Привязка товаров к лиду

```php
$ok = $api->addProductsToLead($leadId, [
    [
        'PRODUCT_ID' => 123,
        'PRICE'      => 1000,
        'QUANTITY'   => 2,
    ],
]);
```

### Найти или создать контакт

```php
$contactId = $api->getOrCreateContact(
    name: 'Пётр Петров',
    phone: '+79997654321',
    email: 'petrov@example.com'
);
```

### Создание сделки с контактом

```php
$dealId = $api->createDealWithContact(
    dealFields: [
        'TITLE' => 'Сделка с сайта',
        'OPPORTUNITY' => 15000,
    ],
    contactName: 'Анна',
    phone: '+79998887766',
    email: 'anna@example.com'
);
```

### Получение доступных полей сущности

```php
$fields = $api->getEntityFields('DEAL');
print_r($fields);
```

### Поиск кода пользовательского поля по названию

```php
$ufCode = $api->findUserFieldCodeByTitle('DEAL', 'Источник лида');
echo $ufCode; // например UF_CRM_1706523456
```

## Обработка ошибок

Методы выбрасывают исключения `RuntimeException` при сетевых проблемах, ошибках Bitrix24 API или HTTP >= 400. Используйте `try/catch` для обработки.

```php
try {
    $dealId = $api->addDeal([...]);
} catch (RuntimeException $e) {
    error_log('Ошибка Bitrix24: ' . $e->getMessage());
}
```