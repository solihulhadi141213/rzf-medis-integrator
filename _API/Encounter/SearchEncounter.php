<?php
    /**
     * Search Encounter by SATUSEHAT Code (IHS)
     * Endpoint: GET /_API/Encounter/SearchEncounter.php?satuSehatCode={id}
     * Header: token, account_token
     * 
     * Mencari data encounter di SATUSEHAT menggunakan satuSehatCode (ID Encounter).
     * Jika ditemukan, mengembalikan data encounter dari SATUSEHAT.
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
    $Limiter->check("search_encounter", 10, 60);

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

        // Validasi Permission (fitur search_encounter)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'search_encounter']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur search_encounter tidak ditemukan",
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
                    "message" => "Tidak memiliki izin untuk mencari encounter berdasarkan SATUSEHAT Code",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[SearchEncounter] DB/Permission error: ' . $e->getMessage());
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

    // --- 7. Validasi Parameter satuSehatCode ---
    if (!isset($_GET['satuSehatCode']) || trim($_GET['satuSehatCode']) === '') {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter satuSehatCode wajib diisi",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    $satuSehatCode = trim($_GET['satuSehatCode']);

    // Validasi IHS (biasanya UUID atau alfanumerik)
    if (!preg_match('/^[a-zA-Z0-9\-]+$/', $satuSehatCode)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "satuSehatCode hanya boleh berisi huruf, angka, dan tanda hubung",
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
        error_log('[SearchEncounter] Get credential error: ' . $e->getMessage());
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

    // --- 10. Panggil API SATUSEHAT untuk mendapatkan Encounter berdasarkan ID ---
    $url = $baseUrl . '/fhir-r4/v1/Encounter/' . $satuSehatCode;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
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

    // --- 11. Cek status code ---
    if ($httpCode === 404) {
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Data encounter dengan SATUSEHAT Code tersebut tidak ditemukan di SATUSEHAT",
                "code" => 200
            ],
            "metadata" => [
                "satuSehatCode" => $satuSehatCode,
                "total_found" => 0,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => null
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

    // --- 12. Parse hasil (langsung resource Encounter) ---
    $resource = $result;

    // Ekstrak data penting
    $encounterData = [
        'id' => $resource['id'] ?? null,
        'status' => $resource['status'] ?? null,
        'class' => isset($resource['class']) ? [
            'system' => $resource['class']['system'] ?? null,
            'code' => $resource['class']['code'] ?? null,
            'display' => $resource['class']['display'] ?? null
        ] : null,
        'subject' => isset($resource['subject']['reference']) ? $resource['subject']['reference'] : null,
        'period' => isset($resource['period']) ? [
            'start' => $resource['period']['start'] ?? null,
            'end' => $resource['period']['end'] ?? null
        ] : null,
        'participant' => [],
        'reasonCode' => isset($resource['reasonCode']) ? array_column($resource['reasonCode'], 'text') : [],
        'serviceProvider' => isset($resource['serviceProvider']['reference']) ? $resource['serviceProvider']['reference'] : null,
        'location' => [],
        'statusHistory' => isset($resource['statusHistory']) ? $resource['statusHistory'] : []
    ];

    // Ekstrak participant
    if (isset($resource['participant']) && is_array($resource['participant'])) {
        foreach ($resource['participant'] as $part) {
            $type = isset($part['type'][0]['coding'][0]['code']) ? $part['type'][0]['coding'][0]['code'] : null;
            $reference = isset($part['individual']['reference']) ? $part['individual']['reference'] : null;
            if ($reference) {
                $encounterData['participant'][] = [
                    'type' => $type,
                    'reference' => $reference
                ];
            }
        }
    }

    // Ekstrak location
    if (isset($resource['location']) && is_array($resource['location'])) {
        foreach ($resource['location'] as $loc) {
            $ref = isset($loc['location']['reference']) ? $loc['location']['reference'] : null;
            $status = isset($loc['status']) ? $loc['status'] : null;
            if ($ref) {
                $encounterData['location'][] = [
                    'reference' => $ref,
                    'status' => $status
                ];
            }
        }
    }

    // --- 13. Response Sukses ---
    http_response_code(200);
    echo json_encode([
        "response" => [
            "message" => "Data encounter ditemukan di SATUSEHAT",
            "code" => 200
        ],
        "metadata" => [
            "satuSehatCode" => $satuSehatCode,
            "total_found" => 1,
            "retrieved_at" => $nowUtc . ' GMT'
        ],
        "data" => $encounterData
    ]);
?>