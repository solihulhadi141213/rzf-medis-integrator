<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token, account_token");

    // Set Time Zone
    date_default_timezone_set('UTC');

    // Include-Require Resource
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // Limiter
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("create_account", 5, 60);

    // Validate Method
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

    // Menangkap data dari header (token dan account_token)
    $apiToken     = getRequestHeader('token');
    $accountToken = getRequestHeader('account_token');

    // Validasi token dan account_token
    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode(["response"=>["message"=>"Token Kredensial Aplikasi Tidak Boleh Kosong","code"=>401],"metadata"=>[]]);
        exit;
    }
    if (empty($accountToken)) {
        http_response_code(401);
        echo json_encode(["response"=>["message"=>"Token Sesi Akses Tidak Boleh Kosong","code"=>401],"metadata"=>[]]);
        exit;
    }

    // Menangkap Data Body
    $raw         = file_get_contents('php://input');
    $requestBody = json_decode($raw, true);
    if (!is_array($requestBody)) {
        http_response_code(400);
        echo json_encode(["response"=>["message"=>"Format JSON tidak valid","code"=>400],"metadata"=>[]]);
        exit;
    }

    $requiredFields = ['id_account_level', 'name', 'email', 'phone', 'password'];
    foreach ($requiredFields as $field) {
        if (!isset($requestBody[$field]) || $requestBody[$field] === '') {
            http_response_code(422);
            echo json_encode(["response"=>["message"=>"{$field} tidak boleh kosong","code"=>422],"metadata"=>[]]);
            exit;
        }
    }

    // Buat variabel Dan Sanitasi
    $id_account_level = (int) $requestBody['id_account_level'];
    $photoBase64      = isset($requestBody['photo']) ? trim($requestBody['photo']) : '';
    $name             = validateAndSanitizeInput($requestBody['name']);
    $email            = validateAndSanitizeInput($requestBody['email']);
    $phone            = validateAndSanitizeInput($requestBody['phone']);
    $password         = $requestBody['password'];
    $status           = isset($requestBody['status']) ? (int) $requestBody['status'] : 1;

    if (strlen($name) > 200) {
        http_response_code(422);
        echo json_encode(["response"=>["message"=>"name maksimal 200 karakter","code"=>422],"metadata"=>[]]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(["response"=>["message"=>"Format email tidak valid","code"=>422],"metadata"=>[]]);
        exit;
    }

    if (!preg_match('/^[0-9]+$/', $phone)) {
        http_response_code(422);
        echo json_encode(["response"=>["message"=>"phone hanya boleh angka","code"=>422],"metadata"=>[]]);
        exit;
    }

    if (!in_array($status, [0,1], true)) {
        $status = 1;
    }

    if ($id_account_level <= 0) {
        http_response_code(422);
        echo json_encode(["response"=>["message"=>"id_account_level tidak valid","code"=>422],"metadata"=>[]]);
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

        $stmt = $Conn->prepare("SELECT accountId FROM account_token WHERE account_token = :account_token LIMIT 1");
        $stmt->execute([':account_token' => $accountToken]);
        $accountTokenData = $stmt->fetch();
        if (!$accountTokenData) {
            http_response_code(401);
            echo json_encode(["response"=>["message"=>"account_token tidak valid","code"=>401],"metadata"=>[]]);
            exit;
        }

        $accountId = (int) $accountTokenData['accountId'];
        if ($accountId <= 0) {
            http_response_code(401);
            echo json_encode(["response"=>["message"=>"account_token tidak valid","code"=>401],"metadata"=>[]]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'creat_account']);
        $feature = $stmt->fetch();
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response"=>["message"=>"Fitur creat_account tidak ditemukan","code"=>403],"metadata"=>[]]);
            exit;
        }

        $id_service_feature = $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $accountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response"=>["message"=>"Tidak memiliki izin untuk membuat akun","code"=>403],"metadata"=>[]]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT accountId FROM account WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(["response"=>["message"=>"Email sudah terdaftar","code"=>409],"metadata"=>[]]);
            exit;
        }

        $stmt = $Conn->prepare("SELECT id_account_level FROM account_level WHERE id_account_level = :id_account_level LIMIT 1");
        $stmt->execute([':id_account_level' => $id_account_level]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response"=>["message"=>"id_account_level tidak ditemukan","code"=>422],"metadata"=>[]]);
            exit;
        }

        $photoName = null;
        if ($photoBase64 !== '') {
            if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|bmp|webp);base64,/', $photoBase64, $matches)) {
                http_response_code(422);
                echo json_encode(["response"=>["message"=>"Format photo base64 tidak valid","code"=>422],"metadata"=>[]]);
                exit;
            }

            $mimeType = strtolower($matches[1]);
            $allowedTypes = ['png','jpeg','jpg','gif','bmp','webp'];
            if (!in_array($mimeType, $allowedTypes, true)) {
                http_response_code(422);
                echo json_encode(["response"=>["message"=>"Tipe photo tidak diperbolehkan","code"=>422],"metadata"=>[]]);
                exit;
            }

            $base64Data = substr($photoBase64, strpos($photoBase64, ',') + 1);
            $photoData = base64_decode($base64Data);
            if ($photoData === false) {
                http_response_code(422);
                echo json_encode(["response"=>["message"=>"Photo base64 tidak valid","code"=>422],"metadata"=>[]]);
                exit;
            }

            $photoName = bin2hex(random_bytes(16)) . '.' . ($mimeType === 'jpeg' ? 'jpg' : $mimeType);
            $photoDir = __DIR__ . '/../../Storage/Img/Account';
            if (!is_dir($photoDir)) {
                mkdir($photoDir, 0755, true);
            }
            $photoPath = $photoDir . '/' . $photoName;
            if (file_put_contents($photoPath, $photoData) === false) {
                http_response_code(500);
                echo json_encode(["response"=>["message"=>"Gagal menyimpan file photo","code"=>500],"metadata"=>[]]);
                exit;
            }
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $datetimeNow = gmdate('Y-m-d H:i:s');

        $stmt = $Conn->prepare("INSERT INTO account (id_account_level, photo, name, email, phone, password, status, createdBy, createdDate, updatedBy, updatedDate) VALUES (:id_account_level, :photo, :name, :email, :phone, :password, :status, :createdBy, :createdDate, :updatedBy, :updatedDate)");
        $stmt->execute([
            ':id_account_level' => $id_account_level,
            ':photo' => $photoName,
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':password' => $passwordHash,
            ':status' => $status,
            ':createdBy' => $accountId,
            ':createdDate' => $datetimeNow,
            ':updatedBy' => $accountId,
            ':updatedDate' => $datetimeNow
        ]);

        $newAccountId = (int) $Conn->lastInsertId();

        http_response_code(201);
        echo json_encode(["response"=>["message"=>"Account berhasil dibuat","code"=>201],"metadata"=>["accountId" => $newAccountId, "createdDate" => $datetimeNow]]);
    } catch (Exception $e) {
        error_log('[CreatAccount] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response"=>["message"=>"Internal Server Error","code"=>500],"metadata"=>[]]);
    }
