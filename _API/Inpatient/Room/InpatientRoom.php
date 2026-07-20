<?php
    /**
     * List Inpatient Room
     * Endpoint: GET /_API/Inpatient/Room/InpatientRoom.php?inpatientClassId={id}
     * Header: token, account_token
     * Menampilkan semua ruangan rawat inap berdasarkan kelas rawat inap tertentu
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
    $Limiter->check("list_inpatient_room", 10, 60);

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

    // --- 6. Validasi Parameter inpatientClassId ---
    if (!isset($_GET['inpatientClassId']) || !is_numeric($_GET['inpatientClassId']) || (int)$_GET['inpatientClassId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter inpatientClassId wajib diisi dengan angka positif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }
    $inpatientClassId = (int) $_GET['inpatientClassId'];

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

        // Validasi Permission (fitur list_inpatient_room)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'list_inpatient_room']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur list_inpatient_room tidak ditemukan",
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
                    "message" => "Tidak memiliki izin untuk melihat daftar ruangan rawat inap",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[ListInpatientRoom] DB/Permission error: ' . $e->getMessage());
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

    // --- 8. Cek keberadaan kelas rawat inap ---
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
        error_log('[ListInpatientRoom] Check class error: ' . $e->getMessage());
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

    // --- 9. Ambil Data Ruangan Rawat Inap ---
    try {
        $sql = "SELECT
                    ir.inpatientRoomId,
                    ir.inpatientClassId,
                    ir.inpatientRoomCode,
                    ir.satuSehatCode,
                    ir.inpatientRoomName,
                    ir.status,
                    ir.creatBy,
                    ca.name AS createdName,
                    ir.creatAt,
                    ir.updateBy,
                    ua.name AS updatedName,
                    ir.updateAt,
                    ic.inpatientClassName
                FROM inpatient_room ir
                LEFT JOIN account ca ON ir.creatBy = ca.accountId
                LEFT JOIN account ua ON ir.updateBy = ua.accountId
                LEFT JOIN inpatient_class ic ON ir.inpatientClassId = ic.inpatientClassId
                WHERE ir.inpatientClassId = :classId
                ORDER BY ir.inpatientRoomName ASC";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([':classId' => $inpatientClassId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format response
        foreach ($rows as &$row) {
            $row['inpatientRoomId'] = (int) $row['inpatientRoomId'];
            $row['inpatientClassId'] = (int) $row['inpatientClassId'];
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
                "message" => "Daftar ruangan rawat inap berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "inpatientClassId" => $inpatientClassId,
                "total" => $total,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $rows
        ]);

    } catch (PDOException $e) {
        error_log('[ListInpatientRoom] Query error: ' . $e->getMessage());
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