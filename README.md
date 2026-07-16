# RZF Medis Integrator

Backend API untuk integrasi Sistem Informasi Manajemen Rumah Sakit (SIMRS), Klinik, dan Puskesmas dengan Platform SATUSEHAT Kementerian Kesehatan Republik Indonesia.

---

## Tentang Project

**RZF Medis Integrator** merupakan aplikasi backend berbasis PHP yang berfungsi sebagai middleware antara aplikasi SIMRS dengan berbagai layanan eksternal, khususnya **SATUSEHAT Platform**.

Aplikasi ini dibuat agar sistem informasi rumah sakit, klinik, maupun puskesmas dapat melakukan integrasi tanpa harus mengimplementasikan seluruh API SATUSEHAT secara langsung pada aplikasi utama.

Selain integrasi SATUSEHAT, aplikasi ini juga dirancang sebagai pusat layanan (service layer) yang dapat menangani:

- REST API
- Autentikasi pengguna
- API Key Management
- Token Management
- Upload dan Proxy File
- Penyimpanan Dokumen Medis
- Penyimpanan Citra Medis
- DICOM Storage
- Logging
- Rate Limiting
- Integrasi sistem pihak ketiga

---

# Fitur

Saat ini aplikasi telah memiliki fitur berikut.

## Authentication

- Login User
- JWT/Token Authentication
- Account Management
- Permission Management

## API Key

- Generate API Key
- Update API Key
- Delete API Key
- List API Key

## Storage

Media penyimpanan untuk berbagai file.

- Dokumen
- Image
- DICOM

## Proxy

Proxy digunakan agar file tidak diakses secara langsung dari folder storage.

- Image Proxy
- Document Proxy

## Security

- API Key Validation
- Token Authentication
- Rate Limiter
- Helper Validation
- Permission Access

## Reference

Folder khusus yang nantinya berisi berbagai referensi master dari SATUSEHAT maupun referensi internal.

Contoh:

- Agama
- Jenis Kelamin
- Pendidikan
- Pekerjaan
- ICD-10
- ICD-9 CM
- Wilayah
- dan lain-lain.

---

# Spesifikasi Sistem

## Server

| Komponen | Versi |
|----------|--------|
| PHP | 8.1 |
| Apache | 2.4.62.1 |
| MySQL | 9.1.0 |

## Bahasa

- PHP 8.1
- SQL
- JSON
- REST API

---

# Struktur Project

```
rzf-medis-integrator
│
├── DB
│   └── default.sql
│
├── Storage
│   ├── DICOM
│   ├── Doc
│   └── Img
│
├── _API
│   ├── Account
│   ├── ApiKey
│   ├── Reference
│   └── Token
│
├── _Config
│   ├── Connection.php
│   ├── Helper.php
│   └── RateLimiter.php
│
├── _Proxy
│   ├── DocumentProxy
│   └── ImageProxy
│
├── index.php
├── README.md
└── LICENSE
```

---
# Database
Secara default, database terdiri dari struktur dan data basic yang akan dimuat pada pertama kali aplikassi digunakan.


| Tabel                       | Structure  | Data   |
|-----------------------------|-----------:|--------|
|  account                    | Ya         | Tidak  |
|  account_level              | Ya         | Tidak  |
|  account_level_reference    | Ya         | Tidak  |
|  account_permission         | Ya         | Tidak  |


# Persyaratan

Sebelum instalasi pastikan telah tersedia:

- PHP 8.1 atau lebih baru
- Apache Web Server
- MySQL Server
- php_mysqli
- php_openssl
- php_curl
- php_json
- php_mbstring
- php_fileinfo

---

# Instalasi

## 1. Clone Repository

```bash
git clone https://github.com/solihulhadi141213/rzf-medis-integrator.git
```

atau download ZIP dari GitHub.

---

## 2. Letakkan pada Web Server

Contoh WAMP

```
C:\wamp64\www\rzf-medis-integrator
```

Contoh XAMPP

```
C:\xampp\htdocs\rzf-medis-integrator
```

---

## 3. Membuat Database

Masuk ke MySQL.

```sql
CREATE DATABASE rzf_medis;
```

Kemudian import file

```
DB/default.sql
```

---

## 4. Konfigurasi Database

Buka

```
_Config/Connection.php
```

Sesuaikan konfigurasi.

```php
private $host     = 'localhost';
private $username = 'root';
private $password = '';
private $database = 'rzf_medis';
private $port     = 3306;
```

---

## 5. Permission Folder

Pastikan folder berikut dapat ditulis oleh web server.

```
Storage/

Storage/DICOM/

Storage/Doc/

Storage/Img/
```

---

## 6. Jalankan

Buka browser

```
http://localhost/rzf-medis-integrator/
```

Jika instalasi berhasil maka aplikasi siap digunakan.

---

# API Endpoint

## Authentication

```
POST /_API/Account/Login.php
```

---

## Account

```
POST   /_API/Account/CreateAccount.php

GET    /_API/Account/ListAccount.php

GET    /_API/Account/DetailAccount.php

PUT    /_API/Account/UpdateAccount.php

DELETE /_API/Account/DeleteAccount.php
```

---

## API Key

```
POST   /_API/ApiKey/CreateApiKey.php

GET    /_API/ApiKey/ListApiKey.php

PUT    /_API/ApiKey/UpdateApiKey.php

DELETE /_API/ApiKey/DeleteApiKey.php
```

---

## Token

```
GET /_API/Token/get_token.php
```

---

# Folder Storage

Folder Storage digunakan untuk menyimpan berbagai file.

```
Storage
│
├── DICOM
│      File DICOM
│
├── Doc
│      Dokumen
│
└── Img
       Image User
       Image Pasien
```

File tidak disarankan diakses secara langsung.

Gunakan Proxy yang telah disediakan.

---

# Keamanan

Aplikasi telah menerapkan beberapa lapisan keamanan.

- API Key
- Access Token
- Permission Validation
- Rate Limiter
- Prepared Statement
- Helper Validation
- Proxy File Access

---

# Roadmap

Pengembangan selanjutnya meliputi:

- [ ] SATUSEHAT Authentication
- [ ] SATUSEHAT Organization
- [ ] SATUSEHAT Practitioner
- [ ] SATUSEHAT Patient
- [ ] SATUSEHAT Encounter
- [ ] SATUSEHAT Observation
- [ ] SATUSEHAT Condition
- [ ] SATUSEHAT Medication
- [ ] SATUSEHAT Procedure
- [ ] SATUSEHAT DiagnosticReport
- [ ] SATUSEHAT ImagingStudy
- [ ] SATUSEHAT Binary
- [ ] SATUSEHAT DocumentReference
- [ ] Audit Log
- [ ] Queue Service
- [ ] Scheduler
- [ ] Background Worker
- [ ] Webhook
- [ ] Swagger Documentation
- [ ] Unit Testing
- [ ] Docker Support

---

# Kontribusi

Kontribusi sangat terbuka.

Silakan membuat:

- Issue
- Pull Request
- Feature Request

---

# License

Project ini menggunakan lisensi MIT.

Lihat file LICENSE untuk informasi lebih lanjut.

---

# Author

**Solihul Hadi**

Programmer SIMRS

RSU El-Syifa Kuningan

GitHub

https://github.com/solihulhadi141213

---

# Repository

https://github.com/solihulhadi141213/rzf-medis-integrator