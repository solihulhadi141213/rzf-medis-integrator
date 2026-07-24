<?php
    /**
     * Delete Encounter (Hard Delete)
     * Endpoint: DELETE /_API/Encounter/DeleteEncounter.php?encounterId={id}
     * Header: token, account_token
     * 
     * - Menghapus encounter dari database (hard delete).
     * - Jika memiliki satuSehatCode, update status di SATUSEHAT menjadi "cancelled".
     * - Child tables (encounter_status, encounter_performer) terhapus otomatis karena ON DELETE CASCADE.
     */

    // --- 1. Response Header ---
    header('Content-Type: application/json');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: DELETE");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    date_default_timezone_set('UTC');

    // --- 2. Include Dependencies ---
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("delete_encounter", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'delete_encounter' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menghapus kunjungan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[DeleteEncounter] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data encounter yang akan dihapus ---
    try {
        $stmt = $Conn->prepare("
            SELECT e.*, p.satuSehatCode AS patientSatuSehat, mp.id_practitioner
            FROM encounter e
            LEFT JOIN patient p ON e.patientId = p.patientId
            LEFT JOIN encounter_performer ep ON e.encounterId = ep.encounterId AND ep.performerType = 'ADM'
            LEFT JOIN medical_personel mp ON ep.medicalPersonelId = mp.medicalPersonelId
            WHERE e.encounterId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $encounterId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Kunjungan tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }

        $satuSehatCode = $existingData['satuSehatCode'];
        $EncounterCode = $existingData['EncounterCode'];
        $patientSatuSehat = $existingData['patientSatuSehat'];
        $practitionerSatuSehat = $existingData['id_practitioner'];
        $registrationDatetime = $existingData['registrationDatetime'];
        $reasonForVisit = $existingData['reasonForVisit'];
        $destination = $existingData['destination'];
        $polyclinicId = $existingData['polyclinicId'];
        $inpatientBedId = $existingData['inpatientBedId'];

    } catch (PDOException $e) {
        error_log('[DeleteEncounter] Fetch data error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 9. Sinkronisasi ke SATUSEHAT jika memiliki satuSehatCode ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Tidak memiliki satuSehatCode, sinkronisasi SATUSEHAT dilewati';

    if (!empty($satuSehatCode) && !empty($patientSatuSehat) && !empty($practitionerSatuSehat)) {
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

                    // Payload untuk update status menjadi "cancelled"
                    $encounterData = [
                        'resourceType' => 'Encounter',
                        'id' => $satuSehatCode,
                        'identifier' => [[ 'system' => 'http://sys-ids.kemkes.go.id/encounter', 'value' => $EncounterCode ]],
                        'status' => 'cancelled',
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
                                'status' => $existingData['status'],
                                'period' => [ 'start' => $registrationDateTimeIso ]
                            ],
                            [
                                'status' => 'cancelled',
                                'period' => [
                                    'start' => $registrationDateTimeIso,
                                    'end' => $nowUtc . 'Z' // waktu penghapusan
                                ]
                            ]
                        ]
                    ];

                    // Location jika ada
                    $locations = [];
                    if ($polyclinicId > 0) {
                        $stmt = $Conn->prepare("SELECT satuSehatCode FROM polyclinic WHERE polyclinicId = :id LIMIT 1");
                        $stmt->execute([':id' => $polyclinicId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['satuSehatCode'])) {
                            $locations[] = [
                                'location' => [ 'reference' => 'Location/' . $row['satuSehatCode'] ],
                                'status' => 'active'
                            ];
                        }
                    }
                    if ($inpatientBedId > 0) {
                        $stmt = $Conn->prepare("SELECT satuSehatCode FROM inpatient_bed WHERE inpatientBedId = :id LIMIT 1");
                        $stmt->execute([':id' => $inpatientBedId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['satuSehatCode'])) {
                            $locations[] = [
                                'location' => [ 'reference' => 'Location/' . $row['satuSehatCode'] ],
                                'status' => 'active'
                            ];
                        }
                    }
                    if (!empty($locations)) {
                        $encounterData['location'] = $locations;
                    }

                    // Kirim PUT ke SATUSEHAT
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
                        $satusehatSyncStatus = 'failed';
                    } elseif ($httpCode === 200) {
                        $satusehatSyncStatus = 'success';
                        $satusehatMessage = 'Berhasil mengupdate status Encounter di SATUSEHAT menjadi cancelled';
                    } else {
                        $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 200);
                        $satusehatSyncStatus = 'failed';
                    }
                } else {
                    $satusehatMessage = 'Token SATUSEHAT error: ' . $tokenResult['message'];
                    $satusehatSyncStatus = 'failed';
                }
            } else {
                $satusehatMessage = 'Tidak ada kredensial SATUSEHAT aktif';
                $satusehatSyncStatus = 'failed';
            }
        } catch (Exception $e) {
            $satusehatMessage = 'Exception: ' . $e->getMessage();
            $satusehatSyncStatus = 'failed';
            error_log('[DeleteEncounter] SATUSEHAT integration error: ' . $e->getMessage());
        }
    }

    // --- 10. Hapus data dari database (hard delete) ---
    try {
        $stmt = $Conn->prepare("DELETE FROM encounter WHERE encounterId = :id");
        $stmt->execute([':id' => $encounterId]);

        // --- 11. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Kunjungan berhasil dihapus" . ($satusehatSyncStatus === 'success' ? " dan status SATUSEHAT diubah menjadi cancelled" : ""),
                "code" => 200
            ],
            "metadata" => [
                "encounterId" => $encounterId,
                "satuSehatCode" => $satuSehatCode,
                "satusehat_sync" => [
                    "status" => $satusehatSyncStatus,
                    "message" => $satusehatMessage
                ],
                "deleted_at" => $nowUtc . ' GMT'
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[DeleteEncounter] Delete error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menghapus data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>