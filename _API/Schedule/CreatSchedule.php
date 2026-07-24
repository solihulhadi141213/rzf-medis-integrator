<?php
    /**
     * Create Schedule
     * Endpoint: POST /_API/Schedule/CreateSchedule.php
     * Header: token, account_token
     * Body: JSON
     *
     * - Validasi medicalPersonelId (status=1), polyclinicId (status=1)
     * - Validasi timeStart < timeFinish
     * - Validasi tidak ada jadwal yang tumpang tindih untuk dokter yang sama di hari yang sama
     * - Insert schedule dengan creatAt, updateAt, creatBy, updateBy
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
    $Limiter->check("create_schedule", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_schedule' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah jadwal", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateSchedule] Auth error: ' . $e->getMessage());
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

    // --- 8. Ambil nilai dari body ---
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : 0;
    $polyclinicId      = isset($input['polyclinicId']) ? (int) $input['polyclinicId'] : 0;
    $dayName           = isset($input['dayName']) ? trim($input['dayName']) : '';
    $timeStart         = isset($input['timeStart']) ? trim($input['timeStart']) : '';
    $timeFinish        = isset($input['timeFinish']) ? trim($input['timeFinish']) : '';
    $quotaAssurance    = isset($input['quotaAssurance']) ? (int) $input['quotaAssurance'] : null;
    $quotaGeneral      = isset($input['quotaGeneral']) ? (int) $input['quotaGeneral'] : null;

    // --- 9. Validasi Field Wajib ---
    $requiredFields = ['medicalPersonelId', 'polyclinicId', 'dayName', 'timeStart', 'timeFinish'];
    foreach ($requiredFields as $field) {
        $value = $$field;
        if (empty($value) && $value !== 0) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 10. Validasi medicalPersonelId (status=1) ---
    try {
        $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE medicalPersonelId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateSchedule] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi polyclinicId (status=1) ---
    try {
        $stmt = $Conn->prepare("SELECT polyclinicId FROM polyclinic WHERE polyclinicId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $polyclinicId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "polyclinicId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateSchedule] Check polyclinicId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi dayName ---
    $allowedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    if (!in_array($dayName, $allowedDays, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "dayName tidak valid. Harus salah satu dari: " . implode(', ', $allowedDays), "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi format timeStart dan timeFinish (HH:MM) ---
    if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $timeStart)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "timeStart harus format HH:MM (24 jam)", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $timeFinish)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "timeFinish harus format HH:MM (24 jam)", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 14. Validasi timeStart < timeFinish ---
    if ($timeStart >= $timeFinish) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "timeStart harus lebih awal dari timeFinish", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 15. Validasi jadwal tidak beririsan ---
    // Cek apakah sudah ada jadwal untuk dokter yang sama di hari yang sama
    // dengan rentang waktu yang tumpang tindih
    try {
        $sql = "SELECT scheduleId 
                FROM schedule 
                WHERE medicalPersonelId = :medicalPersonelId 
                AND dayName = :dayName
                AND (
                    (timeStart < :timeFinish AND timeFinish > :timeStart)
                )";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicalPersonelId' => $medicalPersonelId,
            ':dayName' => $dayName,
            ':timeStart' => $timeStart,
            ':timeFinish' => $timeFinish
        ]);
        $overlap = $stmt->fetch();
        if ($overlap) {
            http_response_code(409);
            echo json_encode([
                "response" => [
                    "message" => "Jadwal dokter yang sama pada hari yang sama sudah ada yang tumpang tindih",
                    "code" => 409
                ],
                "metadata" => []
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateSchedule] Check overlap error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 16. Validasi quota (jika diisi, harus >= 0) ---
    if ($quotaAssurance !== null && $quotaAssurance < 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "quotaAssurance tidak boleh negatif", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($quotaGeneral !== null && $quotaGeneral < 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "quotaGeneral tidak boleh negatif", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 17. Insert ke database ---
    try {
        $createdDate = $nowUtc;

        $sql = "INSERT INTO schedule (
                    medicalPersonelId,
                    polyclinicId,
                    dayName,
                    timeStart,
                    timeFinish,
                    quotaAssurance,
                    quotaGeneral,
                    creatAt,
                    updateAt,
                    creatBy,
                    updateBy
                ) VALUES (
                    :medicalPersonelId,
                    :polyclinicId,
                    :dayName,
                    :timeStart,
                    :timeFinish,
                    :quotaAssurance,
                    :quotaGeneral,
                    :creatAt,
                    :updateAt,
                    :creatBy,
                    :updateBy
                )";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicalPersonelId' => $medicalPersonelId,
            ':polyclinicId' => $polyclinicId,
            ':dayName' => $dayName,
            ':timeStart' => $timeStart,
            ':timeFinish' => $timeFinish,
            ':quotaAssurance' => $quotaAssurance,
            ':quotaGeneral' => $quotaGeneral,
            ':creatAt' => $createdDate,
            ':updateAt' => $createdDate,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);

        $scheduleId = (int) $Conn->lastInsertId();

        // --- 18. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Jadwal berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "scheduleId" => $scheduleId,
                "created_at" => $createdDate . ' GMT'
            ],
            "data" => [
                "scheduleId" => $scheduleId,
                "medicalPersonelId" => $medicalPersonelId,
                "polyclinicId" => $polyclinicId,
                "dayName" => $dayName,
                "timeStart" => $timeStart,
                "timeFinish" => $timeFinish,
                "quotaAssurance" => $quotaAssurance,
                "quotaGeneral" => $quotaGeneral,
                "creatBy" => $loggedInAccountId,
                "creatAt" => $createdDate,
                "updateBy" => $loggedInAccountId,
                "updateAt" => $createdDate
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[CreateSchedule] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data jadwal: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>