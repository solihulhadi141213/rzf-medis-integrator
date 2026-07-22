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

## Definition

Berikut istilah SATUSEHAT yang digunakan pada project ini:

- `Authentication`: proses otentikasi untuk mendapatkan akses ke API SATUSEHAT.
- `Organization`: data fasilitas kesehatan atau organisasi layanan.
- `Practitioner`: data tenaga kesehatan atau petugas medis.
- `Patient`: data pasien.
- `Encounter`: data kunjungan atau perawatan pasien.
- `Observation`: data hasil observasi klinis atau pemeriksaan.
- `Condition`: data diagnosa atau kondisi klinis pasien.
- `Medication`: data obat atau terapi yang diberikan.
- `Procedure`: data tindakan medis.
- `DiagnosticReport`: data laporan hasil pemeriksaan diagnostik.
- `ImagingStudy`: data pemeriksaan pencitraan medis.
- `Binary`: data file biner yang terkait dengan dokumen atau lampiran.
- `DocumentReference`: data referensi dokumen medis.
- `Location`: data lokasi fasilitas, ruangan, kelas, atau bed.
- `Credential`: data konfigurasi akses ke SATUSEHAT pada sistem ini.

---

# Fitur

Saat ini aplikasi telah memiliki fitur berikut.

## Authentication

- Login User: melakukan autentikasi akun dan menghasilkan token untuk akses API.
- JWT/Token Authentication: validasi token API pada setiap request yang memerlukan hak akses.
- Account Management: pembuatan, pembaruan, dan pengelolaan data akun pengguna.
- Permission Management: pengaturan hak akses fitur berdasarkan akun.
- Logout: mengakhiri sesi dan menonaktifkan token jika diperlukan.

## API Key Management

- Generate API Key: membuat kredensial API baru untuk aplikasi klien.
- List API Key: menampilkan daftar API Key yang terdaftar.
- Update API Key: memperbarui informasi dan status API Key.
- Delete API Key: menghapus API Key dari sistem.
- Token Issuance: endpoint token untuk mendapatkan akses token dari API Key.

## Reference Data

Menangani data master referensi yang sering digunakan dalam sistem kesehatan, seperti kode ICD dan lokasi tubuh.

- Body Site Reference: menampilkan daftar referensi lokasi tubuh (`body_site`).
- ICD Reference: menampilkan daftar referensi ICD (`icd`).
- Region Reference: menampilkan daftar provinsi, kabupaten/kota, kecamatan, dan desa.
- Referensi ini mendukung pencarian, penyaringan, paging, dan pengurutan.

## Storage

Media penyimpanan untuk berbagai file.

- Dokumen: penyimpanan file dokumen medis.
- Image: penyimpanan gambar medis.
- DICOM: penyimpanan berkas DICOM untuk citra medis.

## Proxy

Proxy digunakan agar file tidak diakses secara langsung dari folder storage.

- Image Proxy: mengakses gambar melalui endpoint terkontrol.
- Document Proxy: mengakses dokumen melalui endpoint terkontrol.

## Security

- API Key Validation: memastikan request datang dari aplikasi yang terdaftar.
- Token Authentication: memeriksa token aktif untuk setiap panggilan API.
- Rate Limiter: membatasi jumlah request per interval waktu untuk mencegah penyalahgunaan.
- Helper Validation: sanitasi input dan validasi data request.
- Permission Access: memastikan akun hanya mengakses fitur yang diizinkan.

## Available API Endpoints

Beberapa endpoint API yang tersedia di folder `_API`:

- `_API/Token/get_token.php`: mendapatkan token akses dari `client_id` dan `client_key`.
- `_API/ApiKey/CreatApiKey.php`: membuat API Key baru.
- `_API/ApiKey/ListApiKey.php`: menampilkan daftar API Key.
- `_API/ApiKey/UpdateApiKey.php`: memperbarui API Key.
- `_API/ApiKey/DeleteApiKey.php`: menghapus API Key.
- `_API/Account/Login.php`: login user.
- `_API/Account/Logout.php`: logout user.
- `_API/Account/CreatAccount.php`: membuat akun baru.
- `_API/Account/UpdateAccount.php`: memperbarui data akun.
- `_API/Account/UpdateAccountPassword.php`: mengubah password akun.
- `_API/Account/UpdateAccountPermission.php`: mengelola hak akses akun.
- `_API/Account/UpdateAccountPhoto.php`: memperbarui foto akun.
- `_API/Account/DetailAccount.php`: menampilkan detail akun.
- `_API/Account/ListAccount.php`: daftar akun.
- `_API/Account/ListAccountLevel.php`: menampilkan level akun.
- `_API/Account/ListServiceFeature.php`: menampilkan fitur layanan.
- `_API/Reference/BodySite/bodysite.php`: referensi body site.
- `_API/Reference/ICD/icd.php`: referensi ICD.
- `_API/Reference/Region/Province.php`: referensi provinsi.
- `_API/Reference/Region/City.php`: referensi kabupaten/kota.
- `_API/Reference/Region/District.php`: referensi kecamatan.
- `_API/Reference/Region/Vilage.php`: referensi desa.
- `_API/Patient/`: folder untuk endpoint pasien (belum ada file saat ini).

Setiap endpoint dapat digunakan untuk mengakses data referensi dan manajemen API secara terpusat, sehingga memudahkan integrasi antara SIMRS dan layanan eksternal.

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
|   .gitignore
|   index.php
|   LICENSE
|   README-structure.txt
|   README.md
|   struktur.txt
|   
+---DB
|       default.sql
|       
+---Storage
|   +---DICOM
|   +---Doc
|   \---Img
|       +---Account
|       |       24a166d3a8eeed3b46167f93624ca83a.png
|       |       64ffa523717340c164e75f3f74302f.png
|       |       e7dff11a659df09176d5b15f282ea193.png
|       |       f0b31cf59510443af5f6b75bbc7baec2.png
|       |       
|       \---Patient
+---_API
|   +---Account
|   |       CreatAccount.php
|   |       DeleteAccount.php
|   |       DetailAccount.php
|   |       ListAccount.php
|   |       ListAccountLevel.php
|   |       ListServiceFeature.php
|   |       Login.php
|   |       Logout.php
|   |       UpdateAccount.php
|   |       UpdateAccountPassword.php
|   |       UpdateAccountPermission.php
|   |       UpdateAccountPhoto.php
|   |       
|   +---ApiKey
|   |       CreatApiKey.php
|   |       DeleteApiKey.php
|   |       ListApiKey.php
|   |       UpdateApiKey.php
|   |       
|   +---Patient
|   +---Reference
|   |   +---BodySite
|   |   |       bodysite.php
|   |   |       
|   |   +---ICD
|   |   |       icd.php
|   |   |       
|   |   \---Region
|   |           City.php
|   |           District.php
|   |           Province.php
|   |           Vilage.php
|   |           
|   +---Satusehat
|   |       CreatCredential.php
|   |       CredentialStatus.php
|   |       DeleteCredential.php
|   |       DetailCredential.php
|   |       ListCredential.php
|   |       UpdateCredential.php
|   |       
|   \---Token
|           get_token.php
|           
+---_Config
|       Connection.php
|       Helper.php
|       RateLimiter.php
|       
\---_Proxy
    +---DocumentProxy
    \---ImageProxy
            AccountImage.php
```

---
# Database
Secara default, database terdiri dari struktur dan data basic yang akan dimuat pada pertama kali aplikassi digunakan.


| No | Tabel                       | Structure  | Default Data   |
|----|-----------------------------|-----------:|----------------|
| 1  |  account                    | Ya         |      Tidak     |
| 2  |  account_level              | Ya         |      Tidak     |
| 3  |  account_level_reference    | Ya         |      Tidak     |
| 4  |  account_permission         | Ya         |      Tidak     |


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
