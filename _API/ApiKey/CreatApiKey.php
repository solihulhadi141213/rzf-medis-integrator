<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token");

    date_default_timezone_set('UTC');

    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    $Limiter = new RateLimiter($Conn);
    $Limiter->check("create_api_key", 5, 60);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

    $raw         = file_get_contents('php://input');
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

    $requiredFields = ['api_name', 'api_description', 'client_id', 'client_key', 'expired_duration', 'status'];
    foreach ($requiredFields as $field) {
        if (empty($requestBody[$field]) && $requestBody[$field] !== '0' && $requestBody[$field] !== 0) {
            http_response_code(422);
            echo json_encode([
                "response" => [
                    "message" => ucfirst(str_replace('_', ' ', $field)) . " tidak boleh kosong",
                    "code" => 422
                ],
                "metadata" => []
            ]);
            exit;
        }
    }

    $api_name         = validateAndSanitizeInput($requestBody['api_name']);
    $api_description  = validateAndSanitizeInput($requestBody['api_description']);
    $client_id        = validateAndSanitizeInput($requestBody['client_id']);
    $client_key       = validateAndSanitizeInput($requestBody['client_key']);
    $expired_duration = (int) $requestBody['expired_duration'];
    $status           = (int) $requestBody['status'];

    if ($expired_duration <= 0) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "expired_duration harus lebih besar dari 0",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    if (!in_array($status, [0, 1], true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "status harus bernilai 0 atau 1",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

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

        $stmt = $Conn->prepare("SELECT COUNT(*) AS total FROM api_key");
        $stmt->execute();
        $countData = $stmt->fetch();
        if ($countData && (int)$countData['total'] >= 10) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Batas maksimal 10 api_key telah tercapai",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT id_api_key FROM api_key WHERE client_id = :client_id LIMIT 1");
        $stmt->execute([':client_id' => $client_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode([
                "response" => [
                    "message" => "client_id sudah digunakan",
                    "code" => 409
                ],
                "metadata" => []
            ]);
            exit;
        }

        $hashedClientKey = password_hash($client_key, PASSWORD_DEFAULT);
        $datetimeNow = gmdate('Y-m-d H:i:s');

        $Conn->beginTransaction();

        $stmt = $Conn->prepare("INSERT INTO api_key (api_name, api_description, client_id, client_key, expired_duration, datetime_creat, datetime_update, status) VALUES (:api_name, :api_description, :client_id, :client_key, :expired_duration, :datetime_creat, :datetime_update, :status)");
        $stmt->execute([
            ':api_name' => $api_name,
            ':api_description' => $api_description,
            ':client_id' => $client_id,
            ':client_key' => $hashedClientKey,
            ':expired_duration' => $expired_duration,
            ':datetime_creat' => $datetimeNow,
            ':datetime_update' => $datetimeNow,
            ':status' => $status
        ]);

        $insertId = (int) $Conn->lastInsertId();

        if ($status === 1) {
            $stmt = $Conn->prepare("UPDATE api_key SET status = 0 WHERE id_api_key <> :id_api_key");
            $stmt->execute([':id_api_key' => $insertId]);
        }

        $Conn->commit();

        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Api key berhasil dibuat",
                "code" => 201
            ],
            "metadata" => [
                "id_api_key" => $insertId,
                "api_name" => $api_name,
                "client_id" => $client_id,
                "expired_duration" => $expired_duration,
                "status" => $status,
                "datetime_creat" => $datetimeNow,
                "datetime_update" => $datetimeNow
            ]
        ]);
    } catch (Exception $e) {
        if ($Conn->inTransaction()) {
            $Conn->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Internal Server Error",
                "code" => 500
            ],
            "metadata" => []
        ]);
    }
