<?php
    /**
     * List Patient
     * Endpoint: GET /_API/Patient/Patient.php?limit=10&page=1&order_by=patientId&short_by=DESC&keyword_by=&keyword=
     * Header: token, account_token
     * 
     * Menampilkan daftar pasien dengan paginasi, sorting, pencarian, serta informasi pembuat dan pengubah.
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
    $Limiter->check("list_patient", 10, 60);

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
        $stmt->execute([':account_token' => $accountToken, ':nowUtc' => $nowUtc]);
        $accountTokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$accountTokenData) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }
        $loggedInAccountId = (int) $accountTokenData['accountId'];

        // Validasi Permission (fitur list_patient)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'list_patient']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur list_patient tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat daftar pasien", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[ListPatient] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Tangkap parameter query ---
    $limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $page       = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $order_by   = isset($_GET['order_by']) ? validateAndSanitizeInput($_GET['order_by']) : 'patientId';
    $short_by   = isset($_GET['short_by']) ? strtoupper(validateAndSanitizeInput($_GET['short_by'])) : 'DESC';
    $keyword_by = isset($_GET['keyword_by']) ? validateAndSanitizeInput($_GET['keyword_by']) : '';
    $keyword    = isset($_GET['keyword']) ? validateAndSanitizeInput($_GET['keyword']) : '';

    // --- 8. Validasi limit dan page ---
    if ($limit < 10) $limit = 10;
    if ($limit > 100) $limit = 100;
    if ($page < 1) $page = 1;

    // --- 9. Daftar kolom yang diizinkan untuk sorting dan filter ---
    $allowedColumns = [
        'patientId', 'noMedicalRecord', 'satuSehatCode', 'isInfant', 'name',
        'email', 'phone', 'gender', 'birthPlace', 'birthDate', 'nik',
        'religion', 'martialStatus', 'lastEducation', 'occupation',
        'language', 'ethnic', 'citizenshipStatus',
        'provinceId', 'cityId', 'districtId', 'villageId',
        'rt', 'rw', 'postalCode', 'address', 'medicalRecordStatus',
        'oldMedicalRecord', 'motherMedicalRecord', 'kkNumber', 'kkName',
        'creatBy', 'creatAt', 'updateBy', 'updateAt'
    ];

    // --- 10. Validasi order_by ---
    if (!in_array($order_by, $allowedColumns, true)) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "order_by tidak valid", "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi short_by ---
    if (!in_array($short_by, ['ASC', 'DESC'], true)) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "short_by harus ASC atau DESC", "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi keyword_by ---
    if ($keyword_by !== '' && !in_array($keyword_by, $allowedColumns, true)) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "keyword_by tidak valid", "code" => 400], "metadata" => []]);
        exit;
    }

    $offset = ($page - 1) * $limit;

    // --- 13. Build WHERE clause untuk filter ---
    $where = "WHERE 1";
    $params = [];
    if ($keyword !== '' && $keyword_by !== '') {
        $where .= " AND p.`{$keyword_by}` LIKE :keyword";
        $params[':keyword'] = "%{$keyword}%";
    }

    // --- 14. Count total data ---
    try {
        $countSql = "SELECT COUNT(*) AS total FROM patient p " . $where;
        $countStmt = $Conn->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) ($countRow['total'] ?? 0);
    } catch (PDOException $e) {
        error_log('[ListPatient] Count error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 15. Query utama dengan JOIN region dan account ---
    try {
        $sql = "SELECT
                    p.patientId,
                    p.noMedicalRecord,
                    p.satuSehatCode,
                    p.isInfant,
                    p.name,
                    p.email,
                    p.phone,
                    p.gender,
                    p.birthPlace,
                    p.birthDate,
                    p.nik,
                    p.religion,
                    p.martialStatus,
                    p.lastEducation,
                    p.occupation,
                    p.language,
                    p.ethnic,
                    p.citizenshipStatus,
                    p.provinceId,
                    rp.name AS provinceName,
                    p.cityId,
                    rc.name AS cityName,
                    p.districtId,
                    rd.name AS districtName,
                    p.villageId,
                    rv.name AS villageName,
                    p.rt,
                    p.rw,
                    p.postalCode,
                    p.address,
                    p.medicalRecordStatus,
                    p.oldMedicalRecord,
                    p.motherMedicalRecord,
                    p.kkNumber,
                    p.kkName,
                    p.photo,
                    p.creatBy,
                    cAccount.name AS creatName,
                    p.creatAt,
                    p.updateBy,
                    uAccount.name AS updateName,
                    p.updateAt
                FROM patient p
                LEFT JOIN region_province rp ON p.provinceId = rp.provinceId
                LEFT JOIN region_city rc ON p.cityId = rc.cityId
                LEFT JOIN region_district rd ON p.districtId = rd.districtId
                LEFT JOIN region_village rv ON p.villageId = rv.villageId
                LEFT JOIN account cAccount ON p.creatBy = cAccount.accountId
                LEFT JOIN account uAccount ON p.updateBy = uAccount.accountId
                " . $where;
        $sql .= " ORDER BY p.`{$order_by}` {$short_by} LIMIT {$offset}, {$limit}";

        $stmt = $Conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- 16. Format response data ---
        foreach ($rows as &$row) {
            $row['patientId'] = (int) $row['patientId'];
            $row['isInfant'] = (int) $row['isInfant'];
            $row['provinceId'] = $row['provinceId'] !== null ? (int) $row['provinceId'] : null;
            $row['cityId'] = $row['cityId'] !== null ? (int) $row['cityId'] : null;
            $row['districtId'] = $row['districtId'] !== null ? (int) $row['districtId'] : null;
            $row['villageId'] = $row['villageId'] !== null ? (int) $row['villageId'] : null;
            $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;

            // Hapus null region names
            if ($row['provinceName'] === null) unset($row['provinceName']);
            if ($row['cityName'] === null) unset($row['cityName']);
            if ($row['districtName'] === null) unset($row['districtName']);
            if ($row['villageName'] === null) unset($row['villageName']);

            // Hapus null creator/updater names
            if ($row['creatName'] === null) unset($row['creatName']);
            if ($row['updateName'] === null) unset($row['updateName']);
        }
        unset($row);

        // --- 17. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Daftar pasien berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "total" => $total,
                "limit" => $limit,
                "page" => $page,
                "offset" => $offset,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $rows
        ]);

    } catch (PDOException $e) {
        error_log('[ListPatient] Query error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>