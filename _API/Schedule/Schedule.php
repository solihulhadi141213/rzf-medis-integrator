<?php
    /**
     * List Schedule
     * Endpoint: GET /_API/Schedule/Schedule.php?medicalPersonelId={id}&polyclinicId={id}&dayName={Monday|...}
     * Header: token, account_token
     *
     * Menampilkan daftar jadwal praktek dengan filter opsional berdasarkan:
     * - medicalPersonelId
     * - polyclinicId
     * - dayName (nama hari dalam bahasa Inggris)
     * WAJIB: setidaknya satu parameter filter harus diisi.
     */

    // --- 1. Response Header ---
    header('Content-Type: application/json');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    date_default_timezone_set('UTC');

    // --- 2. Include Dependencies ---
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("list_schedule", 10, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            "response" => ["message" => "Metode request tidak diizinkan", "code" => 405],
            "metadata" => []
        ]);
        exit;
    }

    // --- 5. Validasi Header Token & Account Token ---
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

    // --- 6. Validasi Token dan Permission ---
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
            echo json_encode(["response" => ["message" => "Token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }
        if ($tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "Token sudah kedaluwarsa", "code" => 401], "metadata" => []]);
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
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }
        $loggedInAccountId = (int) $accountTokenData['accountId'];

        // Validasi Permission (fitur list_schedule)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'list_schedule']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur list_schedule tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat jadwal", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[ListSchedule] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Tangkap parameter query (opsional) ---
    $medicalPersonelId = isset($_GET['medicalPersonelId']) ? (int) $_GET['medicalPersonelId'] : null;
    $polyclinicId      = isset($_GET['polyclinicId']) ? (int) $_GET['polyclinicId'] : null;
    $dayName           = isset($_GET['dayName']) ? trim($_GET['dayName']) : null;

    // --- 8. Validasi wajib: setidaknya satu parameter harus diisi ---
    if (($medicalPersonelId === null || $medicalPersonelId <= 0) &&
        ($polyclinicId === null || $polyclinicId <= 0) &&
        (empty($dayName))) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "Setidaknya satu parameter filter harus diisi: medicalPersonelId, polyclinicId, atau dayName",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 9. Validasi dayName jika diisi ---
    $allowedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    if ($dayName !== null && $dayName !== '' && !in_array($dayName, $allowedDays, true)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "dayName tidak valid. Harus salah satu dari: " . implode(', ', $allowedDays),
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 10. Build WHERE clause ---
    $where = "WHERE 1=1";
    $params = [];

    if ($medicalPersonelId !== null && $medicalPersonelId > 0) {
        $where .= " AND s.medicalPersonelId = :medicalPersonelId";
        $params[':medicalPersonelId'] = $medicalPersonelId;
    }
    if ($polyclinicId !== null && $polyclinicId > 0) {
        $where .= " AND s.polyclinicId = :polyclinicId";
        $params[':polyclinicId'] = $polyclinicId;
    }
    if ($dayName !== null && $dayName !== '') {
        $where .= " AND s.dayName = :dayName";
        $params[':dayName'] = $dayName;
    }

    // --- 11. Query utama dengan JOIN ke medical_personel, polyclinic, dan account ---
    try {
        $sql = "SELECT
                    s.scheduleId,
                    s.medicalPersonelId,
                    mp.name AS medicalPersonelName,
                    mp.medicalPersonelCategory,
                    s.polyclinicId,
                    p.name AS polyclinicName,
                    s.dayName,
                    s.timeStart,
                    s.timeFinish,
                    s.quotaAssurance,
                    s.quotaGeneral,
                    s.creatBy,
                    ca.name AS createdName,
                    s.creatAt,
                    s.updateBy,
                    ua.name AS updatedName,
                    s.updateAt
                FROM schedule s
                LEFT JOIN medical_personel mp ON s.medicalPersonelId = mp.medicalPersonelId
                LEFT JOIN polyclinic p ON s.polyclinicId = p.polyclinicId
                LEFT JOIN account ca ON s.creatBy = ca.accountId
                LEFT JOIN account ua ON s.updateBy = ua.accountId
                " . $where . "
                ORDER BY 
                    FIELD(s.dayName, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                    s.timeStart ASC";

        $stmt = $Conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- 12. Format response ---
        foreach ($rows as &$row) {
            $row['scheduleId'] = (int) $row['scheduleId'];
            $row['medicalPersonelId'] = (int) $row['medicalPersonelId'];
            $row['polyclinicId'] = (int) $row['polyclinicId'];
            $row['quotaAssurance'] = $row['quotaAssurance'] !== null ? (int) $row['quotaAssurance'] : null;
            $row['quotaGeneral'] = $row['quotaGeneral'] !== null ? (int) $row['quotaGeneral'] : null;
            $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;

            // Hapus null names
            if ($row['medicalPersonelName'] === null) unset($row['medicalPersonelName']);
            if ($row['polyclinicName'] === null) unset($row['polyclinicName']);
            if ($row['createdName'] === null) unset($row['createdName']);
            if ($row['updatedName'] === null) unset($row['updatedName']);
        }
        unset($row);

        $total = count($rows);

        // --- 13. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Daftar jadwal berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "total" => $total,
                "filters" => [
                    "medicalPersonelId" => $medicalPersonelId,
                    "polyclinicId" => $polyclinicId,
                    "dayName" => $dayName
                ],
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $rows
        ]);

    } catch (PDOException $e) {
        error_log('[ListSchedule] Query error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>