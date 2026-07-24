<?php
    /**
     * Detail Encounter
     * Endpoint: GET /_API/Encounter/DetailEncounter.php?encounterId={id}
     * Header: token, account_token
     * 
     * Menampilkan detail encounter termasuk:
     * - Data encounter (dengan nama pasien, poli, kelas)
     * - Riwayat status (encounter_status)
     * - Daftar performer (encounter_performer) dengan nama tenaga medis
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
    $Limiter->check("detail_encounter", 10, 60);

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

    // --- 6. Validasi Parameter encounterId ---
    if (!isset($_GET['encounterId']) || !is_numeric($_GET['encounterId']) || (int)$_GET['encounterId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter encounterId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $encounterId = (int) $_GET['encounterId'];

    // --- 7. Validasi Token & Permission ---
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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'detail_encounter' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat detail kunjungan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[DetailEncounter] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Query utama detail encounter dengan JOIN ---
    try {
        $sql = "SELECT
                    e.encounterId,
                    e.EncounterCode,
                    e.satuSehatCode,
                    e.registrationDatetime,
                    e.patientId,
                    p.name AS patientName,
                    p.noMedicalRecord,
                    p.phone AS patientPhone,
                    e.reasonForVisit,
                    e.chiefComplaint,
                    e.priority,
                    e.destination,
                    e.polyclinicId,
                    pl.name AS polyclinicName,
                    e.inpatientClassId,
                    ic.inpatientClassName,
                    e.inpatientRoomId,
                    ir.inpatientRoomName,
                    e.inpatientBedId,
                    ib.inpatientBedCode,
                    e.assurance,
                    e.assuranceName,
                    e.assuranceNumber,
                    e.emergencyContactName,
                    e.emergencyContactPhone,
                    e.status,
                    e.creatAt,
                    e.updateAt,
                    e.creatBy,
                    ca.name AS createdName,
                    e.updateBy,
                    ua.name AS updatedName
                FROM encounter e
                LEFT JOIN patient p ON e.patientId = p.patientId
                LEFT JOIN polyclinic pl ON e.polyclinicId = pl.polyclinicId
                LEFT JOIN inpatient_class ic ON e.inpatientClassId = ic.inpatientClassId
                LEFT JOIN inpatient_room ir ON e.inpatientRoomId = ir.inpatientRoomId
                LEFT JOIN inpatient_bed ib ON e.inpatientBedId = ib.inpatientBedId
                LEFT JOIN account ca ON e.creatBy = ca.accountId
                LEFT JOIN account ua ON e.updateBy = ua.accountId
                WHERE e.encounterId = :id LIMIT 1";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([':id' => $encounterId]);
        $encounter = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$encounter) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Kunjungan tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }

        // Format encounter data
        $encounter['encounterId'] = (int) $encounter['encounterId'];
        $encounter['patientId'] = (int) $encounter['patientId'];
        $encounter['polyclinicId'] = $encounter['polyclinicId'] !== null ? (int) $encounter['polyclinicId'] : null;
        $encounter['inpatientClassId'] = $encounter['inpatientClassId'] !== null ? (int) $encounter['inpatientClassId'] : null;
        $encounter['inpatientRoomId'] = $encounter['inpatientRoomId'] !== null ? (int) $encounter['inpatientRoomId'] : null;
        $encounter['inpatientBedId'] = $encounter['inpatientBedId'] !== null ? (int) $encounter['inpatientBedId'] : null;
        $encounter['assurance'] = (int) $encounter['assurance'];
        $encounter['creatBy'] = $encounter['creatBy'] !== null ? (int) $encounter['creatBy'] : null;
        $encounter['updateBy'] = $encounter['updateBy'] !== null ? (int) $encounter['updateBy'] : null;

        // Hapus null values
        if ($encounter['patientName'] === null) unset($encounter['patientName']);
        if ($encounter['polyclinicName'] === null) unset($encounter['polyclinicName']);
        if ($encounter['inpatientClassName'] === null) unset($encounter['inpatientClassName']);
        if ($encounter['inpatientRoomName'] === null) unset($encounter['inpatientRoomName']);
        if ($encounter['inpatientBedCode'] === null) unset($encounter['inpatientBedCode']);
        if ($encounter['createdName'] === null) unset($encounter['createdName']);
        if ($encounter['updatedName'] === null) unset($encounter['updatedName']);

    } catch (PDOException $e) {
        error_log('[DetailEncounter] Query encounter error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 9. Ambil riwayat status (encounter_status) ---
    try {
        $stmt = $Conn->prepare("
            SELECT encounterStatusId, encounterStatus, updateAt, updateBy, a.name AS updatedName
            FROM encounter_status es
            LEFT JOIN account a ON es.updateBy = a.accountId
            WHERE es.encounterId = :id
            ORDER BY es.updateAt ASC
        ");
        $stmt->execute([':id' => $encounterId]);
        $statusHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($statusHistory as &$row) {
            $row['encounterStatusId'] = (int) $row['encounterStatusId'];
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;
            if ($row['updatedName'] === null) unset($row['updatedName']);
        }
        unset($row);
    } catch (PDOException $e) {
        error_log('[DetailEncounter] Query status error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 10. Ambil performer (encounter_performer) dengan nama tenaga medis ---
    try {
        $stmt = $Conn->prepare("
            SELECT ep.encounterPerformerId,
                ep.performerType,
                ep.medicalPersonelId,
                mp.name AS medicalPersonelName,
                mp.medicalPersonelCategory,
                mp.id_practitioner,
                ep.updateAt,
                ep.updateBy,
                a.name AS updatedName
            FROM encounter_performer ep
            LEFT JOIN medical_personel mp ON ep.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN account a ON ep.updateBy = a.accountId
            WHERE ep.encounterId = :id
            ORDER BY ep.performerType ASC
        ");
        $stmt->execute([':id' => $encounterId]);
        $performers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($performers as &$row) {
            $row['encounterPerformerId'] = (int) $row['encounterPerformerId'];
            $row['medicalPersonelId'] = (int) $row['medicalPersonelId'];
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;
            if ($row['medicalPersonelName'] === null) unset($row['medicalPersonelName']);
            if ($row['medicalPersonelCategory'] === null) unset($row['medicalPersonelCategory']);
            if ($row['id_practitioner'] === null) unset($row['id_practitioner']);
            if ($row['updatedName'] === null) unset($row['updatedName']);
        }
        unset($row);
    } catch (PDOException $e) {
        error_log('[DetailEncounter] Query performer error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 11. Response ---
    http_response_code(200);
    echo json_encode([
        "response" => [
            "message" => "Detail kunjungan berhasil diambil",
            "code" => 200
        ],
        "metadata" => [
            "retrieved_at" => $nowUtc . ' GMT'
        ],
        "data" => [
            "encounter" => $encounter,
            "status_history" => $statusHistory,
            "performers" => $performers
        ]
    ]);
?>