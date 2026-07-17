<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    // Set Time Zone
    date_default_timezone_set('UTC');

    // Include-Require Resource
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // Limiter
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("list_medical_personel", 5, 60);

    // Validate Method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

    // Validasi Header token dan account_token
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

      // Tangkap parameter query
    $limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $page       = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $order_by   = isset($_GET['order_by']) ? validateAndSanitizeInput($_GET['order_by']) : 'medicalPersonelId';
    $short_by   = isset($_GET['short_by']) ? strtoupper(validateAndSanitizeInput($_GET['short_by'])) : 'DESC';
    $keyword_by = isset($_GET['keyword_by']) ? validateAndSanitizeInput($_GET['keyword_by']) : '';
    $keyword    = isset($_GET['keyword']) ? validateAndSanitizeInput($_GET['keyword']) : '';

    // Validasi limit dan page
    if ($limit < 10) $limit = 10;
    if ($limit > 100) $limit = 100;
    if ($page < 1) $page = 1;

    // Daftar kolom yang diizinkan untuk sorting dan filter
    $allowedColumns = [
        'medicalPersonelId',
        'medicalPersonelCode',
        'id_practitioner',
        'medicalPersonelCategory',
        'nik',
        'name',
        'gender',
        'email',
        'phone',
        'citizenshipStatus',
        'provinceId',
        'cityId',
        'districtId',
        'villageId',
        'postalCode',
        'address',
        'photo',
        'status',
        'createdDate',
        'updatedDate',
        'accountId'
    ];

    // Validasi order_by
    if (!in_array($order_by, $allowedColumns, true)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "order_by tidak valid",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // Validasi short_by
    if (!in_array($short_by, ['ASC', 'DESC'], true)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "short_by harus ASC atau DESC",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // Validasi keyword_by
    if ($keyword_by !== '' && !in_array($keyword_by, $allowedColumns, true)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "keyword_by tidak valid",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    $offset = ($page - 1) * $limit;

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

        $nowUtc = gmdate('Y-m-d H:i:s');
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

        // Build WHERE clause untuk filter
        $where = "WHERE 1";
        $params = [];
        if ($keyword !== '' && $keyword_by !== '') {
            $where .= " AND mp.`{$keyword_by}` LIKE :keyword";
            $params[':keyword'] = "%{$keyword}%";
        }

        // Count total data
        $countSql = "SELECT COUNT(*) AS total FROM medical_personel mp " . $where;
        $countStmt = $Conn->prepare($countSql);
        $countStmt->execute($params);
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) ($countRow['total'] ?? 0);

        // Query utama untuk mengambil data medical personel
        $sql = "SELECT
            mp.medicalPersonelId,
            mp.medicalPersonelCode,
            mp.id_practitioner,
            mp.medicalPersonelCategory,
            mp.nik,
            mp.name,
            mp.gender,
            mp.email,
            mp.phone,
            mp.citizenshipStatus,
            mp.provinceId,
            rp.name AS provinceName,
            mp.cityId,
            rc.name AS cityName,
            mp.districtId,
            rd.name AS districtName,
            mp.villageId,
            rv.name AS villageName,
            mp.postalCode,
            mp.address,
            mp.photo,
            mp.status,
            mp.createdBy,
            ca.name AS createdName,
            mp.createdDate,
            mp.updatedBy,
            ua.name AS updatedName,
            mp.updatedDate,
            mp.accountId
        FROM medical_personel mp
        LEFT JOIN region_province rp ON mp.provinceId = rp.provinceId
        LEFT JOIN region_city rc ON mp.cityId = rc.cityId
        LEFT JOIN region_district rd ON mp.districtId = rd.districtId
        LEFT JOIN region_village rv ON mp.villageId = rv.villageId
        LEFT JOIN account ca ON mp.createdBy = ca.accountId
        LEFT JOIN account ua ON mp.updatedBy = ua.accountId
        " . $where;
        $sql .= " ORDER BY mp.`{$order_by}` {$short_by} LIMIT {$offset}, {$limit}";

        $stmt = $Conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format response data
        foreach ($rows as &$row) {
            $row['medicalPersonelId'] = isset($row['medicalPersonelId']) ? (int) $row['medicalPersonelId'] : null;
            $row['provinceId'] = isset($row['provinceId']) ? (int) $row['provinceId'] : null;
            $row['cityId'] = isset($row['cityId']) ? (int) $row['cityId'] : null;
            $row['districtId'] = isset($row['districtId']) ? (int) $row['districtId'] : null;
            $row['villageId'] = isset($row['villageId']) ? (int) $row['villageId'] : null;
            $row['status'] = isset($row['status']) ? (int) $row['status'] : 0;
            $row['status_display'] = $row['status'] === 1 ? 'Terdaftar' : 'Dihapus';
            $row['createdBy'] = isset($row['createdBy']) ? (int) $row['createdBy'] : null;
            $row['updatedBy'] = isset($row['updatedBy']) ? (int) $row['updatedBy'] : null;
            $row['accountId'] = isset($row['accountId']) ? (int) $row['accountId'] : null;
            
            // Remove null region names
            if ($row['provinceName'] === null) {
                unset($row['provinceName']);
            }
            if ($row['cityName'] === null) {
                unset($row['cityName']);
            }
            if ($row['districtName'] === null) {
                unset($row['districtName']);
            }
            if ($row['villageName'] === null) {
                unset($row['villageName']);
            }
            
            // Remove null creator/updater names
            if ($row['createdName'] === null) {
                unset($row['createdName']);
            }
            if ($row['updatedName'] === null) {
                unset($row['updatedName']);
            }
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "List medical personel berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "total" => $total,
                "limit" => $limit,
                "page" => $page,
                "offset" => $offset,
                "retrieved_at" => gmdate('Y-m-d H:i:s') . ' GMT'
            ],
            "data" => $rows
        ]);
    } catch (Exception $e) {
        error_log('[ListMedicalPersonel] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Internal Server Error",
                "code" => 500
            ],
            "metadata" => []
        ]);
    }
?>
