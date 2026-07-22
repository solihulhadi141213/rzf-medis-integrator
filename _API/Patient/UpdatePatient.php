<?php
    /**
     * Update Patient
     * Endpoint: PUT /_API/Patient/UpdatePatient.php?patientId={id}
     * Header: token, account_token
     * Body: JSON (lihat contoh)
     * 
     * - noMedicalRecord: jika berubah, cek duplikat (kecuali dirinya)
     * - email, phone, nik: jika berubah, validasi format dan duplikat
     * - Validasi wilayah: provinceId, cityId, districtId, villageId
     * - oldMedicalRecord & motherMedicalRecord: validasi keberadaan di tabel patient
     * - Photo: jika diisi, upload baru dan hapus lama; jika kosong, biarkan
     * - updateBy dan updateAt diisi otomatis dari akun yang login dan waktu UTC.
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
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_patient", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    // --- 6. Validasi Parameter patientId ---
    if (!isset($_GET['patientId']) || !is_numeric($_GET['patientId']) || (int)$_GET['patientId'] <= 0) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Parameter patientId wajib diisi dengan angka positif", "code" => 400], "metadata" => []]);
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

        // Validasi Permission (fitur update_patient)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'update_patient']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur update_patient tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah data pasien", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[UpdatePatient] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data pasien yang akan diupdate ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Pasien tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdatePatient] Fetch data error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 9. Ambil dan Decode Body JSON ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Invalid JSON payload: " . json_last_error_msg(), "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 10. Ambil nilai dari body, gunakan nilai lama jika tidak ada ---
    $noMedicalRecord = isset($input['noMedicalRecord']) ? trim($input['noMedicalRecord']) : $existingData['noMedicalRecord'];
    $satuSehatCode   = isset($input['satuSehatCode']) ? trim($input['satuSehatCode']) : $existingData['satuSehatCode'];
    $isInfant        = isset($input['isInfant']) ? (int) $input['isInfant'] : (int) $existingData['isInfant'];
    $name            = isset($input['name']) ? trim($input['name']) : $existingData['name'];
    $email           = isset($input['email']) ? trim($input['email']) : $existingData['email'];
    $phone           = isset($input['phone']) ? trim($input['phone']) : $existingData['phone'];
    $gender          = isset($input['gender']) ? trim($input['gender']) : $existingData['gender'];
    $birthPlace      = isset($input['birthPlace']) ? trim($input['birthPlace']) : $existingData['birthPlace'];
    $birthDate       = isset($input['birthDate']) ? trim($input['birthDate']) : $existingData['birthDate'];
    $nik             = isset($input['nik']) ? trim($input['nik']) : $existingData['nik'];
    $religion        = isset($input['religion']) ? trim($input['religion']) : $existingData['religion'];
    $martialStatus   = isset($input['martialStatus']) ? trim($input['martialStatus']) : $existingData['martialStatus'];
    $lastEducation   = isset($input['lastEducation']) ? trim($input['lastEducation']) : $existingData['lastEducation'];
    $occupation      = isset($input['occupation']) ? trim($input['occupation']) : $existingData['occupation'];
    $language        = isset($input['language']) ? trim($input['language']) : $existingData['language'];
    $ethnic          = isset($input['ethnic']) ? trim($input['ethnic']) : $existingData['ethnic'];
    $citizenshipStatus = isset($input['citizenshipStatus']) ? trim($input['citizenshipStatus']) : $existingData['citizenshipStatus'];
    $provinceId      = isset($input['provinceId']) ? (int) $input['provinceId'] : $existingData['provinceId'];
    $cityId          = isset($input['cityId']) ? (int) $input['cityId'] : $existingData['cityId'];
    $districtId      = isset($input['districtId']) ? (int) $input['districtId'] : $existingData['districtId'];
    $villageId       = isset($input['villageId']) ? (int) $input['villageId'] : $existingData['villageId'];
    $rt              = isset($input['rt']) ? trim($input['rt']) : $existingData['rt'];
    $rw              = isset($input['rw']) ? trim($input['rw']) : $existingData['rw'];
    $postalCode      = isset($input['postalCode']) ? trim($input['postalCode']) : $existingData['postalCode'];
    $address         = isset($input['address']) ? trim($input['address']) : $existingData['address'];
    $medicalRecordStatus = isset($input['medicalRecordStatus']) ? trim($input['medicalRecordStatus']) : $existingData['medicalRecordStatus'];
    $oldMedicalRecord = isset($input['oldMedicalRecord']) ? trim($input['oldMedicalRecord']) : $existingData['oldMedicalRecord'];
    $motherMedicalRecord = isset($input['motherMedicalRecord']) ? trim($input['motherMedicalRecord']) : $existingData['motherMedicalRecord'];
    $kkNumber        = isset($input['kkNumber']) ? trim($input['kkNumber']) : $existingData['kkNumber'];
    $kkName          = isset($input['kkName']) ? trim($input['kkName']) : $existingData['kkName'];
    $photo           = isset($input['photo']) ? trim($input['photo']) : '';

    // --- 11. Validasi noMedicalRecord (jika berubah) ---
    if ($noMedicalRecord !== $existingData['noMedicalRecord']) {
        if (strlen($noMedicalRecord) > 20) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "noMedicalRecord maksimal 20 karakter", "code" => 422], "metadata" => []]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE noMedicalRecord = :record AND patientId != :id LIMIT 1");
            $stmt->execute([':record' => $noMedicalRecord, ':id' => $patientId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "noMedicalRecord sudah digunakan oleh pasien lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdatePatient] Check noMedicalRecord error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 12. Validasi Email (jika berubah) ---
    if ($email !== $existingData['email'] && !empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Format email tidak valid", "code" => 422], "metadata" => []]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE email = :email AND patientId != :id LIMIT 1");
            $stmt->execute([':email' => $email, ':id' => $patientId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "Email sudah digunakan oleh pasien lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdatePatient] Check email error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 13. Validasi Phone (jika berubah) ---
    if ($phone !== $existingData['phone'] && !empty($phone)) {
        if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Nomor telepon harus terdiri dari 10-15 digit angka", "code" => 422], "metadata" => []]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE phone = :phone AND patientId != :id LIMIT 1");
            $stmt->execute([':phone' => $phone, ':id' => $patientId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "Nomor telepon sudah digunakan oleh pasien lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdatePatient] Check phone error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 14. Validasi NIK (jika berubah) ---
    if ($nik !== $existingData['nik'] && !empty($nik)) {
        if (!preg_match('/^\d{16}$/', $nik)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "NIK harus terdiri dari 16 digit angka", "code" => 422], "metadata" => []]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE nik = :nik AND patientId != :id LIMIT 1");
            $stmt->execute([':nik' => $nik, ':id' => $patientId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "NIK sudah digunakan oleh pasien lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdatePatient] Check NIK error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 15. Validasi Gender (konversi numerik 1/2 ke Male/Female) ---
    if ($gender !== null && $gender !== '') {
        if (is_numeric($gender)) {
            $genderNum = (int) $gender;
            if ($genderNum === 1) {
                $gender = 'Male';
            } elseif ($genderNum === 2) {
                $gender = 'Female';
            } else {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "gender numeric harus 1 (Male) atau 2 (Female)", "code" => 422], "metadata" => []]);
                exit;
            }
        } else {
            if (!in_array($gender, ['Male', 'Female'], true)) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "gender harus Male atau Female", "code" => 422], "metadata" => []]);
                exit;
            }
        }
    }

    // --- 16. Validasi Enum fields ---
    $allowedReligions = ['Islam','Kristen Protestan','Kristen Katolik','Hindu','Buddha','Konghucu','Lainnya'];
    $allowedMartial = ['Single','Married','Widowed','Divorced'];
    $allowedEducation = ['Tidak Sekolah','SD','SMP','SMA','D1','D2','D3','D4','S1','S2','S3'];
    $allowedOccupation = ['Tidak Bekerja','Wirausaha','Karyawan Swasta','ASN','TNI','POLRI'];
    $allowedCitizenship = ['WNI','WNA'];
    $allowedMedicalRecordStatus = ['Terdaftar','Meninggal','Retensi'];

    if ($religion !== null && $religion !== '' && !in_array($religion, $allowedReligions, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "religion tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($martialStatus !== null && $martialStatus !== '' && !in_array($martialStatus, $allowedMartial, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "martialStatus tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($lastEducation !== null && $lastEducation !== '' && !in_array($lastEducation, $allowedEducation, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "lastEducation tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($occupation !== null && $occupation !== '' && !in_array($occupation, $allowedOccupation, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "occupation tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($citizenshipStatus, $allowedCitizenship, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "citizenshipStatus harus WNI atau WNA", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($medicalRecordStatus, $allowedMedicalRecordStatus, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalRecordStatus tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 17. Validasi tanggal lahir (jika diubah) ---
    if (!empty($birthDate) && $birthDate !== $existingData['birthDate']) {
        $date = DateTime::createFromFormat('Y-m-d', $birthDate);
        if (!$date || $date->format('Y-m-d') !== $birthDate) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "birthDate harus format YYYY-MM-DD", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 18. Validasi Wilayah (jika ada) ---
    if ($provinceId !== null && $provinceId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT provinceId FROM region_province WHERE provinceId = :id LIMIT 1");
            $stmt->execute([':id' => $provinceId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "provinceId tidak ditemukan", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) { error_log('[UpdatePatient] ' . $e->getMessage()); }
    } else {
        $provinceId = null;
    }

    if ($cityId !== null && $cityId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT cityId FROM region_city WHERE cityId = :id AND provinceId = :provinceId LIMIT 1");
            $stmt->execute([':id' => $cityId, ':provinceId' => $provinceId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "cityId tidak ditemukan atau tidak sesuai dengan provinceId", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) { error_log('[UpdatePatient] ' . $e->getMessage()); }
    } else {
        $cityId = null;
    }

    if ($districtId !== null && $districtId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT districtId FROM region_district WHERE districtId = :id AND cityId = :cityId LIMIT 1");
            $stmt->execute([':id' => $districtId, ':cityId' => $cityId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "districtId tidak ditemukan atau tidak sesuai dengan cityId", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) { error_log('[UpdatePatient] ' . $e->getMessage()); }
    } else {
        $districtId = null;
    }

    if ($villageId !== null && $villageId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT villageId FROM region_village WHERE villageId = :id AND districtId = :districtId LIMIT 1");
            $stmt->execute([':id' => $villageId, ':districtId' => $districtId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "villageId tidak ditemukan atau tidak sesuai dengan districtId", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) { error_log('[UpdatePatient] ' . $e->getMessage()); }
    } else {
        $villageId = null;
    }

    // --- 19. Validasi oldMedicalRecord (cek di tabel patient, selain dirinya) ---
    if (!empty($oldMedicalRecord)) {
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE noMedicalRecord = :record AND patientId != :id LIMIT 1");
            $stmt->execute([':record' => $oldMedicalRecord, ':id' => $patientId]);
            if (!$stmt->fetch()) {
                $oldMedicalRecord = null;
            }
        } catch (PDOException $e) {
            error_log('[UpdatePatient] Check oldMedicalRecord error: ' . $e->getMessage());
            $oldMedicalRecord = null;
        }
    } else {
        $oldMedicalRecord = null;
    }

    // --- 20. Validasi motherMedicalRecord (cek di tabel patient, selain dirinya) ---
    if (!empty($motherMedicalRecord)) {
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE noMedicalRecord = :record AND patientId != :id LIMIT 1");
            $stmt->execute([':record' => $motherMedicalRecord, ':id' => $patientId]);
            if (!$stmt->fetch()) {
                $motherMedicalRecord = null;
            }
        } catch (PDOException $e) {
            error_log('[UpdatePatient] Check motherMedicalRecord error: ' . $e->getMessage());
            $motherMedicalRecord = null;
        }
    } else {
        $motherMedicalRecord = null;
    }

    // --- 21. Proses Upload Foto (jika diisi) ---
    $photoFilename = $existingData['photo']; // default: foto lama
    $uploadDir = __DIR__ . '/../../Storage/Img/Patient/';
    if (!empty($photo)) {
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $photo, $matches)) {
            $imageType = strtolower($matches[1]);
            $base64Data = $matches[2];
            $binaryData = base64_decode($base64Data);
            if ($binaryData !== false) {
                $allowedImageTypes = ['jpeg', 'png', 'webp'];
                if (in_array($imageType, $allowedImageTypes, true)) {
                    $ext = $imageType === 'jpeg' ? 'jpg' : $imageType;
                    $newPhoto = md5(uniqid() . '_' . time()) . '.' . $ext;
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $filePath = $uploadDir . $newPhoto;
                    if (file_put_contents($filePath, $binaryData) !== false) {
                        if (!empty($photoFilename) && $photoFilename !== $newPhoto) {
                            $oldFilePath = $uploadDir . $photoFilename;
                            if (file_exists($oldFilePath)) {
                                unlink($oldFilePath);
                            }
                        }
                        $photoFilename = $newPhoto;
                    }
                }
            }
        }
        // Jika gagal upload, tetap gunakan foto lama
    }

    // --- 22. Update Data ke Database (dengan updateBy dan updateAt) ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE patient SET
                    noMedicalRecord = :noMedicalRecord,
                    satuSehatCode = :satuSehatCode,
                    isInfant = :isInfant,
                    name = :name,
                    email = :email,
                    phone = :phone,
                    gender = :gender,
                    birthPlace = :birthPlace,
                    birthDate = :birthDate,
                    nik = :nik,
                    religion = :religion,
                    martialStatus = :martialStatus,
                    lastEducation = :lastEducation,
                    occupation = :occupation,
                    language = :language,
                    ethnic = :ethnic,
                    citizenshipStatus = :citizenshipStatus,
                    provinceId = :provinceId,
                    cityId = :cityId,
                    districtId = :districtId,
                    villageId = :villageId,
                    rt = :rt,
                    rw = :rw,
                    postalCode = :postalCode,
                    address = :address,
                    medicalRecordStatus = :medicalRecordStatus,
                    oldMedicalRecord = :oldMedicalRecord,
                    motherMedicalRecord = :motherMedicalRecord,
                    kkNumber = :kkNumber,
                    kkName = :kkName,
                    photo = :photo,
                    updateBy = :updateBy,
                    updateAt = :updateAt
                WHERE patientId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':noMedicalRecord' => $noMedicalRecord,
            ':satuSehatCode' => empty($satuSehatCode) ? null : $satuSehatCode,
            ':isInfant' => $isInfant,
            ':name' => $name,
            ':email' => empty($email) ? null : $email,
            ':phone' => empty($phone) ? null : $phone,
            ':gender' => $gender,
            ':birthPlace' => $birthPlace,
            ':birthDate' => $birthDate,
            ':nik' => empty($nik) ? null : $nik,
            ':religion' => $religion,
            ':martialStatus' => $martialStatus,
            ':lastEducation' => $lastEducation,
            ':occupation' => $occupation,
            ':language' => $language,
            ':ethnic' => $ethnic,
            ':citizenshipStatus' => $citizenshipStatus,
            ':provinceId' => $provinceId,
            ':cityId' => $cityId,
            ':districtId' => $districtId,
            ':villageId' => $villageId,
            ':rt' => $rt,
            ':rw' => $rw,
            ':postalCode' => $postalCode,
            ':address' => $address,
            ':medicalRecordStatus' => $medicalRecordStatus,
            ':oldMedicalRecord' => $oldMedicalRecord,
            ':motherMedicalRecord' => $motherMedicalRecord,
            ':kkNumber' => empty($kkNumber) ? null : $kkNumber,
            ':kkName' => empty($kkName) ? null : $kkName,
            ':photo' => $photoFilename,
            ':updateBy' => $loggedInAccountId,
            ':updateAt' => $updatedDate,
            ':id' => $patientId
        ]);

        // --- 23. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Data pasien berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "patientId" => $patientId,
                "updated_at" => $updatedDate . ' GMT'
            ],
            "data" => [
                "patientId" => $patientId,
                "noMedicalRecord" => $noMedicalRecord,
                "satuSehatCode" => empty($satuSehatCode) ? null : $satuSehatCode,
                "isInfant" => $isInfant,
                "name" => $name,
                "email" => empty($email) ? null : $email,
                "phone" => empty($phone) ? null : $phone,
                "gender" => $gender,
                "birthPlace" => $birthPlace,
                "birthDate" => $birthDate,
                "nik" => empty($nik) ? null : $nik,
                "religion" => $religion,
                "martialStatus" => $martialStatus,
                "lastEducation" => $lastEducation,
                "occupation" => $occupation,
                "language" => $language,
                "ethnic" => $ethnic,
                "citizenshipStatus" => $citizenshipStatus,
                "provinceId" => $provinceId,
                "cityId" => $cityId,
                "districtId" => $districtId,
                "villageId" => $villageId,
                "rt" => $rt,
                "rw" => $rw,
                "postalCode" => $postalCode,
                "address" => $address,
                "medicalRecordStatus" => $medicalRecordStatus,
                "oldMedicalRecord" => $oldMedicalRecord,
                "motherMedicalRecord" => $motherMedicalRecord,
                "kkNumber" => empty($kkNumber) ? null : $kkNumber,
                "kkName" => empty($kkName) ? null : $kkName,
                "photo" => $photoFilename,
                "creatBy" => (int) $existingData['creatBy'],
                "creatAt" => $existingData['creatAt'],
                "updateBy" => $loggedInAccountId,
                "updateAt" => $updatedDate
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[UpdatePatient] Update error: ' . $e->getMessage());
        // Jika ada foto baru yang diupload tapi gagal update, hapus foto baru
        if (!empty($photo) && isset($newPhoto) && file_exists($uploadDir . $newPhoto)) {
            unlink($uploadDir . $newPhoto);
        }
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>