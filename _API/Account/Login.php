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

    // Timezone
    date_default_timezone_set('UTC');

    // Config dan Helper
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";

    // Ratelimit
    require "../../_Config/RateLimiter.php";

    $Limiter = new RateLimiter($Conn);

    // Maksimal 5 request setiap 60 detik
    $Limiter->check("account_login",5,60);

    // Validasi Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            "response"=>[
                "message"=>"Metode request tidak diizinkan",
                "code"=>405
            ],
            "metadata"=>[]
        ]);
        exit;
    }


    // Ambil token API dari header
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

    // Ambil Body JSON
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

    // Validasi mandatory
    if (empty($requestBody['email'])) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "Email tidak boleh kosong",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    if (empty($requestBody['password'])) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "Password tidak boleh kosong",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    $email    = validateAndSanitizeInput($requestBody['email']);
    $password = validateAndSanitizeInput($requestBody['password']);

    try {
        // Validasi token API
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

        // Validasi akun
        $stmt = $Conn->prepare("SELECT * FROM account WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $account = $stmt->fetch();

        if (!$account) {
            http_response_code(401);
            echo json_encode([
                "response" => [
                    "message" => "Email atau password tidak valid",
                    "code" => 401
                ],
                "metadata" => []
            ]);
            exit;
        }

        if (!password_verify($password, $account['password'])) {
            http_response_code(401);
            echo json_encode([
                "response" => [
                    "message" => "Email atau password tidak valid",
                    "code" => 401
                ],
                "metadata" => []
            ]);
            exit;
        }

        if ($account['status'] !== '1') {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Akun tidak aktif atau tidak memiliki izin login",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

        $accountToken = bin2hex(random_bytes(32));
        $datetimeCreat = gmdate('Y-m-d H:i:s');
        $datetimeExpired = gmdate('Y-m-d H:i:s', time() + 86400);
        $datetimeExpiredLabel = $datetimeExpired . ' GMT';

        $Conn->beginTransaction();

        // Hapus token lama untuk akun ini agar hanya satu token aktif
        $stmt = $Conn->prepare("DELETE FROM account_token WHERE accountId = :accountId");
        $stmt->execute([':accountId' => $account['accountId']]);

        $stmt = $Conn->prepare("INSERT INTO account_token (accountId, account_token, datetime_creat, datetime_expired) VALUES (:accountId, :account_token, :datetime_creat, :datetime_expired)");
        $stmt->execute([
            ':accountId' => $account['accountId'],
            ':account_token' => $accountToken,
            ':datetime_creat' => $datetimeCreat,
            ':datetime_expired' => $datetimeExpired
        ]);

        $Conn->commit();

        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Login Berhasil",
                "code" => 200
            ],
            "metadata" => [
                "id_api_key" => (int) $tokenData['id_api_key'],
                "client_id" => $tokenData['client_id'],
                "api_name" => $tokenData['api_name'],
                "accountId" => (int) $account['accountId'],
                "id_account_level" => isset($account['id_account_level']) ? (int) $account['id_account_level'] : null,
                "photo" => $account['photo'],
                "name" => $account['name'],
                "email" => $account['email'],
                "phone" => $account['phone'],
                "status" => $account['status'],
                "account_token" => $accountToken,
                "expired_at" => $datetimeExpiredLabel
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

    