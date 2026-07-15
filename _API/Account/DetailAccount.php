<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token, account_token");

    // Set Time Zone
    date_default_timezone_set('UTC');

    // Include-Require Resource
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // Limiter
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("detail_account", 5, 60);

    // Validate Method
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

    $accountIdToView = isset($_GET['accountId']) ? (int) $_GET['accountId'] : 0;
    if ($accountIdToView <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "accountId tidak valid", "code" => 422], "metadata" => []]);
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
        $stmt->execute([':feature_name' => 'detail_account']);
        $feature = $stmt->fetch();
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur detail_account tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }

        $id_service_feature = $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $accountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat detail akun", "code" => 403], "metadata" => []]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT a.accountId, a.id_account_level, a.photo, a.name, a.email, a.phone, a.status, a.createdBy, a.createdDate, a.updatedBy, a.updatedDate, al.level_name
                                FROM account a
                                LEFT JOIN account_level al ON al.id_account_level = a.id_account_level
                                WHERE a.accountId = :accountId LIMIT 1");
        $stmt->execute([':accountId' => $accountIdToView]);
        $accountData = $stmt->fetch();
        if (!$accountData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Akun tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT ap.id_account_permission, ap.id_service_feature, sf.feature_name
                                FROM account_permission ap
                                LEFT JOIN service_feature sf ON sf.id_service_feature = ap.id_service_feature
                                WHERE ap.accountId = :accountId");
        $stmt->execute([':accountId' => $accountIdToView]);
        $accountPermissions = $stmt->fetchAll();

        $permissions = [];
        foreach ($accountPermissions as $permission) {
            $permissions[] = [
                "id_account_permission" => (int) $permission['id_account_permission'],
                "id_service_feature" => (int) $permission['id_service_feature'],
                "feature_name" => $permission['feature_name'] ?? null
            ];
        }

        $accountData['level_name'] = $accountData['level_name'] ?? null;
        $accountData['permissions'] = $permissions;

        unset($accountData['password']);

        http_response_code(200);
        echo json_encode([
            "response" => ["message" => "Detail akun berhasil diambil", "code" => 200],
            "metadata" => $accountData
        ]);
    } catch (Exception $e) {
        error_log('[DetailAccount] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    }
