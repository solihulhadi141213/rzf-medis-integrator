<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: DELETE");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token");

    date_default_timezone_set('UTC');

    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    $Limiter = new RateLimiter($Conn);
    $Limiter->check("delete_api_key", 5, 60);

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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
                if (strtolower($key) === strtolower($name)) {
                    return trim($value);
                }
            }
        }

        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$headerKey]) ? trim($_SERVER[$headerKey]) : '';
    }

    $apiToken = getRequestHeader('token');
    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode([
            "response" => [
                "message" => "Token tidak ditemukan atau tidak valid",
                "code" => 401
            ],
            "metadata" => []
        ]);
        exit;
    }

    $raw = file_get_contents('php://input');
    $requestBody = json_decode($raw, true);

    if (!is_array($requestBody)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Format JSON tidak valid",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    if (empty($requestBody['id_api_key'])) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "id_api_key tidak boleh kosong",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    $id_api_key = (int) $requestBody['id_api_key'];

    try {
        $stmt = $Conn->prepare("SELECT t.*, k.client_id, k.api_name, k.id_api_key FROM api_token t JOIN api_key k ON t.id_api_key = k.id_api_key WHERE t.token = :token LIMIT 1");
        $stmt->execute([':token' => $apiToken]);
        $tokenData = $stmt->fetch();

        if (!$tokenData) {
            http_response_code(401);
            echo json_encode([
                "response" => [
                    "message" => "Token tidak valid",
                    "code" => 401
                ],
                "metadata" => []
            ]);
            exit;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        if ($tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode([
                "response" => [
                    "message" => "Token sudah kedaluwarsa",
                    "code" => 401
                ],
                "metadata" => []
            ]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT id_api_key, client_id, api_name, status FROM api_key WHERE id_api_key = :id_api_key LIMIT 1");
        $stmt->execute([':id_api_key' => $id_api_key]);
        $existing = $stmt->fetch();

        if (!$existing) {
            http_response_code(404);
            echo json_encode([
                "response" => [
                    "message" => "Api key tidak ditemukan",
                    "code" => 404
                ],
                "metadata" => []
            ]);
            exit;
        }

        if ((int) $existing['status'] !== 1) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Hanya api key dengan status 1 yang dapat dihapus",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

        $stmt = $Conn->prepare("DELETE FROM api_key WHERE id_api_key = :id_api_key");
        $stmt->execute([':id_api_key' => $id_api_key]);

        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Api key berhasil dihapus",
                "code" => 200
            ],
            "metadata" => [
                "id_api_key" => $id_api_key,
                "client_id" => $existing['client_id'],
                "api_name" => $existing['api_name']
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Internal Server Error",
                "code" => 500
            ],
            "metadata" => []
        ]);
    }
