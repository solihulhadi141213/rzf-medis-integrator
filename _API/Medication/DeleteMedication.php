<?php
    /**
     * Delete Medication
     * Endpoint: DELETE /_API/Medication/DeleteMedication.php?medicationId={id}
     * Header: token, account_token
     *
     * Menghapus data obat/alkes (hard delete) dari database.
     * Data child (medication_multi_form dan medication_selling_price) akan terhapus otomatis via ON DELETE CASCADE.
     * Jika memiliki satuSehatCode, update status di SATUSEHAT menjadi inactive.
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
    $Limiter->check("delete_medication", 5, 60);

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

    // --- 6. Validasi Parameter medicationId ---
    if (!isset($_GET['medicationId']) || !is_numeric($_GET['medicationId']) || (int)$_GET['medicationId'] <= 0) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Parameter medicationId wajib diisi dengan angka positif", "code" => 400], "metadata" => []]);
        exit;
    }
    $medicationId = (int) $_GET['medicationId'];

    // --- 7. Validasi Token & Permission ---
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

        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'delete_medication' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menghapus obat/alkes", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[DeleteMedication] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing untuk mendapatkan satuSehatCode dan informasi lain ---
    try {
        $stmt = $Conn->prepare("
            SELECT medicationId, satuSehatCode, medicationLocalCode, medicationName,
                medicationGroup, kfaCode, kfaDisplay, kfaSystem,
                medicationFormCode, medicationFormDisplay, medicationFormSystem,
                manufacturerId, medicationType
            FROM medication
            WHERE medicationId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $medicationId]);
        $medication = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$medication) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data obat/alkes tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
        $satuSehatCode = $medication['satuSehatCode'];
        $medicationLocalCode = $medication['medicationLocalCode'];
        $medicationName = $medication['medicationName'];
        $medicationGroup = $medication['medicationGroup'];
        $kfaCode = $medication['kfaCode'];
        $kfaDisplay = $medication['kfaDisplay'];
        $kfaSystem = $medication['kfaSystem'];
        $medicationFormCode = $medication['medicationFormCode'];
        $medicationFormDisplay = $medication['medicationFormDisplay'];
        $medicationFormSystem = $medication['medicationFormSystem'];
        $manufacturerId = $medication['manufacturerId'];
        $medicationType = $medication['medicationType'];
    } catch (PDOException $e) {
        error_log('[DeleteMedication] Fetch medication error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 9. Sinkronisasi ke SATUSEHAT (update status menjadi inactive) jika memiliki satuSehatCode ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
    $satusehatErrorDetail = null;

    if (!empty($satuSehatCode)) {
        // Cek kelengkapan data untuk update ke SATUSEHAT
        $canSync = !empty($kfaCode) && !empty($kfaDisplay) && !empty($kfaSystem) &&
                !empty($medicationFormCode) && !empty($medicationFormDisplay) && !empty($medicationFormSystem) &&
                !empty($manufacturerId);

        if ($canSync) {
            try {
                // Ambil credential SATUSEHAT
                $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
                $credStmt->execute();
                $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
                if ($credential) {
                    $organizationId = $credential['organizationId'];
                    $tokenResult = generateTokenSatusehat($Conn);
                    if ($tokenResult['status'] === 'success') {
                        $accessToken = $tokenResult['token'];
                        $baseUrl = rtrim($credential['baseUrl'], '/');
                        $resourceType = ($medicationGroup === 'Device') ? 'Device' : 'Medication';

                        // Payload update status menjadi inactive
                        $payload = [
                            'resourceType' => $resourceType,
                            'id' => $satuSehatCode,
                            'identifier' => [
                                [
                                    'system' => 'http://sys-ids.kemkes.go.id/medication/' . $organizationId,
                                    'value' => $medicationLocalCode
                                ]
                            ],
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
                            'status' => 'inactive'
                        ];

                        $satusehatSyncStatus = 'failed';
                        $satusehatMessage = 'Gagal mengupdate status SATUSEHAT';

                        $ch = curl_init();
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $baseUrl . '/fhir-r4/v1/' . $resourceType . '/' . $satuSehatCode,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_CUSTOMREQUEST => 'PUT',
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
                        } elseif ($httpCode === 200) {
                            $satusehatSyncStatus = 'success';
                            $satusehatMessage = 'Berhasil mengupdate status SATUSEHAT menjadi inactive';
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
                } else {
                    $satusehatMessage = 'Tidak ada kredensial SATUSEHAT aktif';
                    $satusehatErrorDetail = 'Tidak ada kredensial SATUSEHAT aktif';
                }
            } catch (Exception $e) {
                $satusehatMessage = 'Exception: ' . $e->getMessage();
                $satusehatErrorDetail = $e->getMessage();
                error_log('[DeleteMedication] SATUSEHAT integration error: ' . $e->getMessage());
                $satusehatSyncStatus = 'failed';
            }
        } else {
            $satusehatMessage = 'Data tidak lengkap untuk update SATUSEHAT (kfaCode, kfaDisplay, kfaSystem, form, manufacturerId)';
            $satusehatSyncStatus = 'skipped';
        }
    }

    // --- 10. Hapus data dari database (hard delete) ---
    // Child tables akan terhapus otomatis karena ON DELETE CASCADE
    try {
        $stmt = $Conn->prepare("DELETE FROM medication WHERE medicationId = :id");
        $stmt->execute([':id' => $medicationId]);

        // --- 11. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Obat/Alkes berhasil dihapus",
                "code" => 200
            ],
            "metadata" => [
                "medicationId" => $medicationId,
                "satuSehatCode" => $satuSehatCode,
                "satusehat_sync" => [
                    "status" => $satusehatSyncStatus,
                    "message" => $satusehatMessage,
                    "error_detail" => $satusehatErrorDetail
                ],
                "deleted_at" => $nowUtc . ' GMT'
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[DeleteMedication] Delete error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menghapus data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>