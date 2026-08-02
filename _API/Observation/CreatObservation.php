<?php
    /**
     * Create Observation Result
     * Endpoint: POST /_API/Observation/CreateObservation.php
     * Header: token, account_token
     * Body: JSON {
     *     "observationReferenceId": 1,
     *     "encounterId": 22,
     *     "medicalPersonelId": 5,
     *     "observationAt": "2026-08-01 10:00:00",
     *     "resultNumeric": 120,       // jika resultType Numeric
     *     "resultDecimal": 120.5,     // jika resultType Decimal
     *     "resultCoded": "A",         // jika resultType Coded
     *     "resultText": "Normal",     // jika resultType Text
     *     "OtherDetail": "Catatan"
     * }
     *
     * - Validasi mandatory: observationReferenceId, encounterId, medicalPersonelId, observationAt.
     * - observationReferenceId harus ada dan status=1.
     * - encounterId dan medicalPersonelId harus valid.
     * - observationAt harus format YYYY-MM-DD HH:MM:SS.
     * - Berdasarkan resultType dari observation_reference, isi field hasil yang sesuai.
     * - Jika allowAge true, hitung usia pasien dan cari observation_reference_age yang cocok.
     * - Jika resultType Coded, cari observation_reference_coded yang cocok untuk InterpertationByCoded.
     * - Jika resultType Numeric/Decimal, cari observation_reference_range yang cocok untuk InterpertationByRange.
     * - allowSex diperhatikan untuk pencarian range/coded.
     * - SatuSehatCode disiapkan (belum dikirim).
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
    $Limiter->check("create_observation", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_observation' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah hasil observasi", "code" => 403], "metadata" => []]);
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
    $observationReferenceId = isset($input['observationReferenceId']) ? (int) $input['observationReferenceId'] : 0;
    $encounterId            = isset($input['encounterId']) ? (int) $input['encounterId'] : 0;
    $medicalPersonelId      = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : 0;
    $observationAt          = isset($input['observationAt']) ? trim($input['observationAt']) : '';
    $resultNumeric          = isset($input['resultNumeric']) && $input['resultNumeric'] !== '' ? (int) $input['resultNumeric'] : null;
    $resultDecimal          = isset($input['resultDecimal']) && $input['resultDecimal'] !== '' ? (float) $input['resultDecimal'] : null;
    $resultCoded            = isset($input['resultCoded']) ? trim($input['resultCoded']) : null;
    $resultText             = isset($input['resultText']) ? trim($input['resultText']) : null;
    $OtherDetail            = isset($input['OtherDetail']) ? trim($input['OtherDetail']) : null;

    // --- 9. Validasi Field Wajib ---
    $required = ['observationReferenceId', 'encounterId', 'medicalPersonelId', 'observationAt'];
    foreach ($required as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 10. Validasi observationAt format ---
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $observationAt);
    if (!$date || $date->format('Y-m-d H:i:s') !== $observationAt) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "observationAt harus format YYYY-MM-DD HH:MM:SS (UTC)", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi observationReferenceId ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM observation_reference WHERE observationReferenceId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $observationReferenceId]);
        $ref = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ref) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "observationReferenceId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultType = $ref['resultType'];
        $allowAge   = (int) $ref['allowAge'];
        $allowSex   = (int) $ref['allowSex'];
    } catch (PDOException $e) {
        error_log('[CreateObservation] Check observationReferenceId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi encounterId dan ambil patientId ---
    $patientId = null;
    try {
        $stmt = $Conn->prepare("SELECT patientId FROM encounter WHERE encounterId = :id LIMIT 1");
        $stmt->execute([':id' => $encounterId]);
        $enc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enc) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "encounterId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
        $patientId = $enc['patientId'];
    } catch (PDOException $e) {
        error_log('[CreateObservation] Check encounterId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi medicalPersonelId ---
    try {
        $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE medicalPersonelId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateObservation] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Ambil data patient (gender, birthDate) ---
    $patientGender = null;
    $patientBirthDate = null;
    try {
        $stmt = $Conn->prepare("SELECT gender, birthDate FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        $pat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pat) {
            $patientGender = $pat['gender'];
            $patientBirthDate = $pat['birthDate'];
        } else {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Data pasien tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateObservation] Get patient data error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 15. Validasi hasil sesuai resultType ---
    if ($resultType === 'Numeric') {
        if ($resultNumeric === null) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "resultNumeric wajib diisi untuk resultType Numeric", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultValue = (float) $resultNumeric;
        $resultDecimal = null;
        $resultCoded = null;
        $resultText = null;
    } elseif ($resultType === 'Decimal') {
        if ($resultDecimal === null) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "resultDecimal wajib diisi untuk resultType Decimal", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultValue = $resultDecimal;
        $resultNumeric = null;
        $resultCoded = null;
        $resultText = null;
    } elseif ($resultType === 'Coded') {
        if (empty($resultCoded)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "resultCoded wajib diisi untuk resultType Coded", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultValue = $resultCoded;
        $resultNumeric = null;
        $resultDecimal = null;
        $resultText = null;
    } elseif ($resultType === 'Text') {
        if (empty($resultText)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "resultText wajib diisi untuk resultType Text", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultValue = $resultText;
        $resultNumeric = null;
        $resultDecimal = null;
        $resultCoded = null;
    } else {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "resultType tidak dikenal", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 16. Inisialisasi variabel interpertasi ---
    $InterpertationByAge = null;
    $InterpertationByCoded = null;
    $InterpertationByRange = null;

    // --- 17. Proses jika allowAge true ---
    if ($allowAge && $patientBirthDate) {
        // Hitung usia pasien pada tanggal observationAt
        $birth = DateTime::createFromFormat('Y-m-d', $patientBirthDate);
        $obs = DateTime::createFromFormat('Y-m-d H:i:s', $observationAt);
        if ($birth && $obs) {
            // Cari age di observation_reference_age
            $stmt = $Conn->prepare("
                SELECT observationReferenceAgeId, ageMin, ageMax, ageUnit
                FROM observation_reference_age
                WHERE observationReferenceId = :refId
                ORDER BY ageMin ASC
            ");
            $stmt->execute([':refId' => $observationReferenceId]);
            $ages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ageInYears = $birth->diff($obs)->y;
            $ageInMonths = $birth->diff($obs)->m + ($birth->diff($obs)->y * 12);
            $ageInDays = $birth->diff($obs)->days;

            foreach ($ages as $age) {
                $unit = $age['ageUnit'];
                $ageValue = null;
                if ($unit === 'Year') $ageValue = $ageInYears;
                elseif ($unit === 'Month') $ageValue = $ageInMonths;
                elseif ($unit === 'Day') $ageValue = $ageInDays;
                else continue;

                $min = $age['ageMin'];
                $max = $age['ageMax'];
                $match = false;
                if ($max === null) {
                    if ($ageValue >= $min) $match = true;
                } else {
                    if ($ageValue >= $min && $ageValue <= $max) $match = true;
                }
                if ($match) {
                    $InterpertationByAge = $age['observationReferenceAgeId'];
                    break;
                }
            }
        }
    }

    // --- 18. Jika resultType Coded, cari coded matching ---
    if ($resultType === 'Coded' && !empty($resultCoded)) {
        $sql = "SELECT `observationReferenceCodedId`
                FROM `observation_reference_coded`
                WHERE `observationReferenceId` = :refId
                AND `valueResult` = :value";
        if ($InterpertationByAge === null) {
            $sql .= " AND `observationReferenceAgeId` IS NULL";
        } else {
            $sql .= " AND `observationReferenceAgeId` = :ageId";
        }
        $sql .= " AND (`groupGender` = :gender OR `groupGender` = 'All')
                LIMIT 1";

        $stmt = $Conn->prepare($sql);
        $params = [
            ':refId' => $observationReferenceId,
            ':value' => $resultCoded,
            ':gender' => $patientGender
        ];
        if ($InterpertationByAge !== null) {
            $params[':ageId'] = $InterpertationByAge;
        }
        $stmt->execute($params);
        $codedMatch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($codedMatch) {
            $InterpertationByCoded = $codedMatch['observationReferenceCodedId'];
        }
    }

    // --- 19. Jika resultType Numeric atau Decimal, cari range matching ---
    if (($resultType === 'Numeric' || $resultType === 'Decimal') && $resultValue !== null) {
        $sql = "SELECT `observationResultRangeId`, `minValue`, `maxValue`, `rangeOperator`
                FROM `observation_reference_range`
                WHERE `observationReferenceId` = :refId";
        if ($InterpertationByAge === null) {
            $sql .= " AND `observationReferenceAgeId` IS NULL";
        } else {
            $sql .= " AND `observationReferenceAgeId` = :ageId";
        }
        $sql .= " AND (`groupGender` = :gender OR `groupGender` = 'All')
                ORDER BY `normalResult` DESC, `observationResultRangeId` ASC";

        $stmt = $Conn->prepare($sql);
        $params = [
            ':refId' => $observationReferenceId,
            ':gender' => $patientGender
        ];
        if ($InterpertationByAge !== null) {
            $params[':ageId'] = $InterpertationByAge;
        }
        $stmt->execute($params);
        $ranges = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ranges as $range) {
            $match = false;
            $op = $range['rangeOperator'];
            $min = ($range['minValue'] !== null) ? (float) $range['minValue'] : null;
            $max = ($range['maxValue'] !== null) ? (float) $range['maxValue'] : null;
            if ($op === 'Less' && $max !== null && $resultValue < $max) $match = true;
            elseif ($op === 'More' && $min !== null && $resultValue > $min) $match = true;
            elseif ($op === 'Between' && $min !== null && $max !== null && $resultValue >= $min && $resultValue <= $max) $match = true;
            if ($match) {
                $InterpertationByRange = $range['observationResultRangeId'];
                break;
            }
        }
    }

    // --- 20. Insert data ke observation_result ---
    try {
        $createdDate = $nowUtc;

        $sql = "INSERT INTO observation_result (
                    observationReferenceId,
                    satuSehatCode,
                    patientId,
                    encounterId,
                    medicalPersonelId,
                    observationAt,
                    resultNumeric,
                    resultDecimal,
                    resultCoded,
                    resultText,
                    InterpertationByAge,
                    InterpertationByCoded,
                    InterpertationByRange,
                    OtherDetail,
                    creatAt,
                    updateAt,
                    creatBy,
                    updateBy
                ) VALUES (
                    :observationReferenceId,
                    :satuSehatCode,
                    :patientId,
                    :encounterId,
                    :medicalPersonelId,
                    :observationAt,
                    :resultNumeric,
                    :resultDecimal,
                    :resultCoded,
                    :resultText,
                    :InterpertationByAge,
                    :InterpertationByCoded,
                    :InterpertationByRange,
                    :OtherDetail,
                    :creatAt,
                    :updateAt,
                    :creatBy,
                    :updateBy
                )";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':observationReferenceId' => $observationReferenceId,
            ':satuSehatCode' => null,
            ':patientId' => $patientId,
            ':encounterId' => $encounterId,
            ':medicalPersonelId' => $medicalPersonelId,
            ':observationAt' => $observationAt,
            ':resultNumeric' => $resultNumeric,
            ':resultDecimal' => $resultDecimal,
            ':resultCoded' => $resultCoded,
            ':resultText' => $resultText,
            ':InterpertationByAge' => $InterpertationByAge,
            ':InterpertationByCoded' => $InterpertationByCoded,
            ':InterpertationByRange' => $InterpertationByRange,
            ':OtherDetail' => $OtherDetail,
            ':creatAt' => $createdDate,
            ':updateAt' => $createdDate,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);

        $newId = (int) $Conn->lastInsertId();

        // --- 21. Ambil data yang baru dibuat untuk response ---
        $stmt = $Conn->prepare("
            SELECT ors.*,
                p.name AS patientName,
                e.EncounterCode,
                mp.name AS medicalPersonelName,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM observation_result ors
            LEFT JOIN patient p ON ors.patientId = p.patientId
            LEFT JOIN encounter e ON ors.encounterId = e.encounterId
            LEFT JOIN medical_personel mp ON ors.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN account ca ON ors.creatBy = ca.accountId
            LEFT JOIN account ua ON ors.updateBy = ua.accountId
            WHERE ors.observationResumeId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $newId]);
        $newData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($newData) {
            $newData['observationResumeId'] = (int) $newData['observationResumeId'];
            $newData['observationReferenceId'] = (int) $newData['observationReferenceId'];
            $newData['patientId'] = (int) $newData['patientId'];
            $newData['encounterId'] = (int) $newData['encounterId'];
            $newData['medicalPersonelId'] = (int) $newData['medicalPersonelId'];
            $newData['InterpertationByAge'] = $newData['InterpertationByAge'] !== null ? (int) $newData['InterpertationByAge'] : null;
            $newData['InterpertationByCoded'] = $newData['InterpertationByCoded'] !== null ? (int) $newData['InterpertationByCoded'] : null;
            $newData['InterpertationByRange'] = $newData['InterpertationByRange'] !== null ? (int) $newData['InterpertationByRange'] : null;
            $newData['creatBy'] = $newData['creatBy'] !== null ? (int) $newData['creatBy'] : null;
            $newData['updateBy'] = $newData['updateBy'] !== null ? (int) $newData['updateBy'] : null;
            // Hapus null values untuk nama
            if ($newData['patientName'] === null) unset($newData['patientName']);
            if ($newData['EncounterCode'] === null) unset($newData['EncounterCode']);
            if ($newData['medicalPersonelName'] === null) unset($newData['medicalPersonelName']);
            if ($newData['createdName'] === null) unset($newData['createdName']);
            if ($newData['updatedName'] === null) unset($newData['updatedName']);
        }

        // 21. Sinkronisasi ke SATUSEHAT
        $satusehatSyncStatus = 'skipped';
        $satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
        $satusehatCode = null;

        try {
            // Ambil data yang diperlukan untuk payload SATUSEHAT
            $stmt = $Conn->prepare("
                SELECT 
                    ors.*,
                    p.satuSehatCode AS patientSatuSehat,
                    e.satuSehatCode AS encounterSatuSehat,
                    mp.id_practitioner,
                    ref.categoryCode,
                    ref.categoryDisplay,
                    ref.categorySystem,
                    ref.observationCode,
                    ref.observationDisplay,
                    ref.observationSystem,
                    ref.unitCode,
                    ref.unitDisplay,
                    ref.unitSystem,
                    coded.codeResult AS interpCode,
                    coded.displayResult AS interpDisplay,
                    coded.systemResult AS interpSystem,
                    rng.InterpertationCode AS rangeCode,
                    rng.InterpertationDisplay AS rangeDisplay,
                    rng.InterpertationSystem AS rangeSystem,
                    p.gender AS patientGender
                FROM observation_result ors
                LEFT JOIN patient p ON ors.patientId = p.patientId
                LEFT JOIN encounter e ON ors.encounterId = e.encounterId
                LEFT JOIN medical_personel mp ON ors.medicalPersonelId = mp.medicalPersonelId
                LEFT JOIN observation_reference ref ON ors.observationReferenceId = ref.observationReferenceId
                LEFT JOIN observation_reference_coded coded ON ors.InterpertationByCoded = coded.observationReferenceCodedId
                LEFT JOIN observation_reference_range rng ON ors.InterpertationByRange = rng.observationResultRangeId
                WHERE ors.observationResumeId = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $newId]);
            $syncData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($syncData) {
                $canSync = true;
                $missing = [];

                // Status selalu final
                $status = 'final';

                // Category
                if (empty($syncData['categoryCode']) || empty($syncData['categoryDisplay']) || empty($syncData['categorySystem'])) {
                    $canSync = false;
                    $missing[] = 'Kategori tidak lengkap (code, display, system)';
                }

                // Observation code
                if (empty($syncData['observationCode']) || empty($syncData['observationDisplay']) || empty($syncData['observationSystem'])) {
                    $canSync = false;
                    $missing[] = 'Kode observasi tidak lengkap (code, display, system)';
                }

                // Unit (opsional, tapi jika ada harus lengkap)
                if (!empty($syncData['unitCode']) || !empty($syncData['unitDisplay']) || !empty($syncData['unitSystem'])) {
                    if (empty($syncData['unitCode']) || empty($syncData['unitDisplay']) || empty($syncData['unitSystem'])) {
                        $canSync = false;
                        $missing[] = 'Unit tidak lengkap (code, display, system)';
                    }
                }

                // Patient satuSehatCode
                if (empty($syncData['patientSatuSehat'])) {
                    $canSync = false;
                    $missing[] = 'Pasien tidak memiliki satuSehatCode';
                }

                // Encounter satuSehatCode
                if (empty($syncData['encounterSatuSehat'])) {
                    $canSync = false;
                    $missing[] = 'Kunjungan tidak memiliki satuSehatCode';
                }

                // Interpertasi (jika ada, harus lengkap)
                $hasInterpretation = false;
                $interpCode = null;
                $interpDisplay = null;
                $interpSystem = null;

                if (!empty($syncData['interpCode']) && !empty($syncData['interpDisplay']) && !empty($syncData['interpSystem'])) {
                    $interpCode = $syncData['interpCode'];
                    $interpDisplay = $syncData['interpDisplay'];
                    $interpSystem = $syncData['interpSystem'];
                    $hasInterpretation = true;
                } elseif (!empty($syncData['rangeCode']) && !empty($syncData['rangeDisplay']) && !empty($syncData['rangeSystem'])) {
                    $interpCode = $syncData['rangeCode'];
                    $interpDisplay = $syncData['rangeDisplay'];
                    $interpSystem = $syncData['rangeSystem'];
                    $hasInterpretation = true;
                }

                if ($hasInterpretation && (empty($interpCode) || empty($interpDisplay) || empty($interpSystem))) {
                    $canSync = false;
                    $missing[] = 'Interpertasi tidak lengkap (code, display, system)';
                }

                if ($canSync) {
                    // Siapkan payload dasar
                    $payload = [
                        'resourceType' => 'Observation',
                        'status' => $status,
                        'category' => [
                            [
                                'coding' => [
                                    [
                                        'system' => $syncData['categorySystem'],
                                        'code' => $syncData['categoryCode'],
                                        'display' => $syncData['categoryDisplay']
                                    ]
                                ]
                            ]
                        ],
                        'code' => [
                            'coding' => [
                                [
                                    'system' => $syncData['observationSystem'],
                                    'code' => $syncData['observationCode'],
                                    'display' => $syncData['observationDisplay']
                                ]
                            ]
                        ],
                        'subject' => [
                            'reference' => 'Patient/' . $syncData['patientSatuSehat']
                        ],
                        'encounter' => [
                            'reference' => 'Encounter/' . $syncData['encounterSatuSehat']
                        ],
                        'effectiveDateTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime($syncData['observationAt'])),
                        'issued' => gmdate('Y-m-d\TH:i:s\Z'),
                        'performer' => [
                            [
                                'reference' => 'Practitioner/' . $syncData['id_practitioner']
                            ]
                        ]
                    ];

                    // Tambahkan value sesuai resultType
                    $resultType = $ref['resultType'];
                    if ($resultType === 'Numeric' || $resultType === 'Decimal') {
                        $value = (float) ($syncData['resultDecimal'] ?: $syncData['resultNumeric']);
                        $payload['valueQuantity'] = [
                            'value' => $value,
                            'unit' => $syncData['unitDisplay'],
                            'system' => $syncData['unitSystem'],
                            'code' => $syncData['unitCode']
                        ];
                    } elseif ($resultType === 'Coded') {
                        // Untuk Coded, cari system dari coded yang cocok
                        if ($syncData['InterpertationByCoded']) {
                            $stmt2 = $Conn->prepare("SELECT systemResult, codeResult, displayResult FROM observation_reference_coded WHERE observationReferenceCodedId = :id LIMIT 1");
                            $stmt2->execute([':id' => $syncData['InterpertationByCoded']]);
                            $codedDetail = $stmt2->fetch(PDO::FETCH_ASSOC);
                            if ($codedDetail) {
                                $payload['valueCodeableConcept'] = [
                                    'coding' => [
                                        [
                                            'system' => $codedDetail['systemResult'],
                                            'code' => $codedDetail['codeResult'],
                                            'display' => $codedDetail['displayResult']
                                        ]
                                    ],
                                    'text' => $syncData['resultCoded']
                                ];
                            } else {
                                $payload['valueCodeableConcept'] = [
                                    'text' => $syncData['resultCoded']
                                ];
                            }
                        } else {
                            $payload['valueCodeableConcept'] = [
                                'text' => $syncData['resultCoded']
                            ];
                        }
                    } elseif ($resultType === 'Text') {
                        $payload['valueString'] = $syncData['resultText'];
                    }

                    // Tambahkan interpretation jika ada
                    if ($hasInterpretation) {
                        $payload['interpretation'] = [
                            [
                                'coding' => [
                                    [
                                        'system' => $interpSystem,
                                        'code' => $interpCode,
                                        'display' => $interpDisplay
                                    ]
                                ]
                            ]
                        ];
                    }

                    // Tambahkan note jika ada
                    if (!empty($syncData['OtherDetail'])) {
                        $payload['note'] = [
                            [
                                'text' => $syncData['OtherDetail']
                            ]
                        ];
                    }

                    // Kirim ke SATUSEHAT
                    $satusehatSyncStatus = 'failed';
                    $satusehatMessage = 'Gagal mengirim ke SATUSEHAT';

                    $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
                    $credStmt->execute();
                    $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
                    if ($credential) {
                        $tokenResult = generateTokenSatusehat($Conn);
                        if ($tokenResult['status'] === 'success') {
                            $accessToken = $tokenResult['token'];
                            $baseUrl = rtrim($credential['baseUrl'], '/');

                            $ch = curl_init();
                            curl_setopt_array($ch, [
                                CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Observation',
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
                                    $satusehatCode = $result['id'];
                                    // Update observation_result.satuSehatCode
                                    $updStmt = $Conn->prepare("UPDATE observation_result SET satuSehatCode = :code WHERE observationResumeId = :id");
                                    $updStmt->execute([':code' => $satusehatCode, ':id' => $newId]);
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
                } else {
                    $satusehatMessage = 'Syarat tidak terpenuhi: ' . implode(', ', $missing);
                    $satusehatSyncStatus = 'skipped';
                }
            }
        } catch (Exception $e) {
            error_log('[CreateObservation] SATUSEHAT integration error: ' . $e->getMessage());
            $satusehatMessage = 'Exception: ' . $e->getMessage();
            $satusehatSyncStatus = 'failed';
        }

        // Perbarui data response dengan satuSehatCode dan status sync
        if ($newData) {
            $newData['satuSehatCode'] = $satusehatCode;
        }

        // --- 22. Response Sukses (dengan tambahan informasi sync) ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Hasil observasi berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "observationResumeId" => $newId,
                "satuSehatCode" => $satusehatCode,
                "satusehat_sync" => [
                    "status" => $satusehatSyncStatus,
                    "message" => $satusehatMessage
                ],
                "created_at" => $createdDate . ' GMT'
            ],
            "data" => $newData
        ]);

    } catch (PDOException $e) {
        error_log('[CreateObservation] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>