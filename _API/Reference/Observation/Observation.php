<?php
    /**
     * List Observation Reference
     * Endpoint: GET /_API/Reference/Observation/Observation.php?limit=10&page=1&order_by=observationReferenceId&short_by=DESC&keyword_by=&keyword=
     * Header: token, account_token
     *
     * Menampilkan daftar referensi pemeriksaan (observation_reference) dengan paginasi, sorting, dan pencarian.
     * Data ditampilkan dengan informasi pembuat/update dari account.
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
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("list_observation_reference", 10, 60);

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

        // Validasi Permission (fitur list_observation_reference)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'list_observation_reference']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur list_observation_reference tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat daftar referensi pemeriksaan", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[ListObservationReference] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Tangkap parameter query ---
    $limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $page       = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $order_by   = isset($_GET['order_by']) ? trim($_GET['order_by']) : 'observationReferenceId';
    $short_by   = isset($_GET['short_by']) ? strtoupper(trim($_GET['short_by'])) : 'DESC';
    $keyword_by = isset($_GET['keyword_by']) ? trim($_GET['keyword_by']) : '';
    $keyword    = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

    // --- 8. Validasi limit dan page ---
    if ($limit < 10) $limit = 10;
    if ($limit > 100) $limit = 100;
    if ($page < 1) $page = 1;

    // Set default order_by jika kosong
    if (empty($order_by)) {
        $order_by = 'observationReferenceId';
    }
    if (empty($short_by)) {
        $short_by = 'DESC';
    }

    // --- 9. Daftar kolom yang diizinkan untuk sorting dan filter ---
    $allowedColumns = [
        'observationReferenceId', 'categoryName', 'categoryCode', 'categoryDisplay', 'categorySystem',
        'observationName', 'observationCode', 'observationDisplay', 'observationSystem',
        'unitName', 'unitCode', 'unitDisplay', 'unitSystem',
        'resultType', 'allowSex', 'allowAge', 'status',
        'creatAt', 'updateAt', 'creatBy', 'updateBy'
    ];

    // --- 10. Validasi order_by ---
    if (!in_array($order_by, $allowedColumns, true)) {
        $order_by = 'observationReferenceId';
    }

    // --- 11. Validasi short_by ---
    if (!in_array($short_by, ['ASC', 'DESC'], true)) {
        $short_by = 'DESC';
    }

    // --- 12. Validasi keyword_by ---
    if ($keyword_by !== '' && !in_array($keyword_by, $allowedColumns, true)) {
        $keyword_by = '';
    }

    $offset = ($page - 1) * $limit;

    // --- 13. Build WHERE clause untuk filter ---
    $where = "WHERE 1";
    $params = [];
    if ($keyword !== '' && $keyword_by !== '') {
        $where .= " AND o.`{$keyword_by}` LIKE :keyword";
        $params[':keyword'] = "%{$keyword}%";
    }

    // --- 14. Count total data ---
    try {
        $countSql = "SELECT COUNT(*) AS total FROM observation_reference o " . $where;
        $countStmt = $Conn->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) ($countRow['total'] ?? 0);
    } catch (PDOException $e) {
        error_log('[ListObservationReference] Count error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // Validasi page tidak boleh melebihi total halaman
    $totalPages = ceil($total / $limit);
    if ($page > $totalPages && $total > 0) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Page tidak boleh melebihi total halaman ($totalPages)",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 15. Query utama dengan JOIN ke account untuk nama pembuat/update ---
    try {
        $sql = "SELECT
                    o.observationReferenceId,
                    o.categoryName,
                    o.categoryCode,
                    o.categoryDisplay,
                    o.categorySystem,
                    o.observationName,
                    o.observationCode,
                    o.observationDisplay,
                    o.observationSystem,
                    o.unitName,
                    o.unitCode,
                    o.unitDisplay,
                    o.unitSystem,
                    o.resultType,
                    o.allowSex,
                    o.allowAge,
                    o.status,
                    o.creatAt,
                    o.updateAt,
                    o.creatBy,
                    cAccount.name AS createdName,
                    o.updateBy,
                    uAccount.name AS updatedName
                FROM observation_reference o
                LEFT JOIN account cAccount ON o.creatBy = cAccount.accountId
                LEFT JOIN account uAccount ON o.updateBy = uAccount.accountId
                " . $where;
        $sql .= " ORDER BY o.`{$order_by}` {$short_by} LIMIT {$offset}, {$limit}";

        $stmt = $Conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- 16. Format response data ---
        foreach ($rows as &$row) {
            $row['observationReferenceId'] = (int) $row['observationReferenceId'];
            $row['allowSex'] = (int) $row['allowSex'];
            $row['allowAge'] = (int) $row['allowAge'];
            $row['status'] = (int) $row['status'];
            $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;

            // Hapus null names
            if ($row['createdName'] === null) unset($row['createdName']);
            if ($row['updatedName'] === null) unset($row['updatedName']);
        }
        unset($row);

        // --- 17. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Daftar referensi pemeriksaan berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "total" => $total,
                "limit" => $limit,
                "page" => $page,
                "total_pages" => $totalPages,
                "offset" => $offset,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $rows
        ]);

    } catch (PDOException $e) {
        error_log('[ListObservationReference] Query error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>