<?php
final class BitrixClient
{
    private string $base;

    public function __construct()
    {
        $this->base = rtrim(Config::env("BITRIX_WEBHOOK_BASE"), "/") . "/";
    }

    /** @return array<string,mixed> */
    public function call(string $method, array $params = []): array
    {
        $url = $this->base . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false) {
            throw new RuntimeException("Bitrix curl error: {$err}");
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException("Bitrix bad json: " . $raw);
        }
        if ($code >= 400 || isset($json["error"])) {
            throw new RuntimeException(
                "Bitrix API error: " .
                    json_encode($json, JSON_UNESCAPED_UNICODE),
            );
        }
        return $json;
    }

    public function getDeal(int $dealId): array
    {
        $r = $this->call("crm.deal.get", ["id" => $dealId]);
        return $r["result"] ?? [];
    }

    public function getContact(int $contactId): array
    {
        $r = $this->call("crm.contact.get", ["id" => $contactId]);
        return $r["result"] ?? [];
    }
    public function getUser(int $userId): array
    {
        // user.get обычно возвращает массив пользователей
        $r = $this->call("user.get", [
            "FILTER" => ["ID" => $userId],
        ]);

        $list = $r["result"] ?? [];
        if (is_array($list) && isset($list[0]) && is_array($list[0])) {
            return $list[0];
        }
        return [];
    }
}
