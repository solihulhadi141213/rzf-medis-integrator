<?php
    /**
     * Create Care Plan
     * Endpoint: POST /_API/CarePlan/CreateCarePlan.php
     * Header: token, account_token
     * Body: JSON {
     *     "encounterId": 22,
     *     "medicalPersonelId": 5,
     *     "carePlanTitle": "Rencana Perawatan Pasien",
     *     "carePlanStatus": "active",
     *     "carePlanIntent": "plan",
     *     "carePlanCategoryName": "Perencanaan Pasien Rawat Jalan",
     *     "carePlanCategoryCode": "736271009",
     *     "carePlanCategoryDisplay": "Outpatient care plan",
     *     "carePlanCategorySystem": "http://snomed.info/sct",
     *     "carePlaneDescription": "Rencana kontrol 7 hari, edukasi diet rendah garam, dan terapi antihipertensi"
     * }
     *
     * - Validasi mandatory.
     * - Validasi encounterId dan medicalPersonelId.
     * - Validasi carePlanStatus dan carePlanIntent sesuai enum.
     * - Mencari patientId dari encounter.
     * - Insert ke care_plan dengan satuSehatCode NULL.
     * - Sinkronisasi ke SATUSEHAT jika syarat terpenuhi.
     * - Update satuSehatCode setelah sync berhasil.
     */

    // --- 1. Response Header ---
    header('Content-Type: application/json');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    date_default_timezone_set('UTC');

    // --- 2. Include Dependencies ---
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("create_care_plan", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["response" => ["message" => "Metode request tidak diizinkan", "code" => 405], "metadata" => []]);
        exit;
    }

    // --- 5. Validasi Header ---
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

    // --- 6. Validasi Token & Permission ---
    $nowUtc = gmdate('Y-m-d H:i:s');
    try {
        // API Token
        $stmt = $Conn->prepare("
            SELECT t.*, k.client_id, k.api_name, k.id_api_key 
            FROM api_token t 
            JOIN api_key k ON t.id_api_key = k.id_api_key 
            WHERE t.token = :token LIMIT 1
        ");
        $stmt->execute([':token' => $apiToken]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tokenData || $tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "Token tidak valid / kadaluarsa", "code" => 401], "metadata" => []]);
            exit;
        }

        // Account Token
        $stmt = $Conn->prepare("
            SELECT accountId 
            FROM account_token 
            WHERE account_token = :account_token 
            AND datetime_expired >= :nowUtc LIMIT 1
        ");
        $stmt->execute([':account_token' => $accountToken, ':nowUtc' => $nowUtc]);
        $accountTokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$accountTokenData) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }
        $loggedInAccountId = (int) $accountTokenData['accountId'];

        // Permission
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_care_plan' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah Care Plan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateCarePlan] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Parse JSON Body ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Invalid JSON payload", "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil nilai dari body ---
    $encounterId = isset($input['encounterId']) ? (int) $input['encounterId'] : 0;
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : 0;
    $carePlanTitle = isset($input['carePlanTitle']) ? trim($input['carePlanTitle']) : '';
    $carePlanStatus = isset($input['carePlanStatus']) ? trim($input['carePlanStatus']) : '';
    $carePlanIntent = isset($input['carePlanIntent']) ? trim($input['carePlanIntent']) : '';
    $carePlanCategoryName = isset($input['carePlanCategoryName']) ? trim($input['carePlanCategoryName']) : null;
    $carePlanCategoryCode = isset($input['carePlanCategoryCode']) ? trim($input['carePlanCategoryCode']) : null;
    $carePlanCategoryDisplay = isset($input['carePlanCategoryDisplay']) ? trim($input['carePlanCategoryDisplay']) : null;
    $carePlanCategorySystem = isset($input['carePlanCategorySystem']) ? trim($input['carePlanCategorySystem']) : null;
    $carePlaneDescription = isset($input['carePlaneDescription']) ? trim($input['carePlaneDescription']) : null;

    // --- 9. Validasi Field Wajib ---
    $required = ['encounterId', 'medicalPersonelId', 'carePlanTitle', 'carePlanStatus', 'carePlanIntent'];
    foreach ($required as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 10. Validasi encounterId dan ambil patientId ---
    $patientId = null;
    $encounterSatuSehat = null;
    try {
        $stmt = $Conn->prepare("SELECT patientId, satuSehatCode FROM encounter WHERE encounterId = :id LIMIT 1");
        $stmt->execute([':id' => $encounterId]);
        $enc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enc) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "encounterId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
        $patientId = $enc['patientId'];
        $encounterSatuSehat = $enc['satuSehatCode'];
    } catch (PDOException $e) {
        error_log('[CreateCarePlan] Check encounterId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi medicalPersonelId ---
    $practitionerSatuSehat = null;
    try {
        $stmt = $Conn->prepare("SELECT id_practitioner FROM medical_personel WHERE medicalPersonelId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        $mp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mp) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
        $practitionerSatuSehat = $mp['id_practitioner'];
    } catch (PDOException $e) {
        error_log('[CreateCarePlan] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi carePlanStatus ---
    $allowedStatus = ['draft', 'active', 'on-hold', 'revoked', 'completed', 'entered-in-error', 'unknown'];
    if (!in_array($carePlanStatus, $allowedStatus, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "carePlanStatus harus salah satu dari: " . implode(', ', $allowedStatus),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 13. Validasi carePlanIntent ---
    $allowedIntent = ['proposal', 'plan', 'order', 'option'];
    if (!in_array($carePlanIntent, $allowedIntent, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "carePlanIntent harus salah satu dari: " . implode(', ', $allowedIntent),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 14. Ambil data patient untuk satuSehatCode ---
    $patientSatuSehat = null;
    try {
        $stmt = $Conn->prepare("SELECT satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        $pat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pat) {
            $patientSatuSehat = $pat['satuSehatCode'];
        }
    } catch (PDOException $e) {
        error_log('[CreateCarePlan] Get patient satuSehatCode error: ' . $e->getMessage());
    }

    // --- 15. Insert data ke care_plan (satuSehatCode NULL sementara) ---
    $carePlanId = null;
    $createdDate = $nowUtc;
    try {
        $sql = "INSERT INTO care_plan (
                    patientId,
                    encounterId,
                    medicalPersonelId,
                    satuSehatCode,
                    carePlanTitle,
                    carePlanStatus,
                    carePlanIntent,
                    carePlanCategoryName,
                    carePlanCategoryCode,
                    carePlanCategoryDisplay,
                    carePlanCategorySystem,
                    carePlaneDescription,
                    creatAt,
                    updateAt,
                    creatBy,
                    updateBy
                ) VALUES (
                    :patientId,
                    :encounterId,
                    :medicalPersonelId,
                    :satuSehatCode,
                    :carePlanTitle,
                    :carePlanStatus,
                    :carePlanIntent,
                    :carePlanCategoryName,
                    :carePlanCategoryCode,
                    :carePlanCategoryDisplay,
                    :carePlanCategorySystem,
                    :carePlaneDescription,
                    :creatAt,
                    :updateAt,
                    :creatBy,
                    :updateBy
                )";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':patientId' => $patientId,
            ':encounterId' => $encounterId,
            ':medicalPersonelId' => $medicalPersonelId,
            ':satuSehatCode' => null,
            ':carePlanTitle' => $carePlanTitle,
            ':carePlanStatus' => $carePlanStatus,
            ':carePlanIntent' => $carePlanIntent,
            ':carePlanCategoryName' => $carePlanCategoryName,
            ':carePlanCategoryCode' => $carePlanCategoryCode,
            ':carePlanCategoryDisplay' => $carePlanCategoryDisplay,
            ':carePlanCategorySystem' => $carePlanCategorySystem,
            ':carePlaneDescription' => $carePlaneDescription,
            ':creatAt' => $createdDate,
            ':updateAt' => $createdDate,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);
        $carePlanId = (int) $Conn->lastInsertId();
    } catch (PDOException $e) {
        error_log('[CreateCarePlan] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 16. Sinkronisasi ke SATUSEHAT ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
    $satuSehatCode = null;

    // Syarat: patient, encounter, practitioner harus punya satuSehatCode
    $canSync = !empty($patientSatuSehat) && !empty($encounterSatuSehat) && !empty($practitionerSatuSehat);
    if ($canSync) {
        try {
            $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
            $credStmt->execute();
            $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
            if ($credential) {
                $tokenResult = generateTokenSatusehat($Conn);
                if ($tokenResult['status'] === 'success') {
                    $accessToken = $tokenResult['token'];
                    $baseUrl = rtrim($credential['baseUrl'], '/');

                    // Siapkan payload
                    $payload = [
                        'resourceType' => 'CarePlan',
                        'title' => $carePlanTitle,
                        'status' => $carePlanStatus,
                        'intent' => $carePlanIntent,
                        'subject' => [
                            'reference' => 'Patient/' . $patientSatuSehat
                        ],
                        'encounter' => [
                            'reference' => 'Encounter/' . $encounterSatuSehat
                        ],
                        'author' => [
                            'reference' => 'Practitioner/' . $practitionerSatuSehat
                        ],
                        'created' => gmdate('Y-m-d\TH:i:s\Z', strtotime($createdDate))
                    ];

                    // Tambahkan category jika ada
                    if (!empty($carePlanCategoryCode) && !empty($carePlanCategoryDisplay) && !empty($carePlanCategorySystem)) {
                        $payload['category'] = [
                            [
                                'coding' => [
                                    [
                                        'system' => $carePlanCategorySystem,
                                        'code' => $carePlanCategoryCode,
                                        'display' => $carePlanCategoryDisplay
                                    ]
                                ]
                            ]
                        ];
                    }

                    // Tambahkan description jika ada
                    if (!empty($carePlaneDescription)) {
                        $payload['description'] = $carePlaneDescription;
                    }

                    $satusehatSyncStatus = 'failed';
                    $satusehatMessage = 'Gagal mengirim ke SATUSEHAT';

                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $baseUrl . '/fhir-r4/v1/CarePlan',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($payload),
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
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
                        $satusehatMessage = 'Curl error: ' . $curlError;
                    } elseif ($httpCode === 201 || $httpCode === 200) {
                        $result = json_decode($response, true);
                        if (isset($result['id'])) {
                            $satuSehatCode = $result['id'];
                            // Update care_plan dengan satuSehatCode
                            $updStmt = $Conn->prepare("UPDATE care_plan SET satuSehatCode = :code WHERE carePlanId = :id");
                            $updStmt->execute([':code' => $satuSehatCode, ':id' => $carePlanId]);
                            $satusehatSyncStatus = 'success';
                            $satusehatMessage = 'Berhasil disinkronkan ke SATUSEHAT';
                        } else {
                            $satusehatMessage = 'Respons SATUSEHAT tidak mengandung ID';
                        }
                    } else {
                        $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 200);
                    }
                } else {
                    $satusehatMessage = 'Token SATUSEHAT error: ' . $tokenResult['message'];
                }
            } else {
                $satusehatMessage = 'Tidak ada kredensial SATUSEHAT aktif';
            }
        } catch (Exception $e) {
            $satusehatMessage = 'Exception: ' . $e->getMessage();
            error_log('[CreateCarePlan] SATUSEHAT integration error: ' . $e->getMessage());
            $satusehatSyncStatus = 'failed';
        }
    } else {
        $satusehatMessage = 'Syarat sinkronisasi tidak terpenuhi (patient, encounter, atau practitioner tidak memiliki kode SATUSEHAT)';
        $satusehatSyncStatus = 'skipped';
    }

    // --- 17. Ambil data terbaru untuk response ---
    try {
        $stmt = $Conn->prepare("
            SELECT cp.*,
                p.name AS patientName,
                e.EncounterCode,
                mp.name AS medicalPersonelName,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM care_plan cp
            LEFT JOIN patient p ON cp.patientId = p.patientId
            LEFT JOIN encounter e ON cp.encounterId = e.encounterId
            LEFT JOIN medical_personel mp ON cp.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN account ca ON cp.creatBy = ca.accountId
            LEFT JOIN account ua ON cp.updateBy = ua.accountId
            WHERE cp.carePlanId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $carePlanId]);
        $newData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($newData) {
            $newData['carePlanId'] = (int) $newData['carePlanId'];
            $newData['patientId'] = (int) $newData['patientId'];
            $newData['encounterId'] = (int) $newData['encounterId'];
            $newData['medicalPersonelId'] = (int) $newData['medicalPersonelId'];
            $newData['creatBy'] = $newData['creatBy'] !== null ? (int) $newData['creatBy'] : null;
            $newData['updateBy'] = $newData['updateBy'] !== null ? (int) $newData['updateBy'] : null;
            // Hapus null values
            if ($newData['patientName'] === null) unset($newData['patientName']);
            if ($newData['EncounterCode'] === null) unset($newData['EncounterCode']);
            if ($newData['medicalPersonelName'] === null) unset($newData['medicalPersonelName']);
            if ($newData['createdName'] === null) unset($newData['createdName']);
            if ($newData['updatedName'] === null) unset($newData['updatedName']);
        }
    } catch (PDOException $e) {
        error_log('[CreateCarePlan] Fetch response data error: ' . $e->getMessage());
        // Continue with minimal data
    }

    // --- 18. Response Sukses ---
    http_response_code(201);
    echo json_encode([
        "response" => [
            "message" => "Care Plan berhasil ditambahkan",
            "code" => 201
        ],
        "metadata" => [
            "carePlanId" => $carePlanId,
            "satuSehatCode" => $satuSehatCode,
            "satusehat_sync" => [
                "status" => $satusehatSyncStatus,
                "message" => $satusehatMessage
            ],
            "created_at" => $createdDate . ' GMT'
        ],
        "data" => $newData
    ]);
?>