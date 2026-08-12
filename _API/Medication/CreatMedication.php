<?php
    /**
     * Create Medication
     * Endpoint: POST /_API/Medication/CreateMedication.php
     * Header: token, account_token
     * Body: JSON (lihat contoh)
     *
     * - Validasi mandatory.
     * - Generate medicationLocalCode jika kosong (random 16 karakter).
     * - Insert ke medication, medication_multi_form, medication_selling_price.
     * - Sinkronisasi ke SATUSEHAT (Medication/Device) jika syarat terpenuhi.
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
    $Limiter->check("create_medication", 5, 60);

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

        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_medication' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah obat/alkes", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateMedication] Auth error: ' . $e->getMessage());
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
    $medicationLocalCode = isset($input['medicationLocalCode']) ? trim($input['medicationLocalCode']) : '';
    $medicationName      = isset($input['medicationName']) ? trim($input['medicationName']) : '';
    $medicationGroup     = isset($input['medicationGroup']) ? trim($input['medicationGroup']) : '';
    $medicationCategory  = isset($input['medicationCategory']) ? trim($input['medicationCategory']) : '';
    $kfaCode             = isset($input['kfaCode']) ? trim($input['kfaCode']) : null;
    $kfaDisplay          = isset($input['kfaDisplay']) ? trim($input['kfaDisplay']) : null;
    $kfaSystem           = isset($input['kfaSystem']) ? trim($input['kfaSystem']) : null;
    $medicationFormName  = isset($input['medicationFormName']) ? trim($input['medicationFormName']) : '';
    $medicationFormCode  = isset($input['medicationFormCode']) ? trim($input['medicationFormCode']) : null;
    $medicationFormDisplay = isset($input['medicationFormDisplay']) ? trim($input['medicationFormDisplay']) : null;
    $medicationFormSystem  = isset($input['medicationFormSystem']) ? trim($input['medicationFormSystem']) : null;
    $medicationType      = isset($input['medicationType']) ? trim($input['medicationType']) : '';
    $manufacturerId      = isset($input['manufacturerId']) ? trim($input['manufacturerId']) : null;
    $manufacturerName    = isset($input['manufacturerName']) ? trim($input['manufacturerName']) : null;
    $medicationIngredient= isset($input['medicationIngredient']) ? $input['medicationIngredient'] : [];
    $CostPrice           = isset($input['CostPrice']) && $input['CostPrice'] !== '' ? (float) $input['CostPrice'] : null;
    $ActualStock         = isset($input['ActualStock']) && $input['ActualStock'] !== '' ? (float) $input['ActualStock'] : null;
    $MinimumStock        = isset($input['MinimumStock']) && $input['MinimumStock'] !== '' ? (float) $input['MinimumStock'] : null;
    $medicationStatus    = isset($input['medicationStatus']) ? trim($input['medicationStatus']) : 'Registered';
    $multiForm           = isset($input['medication_multi_form']) ? $input['medication_multi_form'] : [];
    $sellingPrices       = isset($input['medication_selling_price']) ? $input['medication_selling_price'] : [];

    // --- 9. Generate medicationLocalCode jika kosong ---
    if (empty($medicationLocalCode)) {
        $medicationLocalCode = GenerateToken(16);
        $unique = false;
        $attempt = 0;
        while (!$unique && $attempt < 10) {
            try {
                $stmt = $Conn->prepare("SELECT medicationId FROM medication WHERE medicationLocalCode = :code LIMIT 1");
                $stmt->execute([':code' => $medicationLocalCode]);
                if (!$stmt->fetch()) {
                    $unique = true;
                } else {
                    $medicationLocalCode = GenerateToken(16);
                    $attempt++;
                }
            } catch (PDOException $e) {
                error_log('[CreateMedication] Generate code error: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
                exit;
            }
        }
        if (!$unique) {
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Gagal generate medicationLocalCode unik", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        if (strlen($medicationLocalCode) > 50) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicationLocalCode maksimal 50 karakter", "code" => 422], "metadata" => []]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT medicationId FROM medication WHERE medicationLocalCode = :code LIMIT 1");
            $stmt->execute([':code' => $medicationLocalCode]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "medicationLocalCode sudah digunakan", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CreateMedication] Check code error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 10. Validasi Field Wajib ---
    $required = ['medicationName', 'medicationGroup', 'medicationCategory', 'medicationFormName', 'medicationType', 'medicationStatus'];
    foreach ($required as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }
    if (!in_array($medicationGroup, ['Medication', 'Device'], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicationGroup harus Medication atau Device", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($medicationType, ['NC', 'SD', 'EP'], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicationType harus NC, SD, atau EP", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($medicationStatus, ['Available', 'Registered', 'Deleted'], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicationStatus harus Available, Registered, atau Deleted", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi medicationIngredient ---
    if (!empty($medicationIngredient) && !is_array($medicationIngredient)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicationIngredient harus berupa array JSON", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi multi_form ---
    if (!empty($multiForm) && is_array($multiForm)) {
        foreach ($multiForm as $idx => $item) {
            if (empty($item['medicationFormName'])) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "medication_multi_form ke-" . ($idx+1) . ": medicationFormName wajib diisi", "code" => 422], "metadata" => []]);
                exit;
            }
            if (!isset($item['conversionFactor']) || $item['conversionFactor'] === '' || (float)$item['conversionFactor'] <= 0) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "medication_multi_form ke-" . ($idx+1) . ": conversionFactor harus diisi dengan angka > 0", "code" => 422], "metadata" => []]);
                exit;
            }
        }
    }

    // --- 13. Validasi selling_price ---
    if (!empty($sellingPrices) && is_array($sellingPrices)) {
        foreach ($sellingPrices as $idx => $item) {
            if (empty($item['medicationSellingPriceName'])) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "medication_selling_price ke-" . ($idx+1) . ": medicationSellingPriceName wajib diisi", "code" => 422], "metadata" => []]);
                exit;
            }
            if (!isset($item['medicationSellingPrice']) || $item['medicationSellingPrice'] === '' || (float)$item['medicationSellingPrice'] < 0) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "medication_selling_price ke-" . ($idx+1) . ": medicationSellingPrice harus diisi dengan angka >= 0", "code" => 422], "metadata" => []]);
                exit;
            }
        }
    }

    // --- 14. Insert ke medication ---
    $medicationId = null;
    $createdDate = $nowUtc;
    try {
        $ingredientJson = !empty($medicationIngredient) ? json_encode($medicationIngredient) : null;

        $sql = "INSERT INTO medication (
                    satuSehatCode,
                    medicationLocalCode,
                    medicationName,
                    medicationGroup,
                    medicationCategory,
                    kfaCode,
                    kfaDisplay,
                    kfaSystem,
                    medicationFormName,
                    medicationFormCode,
                    medicationFormDisplay,
                    medicationFormSystem,
                    medicationType,
                    manufacturerId,
                    manufacturerName,
                    medicationIngredient,
                    CostPrice,
                    ActualStock,
                    MinimumStock,
                    medicationStatus,
                    creatAt,
                    updateAt,
                    creatBy,
                    updateBy
                ) VALUES (
                    :satuSehatCode,
                    :medicationLocalCode,
                    :medicationName,
                    :medicationGroup,
                    :medicationCategory,
                    :kfaCode,
                    :kfaDisplay,
                    :kfaSystem,
                    :medicationFormName,
                    :medicationFormCode,
                    :medicationFormDisplay,
                    :medicationFormSystem,
                    :medicationType,
                    :manufacturerId,
                    :manufacturerName,
                    :medicationIngredient,
                    :CostPrice,
                    :ActualStock,
                    :MinimumStock,
                    :medicationStatus,
                    :creatAt,
                    :updateAt,
                    :creatBy,
                    :updateBy
                )";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':satuSehatCode' => null,
            ':medicationLocalCode' => $medicationLocalCode,
            ':medicationName' => $medicationName,
            ':medicationGroup' => $medicationGroup,
            ':medicationCategory' => $medicationCategory,
            ':kfaCode' => $kfaCode,
            ':kfaDisplay' => $kfaDisplay,
            ':kfaSystem' => $kfaSystem,
            ':medicationFormName' => $medicationFormName,
            ':medicationFormCode' => $medicationFormCode,
            ':medicationFormDisplay' => $medicationFormDisplay,
            ':medicationFormSystem' => $medicationFormSystem,
            ':medicationType' => $medicationType,
            ':manufacturerId' => $manufacturerId,
            ':manufacturerName' => $manufacturerName,
            ':medicationIngredient' => $ingredientJson,
            ':CostPrice' => $CostPrice,
            ':ActualStock' => $ActualStock,
            ':MinimumStock' => $MinimumStock,
            ':medicationStatus' => $medicationStatus,
            ':creatAt' => $createdDate,
            ':updateAt' => $createdDate,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);
        $medicationId = (int) $Conn->lastInsertId();
    } catch (PDOException $e) {
        error_log('[CreateMedication] Insert medication error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data obat: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 15. Insert medication_multi_form ---
    if (!empty($multiForm)) {
        try {
            $sql = "INSERT INTO medication_multi_form (
                        medicationId,
                        medicationFormName,
                        medicationFormCode,
                        medicationFormDisplay,
                        medicationFormSystem,
                        conversionFactor
                    ) VALUES (
                        :medicationId,
                        :medicationFormName,
                        :medicationFormCode,
                        :medicationFormDisplay,
                        :medicationFormSystem,
                        :conversionFactor
                    )";
            $stmt = $Conn->prepare($sql);
            foreach ($multiForm as $item) {
                $stmt->execute([
                    ':medicationId' => $medicationId,
                    ':medicationFormName' => $item['medicationFormName'],
                    ':medicationFormCode' => isset($item['medicationFormCode']) ? $item['medicationFormCode'] : null,
                    ':medicationFormDisplay' => isset($item['medicationFormDisplay']) ? $item['medicationFormDisplay'] : null,
                    ':medicationFormSystem' => isset($item['medicationFormSystem']) ? $item['medicationFormSystem'] : null,
                    ':conversionFactor' => (float) $item['conversionFactor']
                ]);
            }
        } catch (PDOException $e) {
            error_log('[CreateMedication] Insert multi_form error: ' . $e->getMessage());
            $Conn->prepare("DELETE FROM medication WHERE medicationId = :id")->execute([':id' => $medicationId]);
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Gagal menyimpan multi form: " . $e->getMessage(), "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 16. Insert medication_selling_price ---
    if (!empty($sellingPrices)) {
        try {
            $sql = "INSERT INTO medication_selling_price (
                        medicationId,
                        medicationSellingPriceName,
                        medicationSellingPriceDescription,
                        medicationSellingPrice
                    ) VALUES (
                        :medicationId,
                        :medicationSellingPriceName,
                        :medicationSellingPriceDescription,
                        :medicationSellingPrice
                    )";
            $stmt = $Conn->prepare($sql);
            foreach ($sellingPrices as $item) {
                $stmt->execute([
                    ':medicationId' => $medicationId,
                    ':medicationSellingPriceName' => $item['medicationSellingPriceName'],
                    ':medicationSellingPriceDescription' => isset($item['medicationSellingPriceDescription']) ? $item['medicationSellingPriceDescription'] : null,
                    ':medicationSellingPrice' => (float) $item['medicationSellingPrice']
                ]);
            }
        } catch (PDOException $e) {
            error_log('[CreateMedication] Insert selling_price error: ' . $e->getMessage());
            $Conn->prepare("DELETE FROM medication WHERE medicationId = :id")->execute([':id' => $medicationId]);
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Gagal menyimpan selling price: " . $e->getMessage(), "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 17. Sinkronisasi ke SATUSEHAT ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
    $satusehatCode = null;
    $satusehatErrorDetail = null;

    // Ambil organizationId dari credential aktif
    $organizationId = null;
    try {
        $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
        $credStmt->execute();
        $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
        if ($credential) {
            $organizationId = $credential['organizationId'];
        }
    } catch (PDOException $e) {
        error_log('[CreateMedication] Get credential error: ' . $e->getMessage());
    }

    $canSync = !empty($kfaCode) && !empty($kfaDisplay) && !empty($kfaSystem) &&
            !empty($medicationFormCode) && !empty($medicationFormDisplay) && !empty($medicationFormSystem) &&
            !empty($manufacturerId) && !empty($organizationId);

    if ($canSync) {
        try {
            $tokenResult = generateTokenSatusehat($Conn);
            if ($tokenResult['status'] === 'success') {
                $accessToken = $tokenResult['token'];
                $baseUrl = rtrim($credential['baseUrl'], '/');

                $resourceType = ($medicationGroup === 'Device') ? 'Device' : 'Medication';

                $payload = [
                    'resourceType' => $resourceType,

                    'identifier' => [
                        [
                            'system' => 'http://sys-ids.kemkes.go.id/medication/' . $organizationId,
                            'value' => $medicationLocalCode
                        ]
                    ],

                    // Medication Type
                    // NC = Non-compound
                    // SD = Gives of such doses
                    // EP = Divide into equal parts
                    'extension' => [
                        [
                            'url' => 'https://fhir.kemkes.go.id/r4/StructureDefinition/MedicationType',
                            'valueCodeableConcept' => [
                                'coding' => [
                                    [
                                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/medication-type',
                                        'code' => $medicationType,
                                        'display' => $medicationType === 'NC'
                                            ? 'Non-compound'
                                            : ($medicationType === 'SD'
                                                ? 'Gives of such doses'
                                                : 'Divide into equal parts')
                                    ]
                                ]
                            ]
                        ]
                    ],

                    'code' => [
                        'coding' => [
                            [
                                'system' => $kfaSystem,
                                'code' => $kfaCode,
                                'display' => $kfaDisplay
                            ]
                        ]
                    ],

                    'form' => [
                        'coding' => [
                            [
                                'system' => $medicationFormSystem,
                                'code' => $medicationFormCode,
                                'display' => $medicationFormDisplay
                            ]
                        ]
                    ],

                    'manufacturer' => [
                        'reference' => 'Organization/' . $manufacturerId
                    ],

                    'status' => (
                        $medicationStatus === 'Available' ||
                        $medicationStatus === 'Registered'
                    ) ? 'active' : 'inactive'
                ];

                // Tambahkan ingredient jika ada (untuk racikan)
                if (!empty($medicationIngredient) && is_array($medicationIngredient)) {
                    $ingredientList = [];
                    foreach ($medicationIngredient as $ing) {
                        $item = [];
                        if (!empty($ing['kode_kfa'])) {
                            $item['itemCodeableConcept'] = [
                                'coding' => [
                                    [
                                        'system' => 'http://sys-ids.kemkes.go.id/kfa',
                                        'code' => $ing['kode_kfa'],
                                        'display' => $ing['nama_kfa'] ?? ''
                                    ]
                                ]
                            ];
                        }
                        if (isset($ing['jumlah_numerator']) && isset($ing['kode_numerator']) && isset($ing['kode_denominator'])) {
                            $item['strength'] = [
                                'numerator' => [
                                    'value' => (float) $ing['jumlah_numerator'],
                                    'unit' => $ing['kode_numerator']
                                ],
                                'denominator' => [
                                    'value' => 1,
                                    'unit' => $ing['kode_denominator']
                                ]
                            ];
                        }
                        if (!empty($item)) {
                            $ingredientList[] = $item;
                        }
                    }
                    if (!empty($ingredientList)) {
                        $payload['ingredient'] = $ingredientList;
                    }
                }

                $satusehatSyncStatus = 'failed';
                $satusehatMessage = 'Gagal mengirim ke SATUSEHAT';

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . '/fhir-r4/v1/' . $resourceType,
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
                    $satusehatErrorDetail = $curlError;
                } elseif ($httpCode === 201 || $httpCode === 200) {
                    $result = json_decode($response, true);
                    if (isset($result['id'])) {
                        $satusehatCode = $result['id'];
                        $updStmt = $Conn->prepare("UPDATE medication SET satuSehatCode = :code WHERE medicationId = :id");
                        $updStmt->execute([':code' => $satusehatCode, ':id' => $medicationId]);
                        $satusehatSyncStatus = 'success';
                        $satusehatMessage = 'Berhasil disinkronkan ke SATUSEHAT';
                    } else {
                        $satusehatMessage = 'Respons SATUSEHAT tidak mengandung ID';
                        $satusehatErrorDetail = 'ID tidak ditemukan';
                    }
                } else {
                    $result = json_decode($response, true);
                    if ($result && isset($result['issue']) && is_array($result['issue'])) {
                        $errors = [];
                        foreach ($result['issue'] as $issue) {
                            $details = isset($issue['details']['text']) ? $issue['details']['text'] : '';
                            $diagnostics = isset($issue['diagnostics']) ? $issue['diagnostics'] : '';
                            $expression = isset($issue['expression']) ? implode(', ', $issue['expression']) : '';
                            $errorMsg = '';
                            if ($details) $errorMsg .= $details;
                            if ($diagnostics) $errorMsg .= ($errorMsg ? ' - ' : '') . $diagnostics;
                            if ($expression) $errorMsg .= ($errorMsg ? ' (Field: ' : 'Field: ') . $expression . ')';
                            $errors[] = $errorMsg;
                        }
                        $satusehatErrorDetail = implode('; ', $errors);
                        $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . $satusehatErrorDetail;
                    } else {
                        $satusehatErrorDetail = substr($response, 0, 500);
                        $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . $satusehatErrorDetail;
                    }
                }
            } else {
                $satusehatMessage = 'Token SATUSEHAT error: ' . $tokenResult['message'];
                $satusehatErrorDetail = $tokenResult['message'];
            }
        } catch (Exception $e) {
            $satusehatMessage = 'Exception: ' . $e->getMessage();
            $satusehatErrorDetail = $e->getMessage();
            error_log('[CreateMedication] SATUSEHAT integration error: ' . $e->getMessage());
            $satusehatSyncStatus = 'failed';
        }
    } else {
        $satusehatMessage = 'Syarat sinkronisasi tidak terpenuhi (kfaCode, kfaDisplay, kfaSystem, form, manufacturerId, dan organizationId harus lengkap)';
        $satusehatSyncStatus = 'skipped';
    }

    // --- 18. Ambil data yang baru dibuat untuk response ---
    $row = null;
    try {
        $stmt = $Conn->prepare("
            SELECT m.*,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM medication m
            LEFT JOIN account ca ON m.creatBy = ca.accountId
            LEFT JOIN account ua ON m.updateBy = ua.accountId
            WHERE m.medicationId = :id
        ");
        $stmt->execute([':id' => $medicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Ambil multi_form
            $stmt2 = $Conn->prepare("SELECT * FROM medication_multi_form WHERE medicationId = :id");
            $stmt2->execute([':id' => $medicationId]);
            $multiForms = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($multiForms as &$mf) {
                $mf['medicationMultiFormId'] = (int) $mf['medicationMultiFormId'];
                $mf['medicationId'] = (int) $mf['medicationId'];
                $mf['conversionFactor'] = (float) $mf['conversionFactor'];
            }
            $row['multiForms'] = $multiForms;

            // Ambil selling_price
            $stmt3 = $Conn->prepare("SELECT * FROM medication_selling_price WHERE medicationId = :id");
            $stmt3->execute([':id' => $medicationId]);
            $sellingPricesData = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sellingPricesData as &$sp) {
                $sp['medicationSellingPriceId'] = (int) $sp['medicationSellingPriceId'];
                $sp['medicationId'] = (int) $sp['medicationId'];
                $sp['medicationSellingPrice'] = (float) $sp['medicationSellingPrice'];
            }
            $row['sellingPrices'] = $sellingPricesData;

            // Decode medicationIngredient
            $row['medicationIngredient'] = $row['medicationIngredient'] ? json_decode($row['medicationIngredient'], true) : [];

            // Convert numeric types
            $row['medicationId'] = (int) $row['medicationId'];
            $row['CostPrice'] = $row['CostPrice'] !== null ? (float) $row['CostPrice'] : null;
            $row['ActualStock'] = $row['ActualStock'] !== null ? (float) $row['ActualStock'] : null;
            $row['MinimumStock'] = $row['MinimumStock'] !== null ? (float) $row['MinimumStock'] : null;
            $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;
            if ($row['createdName'] === null) unset($row['createdName']);
            if ($row['updatedName'] === null) unset($row['updatedName']);
        }
    } catch (PDOException $e) {
        error_log('[CreateMedication] Fetch response data error: ' . $e->getMessage());
        $row = null;
    }

    // --- 19. Response Sukses ---
    http_response_code(201);
    echo json_encode([
        "response" => [
            "message" => "Obat/Alkes berhasil ditambahkan",
            "code" => 201
        ],
        "metadata" => [
            "medicationId" => $medicationId,
            "satuSehatCode" => $satusehatCode,
            "satusehat_sync" => [
                "status" => $satusehatSyncStatus,
                "message" => $satusehatMessage,
                "error_detail" => $satusehatErrorDetail
            ],
            "created_at" => $createdDate . ' GMT'
        ],
        "data" => $row
    ]);
?>