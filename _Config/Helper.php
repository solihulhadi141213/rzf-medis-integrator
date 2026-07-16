<?php
    // Sanitasi Variabel
    function validateAndSanitizeInput($input) {
        // Menghapus karakter yang tidak diinginkan
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input);
        $input = addslashes($input);
        return $input;
    }

    //Membuat Token
    function GenerateToken($length) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        $charLength = strlen($characters);
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charLength - 1)];
        }
        return $randomString;
    }

    //Data Detail
    function GetDetailData($Conn, $Tabel, $Param, $Value, $Colom) {
        // Validasi input
        if (empty($Conn)) {
            return "No Database Connection";
        }
        if (empty($Tabel)) {
            return "No Table Selected";
        }
        if (empty($Param)) {
            return "No Parameter Selected";
        }
        if (empty($Value)) {
            return "No Value Provided";
        }
        if (empty($Colom)) {
            return "No Column Selected";
        }

        // Validasi nama kolom dan tabel (hindari SQL Injection - minimal manual whitelist bisa ditambahkan jika perlu)
        // Di PDO tidak bisa bind nama tabel/kolom, jadi perlu divalidasi secara ketat jika input berasal dari user
        $allowedChars = '/^[a-zA-Z0-9_]+$/';
        if (!preg_match($allowedChars, $Tabel) || !preg_match($allowedChars, $Param) || !preg_match($allowedChars, $Colom)) {
            return "Invalid table, column, or parameter name";
        }

        // Buat query dinamis dengan nama kolom dan tabel disisipkan secara langsung (karena tidak bisa dibind)
        $sql = "SELECT `$Colom` FROM `$Tabel` WHERE `$Param` = :value LIMIT 1";

        try {
            $stmt = $Conn->prepare($sql);
            $stmt->bindValue(':value', $Value, PDO::PARAM_STR);
            $stmt->execute();
            $Data = $stmt->fetch(PDO::FETCH_ASSOC);

            return $Data[$Colom] ?? "";
        } catch (PDOException $e) {
            return "Query Error: " . $e->getMessage();
        }
    }

    // Validasi Permission Akses
    function ValidatePermission(PDO $Conn, int $accountId, string $id_service_feature): bool{
        $sql = "SELECT accountId
                FROM account_permission
                WHERE accountId = :accountId
                AND id_service_feature = :id_service_feature
                LIMIT 1";

        $stmt = $Conn->prepare($sql);

        $stmt->execute([
            ':accountId' => $accountId,
            ':id_service_feature' => $id_service_feature
        ]);

        return ($stmt->fetch() !== false);
    }

    // Menangkap data dari header
    function getRequestHeader(string $name): string{
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === strtolower($name)) return trim($value);
            }
        }
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$headerKey]) ? trim($_SERVER[$headerKey]) : '';
    }

    // Fungsi Untuk Generate Token Satu Sehat
    function generateTokenSatusehat($Conn) {
        date_default_timezone_set('UTC');

        if (!$Conn instanceof PDO) {
            return [
                'status' => 'error',
                'message' => 'Koneksi database tidak valid!'
            ];
        }

        $stmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            return [
                'status' => 'error',
                'message' => 'Tidak ada koneksi SATUSEHAT yang aktif!'
            ];
        }

        $credentialId   = (int) $data['credentialId'];
        $credentialName = trim((string) $data['credentialName']);
        $baseUrl        = rtrim(trim((string) $data['baseUrl']), '/');
        $clientKey      = trim((string) $data['clientKey']);
        $secretKey      = trim((string) $data['secretKey']);

        if (empty($clientKey) || empty($secretKey) || empty($baseUrl)) {
            return [
                'status' => 'error',
                'message' => 'Konfigurasi SATUSEHAT tidak lengkap!'
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/oauth2/v1/accesstoken?grant_type=client_credentials',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientKey,
                'client_secret' => $secretKey
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'status' => 'error',
                'message' => 'Gagal menghubungi API SATUSEHAT: ' . $curlError
            ];
        }

        if ($httpCode !== 200) {
            return [
                'status' => 'error',
                'message' => 'HTTP Error ' . $httpCode . ' | ' . substr($response, 0, 300)
            ];
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'error',
                'message' => 'JSON Error: ' . json_last_error_msg()
            ];
        }

        if (isset($result['error'])) {
            return [
                'status' => 'error',
                'message' => 'API Error: ' . $result['error']
            ];
        }

        if (empty($result['access_token'])) {
            return [
                'status' => 'error',
                'message' => 'Access token tidak ditemukan'
            ];
        }

        $accessToken = (string) $result['access_token'];
        $expiresIn = !empty($result['expires_in']) ? (int) $result['expires_in'] : 3600;
        $expiredTimestamp = time() + $expiresIn;
        $datetimeExpired = date('Y-m-d H:i:s', $expiredTimestamp);
        $updatedDate = gmdate('Y-m-d H:i:s');

        $updateStmt = $Conn->prepare("UPDATE satusehat SET token = :token, tokenExpired = :tokenExpired, updatedDate = :updatedDate WHERE credentialId = :credentialId");
        $updateStmt->execute([
            ':token' => $accessToken,
            ':tokenExpired' => $datetimeExpired,
            ':updatedDate' => $updatedDate,
            ':credentialId' => $credentialId
        ]);

        return [
            'status' => 'success',
            'message' => 'Token baru berhasil dibuat',
            'credentialId' => $credentialId,
            'credentialName' => $credentialName,
            'baseUrl' => $baseUrl,
            'token' => $accessToken,
            'datetime_expired' => $datetimeExpired,
            'updatedDate' => $updatedDate
        ];
    }