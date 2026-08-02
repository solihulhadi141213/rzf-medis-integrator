<?php
    /**
     * Detail Observation Result
     * Endpoint: GET /_API/Observation/DetailObservation.php?observationResumeId={id}
     * Header: token, account_token
     *
     * Menampilkan detail hasil observasi berdasarkan observationResumeId.
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
    $Limiter->check("detail_observation", 10, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

    // --- 6. Validasi Parameter observationResumeId ---
    if (!isset($_GET['observationResumeId']) || !is_numeric($_GET['observationResumeId']) || (int)$_GET['observationResumeId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter observationResumeId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $observationResumeId = (int) $_GET['observationResumeId'];

    // --- 7. Validasi Token & Permission ---
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
            echo json_encode(["response" => ["message" => "Token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }
        if ($tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "Token sudah kedaluwarsa", "code" => 401], "metadata" => []]);
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
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }
        $loggedInAccountId = (int) $accountTokenData['accountId'];

        // Validasi Permission (fitur detail_observation)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'detail_observation']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur detail_observation tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat detail hasil observasi", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[DetailObservation] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Query detail dengan JOIN ---
    try {
        $sql = "SELECT
                    o.observationResumeId,
                    o.observationReferenceId,
                    ref.observationName,
                    ref.observationCode,
                    ref.observationDisplay,
                    ref.observationSystem,
                    ref.categoryName,
                    ref.categoryCode,
                    ref.categoryDisplay,
                    ref.categorySystem,
                    ref.unitName,
                    ref.unitCode,
                    ref.unitDisplay,
                    ref.unitSystem,
                    ref.resultType,
                    ref.allowSex,
                    ref.allowAge,
                    o.satuSehatCode,
                    o.patientId,
                    p.name AS patientName,
                    p.noMedicalRecord,
                    p.gender AS patientGender,
                    p.birthDate AS patientBirthDate,
                    p.phone AS patientPhone,
                    o.encounterId,
                    e.EncounterCode,
                    e.registrationDatetime,
                    e.status AS encounterStatus,
                    o.medicalPersonelId,
                    mp.name AS medicalPersonelName,
                    mp.medicalPersonelCategory,
                    mp.nik AS medicalPersonelNik,
                    o.observationAt,
                    o.resultNumeric,
                    o.resultDecimal,
                    o.resultCoded,
                    o.resultText,
                    o.InterpertationByAge,
                    age.ageCategory AS ageCategory,
                    age.ageMin AS ageMin,
                    age.ageMax AS ageMax,
                    age.ageUnit AS ageUnit,
                    o.InterpertationByCoded,
                    coded.labelResult AS codedLabel,
                    coded.displayResult AS codedDisplay,
                    coded.codeResult AS codedCode,
                    coded.systemResult AS codedSystem,
                    coded.valueResult AS codedValue,
                    coded.normalResult AS codedNormal,
                    o.InterpertationByRange,
                    rng.InterpertationLabel AS rangeLabel,
                    rng.InterpertationDisplay AS rangeDisplay,
                    rng.InterpertationCode AS rangeCode,
                    rng.InterpertationSystem AS rangeSystem,
                    rng.InterpertationConclusion AS rangeConclusion,
                    rng.rangeOperator,
                    rng.minValue,
                    rng.maxValue,
                    rng.normalResult AS rangeNormal,
                    rng.groupGender AS rangeGender,
                    o.OtherDetail,
                    o.creatAt,
                    o.updateAt,
                    o.creatBy,
                    cAccount.name AS createdName,
                    o.updateBy,
                    uAccount.name AS updatedName
                FROM observation_result o
                LEFT JOIN patient p ON o.patientId = p.patientId
                LEFT JOIN encounter e ON o.encounterId = e.encounterId
                LEFT JOIN medical_personel mp ON o.medicalPersonelId = mp.medicalPersonelId
                LEFT JOIN observation_reference ref ON o.observationReferenceId = ref.observationReferenceId
                LEFT JOIN observation_reference_age age ON o.InterpertationByAge = age.observationReferenceAgeId
                LEFT JOIN observation_reference_coded coded ON o.InterpertationByCoded = coded.observationReferenceCodedId
                LEFT JOIN observation_reference_range rng ON o.InterpertationByRange = rng.observationResultRangeId
                LEFT JOIN account cAccount ON o.creatBy = cAccount.accountId
                LEFT JOIN account uAccount ON o.updateBy = uAccount.accountId
                WHERE o.observationResumeId = :id
                LIMIT 1";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([':id' => $observationResumeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode([
                "response" => ["message" => "Data hasil observasi tidak ditemukan", "code" => 404],
                "metadata" => []
            ]);
            exit;
        }

        // --- 9. Format data ---
        $row['observationResumeId'] = (int) $row['observationResumeId'];
        $row['observationReferenceId'] = (int) $row['observationReferenceId'];
        $row['patientId'] = (int) $row['patientId'];
        $row['encounterId'] = (int) $row['encounterId'];
        $row['medicalPersonelId'] = (int) $row['medicalPersonelId'];
        $row['InterpertationByAge'] = $row['InterpertationByAge'] !== null ? (int) $row['InterpertationByAge'] : null;
        $row['InterpertationByCoded'] = $row['InterpertationByCoded'] !== null ? (int) $row['InterpertationByCoded'] : null;
        $row['InterpertationByRange'] = $row['InterpertationByRange'] !== null ? (int) $row['InterpertationByRange'] : null;
        $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
        $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;
        $row['resultNumeric'] = $row['resultNumeric'] !== null ? (int) $row['resultNumeric'] : null;
        $row['resultDecimal'] = $row['resultDecimal'] !== null ? (float) $row['resultDecimal'] : null;
        $row['ageMin'] = $row['ageMin'] !== null ? (int) $row['ageMin'] : null;
        $row['ageMax'] = $row['ageMax'] !== null ? (int) $row['ageMax'] : null;
        $row['minValue'] = $row['minValue'] !== null ? (float) $row['minValue'] : null;
        $row['maxValue'] = $row['maxValue'] !== null ? (float) $row['maxValue'] : null;
        $row['allowSex'] = (int) $row['allowSex'];
        $row['allowAge'] = (int) $row['allowAge'];
        $row['codedNormal'] = (int) $row['codedNormal'];
        $row['rangeNormal'] = (int) $row['rangeNormal'];

        // Hapus null values untuk field yang tidak diperlukan
        $nullFields = [
            'patientName', 'noMedicalRecord', 'patientGender', 'patientBirthDate', 'patientPhone',
            'EncounterCode', 'registrationDatetime', 'encounterStatus',
            'medicalPersonelName', 'medicalPersonelCategory', 'medicalPersonelNik',
            'observationName', 'observationCode', 'observationDisplay', 'observationSystem',
            'categoryName', 'categoryCode', 'categoryDisplay', 'categorySystem',
            'unitName', 'unitCode', 'unitDisplay', 'unitSystem',
            'ageCategory', 'ageMin', 'ageMax', 'ageUnit',
            'codedLabel', 'codedDisplay', 'codedCode', 'codedSystem', 'codedValue',
            'rangeLabel', 'rangeDisplay', 'rangeCode', 'rangeSystem', 'rangeConclusion',
            'rangeOperator', 'minValue', 'maxValue', 'rangeGender',
            'createdName', 'updatedName'
        ];
        foreach ($nullFields as $field) {
            if (isset($row[$field]) && $row[$field] === null) {
                unset($row[$field]);
            }
        }

        // --- 10. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Detail hasil observasi berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "observationResumeId" => $observationResumeId,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $row
        ]);

    } catch (PDOException $e) {
        error_log('[DetailObservation] Query error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>