<?php
    /**
     * Update Encounter
     * Endpoint: PUT /_API/Encounter/UpdateEncounter.php?encounterId={id}
     * Header: token, account_token
     * Body: JSON (lihat contoh)
     * 
     * - Jika EncounterCode kosong, generate baru (unik).
     * - Jika satuSehatCode kosong, kirim data ke SATUSEHAT (POST) dan dapatkan ID baru.
     * - Jika satuSehatCode terisi, update ke SATUSEHAT (PUT).
     * - Update performer (tipe ADM) jika medicalPersonelId diberikan.
     * - Insert riwayat status jika status berubah.
     */

    // --- 1. Response Header ---
    header('Content-Type: application/json');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: PUT");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    date_default_timezone_set('UTC');

    // --- 2. Include Dependencies ---
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_encounter", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    // --- 6. Validasi Parameter encounterId ---
    if (!isset($_GET['encounterId']) || !is_numeric($_GET['encounterId']) || (int)$_GET['encounterId'] <= 0) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Parameter encounterId wajib diisi dengan angka positif", "code" => 400], "metadata" => []]);
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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_encounter' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah kunjungan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateEncounter] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data encounter yang akan diupdate ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM encounter WHERE encounterId = :id LIMIT 1");
        $stmt->execute([':id' => $encounterId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Kunjungan tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateEncounter] Fetch data error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 9. Parse JSON Body ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Invalid JSON payload", "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 10. Ambil nilai dari body, gunakan nilai lama jika tidak ada ---
    $EncounterCode = isset($input['encounterCode']) ? trim($input['encounterCode']) : $existingData['EncounterCode'];
    $satuSehatCode = isset($input['satuSehatCode']) ? trim($input['satuSehatCode']) : $existingData['satuSehatCode'];
    $registrationDatetime = isset($input['registrationDatetime']) ? trim($input['registrationDatetime']) : $existingData['registrationDatetime'];
    $patientId = isset($input['patientId']) ? (int) $input['patientId'] : (int) $existingData['patientId'];
    $reasonForVisit = isset($input['reasonForVisit']) ? trim($input['reasonForVisit']) : $existingData['reasonForVisit'];
    $chiefComplaint = isset($input['chiefComplaint']) ? trim($input['chiefComplaint']) : $existingData['chiefComplaint'];
    $priority = isset($input['priority']) ? trim($input['priority']) : $existingData['priority'];
    $destination = isset($input['destination']) ? trim($input['destination']) : $existingData['destination'];
    $polyclinicId = isset($input['polyclinicId']) ? (int) $input['polyclinicId'] : (int) $existingData['polyclinicId'];
    $inpatientClassId = isset($input['inpatientClassId']) ? (int) $input['inpatientClassId'] : (int) $existingData['inpatientClassId'];
    $inpatientRoomId = isset($input['inpatientRoomId']) ? (int) $input['inpatientRoomId'] : (int) $existingData['inpatientRoomId'];
    $inpatientBedId = isset($input['inpatientBedId']) ? (int) $input['inpatientBedId'] : (int) $existingData['inpatientBedId'];
    $assurance = isset($input['assurance']) ? (int) $input['assurance'] : (int) $existingData['assurance'];
    $assuranceName = isset($input['assuranceName']) ? trim($input['assuranceName']) : $existingData['assuranceName'];
    $assuranceNumber = isset($input['assuranceNumber']) ? trim($input['assuranceNumber']) : $existingData['assuranceNumber'];
    $emergencyContactName = isset($input['emergencyContactName']) ? trim($input['emergencyContactName']) : $existingData['emergencyContactName'];
    $emergencyContactPhone = isset($input['emergencyContactPhone']) ? trim($input['emergencyContactPhone']) : $existingData['emergencyContactPhone'];
    $status = isset($input['status']) ? trim($input['status']) : $existingData['status'];
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : 0;

    // --- 11. Validasi Field Wajib ---
    if ($patientId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "patientId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($medicalPersonelId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalPersonelId wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi patientId ---
    try {
        $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "patientId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateEncounter] Check patientId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi polyclinicId (jika diisi) ---
    if ($polyclinicId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT polyclinicId FROM polyclinic WHERE polyclinicId = :id LIMIT 1");
            $stmt->execute([':id' => $polyclinicId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "polyclinicId tidak ditemukan", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateEncounter] Check polyclinicId error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        $polyclinicId = null;
    }

    // --- 14. Validasi Inpatient Rawat Inap ---
    if ($inpatientClassId > 0 || $inpatientRoomId > 0 || $inpatientBedId > 0) {
        if ($inpatientClassId <= 0 || $inpatientRoomId <= 0 || $inpatientBedId <= 0) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "inpatientClassId, inpatientRoomId, dan inpatientBedId harus diisi semua jika salah satunya diisi", "code" => 422], "metadata" => []]);
            exit;
        }
        // Validasi class
        try {
            $stmt = $Conn->prepare("SELECT inpatientClassId FROM inpatient_class WHERE inpatientClassId = :id LIMIT 1");
            $stmt->execute([':id' => $inpatientClassId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "inpatientClassId tidak ditemukan", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateEncounter] Check class error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
        // Validasi room (harus berada di class yang sama)
        try {
            $stmt = $Conn->prepare("SELECT inpatientRoomId FROM inpatient_room WHERE inpatientRoomId = :id AND inpatientClassId = :classId LIMIT 1");
            $stmt->execute([':id' => $inpatientRoomId, ':classId' => $inpatientClassId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "inpatientRoomId tidak ditemukan atau tidak sesuai dengan inpatientClassId", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateEncounter] Check room error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
        // Validasi bed (harus berada di room yang sama)
        try {
            $stmt = $Conn->prepare("SELECT inpatientBedId FROM inpatient_bed WHERE inpatientBedId = :id AND inpatientRoomId = :roomId LIMIT 1");
            $stmt->execute([':id' => $inpatientBedId, ':roomId' => $inpatientRoomId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "inpatientBedId tidak ditemukan atau tidak sesuai dengan inpatientRoomId", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateEncounter] Check bed error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        $inpatientClassId = null;
        $inpatientRoomId = null;
        $inpatientBedId = null;
    }

    // --- 15. Validasi medicalPersonelId ---
    try {
        $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE medicalPersonelId = :id LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateEncounter] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 16. Validasi priority, destination, status ---
    $allowedPriority = ['R','UR','EM','EL'];
    $allowedDestination = ['AMB','IMP','EMER','OBSENC','VR','HH'];
    $allowedStatus = ['planned','arrived','triaged','in-progress','onleave','finished','cancelled','entered-in-error','unknown'];
    if (!in_array($priority, $allowedPriority, true) || !in_array($destination, $allowedDestination, true) || !in_array($status, $allowedStatus, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "priority, destination, atau status tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 17. Validasi registrationDatetime ---
    if (!empty($registrationDatetime)) {
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $registrationDatetime);
        if (!$date || $date->format('Y-m-d H:i:s') !== $registrationDatetime) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "registrationDatetime harus format YYYY-MM-DD HH:MM:SS", "code" => 422], "metadata" => []]);
            exit;
        }
    } else {
        $registrationDatetime = $nowUtc;
    }

    // --- 18. Generate / Validasi EncounterCode jika kosong ---
    if (empty($EncounterCode)) {
        $EncounterCode = GenerateToken(16);
        $unique = false;
        $attempt = 0;
        while (!$unique && $attempt < 10) {
            try {
                $stmt = $Conn->prepare("SELECT encounterId FROM encounter WHERE EncounterCode = :code AND encounterId != :id LIMIT 1");
                $stmt->execute([':code' => $EncounterCode, ':id' => $encounterId]);
                if (!$stmt->fetch()) {
                    $unique = true;
                } else {
                    $EncounterCode = GenerateToken(16);
                    $attempt++;
                }
            } catch (PDOException $e) {
                error_log('[UpdateEncounter] Check EncounterCode error: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
                exit;
            }
        }
        if (!$unique) {
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Gagal generate EncounterCode unik", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        if (strlen($EncounterCode) > 20) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "EncounterCode maksimal 20 karakter", "code" => 422], "metadata" => []]);
            exit;
        }
        // Jika EncounterCode berubah, cek duplikat
        if ($EncounterCode !== $existingData['EncounterCode']) {
            try {
                $stmt = $Conn->prepare("SELECT encounterId FROM encounter WHERE EncounterCode = :code AND encounterId != :id LIMIT 1");
                $stmt->execute([':code' => $EncounterCode, ':id' => $encounterId]);
                if ($stmt->fetch()) {
                    http_response_code(409);
                    echo json_encode(["response" => ["message" => "EncounterCode sudah digunakan oleh kunjungan lain", "code" => 409], "metadata" => []]);
                    exit;
                }
            } catch (PDOException $e) {
                error_log('[UpdateEncounter] Check EncounterCode error: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
                exit;
            }
        }
    }

    // --- 19. Periksa apakah status berubah, untuk insert riwayat status ---
    $statusChanged = ($status !== $existingData['status']);

    // --- 20. Mulai Transaksi ---
    $Conn->beginTransaction();
    try {
        // Update Encounter
        $sql = "UPDATE encounter SET
                    EncounterCode = :EncounterCode,
                    registrationDatetime = :registrationDatetime,
                    patientId = :patientId,
                    reasonForVisit = :reasonForVisit,
                    chiefComplaint = :chiefComplaint,
                    priority = :priority,
                    destination = :destination,
                    polyclinicId = :polyclinicId,
                    inpatientClassId = :inpatientClassId,
                    inpatientRoomId = :inpatientRoomId,
                    inpatientBedId = :inpatientBedId,
                    assurance = :assurance,
                    assuranceName = :assuranceName,
                    assuranceNumber = :assuranceNumber,
                    emergencyContactName = :emergencyContactName,
                    emergencyContactPhone = :emergencyContactPhone,
                    status = :status,
                    updateAt = :updateAt,
                    updateBy = :updateBy
                WHERE encounterId = :id";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':EncounterCode' => $EncounterCode,
            ':registrationDatetime' => $registrationDatetime,
            ':patientId' => $patientId,
            ':reasonForVisit' => $reasonForVisit,
            ':chiefComplaint' => $chiefComplaint,
            ':priority' => $priority,
            ':destination' => $destination,
            ':polyclinicId' => $polyclinicId,
            ':inpatientClassId' => $inpatientClassId,
            ':inpatientRoomId' => $inpatientRoomId,
            ':inpatientBedId' => $inpatientBedId,
            ':assurance' => $assurance,
            ':assuranceName' => $assuranceName,
            ':assuranceNumber' => $assuranceNumber,
            ':emergencyContactName' => $emergencyContactName,
            ':emergencyContactPhone' => $emergencyContactPhone,
            ':status' => $status,
            ':updateAt' => $nowUtc,
            ':updateBy' => $loggedInAccountId,
            ':id' => $encounterId
        ]);

        // Update atau Insert Performer (ADM)
        // Cek apakah ada performer dengan tipe ADM
        $stmt = $Conn->prepare("SELECT encounterPerformerId FROM encounter_performer WHERE encounterId = :encounterId AND performerType = 'ADM' LIMIT 1");
        $stmt->execute([':encounterId' => $encounterId]);
        $performer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($performer) {
            // Update
            $stmt = $Conn->prepare("UPDATE encounter_performer SET medicalPersonelId = :medicalPersonelId, updateAt = :updateAt, updateBy = :updateBy WHERE encounterPerformerId = :id");
            $stmt->execute([
                ':medicalPersonelId' => $medicalPersonelId,
                ':updateAt' => $nowUtc,
                ':updateBy' => $loggedInAccountId,
                ':id' => $performer['encounterPerformerId']
            ]);
        } else {
            // Insert
            $stmt = $Conn->prepare("INSERT INTO encounter_performer (encounterId, performerType, medicalPersonelId, updateAt, updateBy) VALUES (:encounterId, 'ADM', :medicalPersonelId, :updateAt, :updateBy)");
            $stmt->execute([
                ':encounterId' => $encounterId,
                ':medicalPersonelId' => $medicalPersonelId,
                ':updateAt' => $nowUtc,
                ':updateBy' => $loggedInAccountId
            ]);
        }

        // Jika status berubah, insert riwayat status
        if ($statusChanged) {
            $stmt = $Conn->prepare("INSERT INTO encounter_status (encounterId, encounterStatus, updateAt, updateBy) VALUES (:encounterId, :status, :updateAt, :updateBy)");
            $stmt->execute([
                ':encounterId' => $encounterId,
                ':status' => $status,
                ':updateAt' => $nowUtc,
                ':updateBy' => $loggedInAccountId
            ]);
        }

        $Conn->commit();
    } catch (PDOException $e) {
        $Conn->rollBack();
        error_log('[UpdateEncounter] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data kunjungan: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 21. Sinkronisasi SATUSEHAT ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
    $newSatuSehatCode = null; // untuk menampung ID baru jika dibuat

    // Ambil data terkait SATUSEHAT
    $patientSatuSehat      = null;
    $practitionerSatuSehat = null;
    $polyclinicSatuSehat   = null;
    $bedSatuSehat          = null;

    try {
        // Ambil satuSehatCode patient
        $stmt = $Conn->prepare("SELECT satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $patientSatuSehat = $row['satuSehatCode'];

        // Ambil id_practitioner dari medical_personel
        $stmt = $Conn->prepare("SELECT id_practitioner FROM medical_personel WHERE medicalPersonelId = :id LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $practitionerSatuSehat = $row['id_practitioner'];

        // Ambil satuSehatCode polyclinic jika ada
        if ($polyclinicId > 0) {
            $stmt = $Conn->prepare("SELECT satuSehatCode FROM polyclinic WHERE polyclinicId = :id LIMIT 1");
            $stmt->execute([':id' => $polyclinicId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $polyclinicSatuSehat = $row['satuSehatCode'];
        }

        // Ambil satuSehatCode bed jika rawat inap
        if ($inpatientBedId > 0) {
            $stmt = $Conn->prepare("SELECT satuSehatCode FROM inpatient_bed WHERE inpatientBedId = :id LIMIT 1");
            $stmt->execute([':id' => $inpatientBedId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $bedSatuSehat = $row['satuSehatCode'];
        }
    } catch (PDOException $e) {
        error_log('[UpdateEncounter] Get SATUSEHAT codes error: ' . $e->getMessage());
    }

    // Syarat: patient dan practitioner harus punya kode SATUSEHAT
    $canSync = (!empty($patientSatuSehat) && !empty($practitionerSatuSehat));

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
                    $organizationId = $credential['organizationId'];

                    // Mapping class
                    $classMapping = [
                        'AMB' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB'],
                        'IMP' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'IMP'],
                        'EMER' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'EMER'],
                        'OBSENC' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'OBSENC'],
                        'VR' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'VR'],
                        'HH' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'HH']
                    ];
                    $class = isset($classMapping[$destination]) ? $classMapping[$destination] : ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB'];

                    // Konversi waktu ke ISO8601
                    $registrationDateTimeIso = gmdate('Y-m-d\TH:i:s\Z', strtotime($registrationDatetime));

                    // Payload dasar
                    $encounterData = [
                        'resourceType' => 'Encounter',
                        'identifier' => [[ 'system' => 'http://sys-ids.kemkes.go.id/encounter', 'value' => $EncounterCode ]],
                        'status' => $status,
                        'class' => $class,
                        'subject' => [ 'reference' => 'Patient/' . $patientSatuSehat ],
                        'participant' => [[
                            'type' => [[ 'coding' => [[ 'system' => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType', 'code' => 'ATND' ]]]],
                            'individual' => [ 'reference' => 'Practitioner/' . $practitionerSatuSehat ]
                        ]],
                        'period' => [ 'start' => $registrationDateTimeIso ],
                        'reasonCode' => [[ 'text' => $reasonForVisit ]],
                        'serviceProvider' => [ 'reference' => 'Organization/' . $organizationId ],
                        'statusHistory' => [
                            [
                                'status' => $status,
                                'period' => [ 'start' => $registrationDateTimeIso ]
                            ]
                        ]
                    ];

                    // Location
                    $locations = [];
                    if (!empty($polyclinicSatuSehat)) {
                        $locations[] = [
                            'location' => [ 'reference' => 'Location/' . $polyclinicSatuSehat ],
                            'status' => 'active'
                        ];
                    }
                    if (!empty($bedSatuSehat)) {
                        $locations[] = [
                            'location' => [ 'reference' => 'Location/' . $bedSatuSehat ],
                            'status' => 'active'
                        ];
                    }
                    if (!empty($locations)) {
                        $encounterData['location'] = $locations;
                    }

                    // --- Tentukan metode: POST jika satuSehatCode kosong, PUT jika terisi ---
                    if (empty($satuSehatCode)) {
                        // POST untuk membuat baru
                        $satusehatSyncStatus = 'failed';
                        $satusehatMessage = 'Gagal membuat Encounter di SATUSEHAT';

                        $ch = curl_init();
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Encounter',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($encounterData),
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
                                $newSatuSehatCode = $result['id'];
                                // Update database dengan ID baru
                                $updStmt = $Conn->prepare("UPDATE encounter SET satuSehatCode = :satuSehatCode WHERE encounterId = :id");
                                $updStmt->execute([':satuSehatCode' => $newSatuSehatCode, ':id' => $encounterId]);
                                $satusehatSyncStatus = 'success';
                                $satusehatMessage = 'Berhasil membuat Encounter baru di SATUSEHAT';
                            } else {
                                $satusehatMessage = 'Respons SATUSEHAT tidak mengandung ID';
                            }
                        } else {
                            $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 200);
                        }
                    } else {
                        // PUT untuk update existing
                        // Tambahkan ID ke payload
                        $encounterData['id'] = $satuSehatCode;

                        $satusehatSyncStatus = 'failed';
                        $satusehatMessage = 'Gagal mengupdate Encounter di SATUSEHAT';

                        $ch = curl_init();
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Encounter/' . $satuSehatCode,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_CUSTOMREQUEST => 'PUT',
                            CURLOPT_POSTFIELDS => json_encode($encounterData),
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
                        } elseif ($httpCode === 200) {
                            $satusehatSyncStatus = 'success';
                            $satusehatMessage = 'Berhasil mengupdate Encounter di SATUSEHAT';
                        } else {
                            $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 200);
                        }
                    }
                } else {
                    $satusehatMessage = 'Token SATUSEHAT error: ' . $tokenResult['message'];
                }
            } else {
                $satusehatMessage = 'Tidak ada kredensial SATUSEHAT aktif';
            }
        } catch (Exception $e) {
            $satusehatMessage = 'Exception: ' . $e->getMessage();
            error_log('[UpdateEncounter] SATUSEHAT integration error: ' . $e->getMessage());
        }
    } else {
        $satusehatMessage = 'Syarat sinkronisasi SATUSEHAT tidak terpenuhi (patient atau practitioner tidak memiliki kode SATUSEHAT)';
    }

    // --- 22. Response ---
    $responseData = [
        "response" => [
            "message" => "Kunjungan berhasil diperbarui",
            "code" => 200
        ],
        "metadata" => [
            "encounterId" => $encounterId,
            "satuSehatCode" => !empty($satuSehatCode) ? $satuSehatCode : $newSatuSehatCode,
            "satusehat_sync" => [
                "status" => $satusehatSyncStatus,
                "message" => $satusehatMessage
            ],
            "updated_at" => $nowUtc . ' GMT'
        ],
        "data" => [
            "encounterId" => $encounterId,
            "EncounterCode" => $EncounterCode,
            "satuSehatCode" => !empty($satuSehatCode) ? $satuSehatCode : $newSatuSehatCode,
            "registrationDatetime" => $registrationDatetime,
            "patientId" => $patientId,
            "reasonForVisit" => $reasonForVisit,
            "chiefComplaint" => $chiefComplaint,
            "priority" => $priority,
            "destination" => $destination,
            "polyclinicId" => $polyclinicId,
            "inpatientClassId" => $inpatientClassId,
            "inpatientRoomId" => $inpatientRoomId,
            "inpatientBedId" => $inpatientBedId,
            "assurance" => $assurance,
            "assuranceName" => $assuranceName,
            "assuranceNumber" => $assuranceNumber,
            "emergencyContactName" => $emergencyContactName,
            "emergencyContactPhone" => $emergencyContactPhone,
            "status" => $status,
            "creatBy" => (int) $existingData['creatBy'],
            "creatAt" => $existingData['creatAt'],
            "updateBy" => $loggedInAccountId,
            "updateAt" => $nowUtc
        ]
    ];

    http_response_code(200);
    echo json_encode($responseData);
?>