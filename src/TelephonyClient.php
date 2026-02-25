<?php
final class TelephonyClient
{
    private string $base;
    private string $key;

    public function __construct()
    {
        $this->base = rtrim(Config::env("TEL_BASE"), "/");
        $this->key = Config::env("TEL_API_KEY");
    }

    /** @return array<string,mixed> */
    private function req(
        string $method,
        string $path,
        ?array $jsonBody = null,
    ): array {
        $url = $this->base . $path;
        $ch = curl_init($url);

        $headers = [
            "X-API-KEY: " . $this->key,
            "Content-Type: application/json",
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
        ];

        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode(
                $jsonBody,
                JSON_UNESCAPED_UNICODE,
            );
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false) {
            throw new RuntimeException("Telephony curl error: {$err}");
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            // некоторые реализации возвращают пустое тело при 204/200
            $json = ["raw" => $raw];
        }
        if ($code >= 400 || isset($json["error"])) {
            throw new RuntimeException(
                "Telephony API error: HTTP {$code} " .
                    json_encode($json, JSON_UNESCAPED_UNICODE),
            );
        }
        return $json;
    }

    /**
     * Initiate call: POST /crmapi/v1/makecall {user, phone}
     * Совместимый контракт показан в публичной спецификации аналогичного CRM API.  [oai_citation:2‡prostiezvonki.ru](https://prostiezvonki.ru/kb/crm-developers-instruction)
     */
    public function makeCall(string $userExt, string $phoneDigits): array
    {
        echo "makeCall: Operator($userExt) Client ($phoneDigits)" . PHP_EOL;
        return $this->req("POST", "/crmapi/v1/makecall", [
            "user" => $userExt,
            "phone" => $phoneDigits,
        ]);
    }

    /**
     * History: GET /crmapi/v1/history/json with JSON body {start,end,type,pageSize,pageNumber}
     * Формат параметров/полей показан в спецификации совместимого API.  [oai_citation:3‡prostiezvonki.ru](https://prostiezvonki.ru/kb/crm-developers-instruction)
     */
    public function history(
        string $startUtc,
        string $endUtc,
        string $client,
        string $type = "all",
        int $pageSize = 200,
        int $pageNumber = 1,
    ): array {
        return $this->req("GET", "/crmapi/v1/history/json", [
            "start" => $startUtc,
            "end" => $endUtc,
            "type" => $type,
            "pageSize" => $pageSize,
            "pageNumber" => $pageNumber,
            "client" => $client,
        ]);
    }
}
