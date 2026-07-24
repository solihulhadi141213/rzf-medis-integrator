<?php
    /**
     * Create Encounter
     * Endpoint: POST /_API/Encounter/CreateEncounter.php
     * Header: token, account_token
     * Body: JSON (lihat contoh)
     * 
     * - EncounterCode: jika kosong, generate random 16 karakter unik.
     * - Validasi patientId, polyclinicId, dan relasi rawat inap.
     * - Insert ke encounter, lalu encounter_status, lalu encounter_performer (ADM) dengan transaksi.
     * - Sinkronisasi ke SATUSEHAT jika syarat terpenuhi, dengan status sync di response.
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
    $Limiter->check("create_encounter", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_encounter' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah kunjungan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateEncounter] Auth error: ' . $e->getMessage());
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

    // --- 8. Ambil nilai dengan default ---
    $EncounterCode         = isset($input['EncounterCode']) ? trim($input['EncounterCode']) : '';
    $registrationDatetime  = isset($input['registrationDatetime']) ? trim($input['registrationDatetime']) : null;
    $patientId             = isset($input['patientId']) ? (int) $input['patientId'] : 0;
    $reasonForVisit        = isset($input['reasonForVisit']) ? trim($input['reasonForVisit']) : 'Berobat';
    $chiefComplaint        = isset($input['chiefComplaint']) ? trim($input['chiefComplaint']) : null;
    $priority              = isset($input['priority']) ? trim($input['priority']) : 'R';
    $destination           = isset($input['destination']) ? trim($input['destination']) : 'AMB';
    $polyclinicId          = isset($input['polyclinicId']) ? (int) $input['polyclinicId'] : 0;
    $inpatientClassId      = isset($input['inpatientClassId']) ? (int) $input['inpatientClassId'] : 0;
    $inpatientRoomId       = isset($input['inpatientRoomId']) ? (int) $input['inpatientRoomId'] : 0;
    $inpatientBedId        = isset($input['inpatientBedId']) ? (int) $input['inpatientBedId'] : 0;
    $assurance             = isset($input['assurance']) ? (int) $input['assurance'] : 0;
    $assuranceName         = isset($input['assuranceName']) ? trim($input['assuranceName']) : null;
    $assuranceNumber       = isset($input['assuranceNumber']) ? trim($input['assuranceNumber']) : null;
    $emergencyContactName  = isset($input['emergencyContactName']) ? trim($input['emergencyContactName']) : null;
    $emergencyContactPhone = isset($input['emergencyContactPhone']) ? trim($input['emergencyContactPhone']) : null;
    $status                = isset($input['status']) ? trim($input['status']) : 'planned';
    $medicalPersonelId     = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : 0;

    // --- 9. Validasi Field Wajib ---
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

    // --- 10. Validasi patientId ---
    try {
        $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "patientId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateEncounter] Check patientId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi polyclinicId (jika diisi) ---
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
            error_log('[CreateEncounter] Check polyclinicId error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        $polyclinicId = null;
    }

    // --- 12. Validasi Inpatient Rawat Inap ---
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
            error_log('[CreateEncounter] Check class error: ' . $e->getMessage());
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
            error_log('[CreateEncounter] Check room error: ' . $e->getMessage());
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
            error_log('[CreateEncounter] Check bed error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        $inpatientClassId = null;
        $inpatientRoomId = null;
        $inpatientBedId = null;
    }

    // --- 13. Validasi medicalPersonelId ---
    try {
        $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE medicalPersonelId = :id LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateEncounter] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Validasi priority, destination, status ---
    $allowedPriority = ['R','UR','EM','EL'];
    $allowedDestination = ['AMB','IMP','EMER','OBSENC','VR','HH'];
    $allowedStatus = ['planned','arrived','triaged','in-progress','onleave','finished','cancelled','entered-in-error','unknown'];
    if (!in_array($priority, $allowedPriority, true) || !in_array($destination, $allowedDestination, true) || !in_array($status, $allowedStatus, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "priority, destination, atau status tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 15. Validasi registrationDatetime ---
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

    // --- 16. Generate / Validasi EncounterCode ---
    if (empty($EncounterCode)) {
        $EncounterCode = GenerateToken(16);
        $unique = false;
        $attempt = 0;
        while (!$unique && $attempt < 10) {
            try {
                $stmt = $Conn->prepare("SELECT encounterId FROM encounter WHERE EncounterCode = :code LIMIT 1");
                $stmt->execute([':code' => $EncounterCode]);
                if (!$stmt->fetch()) {
                    $unique = true;
                } else {
                    $EncounterCode = GenerateToken(16);
                    $attempt++;
                }
            } catch (PDOException $e) {
                error_log('[CreateEncounter] Check EncounterCode error: ' . $e->getMessage());
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
        try {
            $stmt = $Conn->prepare("SELECT encounterId FROM encounter WHERE EncounterCode = :code LIMIT 1");
            $stmt->execute([':code' => $EncounterCode]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "EncounterCode sudah digunakan", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CreateEncounter] Check EncounterCode error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 17. Mulai Transaksi ---
    $Conn->beginTransaction();
    try {
        // Insert Encounter
        $sql = "INSERT INTO encounter (
                    EncounterCode, satuSehatCode, registrationDatetime, patientId,
                    reasonForVisit, chiefComplaint, priority, destination,
                    polyclinicId, inpatientClassId, inpatientRoomId, inpatientBedId,
                    assurance, assuranceName, assuranceNumber,
                    emergencyContactName, emergencyContactPhone, status,
                    creatAt, updateAt, creatBy, updateBy
                ) VALUES (
                    :EncounterCode, :satuSehatCode, :registrationDatetime, :patientId,
                    :reasonForVisit, :chiefComplaint, :priority, :destination,
                    :polyclinicId, :inpatientClassId, :inpatientRoomId, :inpatientBedId,
                    :assurance, :assuranceName, :assuranceNumber,
                    :emergencyContactName, :emergencyContactPhone, :status,
                    :creatAt, :updateAt, :creatBy, :updateBy
                )";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':EncounterCode' => $EncounterCode,
            ':satuSehatCode' => null,
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
            ':creatAt' => $nowUtc,
            ':updateAt' => $nowUtc,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);
        $encounterId = (int) $Conn->lastInsertId();

        // Insert encounter_status
        $sql = "INSERT INTO encounter_status (encounterId, encounterStatus, updateAt, updateBy) VALUES (:encounterId, :encounterStatus, :updateAt, :updateBy)";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':encounterId' => $encounterId,
            ':encounterStatus' => $status,
            ':updateAt' => $nowUtc,
            ':updateBy' => $loggedInAccountId
        ]);

        // Insert encounter_performer (ADM)
        $sql = "INSERT INTO encounter_performer (encounterId, performerType, medicalPersonelId, updateAt, updateBy) VALUES (:encounterId, 'ADM', :medicalPersonelId, :updateAt, :updateBy)";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':encounterId' => $encounterId,
            ':medicalPersonelId' => $medicalPersonelId,
            ':updateAt' => $nowUtc,
            ':updateBy' => $loggedInAccountId
        ]);

        $Conn->commit();
    } catch (PDOException $e) {
        $Conn->rollBack();
        error_log('[CreateEncounter] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data kunjungan: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 18. Integrasi SATUSEHAT (setelah commit, tidak mempengaruhi transaksi) ---
    $satuSehatCode         = null;
    $patientSatuSehat      = null;
    $practitionerSatuSehat = null;
    $polyclinicSatuSehat   = null;
    $bedSatuSehat          = null;

    // Ambil satuSehatCode patient
    try {
        $stmt = $Conn->prepare("SELECT satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $patientSatuSehat = $row['satuSehatCode'];
    } catch (PDOException $e) { error_log('[CreateEncounter] Get patient satuSehatCode error: ' . $e->getMessage()); }

    // Ambil id_practitioner dari medical_personel
    try {
        $stmt = $Conn->prepare("SELECT id_practitioner FROM medical_personel WHERE medicalPersonelId = :id LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $practitionerSatuSehat = $row['id_practitioner'];
    } catch (PDOException $e) { error_log('[CreateEncounter] Get practitioner id error: ' . $e->getMessage()); }

    // Ambil satuSehatCode polyclinic jika ada
    if ($polyclinicId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT satuSehatCode FROM polyclinic WHERE polyclinicId = :id LIMIT 1");
            $stmt->execute([':id' => $polyclinicId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $polyclinicSatuSehat = $row['satuSehatCode'];
        } catch (PDOException $e) { error_log('[CreateEncounter] Get polyclinic satuSehatCode error: ' . $e->getMessage()); }
    }

    // Ambil satuSehatCode bed jika rawat inap
    if ($inpatientBedId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT satuSehatCode FROM inpatient_bed WHERE inpatientBedId = :id LIMIT 1");
            $stmt->execute([':id' => $inpatientBedId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $bedSatuSehat = $row['satuSehatCode'];
        } catch (PDOException $e) { error_log('[CreateEncounter] Get bed satuSehatCode error: ' . $e->getMessage()); }
    }

    // Inisialisasi status sync
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Syarat sinkronisasi SATUSEHAT tidak terpenuhi (patient atau practitioner tidak memiliki kode SATUSEHAT)';

    // Syarat: patient dan practitioner harus punya kode SATUSEHAT
    if (!empty($patientSatuSehat) && !empty($practitionerSatuSehat)) {
        $satusehatSyncStatus = 'failed';
        $satusehatMessage = 'Gagal mengirim ke SATUSEHAT (tidak ada respons)';

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

                    // Konversi registrationDatetime ke ISO8601 UTC
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
                                'period' => [
                                    'start' => $registrationDateTimeIso
                                ]
                            ]
                        ]
                    ];

                    // Location: polyclinic jika ada
                    $locations = [];
                    if (!empty($polyclinicSatuSehat)) {
                        $locations[] = [
                            'location' => [ 'reference' => 'Location/' . $polyclinicSatuSehat ]
                        ];
                    }
                    // Jika rawat inap dan bed punya kode, tambahkan location
                    if (!empty($bedSatuSehat)) {
                        $locations[] = [
                            'location' => [ 'reference' => 'Location/' . $bedSatuSehat ]
                        ];
                    }
                    if (!empty($locations)) {
                        $encounterData['location'] = $locations;
                    }

                    // Kirim ke SATUSEHAT
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
                            $satuSehatCode = $result['id'];
                            // Update encounter.satuSehatCode
                            $updStmt = $Conn->prepare("UPDATE encounter SET satuSehatCode = :satuSehatCode WHERE encounterId = :id");
                            $updStmt->execute([':satuSehatCode' => $satuSehatCode, ':id' => $encounterId]);
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
            error_log('[CreateEncounter] SATUSEHAT integration error: ' . $e->getMessage());
        }
    }

    // --- 19. Response ---
    http_response_code(201);
    echo json_encode([
        "response" => [
            "message" => "Kunjungan berhasil ditambahkan",
            "code" => 201
        ],
        "metadata" => [
            "encounterId" => $encounterId,
            "satuSehatCode" => $satuSehatCode,
            "satusehat_sync" => [
                "status" => $satusehatSyncStatus, // success | failed | skipped
                "message" => $satusehatMessage
            ],
            "created_at" => $nowUtc . ' GMT'
        ],
        "data" => [
            "encounterId" => $encounterId,
            "EncounterCode" => $EncounterCode,
            "satuSehatCode" => $satuSehatCode,
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
            "creatBy" => $loggedInAccountId,
            "creatAt" => $nowUtc,
            "updateBy" => $loggedInAccountId,
            "updateAt" => $nowUtc
        ]
    ]);
?>