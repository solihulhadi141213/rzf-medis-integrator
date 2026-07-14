<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: PUT");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token");

    date_default_timezone_set('UTC');

    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_api_key", 5, 60);

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    $fields = [
        'api_name',
        'api_description',
        'client_id',
        'client_key',
        'expired_duration',
        'status'
    ];

    $updateValues = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $requestBody) && $requestBody[$field] !== '') {
            if ($field === 'status') {
                $status = (int) $requestBody['status'];
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
                $updateValues['status'] = $status;
                continue;
            }

            if ($field === 'expired_duration') {
                $expired_duration = (int) $requestBody['expired_duration'];
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
                $updateValues['expired_duration'] = $expired_duration;
                continue;
            }

            if ($field === 'client_key') {
                $updateValues['client_key'] = password_hash(validateAndSanitizeInput($requestBody['client_key']), PASSWORD_DEFAULT);
                continue;
            }

            $updateValues[$field] = validateAndSanitizeInput($requestBody[$field]);
        }
    }

    if (isset($updateValues['client_id'])) {
        $stmt = $Conn->prepare("SELECT id_api_key FROM api_key WHERE client_id = :client_id AND id_api_key <> :id_api_key LIMIT 1");
        $stmt->execute([
            ':client_id' => $updateValues['client_id'],
            ':id_api_key' => $id_api_key
        ]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode([
                "response" => [
                    "message" => "client_id sudah digunakan oleh api_key lain",
                    "code" => 409
                ],
                "metadata" => []
            ]);
            exit;
        }
    }

    if (empty($updateValues)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "Tidak ada data yang diupdate",
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

        $stmt = $Conn->prepare("SELECT * FROM api_key WHERE id_api_key = :id_api_key LIMIT 1");
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

        $setClauses = [];
        $params = [':id_api_key' => $id_api_key];
        foreach ($updateValues as $field => $value) {
            $setClauses[] = "{$field} = :{$field}";
            $params[":{$field}"] = $value;
        }
        $setClauses[] = "datetime_update = :datetime_update";
        $params[':datetime_update'] = $nowUtc;

        $Conn->beginTransaction();

        $stmt = $Conn->prepare("UPDATE api_key SET " . implode(', ', $setClauses) . " WHERE id_api_key = :id_api_key");
        $stmt->execute($params);

        if (isset($updateValues['status']) && $updateValues['status'] === 1) {
            $stmt = $Conn->prepare("UPDATE api_key SET status = 0 WHERE id_api_key <> :id_api_key");
            $stmt->execute([':id_api_key' => $id_api_key]);
        }

        $Conn->commit();

        if (isset($updateValues['client_key'])) {
            unset($updateValues['client_key']);
        }

        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Api key berhasil diupdate",
                "code" => 200
            ],
            "metadata" => array_merge(['id_api_key' => $id_api_key, 'datetime_update' => $nowUtc], $updateValues)
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
