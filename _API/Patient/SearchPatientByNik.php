<?php
    /**
     * Search Patient by NIK to SATUSEHAT
     * Endpoint: GET /_API/Patient/SearchPatientByNik.php?nik={16_digit_nik}
     * Header: token, account_token
     * 
     * Mencari data pasien di SATUSEHAT menggunakan NIK.
     * Jika ditemukan, mengembalikan data pasien dari SATUSEHAT.
     * Jika tidak ditemukan, mengembalikan response dengan data kosong.
     */

    // --- 1. Response Header ---
    header('Content-Type: application/json');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    date_default_timezone_set('UTC');

    // --- 2. Include Dependencies ---
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("search_patient_by_nik", 10, 60);

    // --- 4. Validasi Method ---
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

    // --- 5. Validasi Header Token & Account Token ---
    $apiToken     = getRequestHeader('token');
    $accountToken = getRequestHeader('account_token');

    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode([
            "response" => [
                "message" => "Token Kredensial Aplikasi Tidak Boleh Kosong",
                "code" => 401
            ],
            "metadata" => []
        ]);
        exit;
    }

    if (empty($accountToken)) {
        http_response_code(401);
        echo json_encode([
            "response" => [
                "message" => "Token Sesi Akses Tidak Boleh Kosong",
                "code" => 401
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 6. Validasi Token dan Permission ---
    $nowUtc = gmdate('Y-m-d H:i:s');
    try {
        // Validasi API Token
        $stmt = $Conn->prepare("
            SELECT t.*, k.client_id, k.api_name, k.id_api_key 
            FROM api_token t 
            JOIN api_key k ON t.id_api_key = k.id_api_key 
            WHERE t.token = :token LIMIT 1
        ");
        $stmt->execute([':token' => $apiToken]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
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

        // Validasi Account Token
        $stmt = $Conn->prepare("
            SELECT accountId 
            FROM account_token 
            WHERE account_token = :account_token 
            AND datetime_expired >= :nowUtc LIMIT 1
        ");
        $stmt->execute([
            ':account_token' => $accountToken,
            ':nowUtc' => $nowUtc
        ]);
        $accountTokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$accountTokenData) {
            http_response_code(401);
            echo json_encode([
                "response" => [
                    "message" => "account_token tidak valid",
                    "code" => 401
                ],
                "metadata" => []
            ]);
            exit;
        }

        // Validasi Permission (fitur search_patient_by_nik)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'search_patient_by_nik']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur search_patient_by_nik tidak ditemukan",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $accountTokenData['accountId'], $id_service_feature)) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Tidak memiliki izin untuk mencari pasien berdasarkan NIK",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[SearchPatientByNik] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Internal Server Error",
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 7. Validasi Parameter NIK ---
    if (!isset($_GET['nik']) || trim($_GET['nik']) === '') {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter NIK wajib diisi",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    $nik = trim($_GET['nik']);

    // Validasi NIK harus 16 digit angka
    if (!preg_match('/^\d{16}$/', $nik)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "NIK harus terdiri dari 16 digit angka",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 8. Ambil credential SATUSEHAT yang aktif ---
    try {
        $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
        $credStmt->execute();
        $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
        if (!$credential) {
            http_response_code(400);
            echo json_encode([
                "response" => [
                    "message" => "Tidak ada kredensial SATUSEHAT yang aktif",
                    "code" => 400
                ],
                "metadata" => []
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[SearchPatientByNik] Get credential error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Internal Server Error",
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 9. Dapatkan token SATUSEHAT ---
    $tokenResult = generateTokenSatusehat($Conn);
    if ($tokenResult['status'] !== 'success') {
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal mendapatkan token SATUSEHAT: " . $tokenResult['message'],
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    $accessToken = $tokenResult['token'];
    $baseUrl = rtrim($credential['baseUrl'], '/');

    // --- 10. Panggil API SATUSEHAT untuk mencari pasien berdasarkan NIK ---
    $searchUrl = $baseUrl . '/fhir-r4/v1/Patient?identifier=' . urlencode('https://fhir.kemkes.go.id/id/nik|' . $nik);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $searchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal menghubungi API SATUSEHAT: " . $curlError,
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    if ($httpCode !== 200) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "SATUSEHAT API error: " . substr($response, 0, 500),
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "JSON Error: " . json_last_error_msg(),
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 11. Parse hasil pencarian ---
    $patientData = null;
    if (isset($result['total']) && $result['total'] > 0 && isset($result['entry'][0]['resource'])) {
        $resource = $result['entry'][0]['resource'];
        // Ekstrak data yang diperlukan
        $patientData = [
            'id' => $resource['id'] ?? null,
            'name' => isset($resource['name'][0]) ? [
                'use' => $resource['name'][0]['use'] ?? null,
                'text' => $resource['name'][0]['text'] ?? null,
                'family' => $resource['name'][0]['family'] ?? null,
                'given' => $resource['name'][0]['given'] ?? []
            ] : null,
            'gender' => $resource['gender'] ?? null,
            'birthDate' => $resource['birthDate'] ?? null,
            'nik' => null,
            'phone' => null,
            'email' => null,
            'address' => null
        ];

        // Cari NIK dari identifier
        if (isset($resource['identifier']) && is_array($resource['identifier'])) {
            foreach ($resource['identifier'] as $identifier) {
                if (isset($identifier['system']) && $identifier['system'] === 'https://fhir.kemkes.go.id/id/nik') {
                    $patientData['nik'] = $identifier['value'] ?? null;
                    break;
                }
            }
        }

        // Cari telecom (phone/email)
        if (isset($resource['telecom']) && is_array($resource['telecom'])) {
            foreach ($resource['telecom'] as $telecom) {
                if (isset($telecom['system']) && $telecom['system'] === 'phone') {
                    $patientData['phone'] = $telecom['value'] ?? null;
                } elseif (isset($telecom['system']) && $telecom['system'] === 'email') {
                    $patientData['email'] = $telecom['value'] ?? null;
                }
            }
        }

        // Cari address
        if (isset($resource['address'][0])) {
            $address = $resource['address'][0];
            $addressParts = [];
            if (isset($address['line']) && is_array($address['line'])) {
                $addressParts = $address['line'];
            }
            if (isset($address['city'])) $addressParts[] = $address['city'];
            if (isset($address['state'])) $addressParts[] = $address['state'];
            if (isset($address['postalCode'])) $addressParts[] = $address['postalCode'];
            if (isset($address['country'])) $addressParts[] = $address['country'];
            $patientData['address'] = implode(', ', array_filter($addressParts));
        }
    }

    // --- 12. Response ---
    if ($patientData !== null) {
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Data pasien ditemukan di SATUSEHAT",
                "code" => 200
            ],
            "metadata" => [
                "nik" => $nik,
                "total_found" => $result['total'] ?? 0,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $patientData
        ]);
    } else {
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Data pasien dengan NIK tersebut tidak ditemukan di SATUSEHAT",
                "code" => 200
            ],
            "metadata" => [
                "nik" => $nik,
                "total_found" => 0,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => null
        ]);
    }
?>