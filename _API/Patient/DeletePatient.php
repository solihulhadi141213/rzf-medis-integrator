<?php
    /**
     * Delete Patient (Hard Delete)
     * Endpoint: DELETE /_API/Patient/DeletePatient.php?patientId={id}
     * Header: token, account_token
     * 
     * Menghapus data pasien dari database dan menghapus file foto jika ada.
     */

    // --- 1. Response Header ---
    header('Content-Type: application/json');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: DELETE");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    date_default_timezone_set('UTC');

    // --- 2. Include Dependencies ---
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("delete_patient", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

    // --- 6. Validasi Parameter patientId ---
    if (!isset($_GET['patientId']) || !is_numeric($_GET['patientId']) || (int)$_GET['patientId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter patientId wajib diisi dengan angka positif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }
    $patientId = (int) $_GET['patientId'];

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

        // Validasi Permission (fitur delete_patient)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'delete_patient']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur delete_patient tidak ditemukan",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $accountTokenData['accountId'], $id_service_feature)) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Tidak memiliki izin untuk menghapus data pasien",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[DeletePatient] DB/Permission error: ' . $e->getMessage());
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

    // --- 8. Ambil data pasien untuk mendapatkan nama file foto ---
    try {
        $stmt = $Conn->prepare("SELECT photo FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            http_response_code(404);
            echo json_encode([
                "response" => [
                    "message" => "Pasien tidak ditemukan",
                    "code" => 404
                ],
                "metadata" => []
            ]);
            exit;
        }
        $photoFilename = $data['photo'];
    } catch (PDOException $e) {
        error_log('[DeletePatient] Fetch photo error: ' . $e->getMessage());
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

    // --- 9. Hapus file foto jika ada ---
    $photoDeleted = false;
    if (!empty($photoFilename)) {
        $uploadDir = __DIR__ . '/../../Storage/Img/Patient/';
        $filePath = $uploadDir . $photoFilename;
        if (file_exists($filePath)) {
            if (unlink($filePath)) {
                $photoDeleted = true;
            } else {
                // Gagal hapus, log error tapi tetap lanjutkan proses delete
                error_log('[DeletePatient] Gagal menghapus file foto: ' . $filePath);
            }
        }
    }

    // --- 10. Hapus record dari database ---
    try {
        $stmt = $Conn->prepare("DELETE FROM patient WHERE patientId = :id");
        $stmt->execute([':id' => $patientId]);

        // --- 11. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Data pasien berhasil dihapus",
                "code" => 200
            ],
            "metadata" => [
                "patientId" => $patientId,
                "photo_deleted" => $photoDeleted,
                "deleted_at" => $nowUtc . ' GMT'
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[DeletePatient] Delete error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal menghapus data: " . $e->getMessage(),
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }
?>