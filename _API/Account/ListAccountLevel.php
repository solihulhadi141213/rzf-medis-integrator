<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token");

    date_default_timezone_set('UTC');

    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    $Limiter = new RateLimiter($Conn);
    $Limiter->check("list_account_level", 5, 60);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            "response" => [
                "message" => "Metode request tidak diizinkan",
                "code" => 405
            ],
            "metadata" => []
        ]);
        exit;
    }

    function getRequestHeader(string $name): string
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === strtolower($name)) return trim($value);
            }
        }
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$headerKey]) ? trim($_SERVER[$headerKey]) : '';
    }

    $apiToken = getRequestHeader('token');
    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode(["response"=>["message"=>"Token tidak ditemukan atau tidak valid","code"=>401],"metadata"=>[]]);
        exit;
    }

    try {
        $stmt = $Conn->prepare("SELECT t.*, k.client_id, k.api_name, k.id_api_key FROM api_token t JOIN api_key k ON t.id_api_key = k.id_api_key WHERE t.token = :token LIMIT 1");
        $stmt->execute([':token' => $apiToken]);
        $tokenData = $stmt->fetch();

        if (!$tokenData) {
            http_response_code(401);
            echo json_encode(["response"=>["message"=>"Token tidak valid","code"=>401],"metadata"=>[]]);
            exit;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        if ($tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode(["response"=>["message"=>"Token sudah kedaluwarsa","code"=>401],"metadata"=>[]]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT id_account_level, level_name, level_description FROM account_level ORDER BY id_account_level ASC");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "List account_level berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "total" => count($rows),
                "retrieved_at" => gmdate('Y-m-d H:i:s') . ' GMT'
            ],
            "data" => $rows
        ]);
    } catch (Exception $e) {
        error_log('[ListAccountLevel] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response"=>["message"=>"Internal Server Error","code"=>500],"metadata"=>[]]);
    }
