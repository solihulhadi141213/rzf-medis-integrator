<?php
    /**
     * List Inpatient Bed
     * Endpoint: GET /_API/Inpatient/Bed/InpatientBed.php?inpatientClassId={id} OR inpatientRoomId={id}
     * Header: token, account_token
     * Menampilkan data tempat tidur berdasarkan kelas atau ruangan rawat inap.
     * Minimal salah satu parameter harus diberikan.
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
    $Limiter->check("list_inpatient_bed", 10, 60);

    // --- 4. Validasi Method ---
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

    // --- 6. Validasi Parameter ---
    $inpatientClassId = isset($_GET['inpatientClassId']) ? (int) $_GET['inpatientClassId'] : null;
    $inpatientRoomId  = isset($_GET['inpatientRoomId']) ? (int) $_GET['inpatientRoomId'] : null;

    // Minimal salah satu parameter harus diisi
    if (($inpatientClassId === null || $inpatientClassId <= 0) && ($inpatientRoomId === null || $inpatientRoomId <= 0)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter inpatientClassId atau inpatientRoomId wajib diisi dengan angka positif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

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

        // Validasi Permission (fitur list_inpatient_bed)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'list_inpatient_bed']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur list_inpatient_bed tidak ditemukan",
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
                    "message" => "Tidak memiliki izin untuk melihat daftar tempat tidur",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[ListInpatientBed] DB/Permission error: ' . $e->getMessage());
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

    // --- 8. Cek keberadaan parameter yang diberikan (optional) ---
    // Jika inpatientClassId diberikan, cek apakah kelas ada
    if ($inpatientClassId !== null && $inpatientClassId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT inpatientClassId FROM inpatient_class WHERE inpatientClassId = :id LIMIT 1");
            $stmt->execute([':id' => $inpatientClassId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode([
                    "response" => [
                        "message" => "Kelas rawat inap tidak ditemukan",
                        "code" => 404
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[ListInpatientBed] Check class error: ' . $e->getMessage());
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

    // Jika inpatientRoomId diberikan, cek apakah ruangan ada
    if ($inpatientRoomId !== null && $inpatientRoomId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT inpatientRoomId FROM inpatient_room WHERE inpatientRoomId = :id LIMIT 1");
            $stmt->execute([':id' => $inpatientRoomId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode([
                    "response" => [
                        "message" => "Ruangan rawat inap tidak ditemukan",
                        "code" => 404
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[ListInpatientBed] Check room error: ' . $e->getMessage());
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

    // --- 9. Ambil Data Tempat Tidur ---
    try {
        // Bangun query dengan kondisi
        $conditions = [];
        $params = [];

        if ($inpatientClassId !== null && $inpatientClassId > 0) {
            $conditions[] = "ib.inpatientClassId = :inpatientClassId";
            $params[':inpatientClassId'] = $inpatientClassId;
        }

        if ($inpatientRoomId !== null && $inpatientRoomId > 0) {
            $conditions[] = "ib.inpatientRoomId = :inpatientRoomId";
            $params[':inpatientRoomId'] = $inpatientRoomId;
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "SELECT
                    ib.inpatientBedId,
                    ib.inpatientClassId,
                    ic.inpatientClassName,
                    ib.inpatientRoomId,
                    ir.inpatientRoomName,
                    ir.inpatientRoomCode,
                    ib.inpatientBedCode,
                    ib.satuSehatCode,
                    ib.genderPolicy,
                    ib.status,
                    ib.creatBy,
                    ca.name AS createdName,
                    ib.creatAt,
                    ib.updateBy,
                    ua.name AS updatedName,
                    ib.updateAt
                FROM inpatient_bed ib
                LEFT JOIN inpatient_class ic ON ib.inpatientClassId = ic.inpatientClassId
                LEFT JOIN inpatient_room ir ON ib.inpatientRoomId = ir.inpatientRoomId
                LEFT JOIN account ca ON ib.creatBy = ca.accountId
                LEFT JOIN account ua ON ib.updateBy = ua.accountId
                $whereClause
                ORDER BY ir.inpatientRoomName ASC, ib.inpatientBedCode ASC";

        $stmt = $Conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format response
        foreach ($rows as &$row) {
            $row['inpatientBedId'] = (int) $row['inpatientBedId'];
            $row['inpatientClassId'] = (int) $row['inpatientClassId'];
            $row['inpatientRoomId'] = (int) $row['inpatientRoomId'];
            $row['status'] = (int) $row['status'];
            $row['status_display'] = $row['status'] === 1 ? 'Aktif' : 'Tidak Aktif';
            $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
            $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;

            // Hapus null values untuk createdName dan updatedName
            if ($row['createdName'] === null) {
                unset($row['createdName']);
            }
            if ($row['updatedName'] === null) {
                unset($row['updatedName']);
            }
        }
        unset($row);

        $total = count($rows);

        // --- 10. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Daftar tempat tidur berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "inpatientClassId" => $inpatientClassId,
                "inpatientRoomId" => $inpatientRoomId,
                "total" => $total,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $rows
        ]);

    } catch (PDOException $e) {
        error_log('[ListInpatientBed] Query error: ' . $e->getMessage());
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
?>