<?php

declare(strict_types=1);

namespace helpers;

/**
 * Bitrix24API
 *
 * Особенности:
 * - Явная типизация и строгий режим.
 * - Централизованный HTTP-запрос с проверкой ошибок и HTTP-кодов.
 * - Поддержка GET/POST, автоматическая сериализация/десериализация JSON.
 * - Удобные хелперы для продуктов, лидов, контактов и сделок.
 * - Поиск/создание контакта с контролем дублей.
 *
 * Примечание по безопасности: не хардкодьте токены/вебхуки в коде. Передавайте их из
 * окружения/конфига и храните вне VCS.
 */
class Bitrix24API
{
    /** Базовый URL вашего портала Bitrix24, например: https://example.bitrix24.ru */
    private string $baseUrl;

    /** ID пользователя вебхука (обычно числовой идентификатор) */
    private string $userId;

    /** Секрет вебхука (hash) */
    private string $webhook;

    /** Таймаут HTTP-запроса в секундах */
    private int $timeout;

    /** Включать ли проверку SSL-сертификата */
    private bool $verifySsl;

    /**
     * @param string $baseUrl   Базовый URL портала Bitrix24 (без завершающего слэша)
     * @param string $userId    ID пользователя вебхука
     * @param string $webhook   Ключ вебхука
     * @param int    $timeout   Таймаут HTTP-запроса, по умолчанию 30 секунд
     * @param bool   $verifySsl Включить проверку SSL, по умолчанию true
     */
    public function __construct(
        string $baseUrl,
        string $userId,
        string $webhook,
        int $timeout = 30,
        bool $verifySsl = true
    ) {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->userId   = $userId;
        $this->webhook  = $webhook;
        $this->timeout  = $timeout;
        $this->verifySsl = $verifySsl;
    }

    /* ==============================
     * LOW-LEVEL HTTP LAYER
     * ============================== */

    /**
     * Выполняет HTTP-запрос к REST Bitrix24.
     *
     * @param string               $endpoint Относительный путь REST-метода (например, "crm.deal.add.json")
     * @param array<string,mixed>  $data     Параметры запроса (query для GET, body для POST)
     * @param 'GET'|'POST'         $method   HTTP-метод
     *
     * @return array<string,mixed>|int|string|null Распарсенный результат из поля `result` или null
     *
     * @throws \RuntimeException В случае сетевых ошибок, HTTP >= 400 или ошибок Bitrix24 API
     */
    private function sendRequest(string $endpoint, array $data = [], string $method = 'GET')
    {
        $url = sprintf(
            '%s/rest/%s/%s/%s',
            $this->baseUrl,
            rawurlencode($this->userId),
            rawurlencode($this->webhook),
            ltrim($endpoint, '/')
        );

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL');
        }

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                curl_close($ch);
                throw new \RuntimeException('Failed to JSON-encode request body');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch) ?: 'Unknown cURL error';
            curl_close($ch);
            throw new \RuntimeException('cURL error: ' . $err);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("HTTP {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON decode error: ' . json_last_error_msg());
        }

        if (isset($decoded['error'])) {
            $description = $decoded['error_description'] ?? 'Unknown API error';
            throw new \RuntimeException(sprintf('API error: %s — %s', (string)$decoded['error'], (string)$description));
        }

        return $decoded['result'] ?? null;
    }

    /* ==============================
     * PRODUCTS
     * ============================== */

    /**
     * Найти ID продукта по точному имени.
     *
     * @param string $productName Имя продукта (поле NAME)
     * @return int|null           ID продукта или null, если не найден
     */
    public function findProductIdByName(string $productName): ?int
    {
        $res = $this->sendRequest('crm.product.list.json', [
            'filter' => ['NAME' => $productName],
            'select' => ['ID', 'NAME'],
        ], 'GET');

        if (is_array($res) && isset($res[0]['ID'])) {
            return (int)$res[0]['ID'];
        }
        return null;
    }

    /**
     * Создать продукт.
     *
     * @param array<string,mixed> $productFields Поля сущности продукта
     * @return int                              ID созданного продукта
     */
    public function createProduct(array $productFields): int
    {
        $id = $this->sendRequest('crm.product.add.json', ['fields' => $productFields], 'POST');
        return (int)$id;
    }

    /* ==============================
     * LEADS
     * ============================== */

    /**
     * Создать лид.
     *
     * @param array<string,mixed> $fields Поля лида
     * @param array<string,mixed> $params Доп. параметры (например, REGISTER_SONET_EVENT)
     * @return int                        ID созданного лида
     */
    public function addLead(array $fields, array $params = []): int
    {
        $fields['ASSIGNED_BY_ID'] = (int)$this->userId;
        $id = $this->sendRequest('crm.lead.add.json', ['fields' => $fields, 'params' => $params], 'POST');
        return (int)$id;
    }

    /**
     * Задать товарные позиции для лида.
     *
     * @param int                   $leadId ID лида
     * @param array<int,array>      $rows   Массив товарных позиций (PRODUCT_ID, PRICE, QUANTITY, и т.п.)
     * @return bool                         true при успехе
     */
    public function addProductsToLead(int $leadId, array $rows): bool
    {
        $ok = $this->sendRequest('crm.lead.productrows.set.json', [
            'id'   => $leadId,
            'rows' => $rows,
        ], 'POST');
        return (bool)$ok;
    }

    /* ==============================
     * CONTACTS
     * ============================== */

    /**
     * Нормализует телефон: оставляет цифры и '+', приводит 8XXXXXXXXXX к +7XXXXXXXXXX, 7XXXXXXXXXX к +7XXXXXXXXXX.
     *
     * @param string|null $phone Исходная строка телефона
     * @return string|null        Нормализованное значение или null
     */
    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $norm = (string)preg_replace('/[^\d+]/', '', $phone);
        if (preg_match('/^8(\d{10})$/', $norm, $m)) {
            $norm = '+7' . $m[1];
        }
        if (preg_match('/^7\d{10}$/', $norm)) {
            $norm = '+' . $norm;
        }
        return $norm;
    }

    /**
     * Ищет контакт по телефону или email через crm.duplicate.findbycomm.
     * Возвращает первый найденный ID.
     *
     * @param string|null $phone Телефон (будет нормализован)
     * @param string|null $email Email
     * @return int|null          ID контакта или null
     */
    public function findContactByPhoneOrEmail(?string $phone, ?string $email): ?int
    {
        $nPhone = $this->normalizePhone($phone);
        if ($nPhone) {
            $res = $this->sendRequest('crm.duplicate.findbycomm.json', [
                'entity_type' => 'CONTACT',
                'type'        => 'PHONE',
                'values'      => [$nPhone],
            ], 'POST');
            if (!empty($res['CONTACT']) && is_array($res['CONTACT'])) {
                return (int)array_values($res['CONTACT'])[0];
            }
        }

        if ($email) {
            $res = $this->sendRequest('crm.duplicate.findbycomm.json', [
                'entity_type' => 'CONTACT',
                'type'        => 'EMAIL',
                'values'      => [$email],
            ], 'POST');
            if (!empty($res['CONTACT']) && is_array($res['CONTACT'])) {
                return (int)array_values($res['CONTACT'])[0];
            }
        }

        return null;
    }

    /**
     * Создаёт контакт (с контролем дублей). При дубле возвращает существующий ID.
     *
     * @param array<string,mixed> $fields Поля контакта
     * @param array<string,mixed> $params Доп. параметры (по умолчанию REGISTER_SONET_EVENT = 'Y')
     * @return int                        ID созданного или существующего контакта
     */
    public function addContact(array $fields, array $params = ['REGISTER_SONET_EVENT' => 'Y']): int
    {
        $params['ENABLE_DUPLICATE_CONTROL'] = 'Y';

        try {
            $id = $this->sendRequest('crm.contact.add.json', [
                'fields' => $fields,
                'params' => $params,
            ], 'POST');
            return (int)$id;
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate') === false) {
                throw $e;
            }

            $phone = $fields['PHONE'][0]['VALUE'] ?? null;
            $email = $fields['EMAIL'][0]['VALUE'] ?? null;
            $existingId = $this->findContactByPhoneOrEmail($phone, $email);
            if ($existingId) {
                return $existingId;
            }

            throw $e; // если не получилось определить дубль — пробрасываем исходную ошибку
        }
    }

    /**
     * Возвращает существующий контакт или создаёт новый.
     *
     * @param string      $name  Имя
     * @param string|null $phone Телефон
     * @param string|null $email Email
     * @return int               ID контакта
     */
    public function getOrCreateContact(string $name, ?string $phone, ?string $email): int
    {
        if ($id = $this->findContactByPhoneOrEmail($phone, $email)) {
            return $id;
        }

        $fields = ['NAME' => $name];
        if ($phone) {
            $fields['PHONE'] = [[
                'VALUE'      => $this->normalizePhone($phone),
                'VALUE_TYPE' => 'MOBILE',
            ]];
        }
        if ($email) {
            $fields['EMAIL'] = [[
                'VALUE'      => $email,
                'VALUE_TYPE' => 'WORK',
            ]];
        }

        return $this->addContact($fields);
    }

    /* ==============================
     * DEALS
     * ============================== */

    /**
     * Создаёт сделку. Если передан CONTACT_ID в $fields — будет создана связь.
     *
     * @param array<string,mixed> $fields Поля сделки
     * @param array<string,mixed> $params Доп. параметры
     * @return int                        ID сделки
     */
    public function addDeal(array $fields, array $params = []): int
    {
        $fields['ASSIGNED_BY_ID'] = (int)$this->userId;
        $id = $this->sendRequest('crm.deal.add.json', ['fields' => $fields, 'params' => $params], 'POST');
        return (int)$id;
    }

    /**
     * Задать товарные позиции для сделки.
     *
     * @param int                   $dealId ID сделки
     * @param array<int,array>      $rows   Массив товарных позиций
     * @return bool                         true при успехе
     */
    public function addProductsToDeal(int $dealId, array $rows): bool
    {
        $ok = $this->sendRequest('crm.deal.productrows.set.json', [
            'id'   => $dealId,
            'rows' => $rows,
        ], 'POST');
        return (bool)$ok;
    }

    /**
     * Удобный метод: создаёт (или находит) контакт и затем создаёт сделку, привязанную к нему.
     *
     * @param array<string,mixed> $dealFields  Поля сделки
     * @param string              $contactName Имя контакта
     * @param string|null         $phone       Телефон
     * @param string|null         $email       Email
     * @param array<string,mixed> $params      Параметры сделки
     * @return int                              ID сделки
     */
    public function createDealWithContact(
        array $dealFields,
        string $contactName,
        ?string $phone,
        ?string $email,
        array $params = []
    ): int {
        $contactId = $this->getOrCreateContact($contactName, $phone, $email);
        $dealFields['CONTACT_ID'] = $contactId;
        return $this->addDeal($dealFields, $params);
    }

    /* ==============================
     * META
     * ============================== */

    /**
     * Возвращает описание полей для сущности.
     * Поддерживаемые сущности: DEAL | LEAD | CONTACT | COMPANY.
     *
     * @param string $entity Код сущности (регистр не важен)
     * @return array<string,mixed> Карта полей сущности
     */
    public function getEntityFields(string $entity): array
    {
        $map = [
            'DEAL'    => 'crm.deal.fields.json',
            'LEAD'    => 'crm.lead.fields.json',
            'CONTACT' => 'crm.contact.fields.json',
            'COMPANY' => 'crm.company.fields.json',
        ];
        $key = strtoupper($entity);
        if (!isset($map[$key])) {
            throw new \InvalidArgumentException('Unsupported entity: ' . $entity);
        }

        $res = $this->sendRequest($map[$key], [], 'GET');
        return is_array($res) ? $res : [];
    }

    /**
     * Ищет код пользовательского поля по видимому названию (без учёта регистра).
     * Проверяет title, formLabel, name, nameCase. Возвращает, например, 'UF_CRM_1706523456'.
     *
     * @param string $entity Код сущности
     * @param string $title  Заголовок поля
     * @return string|null   Код пользовательского поля или null
     */
    public function findUserFieldCodeByTitle(string $entity, string $title): ?string
    {
        $fields = $this->getEntityFields($entity);
        $needle = mb_strtolower(trim($title));

        foreach ($fields as $code => $meta) {
            if (strpos($code, 'UF_') !== 0) {
                continue; // интересуют только пользовательские поля
            }

            $candidates = [];
            if (!empty($meta['title']))     { $candidates[] = (string)$meta['title']; }
            if (!empty($meta['formLabel'])) { $candidates[] = (string)$meta['formLabel']; }
            if (!empty($meta['name']))      { $candidates[] = (string)$meta['name']; }
            if (!empty($meta['nameCase']))  { $candidates[] = (string)$meta['nameCase']; }

            foreach ($candidates as $label) {
                if (mb_strtolower(trim($label)) === $needle) {
                    return (string)$code;
                }
            }
        }

        return null;
    }
}
