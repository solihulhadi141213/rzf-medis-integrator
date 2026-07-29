<?php
    /**
     * Detail Procedure
     * Endpoint: GET /_API/Procedure/DetailProcedure.php?procedureId={id}
     * Header: token, account_token
     *
     * Menampilkan detail prosedur tindakan (procedure_encounter) beserta informasi terkait:
     * - Data procedure_encounter
     * - Informasi pasien (patient)
     * - Informasi kunjungan (encounter)
     * - Informasi referensi tindakan (procedure_reference)
     * - Daftar performer (procedure_performer)
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
    $Limiter->check("detail_procedure", 10, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            "response" => ["message" => "Metode request tidak diizinkan", "code" => 405],
            "metadata" => []
        ]);
        exit;
    }

    // --- 5. Validasi Header Token & Account Token ---
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

    // --- 6. Validasi Parameter procedureId ---
    if (!isset($_GET['procedureId']) || !is_numeric($_GET['procedureId']) || (int)$_GET['procedureId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter procedureId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $procedureId = (int) $_GET['procedureId'];

    // --- 7. Validasi Token dan Permission ---
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

        // Validasi Permission (fitur detail_procedure)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'detail_procedure']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur detail_procedure tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat detail tindakan", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[DetailProcedure] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Query detail procedure_encounter dengan JOIN terkait ---
    try {
        $sql = "SELECT
                    pe.procedureId,
                    pe.patientId,
                    pat.name AS patientName,
                    pat.noMedicalRecord,
                    pat.phone AS patientPhone,
                    pat.gender AS patientGender,
                    pat.birthDate AS patientBirthDate,
                    pat.nik AS patientNik,
                    pe.encounterId,
                    e.EncounterCode,
                    e.registrationDatetime,
                    e.status AS encounterStatus,
                    e.priority AS encounterPriority,
                    e.destination AS encounterDestination,
                    pe.satusehatCode,
                    pe.procedureStart,
                    pe.procedureEnd,
                    pe.procedureReferenceId,
                    pr.procedureName,
                    pr.procedureCode AS refProcedureCode,
                    pr.procedureDisplay AS refProcedureDisplay,
                    pr.procedureSystem AS refProcedureSystem,
                    pr.bodySiteName,
                    pr.bodySiteCode,
                    pr.bodySiteDisplay,
                    pr.bodySiteSystem,
                    pr.icd9Code,
                    pr.icd9Description,
                    pe.resonReference,
                    pe.resonCode,
                    pe.resonDisplay,
                    pe.postProcedure,
                    pe.creatAt,
                    pe.updateAt,
                    pe.creatBy,
                    cAccount.name AS createdName,
                    pe.updateBy,
                    uAccount.name AS updatedName
                FROM procedure_encounter pe
                LEFT JOIN patient pat ON pe.patientId = pat.patientId
                LEFT JOIN encounter e ON pe.encounterId = e.encounterId
                LEFT JOIN procedure_reference pr ON pe.procedureReferenceId = pr.procedureReferenceId
                LEFT JOIN account cAccount ON pe.creatBy = cAccount.accountId
                LEFT JOIN account uAccount ON pe.updateBy = uAccount.accountId
                WHERE pe.procedureId = :id
                LIMIT 1";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([':id' => $procedureId]);
        $procedure = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$procedure) {
            http_response_code(404);
            echo json_encode([
                "response" => ["message" => "Data tindakan tidak ditemukan", "code" => 404],
                "metadata" => []
            ]);
            exit;
        }

        // Format data procedure
        $procedure['procedureId'] = (int) $procedure['procedureId'];
        $procedure['patientId'] = (int) $procedure['patientId'];
        $procedure['encounterId'] = (int) $procedure['encounterId'];
        $procedure['procedureReferenceId'] = (int) $procedure['procedureReferenceId'];
        $procedure['creatBy'] = $procedure['creatBy'] !== null ? (int) $procedure['creatBy'] : null;
        $procedure['updateBy'] = $procedure['updateBy'] !== null ? (int) $procedure['updateBy'] : null;

        // Hapus null values yang tidak perlu
        $nullFields = ['patientName', 'noMedicalRecord', 'patientPhone', 'patientGender', 'patientBirthDate', 'patientNik',
                    'EncounterCode', 'registrationDatetime', 'encounterStatus', 'encounterPriority', 'encounterDestination',
                    'procedureName', 'refProcedureCode', 'refProcedureDisplay', 'refProcedureSystem',
                    'bodySiteName', 'bodySiteCode', 'bodySiteDisplay', 'bodySiteSystem',
                    'icd9Code', 'icd9Description', 'createdName', 'updatedName'];
        foreach ($nullFields as $field) {
            if ($procedure[$field] === null) {
                unset($procedure[$field]);
            }
        }

    } catch (PDOException $e) {
        error_log('[DetailProcedure] Query procedure error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 9. Query daftar performer ---
    try {
        $stmt = $Conn->prepare("
            SELECT pp.procedurePerformerId,
                pp.procedureId,
                pp.medicalPersonelId,
                mp.name AS medicalPersonelName,
                mp.medicalPersonelCategory,
                pp.performerType,
                pp.id_practitioner,
                pp.performerNik,
                pp.performerName,
                pp.performerNote
            FROM procedure_performer pp
            LEFT JOIN medical_personel mp ON pp.medicalPersonelId = mp.medicalPersonelId
            WHERE pp.procedureId = :id
            ORDER BY pp.performerType ASC
        ");
        $stmt->execute([':id' => $procedureId]);
        $performers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($performers as &$perf) {
            $perf['procedurePerformerId'] = (int) $perf['procedurePerformerId'];
            $perf['procedureId'] = (int) $perf['procedureId'];
            $perf['medicalPersonelId'] = $perf['medicalPersonelId'] !== null ? (int) $perf['medicalPersonelId'] : null;
            if ($perf['medicalPersonelName'] === null) unset($perf['medicalPersonelName']);
            if ($perf['medicalPersonelCategory'] === null) unset($perf['medicalPersonelCategory']);
        }
        unset($perf);

    } catch (PDOException $e) {
        error_log('[DetailProcedure] Query performer error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 10. Response Sukses ---
    http_response_code(200);
    echo json_encode([
        "response" => [
            "message" => "Detail tindakan berhasil diambil",
            "code" => 200
        ],
        "metadata" => [
            "retrieved_at" => $nowUtc . ' GMT'
        ],
        "data" => [
            "procedure" => $procedure,
            "performers" => $performers
        ]
    ]);
?>