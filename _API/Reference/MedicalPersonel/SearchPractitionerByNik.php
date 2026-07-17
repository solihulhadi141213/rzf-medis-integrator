<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    // Set Time Zone
    date_default_timezone_set('UTC');

    // Include-Require Resource
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // Limiter
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("search_practitioner", 5, 60);

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

    // Header token dan account_token
    $apiToken     = getRequestHeader('token');
    $accountToken = getRequestHeader('account_token');
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

    // Tangkap NIK
    if (empty($_GET['NIK'])) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "NIK tidak boleh kosong", "code" => 422], "metadata" => []]);
        exit;
    }

    // Sanitasi NIK
    $NIK = validateAndSanitizeInput($_GET['NIK']);
    if (!preg_match('/^[0-9]{16}$/', $NIK)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "Format NIK tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }

    try {
        // Validasi token
        $stmt = $Conn->prepare("SELECT t.*, k.client_id, k.api_name, k.id_api_key FROM api_token t JOIN api_key k ON t.id_api_key = k.id_api_key WHERE t.token = :token LIMIT 1");
        $stmt->execute([':token' => $apiToken]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

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

        // Validasi Account Token
        $stmt = $Conn->prepare("SELECT accountId FROM account_token WHERE account_token = :account_token AND datetime_expired >= :nowUtc LIMIT 1");
        $stmt->execute([
            ':account_token' => $accountToken,
            ':nowUtc' => $nowUtc
        ]);
        $accountTokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$accountTokenData) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }

        // Generate Token Satusehat
        $tokenResult = generateTokenSatusehat($Conn);
        if (($tokenResult['status'] ?? 'error') !== 'success') {
            http_response_code(502);
            echo json_encode([
                "response" => [
                    "message" => $tokenResult['message'] ?? 'Gagal membuat token SATUSEHAT',
                    "code" => 502
                ],
                "metadata" => []
            ]);
            exit;
        }

        // Ambil Base URL satusehat dari database pengaturan yang aktif
        $credentialStmt = $Conn->prepare("SELECT credentialId, credentialName, baseUrl, organizationId, token FROM satusehat WHERE status = 1 LIMIT 1");
        $credentialStmt->execute();
        $credential = $credentialStmt->fetch(PDO::FETCH_ASSOC);

        if (!$credential || empty($credential['baseUrl']) || empty($credential['token'])) {
            http_response_code(502);
            echo json_encode([
                "response" => [
                    "message" => "Kredensial SATUSEHAT aktif tidak lengkap",
                    "code" => 502
                ],
                "metadata" => []
            ]);
            exit;
        }

        $baseUrl        = rtrim(trim((string) $credential['baseUrl']), '/');
        $accessToken    = $credential['token'];
        $organizationId = trim((string) ($credential['organizationId'] ?? ''));
        $searchUrl      = $baseUrl.'/fhir-r4/v1/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|'.$NIK;

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/fhir+json'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $searchUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        echo $response;
        echo $searchUrl;
    } catch (Exception $e) {
        error_log('[SearchPractitionerByNik] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Internal Server Error",
                "code" => 500
            ],
            "metadata" => []
        ]);
    }
?>