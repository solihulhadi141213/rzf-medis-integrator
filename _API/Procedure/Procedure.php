<?php
    /**
     * List Procedure (Procedure Encounter)
     * Endpoint: GET /_API/Procedure/Procedure.php?limit=10&page=1&order_by=procedureId&short_by=DESC&keyword_by=&keyword=
     * Header: token, account_token
     *
     * Menampilkan daftar tindakan (procedure_encounter) dengan paginasi, sorting, dan pencarian.
     * Data ditampilkan dengan informasi pasien, kunjungan, referensi tindakan, dan pembuat/update.
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
    $Limiter->check("list_procedure", 10, 60);

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

        // Validasi Permission (fitur list_procedure)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'list_procedure']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur list_procedure tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat daftar tindakan", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[ListProcedure] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Tangkap parameter query ---
    $limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $page       = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $order_by   = isset($_GET['order_by']) ? trim($_GET['order_by']) : 'procedureId';
    $short_by   = isset($_GET['short_by']) ? strtoupper(trim($_GET['short_by'])) : 'DESC';
    $keyword_by = isset($_GET['keyword_by']) ? trim($_GET['keyword_by']) : '';
    $keyword    = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

    // --- 8. Validasi limit dan page ---
    if ($limit < 10) $limit = 10;
    if ($limit > 100) $limit = 100;
    if ($page < 1) $page = 1;

    // Set default order_by jika kosong
    if (empty($order_by)) {
        $order_by = 'procedureId';
    }
    if (empty($short_by)) {
        $short_by = 'DESC';
    }

    // --- 9. Daftar kolom yang diizinkan untuk sorting dan filter (dari tabel procedure_encounter) ---
    // Hanya kolom dari procedure_encounter yang diizinkan untuk order_by dan keyword_by
    $allowedColumns = [
        'procedureId', 'patientId', 'encounterId', 'satusehatCode',
        'procedureStart', 'procedureEnd', 'procedureReferenceId',
        'resonReference', 'resonCode', 'resonDisplay',
        'postProcedure', 'creatAt', 'updateAt', 'creatBy', 'updateBy'
    ];

    // --- 10. Validasi order_by ---
    if (!in_array($order_by, $allowedColumns, true)) {
        $order_by = 'procedureId';
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
        $where .= " AND pe.`{$keyword_by}` LIKE :keyword";
        $params[':keyword'] = "%{$keyword}%";
    }

    // --- 14. Count total data ---
    try {
        $countSql = "SELECT COUNT(*) AS total FROM procedure_encounter pe " . $where;
        $countStmt = $Conn->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) ($countRow['total'] ?? 0);
    } catch (PDOException $e) {
        error_log('[ListProcedure] Count error: ' . $e->getMessage());
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

    // --- 15. Query utama dengan JOIN ke patient, encounter, procedure_reference, account ---
    try {
        $sql = "SELECT
                    pe.procedureId,
                    pe.patientId,
                    pat.name AS patientName,
                    pat.noMedicalRecord,
                    pe.encounterId,
                    e.EncounterCode,
                    pe.satusehatCode,
                    pe.procedureStart,
                    pe.procedureEnd,
                    pe.procedureReferenceId,
                    pr.procedureName,
                    pr.procedureCode,
                    pr.procedureDisplay,
                    pr.procedureSystem,
                    pr.bodySiteName,
                    pr.bodySiteCode,
                    pr.bodySiteDisplay,
                    pr.bodySiteSystem,
                    pr.icd9Code,
                    pr.icd9Description,
                    pe.resonReference,
                    pe.resonCode,
                    pe.resonDisplay,
                    pe.postProcedure,
                    pe.creatAt,
                    pe.updateAt,
                    pe.creatBy,
                    cAccount.name AS createdName,
                    pe.updateBy,
                    uAccount.name AS updatedName
                FROM procedure_encounter pe
                LEFT JOIN patient pat ON pe.patientId = pat.patientId
                LEFT JOIN encounter e ON pe.encounterId = e.encounterId
                LEFT JOIN procedure_reference pr ON pe.procedureReferenceId = pr.procedureReferenceId
                LEFT JOIN account cAccount ON pe.creatBy = cAccount.accountId
                LEFT JOIN account uAccount ON pe.updateBy = uAccount.accountId
                " . $where;
        $sql .= " ORDER BY pe.`{$order_by}` {$short_by} LIMIT {$offset}, {$limit}";

        $stmt = $Conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- 16. Format response data ---
        foreach ($rows as &$row) {
            $row['procedureId'] = (int) $row['procedureId'];
            $row['patientId'] = (int) $row['patientId'];
            $row['encounterId'] = (int) $row['encounterId'];
            $row['procedureReferenceId'] = (int) $row['procedureReferenceId'];
            $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;

            // Hapus null values
            if ($row['patientName'] === null) unset($row['patientName']);
            if ($row['noMedicalRecord'] === null) unset($row['noMedicalRecord']);
            if ($row['EncounterCode'] === null) unset($row['EncounterCode']);
            if ($row['procedureName'] === null) unset($row['procedureName']);
            if ($row['procedureCode'] === null) unset($row['procedureCode']);
            if ($row['procedureDisplay'] === null) unset($row['procedureDisplay']);
            if ($row['procedureSystem'] === null) unset($row['procedureSystem']);
            if ($row['bodySiteName'] === null) unset($row['bodySiteName']);
            if ($row['bodySiteCode'] === null) unset($row['bodySiteCode']);
            if ($row['bodySiteDisplay'] === null) unset($row['bodySiteDisplay']);
            if ($row['bodySiteSystem'] === null) unset($row['bodySiteSystem']);
            if ($row['icd9Code'] === null) unset($row['icd9Code']);
            if ($row['icd9Description'] === null) unset($row['icd9Description']);
            if ($row['createdName'] === null) unset($row['createdName']);
            if ($row['updatedName'] === null) unset($row['updatedName']);
        }
        unset($row);

        // --- 17. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Daftar tindakan berhasil diambil",
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
        error_log('[ListProcedure] Query error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>