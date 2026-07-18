<?php
    /**
     * Update Polyclinic
     * Endpoint: PUT /_API/Reference/Polyclinic/UpdatePolyclinic.php?polyclinicId={id}
     * Header: token, account_token
     * Body: JSON { "satuSehatCode": "", "polyclinicCode": "INT", "name": "Poliklinik Penyakit Dalam", "status": 1 }
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
    $Limiter->check("update_polyclinic", 5, 60);

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

    // --- 6. Validasi Parameter polyclinicId ---
    if (!isset($_GET['polyclinicId']) || !is_numeric($_GET['polyclinicId']) || (int)$_GET['polyclinicId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter polyclinicId wajib diisi dengan angka positif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }
    $polyclinicId = (int) $_GET['polyclinicId'];

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

        // Validasi Permission (fitur update_polyclinic)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'update_polyclinic']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur update_polyclinic tidak ditemukan",
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
                    "message" => "Tidak memiliki izin untuk mengubah poliklinik",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[UpdatePolyclinic] DB/Permission error: ' . $e->getMessage());
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

    // --- 8. Ambil data poliklinik yang akan diupdate ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM polyclinic WHERE polyclinicId = :id LIMIT 1");
        $stmt->execute([':id' => $polyclinicId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode([
                "response" => [
                    "message" => "Poliklinik tidak ditemukan",
                    "code" => 404
                ],
                "metadata" => []
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdatePolyclinic] Fetch data error: ' . $e->getMessage());
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
    $satuSehatCode = isset($input['satuSehatCode']) ? trim($input['satuSehatCode']) : $existingData['satuSehatCode'];
    $polyclinicCode = isset($input['polyclinicCode']) ? trim($input['polyclinicCode']) : $existingData['polyclinicCode'];
    $name = isset($input['name']) ? trim($input['name']) : $existingData['name'];
    $status = isset($input['status']) ? (int) $input['status'] : (int) $existingData['status'];

    // --- 11. Validasi polyclinicCode (jika berubah) ---
    if ($polyclinicCode !== $existingData['polyclinicCode']) {
        if (strlen($polyclinicCode) > 20) {
            http_response_code(422);
            echo json_encode([
                "response" => [
                    "message" => "polyclinicCode maksimal 20 karakter",
                    "code" => 422
                ],
                "metadata" => []
            ]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT polyclinicId FROM polyclinic WHERE polyclinicCode = :code AND polyclinicId != :id LIMIT 1");
            $stmt->execute([':code' => $polyclinicCode, ':id' => $polyclinicId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "response" => [
                        "message" => "polyclinicCode sudah digunakan oleh poliklinik lain",
                        "code" => 409
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdatePolyclinic] Check code error: ' . $e->getMessage());
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

    // --- 12. Validasi satuSehatCode (jika diisi dan berubah) ---
    if (!empty($satuSehatCode) && $satuSehatCode !== $existingData['satuSehatCode']) {
        try {
            $stmt = $Conn->prepare("SELECT polyclinicId FROM polyclinic WHERE satuSehatCode = :code AND polyclinicId != :id LIMIT 1");
            $stmt->execute([':code' => $satuSehatCode, ':id' => $polyclinicId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "response" => [
                        "message" => "satuSehatCode sudah digunakan oleh poliklinik lain",
                        "code" => 409
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdatePolyclinic] Check satuSehatCode error: ' . $e->getMessage());
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

    // --- 14. Validasi name (wajib) ---
    if (empty($name)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "name tidak boleh kosong",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 15. Update Data ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE polyclinic SET
                    satuSehatCode = :satuSehatCode,
                    polyclinicCode = :polyclinicCode,
                    name = :name,
                    status = :status,
                    updatedBy = :updatedBy,
                    updatedDate = :updatedDate
                WHERE polyclinicId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':satuSehatCode' => $satuSehatCode,
            ':polyclinicCode' => $polyclinicCode,
            ':name' => $name,
            ':status' => $status,
            ':updatedBy' => $loggedInAccountId,
            ':updatedDate' => $updatedDate,
            ':id' => $polyclinicId
        ]);

        // --- 16. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Poliklinik berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "polyclinicId" => $polyclinicId,
                "updated_at" => $updatedDate . ' GMT'
            ],
            "data" => [
                "polyclinicId" => $polyclinicId,
                "satuSehatCode" => $satuSehatCode,
                "polyclinicCode" => $polyclinicCode,
                "name" => $name,
                "status" => $status,
                "status_display" => $status === 1 ? 'Aktif' : 'Tidak Aktif',
                "createdBy" => (int) $existingData['createdBy'],
                "createdDate" => $existingData['createdDate'],
                "updatedBy" => $loggedInAccountId,
                "updatedDate" => $updatedDate
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[UpdatePolyclinic] Update error: ' . $e->getMessage());
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