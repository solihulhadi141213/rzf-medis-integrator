<?php
    /**
     * Delete Inpatient Room (Hard Delete)
     * Endpoint: DELETE /_API/Inpatient/Room/DeleteInpatientRoom.php?inpatientRoomId={id}
     * Header: token, account_token
     * 
     * Jika satuSehatCode terisi, akan melakukan update status menjadi "inactive" di SATUSEHAT sebelum menghapus data lokal.
     * Data terkait (inpatient_bed) akan terhapus otomatis karena ON DELETE CASCADE.
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
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("delete_inpatient_room", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

    // --- 6. Validasi Parameter inpatientRoomId ---
    if (!isset($_GET['inpatientRoomId']) || !is_numeric($_GET['inpatientRoomId']) || (int)$_GET['inpatientRoomId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter inpatientRoomId wajib diisi dengan angka positif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }
    $inpatientRoomId = (int) $_GET['inpatientRoomId'];

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

        // Validasi Permission (fitur delete_inpatient_room)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'delete_inpatient_room']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur delete_inpatient_room tidak ditemukan",
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
                    "message" => "Tidak memiliki izin untuk menghapus ruangan rawat inap",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[DeleteInpatientRoom] DB/Permission error: ' . $e->getMessage());
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

    // --- 8. Ambil data ruangan rawat inap yang akan dihapus ---
    try {
        $stmt = $Conn->prepare("SELECT inpatientRoomId, satuSehatCode, inpatientRoomName FROM inpatient_room WHERE inpatientRoomId = :id LIMIT 1");
        $stmt->execute([':id' => $inpatientRoomId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode([
                "response" => [
                    "message" => "Ruangan rawat inap tidak ditemukan",
                    "code" => 404
                ],
                "metadata" => []
            ]);
            exit;
        }
        $satuSehatCode = $existingData['satuSehatCode'];
        $inpatientRoomName = $existingData['inpatientRoomName'];
    } catch (PDOException $e) {
        error_log('[DeleteInpatientRoom] Fetch data error: ' . $e->getMessage());
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

    // --- 9. Jika satuSehatCode terisi, update status menjadi inactive di SATUSEHAT ---
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

            // Siapkan data untuk update Location menjadi inactive
            $locationData = [
                "resourceType" => "Location",
                "id" => $satuSehatCode,
                "name" => $inpatientRoomName,
                "status" => "inactive"
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

            // Berhasil update di SATUSEHAT

        } catch (Exception $e) {
            error_log('[DeleteInpatientRoom] SATUSEHAT error: ' . $e->getMessage());
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

    // --- 10. Hapus Data (Hard Delete) ---
    try {
        $stmt = $Conn->prepare("DELETE FROM inpatient_room WHERE inpatientRoomId = :id");
        $stmt->execute([':id' => $inpatientRoomId]);

        // --- 11. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Ruangan rawat inap berhasil dihapus" . (!empty($satuSehatCode) ? " dan status SATUSEHAT diubah menjadi inactive" : ""),
                "code" => 200
            ],
            "metadata" => [
                "inpatientRoomId" => $inpatientRoomId,
                "satuSehatCode" => $satuSehatCode,
                "deleted_at" => $nowUtc . ' GMT'
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[DeleteInpatientRoom] Delete error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal menghapus data: " . $e->getMessage(),
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }
?>