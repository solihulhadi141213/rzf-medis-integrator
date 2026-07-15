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