<?php
    /**
     * Update Schedule
     * Endpoint: PUT /_API/Schedule/UpdateSchedule.php?scheduleId={id}
     * Header: token, account_token
     * Body: JSON (lihat contoh)
     *
     * - Validasi medicalPersonelId, polyclinicId, dayName, timeStart, timeFinish wajib.
     * - Validasi medicalPersonelId aktif (status=1).
     * - Validasi polyclinicId aktif (status=1).
     * - timeStart harus < timeFinish.
     * - Tidak boleh ada irisan jadwal dengan data lain (tenaga medis & poli yang sama).
     * - Menjaga creatAt, creatBy, dan mengupdate updateAt, updateBy.
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
    $Limiter->check("update_schedule", 5, 60);

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

    // --- 6. Validasi Parameter scheduleId ---
    if (!isset($_GET['scheduleId']) || !is_numeric($_GET['scheduleId']) || (int)$_GET['scheduleId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter scheduleId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $scheduleId = (int) $_GET['scheduleId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_schedule' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah jadwal", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateSchedule] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data schedule yang akan diupdate ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM schedule WHERE scheduleId = :id LIMIT 1");
        $stmt->execute([':id' => $scheduleId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Jadwal tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateSchedule] Fetch data error: ' . $e->getMessage());
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
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : (int) $existingData['medicalPersonelId'];
    $polyclinicId      = isset($input['polyclinicId']) ? (int) $input['polyclinicId'] : (int) $existingData['polyclinicId'];
    $dayName           = isset($input['dayName']) ? trim($input['dayName']) : $existingData['dayName'];
    $timeStart         = isset($input['timeStart']) ? trim($input['timeStart']) : $existingData['timeStart'];
    $timeFinish        = isset($input['timeFinish']) ? trim($input['timeFinish']) : $existingData['timeFinish'];
    $quotaAssurance    = isset($input['quotaAssurance']) ? (int) $input['quotaAssurance'] : (int) $existingData['quotaAssurance'];
    $quotaGeneral      = isset($input['quotaGeneral']) ? (int) $input['quotaGeneral'] : (int) $existingData['quotaGeneral'];

    // --- 11. Validasi Field Wajib ---
    if ($medicalPersonelId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalPersonelId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($polyclinicId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "polyclinicId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($dayName)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "dayName wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($timeStart)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "timeStart wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($timeFinish)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "timeFinish wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi dayName ---
    $allowedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    if (!in_array($dayName, $allowedDays, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "dayName harus salah satu dari: " . implode(', ', $allowedDays), "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi medicalPersonelId (aktif status=1) ---
    try {
        $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE medicalPersonelId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateSchedule] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Validasi polyclinicId (aktif status=1) ---
    try {
        $stmt = $Conn->prepare("SELECT polyclinicId FROM polyclinic WHERE polyclinicId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $polyclinicId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "polyclinicId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateSchedule] Check polyclinicId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 15. Validasi timeStart dan timeFinish ---
    $timeStartObj = DateTime::createFromFormat('H:i', $timeStart);
    $timeFinishObj = DateTime::createFromFormat('H:i', $timeFinish);
    if (!$timeStartObj || !$timeFinishObj) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "timeStart atau timeFinish harus format HH:MM", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($timeStartObj >= $timeFinishObj) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "timeStart harus lebih awal dari timeFinish", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 16. Validasi irisan jadwal (overlap) ---
    // Cek apakah ada jadwal lain yang beririsan dengan jadwal ini, kecuali dirinya sendiri
    // Overlap terjadi jika:
    // - Hari sama
    // - medicalPersonelId sama dan/atau polyclinicId sama?
    // Dari ketentuan: "validasi jadwal dokter tidak beririsan dengan jadwal lain di poli berbeda atau sama"
    // Artinya: untuk tenaga medis yang sama, jadwal tidak boleh overlap di hari yang sama.
    // Juga untuk poli yang sama, tidak boleh ada dua jadwal yang overlap.
    // Kita akan cek dua kondisi: (1) tenaga medis + hari overlap, (2) poli + hari overlap.

    $overlapSql = "SELECT scheduleId, medicalPersonelId, polyclinicId, timeStart, timeFinish 
                FROM schedule 
                WHERE dayName = :dayName 
                AND scheduleId != :scheduleId
                AND (
                    (timeStart < :timeFinish AND timeFinish > :timeStart)
                )
                AND (
                    medicalPersonelId = :medicalPersonelId OR polyclinicId = :polyclinicId
                )";

    $stmt = $Conn->prepare($overlapSql);
    $stmt->execute([
        ':dayName' => $dayName,
        ':scheduleId' => $scheduleId,
        ':timeStart' => $timeStart,
        ':timeFinish' => $timeFinish,
        ':medicalPersonelId' => $medicalPersonelId,
        ':polyclinicId' => $polyclinicId
    ]);
    $overlap = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($overlap) {
        http_response_code(409);
        echo json_encode([
            "response" => [
                "message" => "Jadwal bertabrakan dengan jadwal lain. ID: {$overlap['scheduleId']} (Tenaga Medis: {$overlap['medicalPersonelId']}, Poli: {$overlap['polyclinicId']}, Waktu: {$overlap['timeStart']} - {$overlap['timeFinish']})",
                "code" => 409
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 17. Update Data ke Database ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE schedule SET
                    medicalPersonelId = :medicalPersonelId,
                    polyclinicId = :polyclinicId,
                    dayName = :dayName,
                    timeStart = :timeStart,
                    timeFinish = :timeFinish,
                    quotaAssurance = :quotaAssurance,
                    quotaGeneral = :quotaGeneral,
                    updateAt = :updateAt,
                    updateBy = :updateBy
                WHERE scheduleId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicalPersonelId' => $medicalPersonelId,
            ':polyclinicId' => $polyclinicId,
            ':dayName' => $dayName,
            ':timeStart' => $timeStart,
            ':timeFinish' => $timeFinish,
            ':quotaAssurance' => $quotaAssurance,
            ':quotaGeneral' => $quotaGeneral,
            ':updateAt' => $updatedDate,
            ':updateBy' => $loggedInAccountId,
            ':id' => $scheduleId
        ]);

        // --- 18. Ambil data terbaru untuk response ---
        $stmt = $Conn->prepare("
            SELECT
                s.*,
                mp.name AS medicalPersonelName,
                p.name AS polyclinicName,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM schedule s
            LEFT JOIN medical_personel mp ON s.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN polyclinic p ON s.polyclinicId = p.polyclinicId
            LEFT JOIN account ca ON s.creatBy = ca.accountId
            LEFT JOIN account ua ON s.updateBy = ua.accountId
            WHERE s.scheduleId = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $scheduleId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);

        // --- 19. Format response ---
        if ($updatedData) {
            $updatedData['scheduleId'] = (int) $updatedData['scheduleId'];
            $updatedData['medicalPersonelId'] = (int) $updatedData['medicalPersonelId'];
            $updatedData['polyclinicId'] = (int) $updatedData['polyclinicId'];
            $updatedData['quotaAssurance'] = $updatedData['quotaAssurance'] !== null ? (int) $updatedData['quotaAssurance'] : null;
            $updatedData['quotaGeneral'] = $updatedData['quotaGeneral'] !== null ? (int) $updatedData['quotaGeneral'] : null;
            $updatedData['creatBy'] = $updatedData['creatBy'] !== null ? (int) $updatedData['creatBy'] : null;
            $updatedData['updateBy'] = $updatedData['updateBy'] !== null ? (int) $updatedData['updateBy'] : null;

            if ($updatedData['medicalPersonelName'] === null) unset($updatedData['medicalPersonelName']);
            if ($updatedData['polyclinicName'] === null) unset($updatedData['polyclinicName']);
            if ($updatedData['createdName'] === null) unset($updatedData['createdName']);
            if ($updatedData['updatedName'] === null) unset($updatedData['updatedName']);
        }

        // --- 20. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Jadwal berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "scheduleId" => $scheduleId,
                "updated_at" => $updatedDate . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateSchedule] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>