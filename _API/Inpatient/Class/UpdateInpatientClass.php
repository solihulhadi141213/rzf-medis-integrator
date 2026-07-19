<?php
    /**
     * Update Inpatient Class
     * Endpoint: PUT /_API/Inpatient/Class/UpdateInpatientClass.php?inpatientClassId={id}
     * Header: token, account_token
     * Body: JSON { "inpatientClassCode": "KL3", "inpatientClassName": "Kelas III", "status": 1 }
     * 
     * Jika satuSehatCode terisi, akan melakukan update ke SATUSEHAT Location.
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
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_inpatient_class", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        http_response_code(405);
        echo json_encode([
            "response" => [
                "message" => "Metode request tidak diizinkan",
                "code" => 405
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 5. Validasi Header Token & Account Token ---
    $apiToken     = getRequestHeader('token');
    $accountToken = getRequestHeader('account_token');

    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode([
            "response" => [
                "message" => "Token Kredensial Aplikasi Tidak Boleh Kosong",
                "code" => 401
            ],
            "metadata" => []
        ]);
        exit;
    }

    if (empty($accountToken)) {
        http_response_code(401);
        echo json_encode([
            "response" => [
                "message" => "Token Sesi Akses Tidak Boleh Kosong",
                "code" => 401
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 6. Validasi Parameter inpatientClassId ---
    if (!isset($_GET['inpatientClassId']) || !is_numeric($_GET['inpatientClassId']) || (int)$_GET['inpatientClassId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter inpatientClassId wajib diisi dengan angka positif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }
    $inpatientClassId = (int) $_GET['inpatientClassId'];

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
            echo json_encode([
                "response" => [
                    "message" => "Token tidak valid",
                    "code" => 401
                ],
                "metadata" => []
            ]);
            exit;
        }

        if ($tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode([
                "response" => [
                    "message" => "Token sudah kedaluwarsa",
                    "code" => 401
                ],
                "metadata" => []
            ]);
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
            echo json_encode([
                "response" => [
                    "message" => "account_token tidak valid",
                    "code" => 401
                ],
                "metadata" => []
            ]);
            exit;
        }

        $loggedInAccountId = (int) $accountTokenData['accountId'];

        // Validasi Permission (fitur update_inpatient_class)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'update_inpatient_class']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur update_inpatient_class tidak ditemukan",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Tidak memiliki izin untuk mengubah kelas rawat inap",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[UpdateInpatientClass] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Internal Server Error",
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 8. Ambil data kelas rawat inap yang akan diupdate ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM inpatient_class WHERE inpatientClassId = :id LIMIT 1");
        $stmt->execute([':id' => $inpatientClassId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode([
                "response" => [
                    "message" => "Kelas rawat inap tidak ditemukan",
                    "code" => 404
                ],
                "metadata" => []
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateInpatientClass] Fetch data error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Internal Server Error",
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 9. Ambil dan Decode Body JSON ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Invalid JSON payload: " . json_last_error_msg(),
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 10. Ambil nilai dari body, gunakan nilai lama jika tidak ada ---
    $inpatientClassCode = isset($input['inpatientClassCode']) ? trim($input['inpatientClassCode']) : $existingData['inpatientClassCode'];
    $inpatientClassName = isset($input['inpatientClassName']) ? trim($input['inpatientClassName']) : $existingData['inpatientClassName'];
    $status = isset($input['status']) ? (int) $input['status'] : (int) $existingData['status'];

    // --- 11. Validasi inpatientClassCode (jika berubah) ---
    if ($inpatientClassCode !== $existingData['inpatientClassCode']) {
        if (strlen($inpatientClassCode) > 20) {
            http_response_code(422);
            echo json_encode([
                "response" => [
                    "message" => "inpatientClassCode maksimal 20 karakter",
                    "code" => 422
                ],
                "metadata" => []
            ]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT inpatientClassId FROM inpatient_class WHERE inpatientClassCode = :code AND inpatientClassId != :id LIMIT 1");
            $stmt->execute([':code' => $inpatientClassCode, ':id' => $inpatientClassId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "response" => [
                        "message" => "inpatientClassCode sudah digunakan oleh kelas rawat inap lain",
                        "code" => 409
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateInpatientClass] Check code error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                "response" => [
                    "message" => "Internal Server Error",
                    "code" => 500
                ],
                "metadata" => []
            ]);
            exit;
        }
    }

    // --- 12. Validasi inpatientClassName (wajib) ---
    if (empty($inpatientClassName)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "inpatientClassName tidak boleh kosong",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 13. Validasi status ---
    if (!in_array($status, [0, 1], true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "status hanya boleh 0 atau 1",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 14. Jika satuSehatCode terisi, update ke SATUSEHAT ---
    $satuSehatCode = $existingData['satuSehatCode'];
    if (!empty($satuSehatCode)) {
        try {
            // Ambil credential SATUSEHAT yang aktif
            $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
            $credStmt->execute();
            $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
            if (!$credential) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "Tidak ada kredensial SATUSEHAT yang aktif",
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }

            // Dapatkan token SATUSEHAT
            $tokenResult = generateTokenSatusehat($Conn);
            if ($tokenResult['status'] !== 'success') {
                http_response_code(500);
                echo json_encode([
                    "response" => [
                        "message" => "Gagal mendapatkan token SATUSEHAT: " . $tokenResult['message'],
                        "code" => 500
                    ],
                    "metadata" => []
                ]);
                exit;
            }
            $accessToken = $tokenResult['token'];
            $baseUrl = rtrim($credential['baseUrl'], '/');

            // Siapkan data untuk update Location
            $locationData = [
                "resourceType" => "Location",
                "id" => $satuSehatCode,
                "name" => $inpatientClassName,
                "status" => $status === 1 ? "active" : "inactive"
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Location/' . $satuSehatCode,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => json_encode($locationData),
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
                http_response_code(500);
                echo json_encode([
                    "response" => [
                        "message" => "Gagal menghubungi API SATUSEHAT: " . $curlError,
                        "code" => 500
                    ],
                    "metadata" => []
                ]);
                exit;
            }

            // Untuk update, status yang diharapkan 200 OK
            if ($httpCode !== 200) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "SATUSEHAT API error: " . substr($response, 0, 500),
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(500);
                echo json_encode([
                    "response" => [
                        "message" => "JSON Error: " . json_last_error_msg(),
                        "code" => 500
                    ],
                    "metadata" => []
                ]);
                exit;
            }

            // Optional: bisa ambil data dari response jika diperlukan

        } catch (Exception $e) {
            error_log('[UpdateInpatientClass] SATUSEHAT error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                "response" => [
                    "message" => "Internal Server Error: " . $e->getMessage(),
                    "code" => 500
                ],
                "metadata" => []
            ]);
            exit;
        }
    }

    // --- 15. Update Data ke Database ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE inpatient_class SET
                    inpatientClassCode = :inpatientClassCode,
                    inpatientClassName = :inpatientClassName,
                    status = :status,
                    updateBy = :updateBy,
                    updatedAt = :updatedAt
                WHERE inpatientClassId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':inpatientClassCode' => $inpatientClassCode,
            ':inpatientClassName' => $inpatientClassName,
            ':status' => $status,
            ':updateBy' => $loggedInAccountId,
            ':updatedAt' => $updatedDate,
            ':id' => $inpatientClassId
        ]);

        // --- 16. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Kelas rawat inap berhasil diperbarui" . (!empty($satuSehatCode) ? " dan disinkronkan ke SATUSEHAT" : ""),
                "code" => 200
            ],
            "metadata" => [
                "inpatientClassId" => $inpatientClassId,
                "satuSehatCode" => $satuSehatCode,
                "updated_at" => $updatedDate . ' GMT'
            ],
            "data" => [
                "inpatientClassId" => $inpatientClassId,
                "inpatientClassCode" => $inpatientClassCode,
                "satuSehatCode" => $satuSehatCode,
                "inpatientClassName" => $inpatientClassName,
                "status" => $status,
                "status_display" => $status === 1 ? 'Aktif' : 'Tidak Aktif',
                "creatBy" => (int) $existingData['creatBy'],
                "creatAt" => $existingData['creatAt'],
                "updateBy" => $loggedInAccountId,
                "updatedAt" => $updatedDate
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateInpatientClass] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal memperbarui data: " . $e->getMessage(),
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }
?>