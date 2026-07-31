<?php
    /**
     * Create Observation Reference
     * Endpoint: POST /_API/Reference/Observation/CreateObservation.php
     * Header: token, account_token
     * Body: JSON dengan child data menggunakan ageCategory sebagai referensi.
     *
     * - Insert observation_reference, observation_reference_age, observation_reference_coded, observation_reference_range.
     * - Gunakan transaksi untuk atomicity.
     * - ageMax boleh kosong (null) untuk batas atas tidak terbatas.
     * - range dan coded merujuk ke ageCategory (bukan ID).
     * - Semua nama kolom dibungkus backtick untuk menghindari reserved keywords.
     * - unitReferenceId boleh null/0, jika null maka unit fields diisi null.
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
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("create_observation_reference", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_observation_reference' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah referensi pemeriksaan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateObservation] Auth error: ' . $e->getMessage());
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
    $categoryName   = isset($input['categoryName']) ? trim($input['categoryName']) : '';
    $categoryCode   = isset($input['categoryCode']) ? trim($input['categoryCode']) : null;
    $categoryDisplay = isset($input['categoryDisplay']) ? trim($input['categoryDisplay']) : null;
    $categorySystem = isset($input['categorySystem']) ? trim($input['categorySystem']) : null;
    $observationName = isset($input['observationName']) ? trim($input['observationName']) : '';
    $observationCode = isset($input['observationCode']) ? trim($input['observationCode']) : null;
    $observationDisplay = isset($input['observationDisplay']) ? trim($input['observationDisplay']) : null;
    $observationSystem = isset($input['observationSystem']) ? trim($input['observationSystem']) : null;
    $unitReferenceId = isset($input['unitReferenceId']) && $input['unitReferenceId'] !== '' ? (int) $input['unitReferenceId'] : 0;
    $resultType     = isset($input['resultType']) ? trim($input['resultType']) : '';
    $allowSex       = isset($input['allowSex']) ? (int) $input['allowSex'] : 0;
    $allowAge       = isset($input['allowAge']) ? (int) $input['allowAge'] : 0;
    $status         = isset($input['status']) ? (int) $input['status'] : 1;
    $observationAge = isset($input['observation_reference_age']) ? $input['observation_reference_age'] : [];
    $observationCoded = isset($input['observation_reference_coded']) ? $input['observation_reference_coded'] : [];
    $observationRange = isset($input['observation_reference_range']) ? $input['observation_reference_range'] : [];

    // --- 9. Validasi Field Wajib ---
    $requiredFields = ['categoryName', 'observationName', 'resultType'];
    foreach ($requiredFields as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }
    // unitReferenceId boleh 0 (null) - validasi hanya jika >0 maka harus valid
    if ($unitReferenceId < 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "unitReferenceId tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($allowSex, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allowSex hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($allowAge, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allowAge hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($status, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "status hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 10. Validasi resultType ---
    $allowedResultTypes = ['Numeric', 'Decimal', 'Coded', 'Text'];
    if (!in_array($resultType, $allowedResultTypes, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "resultType harus salah satu dari: " . implode(', ', $allowedResultTypes),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 11. Validasi unitReferenceId dan ambil data unit (jika ada) ---
    $unitData = null;
    if ($unitReferenceId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT unitName, unitCode, unitDisplay, unitSystem FROM unit_reference WHERE unitReferenceId = :id LIMIT 1");
            $stmt->execute([':id' => $unitReferenceId]);
            $unitData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unitData) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "unitReferenceId tidak ditemukan", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CreateObservation] Check unitReferenceId error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        // unitReferenceId = 0, berarti tidak ada unit, set null
        $unitData = null;
    }

    // --- 12. Validasi kondisi allowAge terhadap child data ---
    if ($allowAge == 0 && !empty($observationAge)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allowAge = 0, tetapi observation_reference_age tidak boleh diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 13. Mulai Transaksi ---
    $Conn->beginTransaction();
    try {
        // 13a. Insert observation_reference
        $sql = "INSERT INTO `observation_reference` (
                    `categoryName`, `categoryCode`, `categoryDisplay`, `categorySystem`,
                    `observationName`, `observationCode`, `observationDisplay`, `observationSystem`,
                    `unitName`, `unitCode`, `unitDisplay`, `unitSystem`,
                    `resultType`, `allowSex`, `allowAge`, `status`,
                    `creatAt`, `updateAt`, `creatBy`, `updateBy`
                ) VALUES (
                    :categoryName, :categoryCode, :categoryDisplay, :categorySystem,
                    :observationName, :observationCode, :observationDisplay, :observationSystem,
                    :unitName, :unitCode, :unitDisplay, :unitSystem,
                    :resultType, :allowSex, :allowAge, :status,
                    :creatAt, :updateAt, :creatBy, :updateBy
                )";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':categoryName' => $categoryName,
            ':categoryCode' => $categoryCode,
            ':categoryDisplay' => $categoryDisplay,
            ':categorySystem' => $categorySystem,
            ':observationName' => $observationName,
            ':observationCode' => $observationCode,
            ':observationDisplay' => $observationDisplay,
            ':observationSystem' => $observationSystem,
            ':unitName' => $unitData ? $unitData['unitName'] : null,
            ':unitCode' => $unitData ? $unitData['unitCode'] : null,
            ':unitDisplay' => $unitData ? $unitData['unitDisplay'] : null,
            ':unitSystem' => $unitData ? $unitData['unitSystem'] : null,
            ':resultType' => $resultType,
            ':allowSex' => $allowSex,
            ':allowAge' => $allowAge,
            ':status' => $status,
            ':creatAt' => $nowUtc,
            ':updateAt' => $nowUtc,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);
        $observationReferenceId = (int) $Conn->lastInsertId();

        // 13b. Insert observation_reference_age
        $ageIdMap = [];
        foreach ($observationAge as $ageData) {
            $ageCategory = isset($ageData['ageCategory']) ? trim($ageData['ageCategory']) : '';
            $ageMin = isset($ageData['ageMin']) && $ageData['ageMin'] !== '' ? (int) $ageData['ageMin'] : 0;
            $ageMax = isset($ageData['ageMax']) && $ageData['ageMax'] !== '' ? (int) $ageData['ageMax'] : null;
            $ageUnit = isset($ageData['ageUnit']) ? trim($ageData['ageUnit']) : '';

            if (empty($ageCategory) || $ageMin < 0 || empty($ageUnit)) {
                throw new Exception('Data usia tidak lengkap: ageCategory, ageMin, ageUnit wajib diisi, ageMin minimal 0');
            }
            if (!in_array($ageUnit, ['Year', 'Month', 'Day'], true)) {
                throw new Exception('ageUnit harus Year, Month, atau Day');
            }
            if ($ageMax !== null && $ageMin > $ageMax) {
                throw new Exception('ageMin tidak boleh lebih besar dari ageMax untuk kategori ' . $ageCategory);
            }
            if (isset($ageIdMap[$ageCategory])) {
                throw new Exception('Duplikat ageCategory: ' . $ageCategory);
            }

            $sql = "INSERT INTO `observation_reference_age` (
                        `observationReferenceId`, `ageCategory`, `ageMin`, `ageMax`, `ageUnit`
                    ) VALUES (
                        :observationReferenceId, :ageCategory, :ageMin, :ageMax, :ageUnit
                    )";
            $stmt = $Conn->prepare($sql);
            $stmt->execute([
                ':observationReferenceId' => $observationReferenceId,
                ':ageCategory' => $ageCategory,
                ':ageMin' => $ageMin,
                ':ageMax' => $ageMax,
                ':ageUnit' => $ageUnit
            ]);
            $ageIdMap[$ageCategory] = (int) $Conn->lastInsertId();
        }

        // 13c. Insert observation_reference_coded
        foreach ($observationCoded as $codedData) {
            $ageCategory = isset($codedData['ageCategory']) ? trim($codedData['ageCategory']) : null;
            $observationReferenceAgeId = null;
            if ($ageCategory !== null && $ageCategory !== '') {
                if (!isset($ageIdMap[$ageCategory])) {
                    throw new Exception('ageCategory "' . $ageCategory . '" tidak ditemukan dalam daftar usia');
                }
                $observationReferenceAgeId = $ageIdMap[$ageCategory];
            }

            $groupGender = isset($codedData['groupGender']) ? trim($codedData['groupGender']) : 'All';
            $valueResult = isset($codedData['valueResult']) ? trim($codedData['valueResult']) : '';
            $labelResult = isset($codedData['labelResult']) ? trim($codedData['labelResult']) : '';
            $displayResult = isset($codedData['displayResult']) ? trim($codedData['displayResult']) : null;
            $codeResult = isset($codedData['codeResult']) ? trim($codedData['codeResult']) : null;
            $systemResult = isset($codedData['systemResult']) ? trim($codedData['systemResult']) : null;
            $normalResult = isset($codedData['normalResult']) ? (int) $codedData['normalResult'] : 0;

            if (empty($valueResult) || empty($labelResult)) {
                throw new Exception('Data coded tidak lengkap: valueResult dan labelResult wajib diisi');
            }
            if (!in_array($groupGender, ['Male', 'Female', 'All'], true)) {
                throw new Exception('groupGender harus Male, Female, atau All');
            }
            if (!in_array($normalResult, [0, 1], true)) {
                throw new Exception('normalResult hanya boleh 0 atau 1');
            }

            $sql = "INSERT INTO `observation_reference_coded` (
                        `observationReferenceId`, `observationReferenceAgeId`, `groupGender`,
                        `valueResult`, `labelResult`, `displayResult`, `codeResult`, `systemResult`, `normalResult`
                    ) VALUES (
                        :observationReferenceId, :observationReferenceAgeId, :groupGender,
                        :valueResult, :labelResult, :displayResult, :codeResult, :systemResult, :normalResult
                    )";
            $stmt = $Conn->prepare($sql);
            $stmt->execute([
                ':observationReferenceId' => $observationReferenceId,
                ':observationReferenceAgeId' => $observationReferenceAgeId,
                ':groupGender' => $groupGender,
                ':valueResult' => $valueResult,
                ':labelResult' => $labelResult,
                ':displayResult' => $displayResult,
                ':codeResult' => $codeResult,
                ':systemResult' => $systemResult,
                ':normalResult' => $normalResult
            ]);
        }

        // 13d. Insert observation_reference_range
        foreach ($observationRange as $rangeData) {
            $ageCategory = isset($rangeData['ageCategory']) ? trim($rangeData['ageCategory']) : null;
            $observationReferenceAgeId = null;
            if ($ageCategory !== null && $ageCategory !== '') {
                if (!isset($ageIdMap[$ageCategory])) {
                    throw new Exception('ageCategory "' . $ageCategory . '" tidak ditemukan dalam daftar usia');
                }
                $observationReferenceAgeId = $ageIdMap[$ageCategory];
            }

            $groupGender = isset($rangeData['groupGender']) ? trim($rangeData['groupGender']) : 'All';
            $minValue = isset($rangeData['minValue']) && $rangeData['minValue'] !== '' ? (float) $rangeData['minValue'] : null;
            $maxValue = isset($rangeData['maxValue']) && $rangeData['maxValue'] !== '' ? (float) $rangeData['maxValue'] : null;
            $rangeOperator = isset($rangeData['rangeOperator']) ? trim($rangeData['rangeOperator']) : 'Between';
            $InterpertationLabel = isset($rangeData['InterpertationLabel']) ? trim($rangeData['InterpertationLabel']) : '';
            $InterpertationDisplay = isset($rangeData['InterpertationDisplay']) ? trim($rangeData['InterpertationDisplay']) : '';
            $InterpertationCode = isset($rangeData['InterpertationCode']) ? trim($rangeData['InterpertationCode']) : null;
            $InterpertationSystem = isset($rangeData['InterpertationSystem']) ? trim($rangeData['InterpertationSystem']) : null;
            $InterpertationConclusion = isset($rangeData['InterpertationConclusion']) ? trim($rangeData['InterpertationConclusion']) : null;
            $normalResult = isset($rangeData['normalResult']) ? (int) $rangeData['normalResult'] : 0;

            if (empty($InterpertationLabel) || empty($InterpertationDisplay)) {
                throw new Exception('Data range tidak lengkap: InterpertationLabel dan InterpertationDisplay wajib diisi');
            }
            if (!in_array($groupGender, ['Male', 'Female', 'All'], true)) {
                throw new Exception('groupGender harus Male, Female, atau All');
            }
            if (!in_array($rangeOperator, ['Less', 'More', 'Between'], true)) {
                throw new Exception('rangeOperator harus Less, More, atau Between');
            }
            if (!in_array($normalResult, [0, 1], true)) {
                throw new Exception('normalResult hanya boleh 0 atau 1');
            }
            if ($rangeOperator === 'Between' && ($minValue === null || $maxValue === null)) {
                throw new Exception('Untuk range operator Between, minValue dan maxValue harus diisi');
            }
            if ($rangeOperator === 'Less' && $maxValue === null) {
                throw new Exception('Untuk range operator Less, maxValue harus diisi');
            }
            if ($rangeOperator === 'More' && $minValue === null) {
                throw new Exception('Untuk range operator More, minValue harus diisi');
            }
            if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
                throw new Exception('minValue tidak boleh lebih besar dari maxValue');
            }

            $sql = "INSERT INTO `observation_reference_range` (
                        `observationReferenceId`, `observationReferenceAgeId`, `groupGender`,
                        `minValue`, `maxValue`, `rangeOperator`,
                        `InterpertationLabel`, `InterpertationDisplay`, `InterpertationCode`,
                        `InterpertationSystem`, `InterpertationConclusion`, `normalResult`
                    ) VALUES (
                        :observationReferenceId, :observationReferenceAgeId, :groupGender,
                        :minValue, :maxValue, :rangeOperator,
                        :InterpertationLabel, :InterpertationDisplay, :InterpertationCode,
                        :InterpertationSystem, :InterpertationConclusion, :normalResult
                    )";
            $stmt = $Conn->prepare($sql);
            $stmt->execute([
                ':observationReferenceId' => $observationReferenceId,
                ':observationReferenceAgeId' => $observationReferenceAgeId,
                ':groupGender' => $groupGender,
                ':minValue' => $minValue,
                ':maxValue' => $maxValue,
                ':rangeOperator' => $rangeOperator,
                ':InterpertationLabel' => $InterpertationLabel,
                ':InterpertationDisplay' => $InterpertationDisplay,
                ':InterpertationCode' => $InterpertationCode,
                ':InterpertationSystem' => $InterpertationSystem,
                ':InterpertationConclusion' => $InterpertationConclusion,
                ':normalResult' => $normalResult
            ]);
        }

        $Conn->commit();

    } catch (Exception $e) {
        $Conn->rollBack();
        http_response_code(422);
        echo json_encode(["response" => ["message" => $e->getMessage(), "code" => 422], "metadata" => []]);
        exit;
    } catch (PDOException $e) {
        $Conn->rollBack();
        error_log('[CreateObservation] DB error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Ambil data yang baru dibuat untuk response ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM observation_reference WHERE observationReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $observationReferenceId]);
        $mainData = $stmt->fetch(PDO::FETCH_ASSOC);
        $mainData['observationReferenceId'] = (int) $mainData['observationReferenceId'];
        $mainData['allowSex'] = (int) $mainData['allowSex'];
        $mainData['allowAge'] = (int) $mainData['allowAge'];
        $mainData['status'] = (int) $mainData['status'];
        $mainData['creatBy'] = $mainData['creatBy'] !== null ? (int) $mainData['creatBy'] : null;
        $mainData['updateBy'] = $mainData['updateBy'] !== null ? (int) $mainData['updateBy'] : null;

        $stmt = $Conn->prepare("SELECT * FROM observation_reference_age WHERE observationReferenceId = :id");
        $stmt->execute([':id' => $observationReferenceId]);
        $ageData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ageData as &$age) {
            $age['observationReferenceAgeId'] = (int) $age['observationReferenceAgeId'];
            $age['observationReferenceId'] = (int) $age['observationReferenceId'];
            $age['ageMin'] = (int) $age['ageMin'];
            $age['ageMax'] = $age['ageMax'] !== null ? (int) $age['ageMax'] : null;
        }

        $stmt = $Conn->prepare("SELECT * FROM observation_reference_coded WHERE observationReferenceId = :id");
        $stmt->execute([':id' => $observationReferenceId]);
        $codedData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($codedData as &$coded) {
            $coded['observationReferenceCodedId'] = (int) $coded['observationReferenceCodedId'];
            $coded['observationReferenceId'] = (int) $coded['observationReferenceId'];
            $coded['observationReferenceAgeId'] = $coded['observationReferenceAgeId'] !== null ? (int) $coded['observationReferenceAgeId'] : null;
            $coded['normalResult'] = (int) $coded['normalResult'];
        }

        $stmt = $Conn->prepare("SELECT * FROM observation_reference_range WHERE observationReferenceId = :id");
        $stmt->execute([':id' => $observationReferenceId]);
        $rangeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rangeData as &$range) {
            $range['observationResultRangeId'] = (int) $range['observationResultRangeId'];
            $range['observationReferenceId'] = (int) $range['observationReferenceId'];
            $range['observationReferenceAgeId'] = $range['observationReferenceAgeId'] !== null ? (int) $range['observationReferenceAgeId'] : null;
            $range['normalResult'] = (int) $range['normalResult'];
            $range['minValue'] = $range['minValue'] !== null ? (float) $range['minValue'] : null;
            $range['maxValue'] = $range['maxValue'] !== null ? (float) $range['maxValue'] : null;
        }

        // --- 15. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Referensi pemeriksaan berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "observationReferenceId" => $observationReferenceId,
                "created_at" => $nowUtc . ' GMT'
            ],
            "data" => [
                "observation_reference" => $mainData,
                "observation_reference_age" => $ageData,
                "observation_reference_coded" => $codedData,
                "observation_reference_range" => $rangeData
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[CreateObservation] Fetch response error: ' . $e->getMessage());
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Referensi pemeriksaan berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "observationReferenceId" => $observationReferenceId,
                "created_at" => $nowUtc . ' GMT'
            ],
            "data" => null
        ]);
    }
?>