<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: PUT");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token, account_token");

    // Set Time Zone
    date_default_timezone_set('UTC');

    // Include-Require Resource
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // Limiter
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_account_password", 5, 60);

    // Validate Method
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

    // Menangkap data dari header (token dan account_token)
    $apiToken     = getRequestHeader('token');
    $accountToken = getRequestHeader('account_token');

    // Validasi token dan account_token
    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode(["response" => ["message" => "Token Kredensial Aplikasi Tidak Boleh Kosong", "code" => 401], "metadata" => []]);
        exit;
    }
    if (empty($accountToken)) {
        http_response_code(401);
        echo json_encode(["response" => ["message" => "Token Sesi Akses Tidak Boleh Kosong", "code" => 401], "metadata" => []]);
        exit;
    }

    // Menangkap Data Body
    $raw         = file_get_contents('php://input');
    $requestBody = json_decode($raw, true);
    if (!is_array($requestBody)) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Format JSON tidak valid", "code" => 400], "metadata" => []]);
        exit;
    }

    $requiredFields = ['accountId', 'newPassword', 'repeatPassword'];
    foreach ($requiredFields as $field) {
        if (!isset($requestBody[$field]) || $requestBody[$field] === '') {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "{$field} tidak boleh kosong", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    $accountIdToUpdate = (int) $requestBody['accountId'];
    $newPassword = trim($requestBody['newPassword']);
    $repeatPassword = trim($requestBody['repeatPassword']);

    if ($accountIdToUpdate <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "accountId tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }

    if ($newPassword !== $repeatPassword) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "newPassword dan repeatPassword harus sama", "code" => 422], "metadata" => []]);
        exit;
    }

    if (strlen($newPassword) < 5 || strlen($newPassword) > 20) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "Password minimal 5 karakter dan maksimal 20 karakter", "code" => 422], "metadata" => []]);
        exit;
    }

    if (!preg_match('/^[A-Za-z0-9]+$/', $newPassword)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "Password hanya boleh huruf atau angka", "code" => 422], "metadata" => []]);
        exit;
    }

    try {
        $stmt = $Conn->prepare("SELECT t.*, k.client_id, k.api_name, k.id_api_key FROM api_token t JOIN api_key k ON t.id_api_key = k.id_api_key WHERE t.token = :token LIMIT 1");
        $stmt->execute([':token' => $apiToken]);
        $tokenData = $stmt->fetch();
        if (!$tokenData) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "Token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        if ($tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "Token sudah kedaluwarsa", "code" => 401], "metadata" => []]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT accountId FROM account_token WHERE account_token = :account_token LIMIT 1");
        $stmt->execute([':account_token' => $accountToken]);
        $accountTokenData = $stmt->fetch();
        if (!$accountTokenData) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }

        $accountId = (int) $accountTokenData['accountId'];
        if ($accountId <= 0) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'update_account_password']);
        $feature = $stmt->fetch();
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur update_account_password tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }

        $id_service_feature = $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $accountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah password akun", "code" => 403], "metadata" => []]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT accountId FROM account WHERE accountId = :accountId LIMIT 1");
        $stmt->execute([':accountId' => $accountIdToUpdate]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Akun tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $datetimeNow = gmdate('Y-m-d H:i:s');

        $stmt = $Conn->prepare("UPDATE account SET password = :password, updatedBy = :updatedBy, updatedDate = :updatedDate WHERE accountId = :accountId");
        $stmt->execute([
            ':password' => $passwordHash,
            ':updatedBy' => $accountId,
            ':updatedDate' => $datetimeNow,
            ':accountId' => $accountIdToUpdate
        ]);

        http_response_code(200);
        echo json_encode([
            "response" => ["message" => "Password akun berhasil diperbarui", "code" => 200],
            "metadata" => ["accountId" => $accountIdToUpdate, "updatedDate" => $datetimeNow]
        ]);
    } catch (Exception $e) {
        error_log('[UpdateAccountPassword] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    }
