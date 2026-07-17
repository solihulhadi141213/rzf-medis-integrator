<?php
    /**
     * Update Medical Personel Photo
     * Endpoint: PUT /_API/Reference/MedicalPersonel/UpdateMedicalPersonelPhoto.php?medicalPersonelId={id}
     * Header: token, account_token
     * Body: JSON { "photo": "data:image/png;base64,..." }
     */

    // --- 1. Response Header ---
    header('Content-Type: application/json');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: PUT");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    date_default_timezone_set('UTC');

    // --- 2. Include Dependencies ---
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_medical_personel_photo", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    // --- 6. Validasi Parameter medicalPersonelId ---
    if (!isset($_GET['medicalPersonelId']) || !is_numeric($_GET['medicalPersonelId']) || (int)$_GET['medicalPersonelId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter medicalPersonelId wajib diisi dengan angka positif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }
    $medicalPersonelId = (int) $_GET['medicalPersonelId'];

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

        // Validasi Permission (fitur update_medical_personel_photo)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'update_medical_personel_photo']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur update_medical_personel_photo tidak ditemukan",
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
                    "message" => "Tidak memiliki izin untuk mengubah foto tenaga medis",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[UpdateMedicalPersonelPhoto] DB/Permission error: ' . $e->getMessage());
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

    // --- 8. Ambil data medical personel (untuk mengetahui foto lama) ---
    try {
        $stmt = $Conn->prepare("SELECT photo FROM medical_personel WHERE medicalPersonelId = :id LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            http_response_code(404);
            echo json_encode([
                "response" => [
                    "message" => "Tenaga medis tidak ditemukan",
                    "code" => 404
                ],
                "metadata" => []
            ]);
            exit;
        }
        $oldPhoto = $data['photo'];
    } catch (PDOException $e) {
        error_log('[UpdateMedicalPersonelPhoto] Fetch data error: ' . $e->getMessage());
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

    // --- 9. Ambil dan Decode Body JSON ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Invalid JSON payload: " . json_last_error_msg(),
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 10. Validasi field photo ---
    if (!isset($input['photo']) || trim($input['photo']) === '') {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Field 'photo' wajib diisi dengan data URI base64",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }
    $photoDataUri = trim($input['photo']);

    // --- 11. Proses Upload Foto Baru ---
    $uploadDir = __DIR__ . '/../../../Storage/Img/MedicalPersonel/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            http_response_code(500);
            echo json_encode([
                "response" => [
                    "message" => "Gagal membuat direktori penyimpanan foto",
                    "code" => 500
                ],
                "metadata" => []
            ]);
            exit;
        }
    }

    // Ekstrak data URI
    if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $photoDataUri, $matches)) {
        $imageType = strtolower($matches[1]);
        $base64Data = $matches[2];
        $binaryData = base64_decode($base64Data);
        if ($binaryData === false) {
            http_response_code(400);
            echo json_encode([
                "response" => [
                    "message" => "Data base64 foto tidak valid",
                    "code" => 400
                ],
                "metadata" => []
            ]);
            exit;
        }
        // Validasi tipe gambar yang diizinkan
        $allowedImageTypes = ['jpeg', 'png', 'webp'];
        if (!in_array($imageType, $allowedImageTypes, true)) {
            http_response_code(400);
            echo json_encode([
                "response" => [
                    "message" => "Format foto hanya diperbolehkan: jpeg, png, webp",
                    "code" => 400
                ],
                "metadata" => []
            ]);
            exit;
        }
        // Tentukan ekstensi
        $ext = $imageType === 'jpeg' ? 'jpg' : $imageType;
        // Buat nama file unik
        $newPhotoFilename = md5(uniqid() . '_' . time()) . '.' . $ext;
        $filePath = $uploadDir . $newPhotoFilename;
        if (file_put_contents($filePath, $binaryData) === false) {
            http_response_code(500);
            echo json_encode([
                "response" => [
                    "message" => "Gagal menyimpan file foto",
                    "code" => 500
                ],
                "metadata" => []
            ]);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Format foto harus berupa data URI base64 yang valid (data:image/...)",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 12. Update database dengan nama file baru ---
    try {
        $updatedDate = $nowUtc;
        $stmt = $Conn->prepare("UPDATE medical_personel SET photo = :photo, updatedBy = :updatedBy, updatedDate = :updatedDate WHERE medicalPersonelId = :id");
        $stmt->execute([
            ':photo' => $newPhotoFilename,
            ':updatedBy' => $loggedInAccountId,
            ':updatedDate' => $updatedDate,
            ':id' => $medicalPersonelId
        ]);

        // --- 13. Hapus file foto lama jika ada dan berbeda ---
        if (!empty($oldPhoto) && $oldPhoto !== $newPhotoFilename) {
            $oldFilePath = $uploadDir . $oldPhoto;
            if (file_exists($oldFilePath)) {
                if (!unlink($oldFilePath)) {
                    error_log('[UpdateMedicalPersonelPhoto] Gagal menghapus file lama: ' . $oldFilePath);
                }
            }
        }

        // --- 14. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Foto tenaga medis berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "medicalPersonelId" => $medicalPersonelId,
                "old_photo" => $oldPhoto,
                "new_photo" => $newPhotoFilename,
                "updated_at" => $updatedDate . ' GMT'
            ],
            "data" => [
                "photo" => $newPhotoFilename
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateMedicalPersonelPhoto] Update error: ' . $e->getMessage());
        // Jika gagal update, hapus file baru yang sudah diupload
        if (isset($filePath) && file_exists($filePath)) {
            unlink($filePath);
        }
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal memperbarui foto: " . $e->getMessage(),
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }
?>