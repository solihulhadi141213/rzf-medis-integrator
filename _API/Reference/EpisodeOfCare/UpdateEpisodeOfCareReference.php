<?php
    /**
     * Update Episode Of Care Reference
     * Endpoint: PUT /_API/Reference/EpisodeOfCare/UpdateEpisodeOfCareReference.php?episodeOfCareReferenceId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "episodeOfCareTypeCode": "TB-SOTB-SOTB-SO",
     *     "episodeOfCareTypeDisplay": "Tuberkulosis Sensitif Obat",
     *     "episodeOfCareTypeSystem": "https://terminology.kemkes.go.id/CodeSystem/episodeofcare-type"
     * }
     *
     * - Validasi mandatory: semua field wajib diisi.
     * - Validasi duplikat episodeOfCareTypeCode (jika berubah, cek keunikan kecuali dirinya sendiri).
     * - Tidak ada integrasi SATUSEHAT.
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
    $Limiter->check("update_episode_of_care_reference", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    // --- 6. Validasi Parameter episodeOfCareReferenceId ---
    if (!isset($_GET['episodeOfCareReferenceId']) || !is_numeric($_GET['episodeOfCareReferenceId']) || (int)$_GET['episodeOfCareReferenceId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter episodeOfCareReferenceId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $episodeOfCareReferenceId = (int) $_GET['episodeOfCareReferenceId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_episode_of_care_reference' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah referensi Episode of Care", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateEpisodeOfCareReference] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM episode_of_care_reference WHERE episodeOfCareReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $episodeOfCareReferenceId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Referensi Episode of Care tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateEpisodeOfCareReference] Fetch existing error: ' . $e->getMessage());
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
    $episodeOfCareTypeCode = isset($input['episodeOfCareTypeCode']) ? trim($input['episodeOfCareTypeCode']) : $existingData['episodeOfCareTypeCode'];
    $episodeOfCareTypeDisplay = isset($input['episodeOfCareTypeDisplay']) ? trim($input['episodeOfCareTypeDisplay']) : $existingData['episodeOfCareTypeDisplay'];
    $episodeOfCareTypeSystem = isset($input['episodeOfCareTypeSystem']) ? trim($input['episodeOfCareTypeSystem']) : $existingData['episodeOfCareTypeSystem'];

    // --- 11. Validasi Field Wajib ---
    $required = ['episodeOfCareTypeCode', 'episodeOfCareTypeDisplay', 'episodeOfCareTypeSystem'];
    foreach ($required as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 12. Validasi duplikat episodeOfCareTypeCode (jika berubah) ---
    if ($episodeOfCareTypeCode !== $existingData['episodeOfCareTypeCode']) {
        try {
            $stmt = $Conn->prepare("SELECT episodeOfCareReferenceId FROM episode_of_care_reference WHERE episodeOfCareTypeCode = :code AND episodeOfCareReferenceId != :id LIMIT 1");
            $stmt->execute([':code' => $episodeOfCareTypeCode, ':id' => $episodeOfCareReferenceId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "episodeOfCareTypeCode sudah digunakan oleh referensi lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateEpisodeOfCareReference] Check duplicate error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 13. Update Data ---
    try {
        $sql = "UPDATE episode_of_care_reference SET
                    episodeOfCareTypeCode = :episodeOfCareTypeCode,
                    episodeOfCareTypeDisplay = :episodeOfCareTypeDisplay,
                    episodeOfCareTypeSystem = :episodeOfCareTypeSystem
                WHERE episodeOfCareReferenceId = :id";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':episodeOfCareTypeCode' => $episodeOfCareTypeCode,
            ':episodeOfCareTypeDisplay' => $episodeOfCareTypeDisplay,
            ':episodeOfCareTypeSystem' => $episodeOfCareTypeSystem,
            ':id' => $episodeOfCareReferenceId
        ]);

        // --- 14. Ambil data terbaru untuk response ---
        $stmt = $Conn->prepare("SELECT * FROM episode_of_care_reference WHERE episodeOfCareReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $episodeOfCareReferenceId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedData['episodeOfCareReferenceId'] = (int) $updatedData['episodeOfCareReferenceId'];

        // --- 15. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Referensi Episode of Care berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "episodeOfCareReferenceId" => $episodeOfCareReferenceId,
                "updated_at" => $nowUtc . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateEpisodeOfCareReference] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>