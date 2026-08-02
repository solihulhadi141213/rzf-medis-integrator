<?php
    /**
     * List Observation Result
     * Endpoint: GET /_API/Observation/Observation.php?limit=10&page=1&order_by=observationResumeId&short_by=DESC&keyword_by=&keyword=
     * Header: token, account_token
     *
     * Menampilkan daftar hasil observasi (observation_result) dengan paginasi, sorting, dan pencarian.
     * Menampilkan informasi pasien, kunjungan, tenaga medis, referensi observasi, dan interpretasi.
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
    $Limiter->check("list_observation", 10, 60);

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

        // Validasi Permission (fitur list_observation)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'list_observation']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur list_observation tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat daftar hasil observasi", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[ListObservation] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Tangkap parameter query ---
    $limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $page       = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $order_by   = isset($_GET['order_by']) ? trim($_GET['order_by']) : 'observationResumeId';
    $short_by   = isset($_GET['short_by']) ? strtoupper(trim($_GET['short_by'])) : 'DESC';
    $keyword_by = isset($_GET['keyword_by']) ? trim($_GET['keyword_by']) : '';
    $keyword    = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

    // --- 8. Validasi limit dan page ---
    if ($limit < 10) $limit = 10;
    if ($limit > 100) $limit = 100;
    if ($page < 1) $page = 1;

    // Set default order_by jika kosong
    if (empty($order_by)) {
        $order_by = 'observationResumeId';
    }
    if (empty($short_by)) {
        $short_by = 'DESC';
    }

    // --- 9. Daftar kolom yang diizinkan untuk sorting dan filter (hanya dari observation_result) ---
    $allowedColumns = [
        'observationResumeId', 'observationReferenceId', 'satuSehatCode',
        'patientId', 'encounterId', 'medicalPersonelId',
        'observationAt', 'resultNumeric', 'resultDecimal', 'resultCoded', 'resultText',
        'InterpertationByAge', 'InterpertationByCoded', 'InterpertationByRange',
        'OtherDetail', 'creatAt', 'updateAt', 'creatBy', 'updateBy'
    ];

    // --- 10. Validasi order_by ---
    if (!in_array($order_by, $allowedColumns, true)) {
        $order_by = 'observationResumeId';
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
        // Gunakan alias 'o' untuk tabel observation_result
        $where .= " AND o.`{$keyword_by}` LIKE :keyword";
        $params[':keyword'] = "%{$keyword}%";
    }

    // --- 14. Count total data ---
    try {
        $countSql = "SELECT COUNT(*) AS total FROM observation_result o " . $where;
        $countStmt = $Conn->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) ($countRow['total'] ?? 0);
    } catch (PDOException $e) {
        error_log('[ListObservation] Count error: ' . $e->getMessage());
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

    // --- 15. Query utama dengan JOIN ke tabel terkait ---
    try {
        $sql = "SELECT
                    o.observationResumeId,
                    o.observationReferenceId,
                    ref.observationName,
                    ref.observationCode,
                    ref.observationDisplay,
                    ref.observationSystem,
                    o.satuSehatCode,
                    o.patientId,
                    p.name AS patientName,
                    p.noMedicalRecord,
                    o.encounterId,
                    e.EncounterCode,
                    o.medicalPersonelId,
                    mp.name AS medicalPersonelName,
                    o.observationAt,
                    o.resultNumeric,
                    o.resultDecimal,
                    o.resultCoded,
                    o.resultText,
                    o.InterpertationByAge,
                    age.ageCategory AS ageCategory,
                    o.InterpertationByCoded,
                    coded.labelResult AS codedLabel,
                    coded.displayResult AS codedDisplay,
                    coded.codeResult AS codedCode,
                    coded.systemResult AS codedSystem,
                    o.InterpertationByRange,
                    rng.InterpertationLabel AS rangeLabel,
                    rng.InterpertationDisplay AS rangeDisplay,
                    rng.InterpertationCode AS rangeCode,
                    rng.InterpertationSystem AS rangeSystem,
                    rng.rangeOperator,
                    rng.minValue,
                    rng.maxValue,
                    o.OtherDetail,
                    o.creatAt,
                    o.updateAt,
                    o.creatBy,
                    cAccount.name AS createdName,
                    o.updateBy,
                    uAccount.name AS updatedName
                FROM observation_result o
                LEFT JOIN patient p ON o.patientId = p.patientId
                LEFT JOIN encounter e ON o.encounterId = e.encounterId
                LEFT JOIN medical_personel mp ON o.medicalPersonelId = mp.medicalPersonelId
                LEFT JOIN observation_reference ref ON o.observationReferenceId = ref.observationReferenceId
                LEFT JOIN observation_reference_age age ON o.InterpertationByAge = age.observationReferenceAgeId
                LEFT JOIN observation_reference_coded coded ON o.InterpertationByCoded = coded.observationReferenceCodedId
                LEFT JOIN observation_reference_range rng ON o.InterpertationByRange = rng.observationResultRangeId
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
            $row['observationResumeId'] = (int) $row['observationResumeId'];
            $row['observationReferenceId'] = (int) $row['observationReferenceId'];
            $row['patientId'] = (int) $row['patientId'];
            $row['encounterId'] = (int) $row['encounterId'];
            $row['medicalPersonelId'] = (int) $row['medicalPersonelId'];
            $row['InterpertationByAge'] = $row['InterpertationByAge'] !== null ? (int) $row['InterpertationByAge'] : null;
            $row['InterpertationByCoded'] = $row['InterpertationByCoded'] !== null ? (int) $row['InterpertationByCoded'] : null;
            $row['InterpertationByRange'] = $row['InterpertationByRange'] !== null ? (int) $row['InterpertationByRange'] : null;
            $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;
            $row['resultNumeric'] = $row['resultNumeric'] !== null ? (int) $row['resultNumeric'] : null;
            $row['resultDecimal'] = $row['resultDecimal'] !== null ? (float) $row['resultDecimal'] : null;
            $row['minValue'] = $row['minValue'] !== null ? (float) $row['minValue'] : null;
            $row['maxValue'] = $row['maxValue'] !== null ? (float) $row['maxValue'] : null;

            // Hapus null values untuk nama, label, dll
            if ($row['patientName'] === null) unset($row['patientName']);
            if ($row['noMedicalRecord'] === null) unset($row['noMedicalRecord']);
            if ($row['EncounterCode'] === null) unset($row['EncounterCode']);
            if ($row['medicalPersonelName'] === null) unset($row['medicalPersonelName']);
            if ($row['observationName'] === null) unset($row['observationName']);
            if ($row['observationCode'] === null) unset($row['observationCode']);
            if ($row['observationDisplay'] === null) unset($row['observationDisplay']);
            if ($row['observationSystem'] === null) unset($row['observationSystem']);
            if ($row['ageCategory'] === null) unset($row['ageCategory']);
            if ($row['codedLabel'] === null) unset($row['codedLabel']);
            if ($row['codedDisplay'] === null) unset($row['codedDisplay']);
            if ($row['codedCode'] === null) unset($row['codedCode']);
            if ($row['codedSystem'] === null) unset($row['codedSystem']);
            if ($row['rangeLabel'] === null) unset($row['rangeLabel']);
            if ($row['rangeDisplay'] === null) unset($row['rangeDisplay']);
            if ($row['rangeCode'] === null) unset($row['rangeCode']);
            if ($row['rangeSystem'] === null) unset($row['rangeSystem']);
            if ($row['rangeOperator'] === null) unset($row['rangeOperator']);
            if ($row['minValue'] === null) unset($row['minValue']);
            if ($row['maxValue'] === null) unset($row['maxValue']);
            if ($row['createdName'] === null) unset($row['createdName']);
            if ($row['updatedName'] === null) unset($row['updatedName']);
        }
        unset($row);

        // --- 17. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Daftar hasil observasi berhasil diambil",
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
        error_log('[ListObservation] Query error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>