# Strategi deployment Candy CBT 2.9.2

Dokumen ini adalah rencana deployment untuk aplikasi pada repository ini: **Candy CBT 2.9.2** (`config/config.candy.php`). Targetnya adalah satu VPS UpCloud di Singapore dengan Ubuntu 26.04 LTS, 4 vCPU, RAM 8 GB, storage 100 GB, dan aaPanel.

Tujuan utama adalah kestabilan penyimpanan jawaban saat ujian. Kesederhanaan lebih penting daripada menambah layanan baru.

## Arsitektur yang dipakai

```text
Peserta dan pengawas
        |
      HTTPS
        |
Nginx (aaPanel)
        |
PHP 7.4 FPM + OPcache
        |
MariaDB 10.5 (private, tidak dibuka ke internet)
        |
Backup offsite + snapshot UpCloud
```

Gunakan satu server aplikasi dan satu database pada VPS yang sama. Aplikasi ini adalah PHP native, bukan aplikasi container dan bukan aplikasi yang membutuhkan Redis atau message queue. Menambah Docker, Apache, mail server, DNS server, atau caching dinamis hanya menambah titik gagal saat ujian.

## Stack yang direkomendasikan

| Komponen | Pilihan | Alasan |
| --- | --- | --- |
| Panel | aaPanel | Pengelolaan site, file, database, SSL, log, firewall, dan backup melalui UI. |
| Web server | Nginx | Ringan untuk koneksi PHP dan aset soal. |
| Runtime | PHP 7.4 FPM | Kompatibilitas paling aman dengan Candy CBT 2.9.2 dan dependency Composer yang masih menargetkan PHP 7.x. |
| Database | MariaDB 10.5 | Generasi MariaDB yang umum dipakai bersama Candy CBT 2.9.x. |
| Cache PHP | OPcache | Mengurangi parsing ulang file PHP pada setiap request. |
| TLS | Let's Encrypt dari aaPanel | Seluruh akses peserta dan admin wajib HTTPS. |
| Backup | aaPanel + storage offsite + snapshot UpCloud | Satu backup pada VPS tidak cukup bila VPS atau disk bermasalah. |

PHP 7.4 sudah end-of-life. Ini dipakai untuk kompatibilitas awal, bukan sebagai kondisi akhir. Batasi permukaan serangan, jalankan patch keamanan aplikasi di bawah, dan siapkan staging untuk uji PHP 8.2 sebelum migrasi runtime.

## Gambaran codebase yang memengaruhi deployment

- Peserta memakai halaman root (`index.php`, `login.php`) dan jawaban disimpan lewat AJAX ke `soal.php`.
- Panel pengawas/admin berada pada `/panel/`.
- State ujian dan jawaban disimpan di MariaDB, terutama tabel `nilai` dan `jawaban`.
- File soal dan lampiran diunggah ke `files/`; foto peserta berada di `foto/fotosiswa/`; ada juga data unggahan di `berkas/` dan `temp/`.
- Konfigurasi database berada di `config/config.database.php`; modul import Word mempunyai konfigurasi tambahan di `panel/pages/word_import/import/application/config/sq_config.php`.
- Aplikasi membentuk URL melalui `$homeurl` di `config/config.default.php`; nilainya harus memakai domain produksi HTTPS.

Jangan meng-cache HTML, PHP, endpoint AJAX, atau URL ujian. Nginx hanya boleh memberikan cache panjang untuk aset versi-statis seperti CSS, JavaScript, font, gambar desain, dan favicon. File media soal dapat memakai cache sedang jika namanya tidak diganti saat ujian.

## Tahap 0: keputusan sebelum membuat server

1. Tentukan domain produksi, misalnya `cbt.example.sch.id`.
2. Siapkan alamat IP publik tetap yang boleh membuka aaPanel dan SSH. Bila IP admin berubah-ubah, gunakan VPN terlebih dahulu.
3. Siapkan lokasi backup di luar VPS, misalnya bucket object storage atau server SFTP milik sekolah.
4. Pilih waktu maintenance. Jangan memigrasikan, mengubah konfigurasi, atau mengganti bank soal ketika sesi ujian sedang berjalan.
5. Buat snapshot UpCloud saat VPS masih kosong. Snapshot ini adalah rollback tercepat bila pemasangan panel gagal.

## Tahap 1: bootstrap server aaPanel

Lakukan instalasi aaPanel hanya pada Ubuntu yang masih kosong. Setelah panel aktif, seluruh pengelolaan harian dilakukan dari UI aaPanel.

### Layanan yang dipasang

Pasang hanya layanan berikut dari aaPanel:

- Nginx.
- PHP 7.4 dan ekstensi `mysqli`, `mbstring`, `xml`, `zip`, `gd`, `curl`, `openssl`, dan `fileinfo`.
- MariaDB 10.5.
- phpMyAdmin hanya untuk administrasi dan hanya dapat diakses admin.
- Fail2Ban bila tersedia pada panel.

Jangan memasang Apache, OpenLiteSpeed, Redis, mail server, DNS server, FTP publik, atau plugin aaPanel yang tidak dibutuhkan.

### Firewall

Atur firewall UpCloud dan firewall server dengan aturan yang sama:

| Port | Sumber yang diizinkan | Kegunaan |
| --- | --- | --- |
| 22/TCP | Hanya IP admin atau VPN | SSH darurat. Gunakan SSH key. |
| 80/TCP | Publik | Redirect ke HTTPS dan penerbitan sertifikat. |
| 443/TCP | Publik | Aplikasi CBT. |
| Port aaPanel | Hanya IP admin atau VPN | Panel administrasi. Gunakan port yang disediakan saat instalasi. |
| 3306/TCP | Tidak ada akses publik | MariaDB hanya di `127.0.0.1`. |

Simpan kode pemulihan aaPanel dan SSH key di password manager. Jangan menyimpan password pada repository atau dokumen ini.

## Tahap 2: membuat site dan database

1. Di aaPanel, buat **PHP site** untuk domain produksi dan aktifkan Let's Encrypt.
2. Catat document root yang dibuat aaPanel, misalnya `/www/wwwroot/cbt.example.sch.id`.
3. Buat satu database MariaDB dan satu user khusus aplikasi. Beri user tersebut hak hanya pada database Candy CBT.
4. Buat password database acak dan panjang, lalu simpan di password manager.
5. Import `config/ecandy29.sql` hanya untuk instalasi baru. Untuk migrasi dari server lama, gunakan dump database produksi terbaru sebagai sumber utama, bukan dump contoh ini.
6. Upload source aplikasi beserta folder `vendor/`. Jangan menjalankan `composer update` di produksi karena lock file aplikasi lama dibuat untuk PHP 7.x.
7. Ubah `config/config.database.php` dengan host `localhost`, nama database, user database, dan password baru.
8. Jika fitur import Word dipakai, samakan konfigurasi koneksinya di `panel/pages/word_import/import/application/config/sq_config.php`.
9. Ubah `$homeurl` pada `config/config.default.php` menjadi `https://cbt.example.sch.id`.

Set ownership semua file ke user site aaPanel. Folder yang harus bisa ditulis oleh PHP adalah `files/`, `berkas/`, `foto/fotosiswa/`, `foto/qrcode/`, dan `temp/`. File konfigurasi database tidak boleh writable oleh web server setelah konfigurasi selesai.

## Tahap 3: konfigurasi PHP, Nginx, dan MariaDB

### PHP 7.4

Atur melalui aaPanel:

```ini
memory_limit = 256M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 120
max_input_time = 120
max_input_vars = 5000
max_file_uploads = 20
date.timezone = Asia/Jakarta
```

Aktifkan OPcache untuk PHP-FPM. Sebagai nilai awal pada VPS 8 GB, gunakan maksimum 24 worker PHP-FPM. Turunkan nilai ini bila penggunaan RAM bertahan di atas 80%, dan naikkan hanya setelah load test menunjukkan CPU serta database masih sehat.

### Nginx

- Gunakan PHP-FPM, bukan `mod_php`.
- Set `client_max_body_size` minimal `64m`.
- Set timeout FastCGI minimal 120 detik untuk impor berkas.
- Nonaktifkan caching FastCGI untuk seluruh aplikasi.
- Blok eksekusi PHP dari direktori unggahan (`/files/`, `/berkas/`, `/foto/`, dan `/temp/`). File media tetap boleh dibaca, tetapi tidak boleh dijalankan sebagai script.
- Tambahkan pembatasan IP atau Basic Auth untuk `/panel/` jika seluruh pengawas memiliki IP/VPN yang tetap. Jangan menerapkan pembatasan tersebut pada halaman peserta.

### MariaDB

Mulai dari konfigurasi konservatif:

```ini
innodb_buffer_pool_size = 3G
max_connections = 250
slow_query_log = 1
long_query_time = 1
```

Jangan membuka akses jaringan MariaDB. phpMyAdmin juga harus dilindungi oleh autentikasi aaPanel, pembatasan IP, atau VPN.

## Tahap 4: perbaikan wajib sebelum dibuka ke publik

aaPanel melindungi server, tetapi tidak memperbaiki kelemahan kode. Tiga pekerjaan berikut adalah gerbang produksi, terutama bila ujian dapat diakses dari internet.

1. **Rotasi semua kredensial.** Password database yang pernah tersimpan di `config/config.database.php` harus dianggap bocor dan diganti. Hapus password, token, dan data sekolah dari dump atau backup yang akan dibagikan.
2. **Login peserta sudah dimigrasikan secara kompatibel.** Login baru menerima password plaintext lama sekali, lalu langsung mengubahnya menjadi `password_hash()` setelah login berhasil. Login berikutnya memakai `password_verify()`.
3. **Otorisasi endpoint ujian sudah diperketat.** Mulai ujian, membuka soal, autosave jawaban, daftar nomor, cek selesai, dan selesai ujian kini memakai `$_SESSION['id_siswa']`, bukan identitas yang dikirim browser. Server juga memastikan peserta memiliki sesi ujian dan soal yang diminta.

Lanjutan yang tetap penting: ubah helper di `config/functions.crud.php` dan query lain yang menerima input menjadi prepared statements; aktifkan cookie `Secure`, `HttpOnly`, dan `SameSite`; serta validasi MIME file saat upload, bukan hanya ekstensi.

## Tahap 5: indeks database dan konsistensi autosave

Saat peserta menjawab, aplikasi melakukan pola baca lalu insert/update pada tabel `jawaban`. Dump awal hanya memiliki primary key sehingga query dapat melambat ketika riwayat jawaban bertambah.

Sebelum menambah indeks:

1. Buat dump database.
2. Jalankan perubahan di staging dengan salinan data produksi.
3. Periksa duplikasi data pada kombinasi peserta, ujian, mapel, soal, dan jenis.
4. Lakukan perubahan di luar waktu ujian.

Indeks awal yang perlu diuji:

```sql
ALTER TABLE jawaban
  ADD INDEX idx_jawaban_lookup (id_ujian, id_siswa, id_mapel, id_soal, jenis);

ALTER TABLE nilai
  ADD INDEX idx_nilai_lookup (id_ujian, id_siswa, id_mapel);

ALTER TABLE soal
  ADD INDEX idx_soal_mapel_jenis_nomor (id_mapel, jenis, nomor);
```

Jangan membuat indeks `UNIQUE` sebelum duplikasi lama dibersihkan dan kode autosave diubah menjadi operasi atomik (`INSERT ... ON DUPLICATE KEY UPDATE`).

## Tahap 6: staging dan acceptance test

Buat site staging terpisah, misalnya `staging-cbt.example.sch.id`, dengan database hasil salinan. Jangan menghubungkan staging ke database produksi.

Luluskan seluruh checklist berikut sebelum DNS produksi diarahkan:

- Login siswa, pengawas, guru, dan admin.
- Pembuatan jadwal, token, sesi, dan acak soal.
- Menjawab pilihan ganda, esai, ragu-ragu, pindah soal, refresh browser, logout/login kembali, lalu menyelesaikan ujian.
- Impor soal Excel dan Word bila fitur tersebut digunakan.
- Upload dan tampilkan gambar/audio soal.
- Sinkronisasi peserta, soal, dan nilai bila mode server sinkron dipakai.
- Cetak atau ekspor nilai.
- Jalankan restore database ke database kosong dan verifikasi hasilnya.
- Jalankan load test bertahap sampai 150 peserta aktif setara pola ujian nyata.

Selama load test, pantau CPU, RAM, disk, jumlah koneksi MariaDB, slow query log, respons `soal.php`, dan error log PHP/Nginx. Untuk sesi pertama, batasi maksimal 100 sampai 150 peserta bersamaan. Naikkan jumlah hanya dari hasil uji, bukan perkiraan.

## Backup, pemulihan, dan rollback

| Data | Frekuensi | Retensi | Lokasi |
| --- | --- | --- | --- |
| Dump MariaDB | Tiap 1 jam saat hari ujian | 72 jam | Offsite |
| Dump MariaDB | Harian | 30 hari | Offsite |
| File aplikasi dan unggahan | Harian | 30 hari | Offsite |
| Snapshot UpCloud | Sebelum deploy atau perubahan besar | Minimal 2 snapshot terakhir | UpCloud |

Sebelum ujian, lakukan satu kali simulasi restore. Backup yang belum pernah dipulihkan bukan jaminan pemulihan.

Jika deploy baru gagal, jangan memperbaiki server produksi saat peserta aktif. Aktifkan maintenance, pulihkan database dari dump pra-deploy atau rollback ke snapshot UpCloud, lalu verifikasi login peserta dan jawaban terakhir sebelum layanan dibuka lagi.

## SOP hari ujian

### H-1

- Freeze source code, versi PHP, versi database, dan bank soal.
- Ambil snapshot UpCloud dan backup database offsite.
- Periksa sertifikat HTTPS, kapasitas disk, penggunaan RAM, dan backup terakhir.
- Uji login dengan beberapa akun siswa serta satu akun peran admin/pengawas.
- Pastikan kontak operator, prosedur reset, dan akses darurat tersedia.

### Sebelum sesi

- Pastikan jadwal, token, jumlah peserta, ruang, dan sesi benar.
- Lihat dashboard aaPanel serta log error Nginx/PHP.
- Pastikan backup database berhasil.
- Jangan mengubah pengacakan soal atau konfigurasi ujian setelah peserta mulai masuk.

### Saat sesi

- Pantau CPU, RAM, disk, koneksi database, serta error rate setiap 15 menit.
- Jangan update aaPanel, Nginx, PHP, MariaDB, atau source aplikasi.
- Catat waktu dan dampak setiap insiden.
- Jika peserta kehilangan koneksi, verifikasi jawaban tersimpan melalui panel pengawas sebelum melakukan reset.

### Setelah sesi

- Pastikan semua peserta sudah selesai atau diselesaikan sesuai SOP sekolah.
- Buat dump nilai dan jawaban langsung setelah sesi.
- Verifikasi jumlah peserta dan nilai sebelum menghapus atau mereset data apa pun.
- Baru lakukan sinkronisasi, rekap, atau perubahan jadwal sesi berikutnya.

## Jalur modernisasi setelah produksi stabil

1. Buat staging PHP 8.2 dengan database salinan.
2. Naikkan `phpoffice/phpword` dan `phpoffice/phpspreadsheet` ke versi yang mendukung PHP modern, lalu uji impor/ekspor.
3. Ganti SQL hasil konkatenasi dengan prepared statements secara bertahap.
4. Migrasikan password peserta dengan hash adaptif.
5. Ubah data serialisasi PHP baru ke JSON untuk data baru, tanpa merusak arsip lama.
6. Pindahkan runtime produksi ke PHP 8.2 hanya setelah staging dan load test lulus.

Tidak ada upgrade runtime, database besar, atau perubahan arsitektur pada minggu ujian.
