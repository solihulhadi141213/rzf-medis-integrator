<?php
    /**
     * Create Allergy
     * Endpoint: POST /_API/Allergy/CreateAllergy.php
     * Header: token, account_token
     * Body: JSON {
     *     "AllergenId": 2,
     *     "encounterId": 22,
     *     "medicalPersonelId": 5,
     *     "clinicalStatus": "active",
     *     "verificationStatus": "confirmed",
     *     "allergyDescription": "Rasa gatal pada kulit setelah makan"
     * }
     *
     * - Mengambil data allergen dari tabel referensi untuk mengisi kolom alergen.
     * - Mengambil patientId dari encounter.
     * - clinicalStatus dan verificationStatus divalidasi sesuai enum.
     * - medicalPersonelId opsional, divalidasi jika diisi.
     * - Jika patient, encounter, dan allergen memiliki kode SATUSEHAT, kirim ke SATUSEHAT.
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
    $Limiter->check("create_allergy", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            "response" => ["message" => "Metode request tidak diizinkan", "code" => 405],
            "metadata" => []
        ]);
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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_allergy' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah alergi pasien", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateAllergy] Auth error: ' . $e->getMessage());
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
    $AllergenId = isset($input['AllergenId']) ? (int) $input['AllergenId'] : 0;
    $encounterId = isset($input['encounterId']) ? (int) $input['encounterId'] : 0;
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : null;
    $clinicalStatus = isset($input['clinicalStatus']) ? trim($input['clinicalStatus']) : '';
    $verificationStatus = isset($input['verificationStatus']) ? trim($input['verificationStatus']) : '';
    $allergyDescription = isset($input['allergyDescription']) ? trim($input['allergyDescription']) : null;

    // --- 9. Validasi Field Wajib ---
    if ($AllergenId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "AllergenId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($encounterId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "encounterId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($clinicalStatus)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "clinicalStatus wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($verificationStatus)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "verificationStatus wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 10. Validasi AllergenId ---
    try {
        $stmt = $Conn->prepare("SELECT 
            allergenCategory, allergenName, allergenCode, allergenDisplay, allergenSystem 
            FROM allergen WHERE AllergenId = :id LIMIT 1");
        $stmt->execute([':id' => $AllergenId]);
        $allergenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$allergenData) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "AllergenId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateAllergy] Check AllergenId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi encounterId dan ambil patientId serta satuSehatCode ---
    $patientId = null;
    $encounterSatuSehat = null;
    $patientSatuSehat = null;
    $practitionerSatuSehat = null;

    try {
        $stmt = $Conn->prepare("SELECT patientId, satuSehatCode FROM encounter WHERE encounterId = :id LIMIT 1");
        $stmt->execute([':id' => $encounterId]);
        $encounter = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$encounter) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "encounterId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
        $patientId = $encounter['patientId'];
        $encounterSatuSehat = $encounter['satuSehatCode'];
    } catch (PDOException $e) {
        error_log('[CreateAllergy] Check encounterId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // Ambil patient satuSehatCode
    if ($patientId) {
        try {
            $stmt = $Conn->prepare("SELECT satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
            $stmt->execute([':id' => $patientId]);
            $patientRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($patientRow) {
                $patientSatuSehat = $patientRow['satuSehatCode'];
            }
        } catch (PDOException $e) {
            error_log('[CreateAllergy] Get patient satuSehatCode error: ' . $e->getMessage());
        }
    }

    // --- 12. Validasi medicalPersonelId (jika diisi) dan ambil id_practitioner ---
    if ($medicalPersonelId !== null && $medicalPersonelId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT medicalPersonelId, id_practitioner FROM medical_personel WHERE medicalPersonelId = :id LIMIT 1");
            $stmt->execute([':id' => $medicalPersonelId]);
            $mp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mp) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan", "code" => 422], "metadata" => []]);
                exit;
            }
            $practitionerSatuSehat = $mp['id_practitioner'];
        } catch (PDOException $e) {
            error_log('[CreateAllergy] Check medicalPersonelId error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        $medicalPersonelId = null;
    }

    // --- 13. Validasi clinicalStatus dan verificationStatus ---
    $allowedClinicalStatus = ['active', 'inactive', 'resolved'];
    $allowedVerificationStatus = ['unconfirmed', 'presumed', 'confirmed', 'refuted', 'entered-in-error'];

    if (!in_array($clinicalStatus, $allowedClinicalStatus, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "clinicalStatus harus salah satu dari: " . implode(', ', $allowedClinicalStatus),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }
    if (!in_array($verificationStatus, $allowedVerificationStatus, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "verificationStatus harus salah satu dari: " . implode(', ', $allowedVerificationStatus),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 14. Validasi panjang field (opsional) ---
    if ($allergyDescription !== null && strlen($allergyDescription) > 65535) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allergyDescription terlalu panjang", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 15. Insert Data ---
    $newId = null;
    $createdDate = $nowUtc;
    $satuSehatCode = null;

    try {
        $sql = "INSERT INTO allergy (
                    patientId,
                    encounterId,
                    medicalPersonelId,
                    satuSehatCode,
                    allergenCategory,
                    allergenName,
                    allergenCode,
                    allergenDisplay,
                    allergenSystem,
                    clinicalStatus,
                    verificationStatus,
                    allergyDescription,
                    creatAt,
                    updateAt,
                    creatBy,
                    updateBy
                ) VALUES (
                    :patientId,
                    :encounterId,
                    :medicalPersonelId,
                    :satuSehatCode,
                    :allergenCategory,
                    :allergenName,
                    :allergenCode,
                    :allergenDisplay,
                    :allergenSystem,
                    :clinicalStatus,
                    :verificationStatus,
                    :allergyDescription,
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
            ':allergenCategory' => $allergenData['allergenCategory'],
            ':allergenName' => $allergenData['allergenName'],
            ':allergenCode' => $allergenData['allergenCode'],
            ':allergenDisplay' => $allergenData['allergenDisplay'],
            ':allergenSystem' => $allergenData['allergenSystem'],
            ':clinicalStatus' => $clinicalStatus,
            ':verificationStatus' => $verificationStatus,
            ':allergyDescription' => $allergyDescription,
            ':creatAt' => $createdDate,
            ':updateAt' => $createdDate,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);

        $newId = (int) $Conn->lastInsertId();

    } catch (PDOException $e) {
        error_log('[CreateAllergy] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 16. Integrasi SATUSEHAT ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT dilewati (syarat tidak terpenuhi)';

    // Syarat: patient dan encounter memiliki satuSehatCode, dan allergen memiliki code, display, system
    if (!empty($patientSatuSehat) && !empty($encounterSatuSehat) &&
        !empty($allergenData['allergenCode']) && !empty($allergenData['allergenDisplay']) && !empty($allergenData['allergenSystem'])) {
        
        $satusehatSyncStatus = 'failed';
        $satusehatMessage = 'Gagal mengirim ke SATUSEHAT';

        try {
            // Ambil credential SATUSEHAT aktif
            $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
            $credStmt->execute();
            $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
            if ($credential) {
                $tokenResult = generateTokenSatusehat($Conn);
                if ($tokenResult['status'] === 'success') {
                    $accessToken = $tokenResult['token'];
                    $baseUrl = rtrim($credential['baseUrl'], '/');

                    // Siapkan payload
                    $category = strtolower($allergenData['allergenCategory']);
                    // Pastikan category valid: food, medication, environment, biologic
                    $validCategories = ['food', 'medication', 'environment', 'biologic'];
                    if (!in_array($category, $validCategories, true)) {
                        $category = 'environment'; // fallback
                    }

                    $payload = [
                        'resourceType' => 'AllergyIntolerance',
                        'clinicalStatus' => [
                            'coding' => [
                                [
                                    'system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                                    'code' => $clinicalStatus
                                ]
                            ]
                        ],
                        'verificationStatus' => [
                            'coding' => [
                                [
                                    'system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification',
                                    'code' => $verificationStatus
                                ]
                            ]
                        ],
                        'category' => [$category],
                        'code' => [
                            'coding' => [
                                [
                                    'system' => $allergenData['allergenSystem'],
                                    'code' => $allergenData['allergenCode'],
                                    'display' => $allergenData['allergenDisplay']
                                ]
                            ]
                        ],
                        'patient' => [
                            'reference' => 'Patient/' . $patientSatuSehat
                        ],
                        'encounter' => [
                            'reference' => 'Encounter/' . $encounterSatuSehat
                        ]
                    ];

                    // Tambahkan recorder jika practitioner ada
                    if (!empty($practitionerSatuSehat)) {
                        $payload['recorder'] = [
                            'reference' => 'Practitioner/' . $practitionerSatuSehat
                        ];
                    }

                    // Tambahkan note jika ada description
                    if (!empty($allergyDescription)) {
                        $payload['note'] = [
                            [
                                'text' => $allergyDescription
                            ]
                        ];
                    }

                    // Kirim ke SATUSEHAT
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $baseUrl . '/fhir-r4/v1/AllergyIntolerance',
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
                            // Update database
                            $updStmt = $Conn->prepare("UPDATE allergy SET satuSehatCode = :satuSehatCode WHERE allergyId = :id");
                            $updStmt->execute([':satuSehatCode' => $satuSehatCode, ':id' => $newId]);
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
            error_log('[CreateAllergy] SATUSEHAT integration error: ' . $e->getMessage());
        }
    }

    // --- 17. Ambil data yang baru dibuat untuk response ---
    try {
        $stmt = $Conn->prepare("
            SELECT a.*, 
                p.name AS patientName, 
                e.EncounterCode,
                mp.name AS medicalPersonelName,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM allergy a
            LEFT JOIN patient p ON a.patientId = p.patientId
            LEFT JOIN encounter e ON a.encounterId = e.encounterId
            LEFT JOIN medical_personel mp ON a.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN account ca ON a.creatBy = ca.accountId
            LEFT JOIN account ua ON a.updateBy = ua.accountId
            WHERE a.allergyId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $newId]);
        $newData = $stmt->fetch(PDO::FETCH_ASSOC);

        // Format response
        if ($newData) {
            $newData['allergyId'] = (int) $newData['allergyId'];
            $newData['patientId'] = (int) $newData['patientId'];
            $newData['encounterId'] = (int) $newData['encounterId'];
            $newData['medicalPersonelId'] = $newData['medicalPersonelId'] !== null ? (int) $newData['medicalPersonelId'] : null;
            $newData['creatBy'] = $newData['creatBy'] !== null ? (int) $newData['creatBy'] : null;
            $newData['updateBy'] = $newData['updateBy'] !== null ? (int) $newData['updateBy'] : null;
            if ($newData['patientName'] === null) unset($newData['patientName']);
            if ($newData['EncounterCode'] === null) unset($newData['EncounterCode']);
            if ($newData['medicalPersonelName'] === null) unset($newData['medicalPersonelName']);
            if ($newData['createdName'] === null) unset($newData['createdName']);
            if ($newData['updatedName'] === null) unset($newData['updatedName']);
        }
    } catch (PDOException $e) {
        error_log('[CreateAllergy] Fetch response data error: ' . $e->getMessage());
        // Continue tanpa data detail
    }

    // --- 18. Response Sukses ---
    http_response_code(201);
    echo json_encode([
        "response" => [
            "message" => "Alergi pasien berhasil ditambahkan",
            "code" => 201
        ],
        "metadata" => [
            "allergyId" => $newId,
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