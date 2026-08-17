# Modul Skrining Penyakit Tidak Menular (PTM) — Simpuswangi

## Deskripsi Aplikasi

Modul Skrining PTM merupakan bagian dari **Simpuswangi**, sistem informasi kesehatan yang digunakan oleh Pusat Kesehatan Masyarakat (Puskesmas) untuk mendukung proses skrining Penyakit Tidak Menular (PTM). Modul ini dirancang untuk membantu tenaga kesehatan dalam melakukan pemeriksaan skrining, mencatat data pasien berisiko (khususnya hipertensi dan diabetes melitus), menghasilkan laporan sesuai standar klaster 3 PTM, serta mengintegrasikan data hasil pemeriksaan ke platform kesehatan nasional **SATUSEHAT** melalui standar **FHIR (Fast Healthcare Interoperability Resources)**.

Pengembangan modul ini menggunakan metode **iterative incremental**, di mana setiap fitur dibangun dan disempurnakan secara bertahap dalam beberapa iterasi, memungkinkan penyesuaian berkelanjutan berdasarkan kebutuhan pengguna dan hasil uji coba di lingkungan sandbox.

## Teknologi yang Digunakan

- **Backend:** [Laravel](https://laravel.com) — framework PHP untuk logika bisnis, API, dan integrasi layanan eksternal
- **Frontend:** [Vue 3](https://vuejs.org) — dibangun dengan Composition API untuk komponen antarmuka yang reaktif
- **Konektor SPA:** [Inertia.js](https://inertiajs.com) — menjembatani Laravel dan Vue tanpa perlu membangun API terpisah, menghasilkan pengalaman single page application
- **Routing:** Ziggy — menghadirkan named routes Laravel ke sisi Vue
- **HTTP Client:** Axios
- **UI Icons:** Bootstrap Icons
- **Integrasi Eksternal:** SATUSEHAT (Sandbox) — pertukaran data menggunakan resource FHIR (`Encounter`, `Observation`, `Condition`)

## Fitur Utama

### 1. Pemeriksaan Skrining PTM
Form pemeriksaan untuk mencatat hasil skrining Penyakit Tidak Menular, termasuk data pemeriksaan metabolik dan penunjang lainnya, dengan antarmuka form yang konsisten mengikuti desain sistem yang telah ditetapkan.

### 2. Integrasi dengan Sandbox SATUSEHAT
Layer integrasi yang mengirimkan data hasil skrining ke platform SATUSEHAT dalam bentuk resource FHIR standar:
- `Encounter` — pencatatan kunjungan/episode pelayanan
- `Observation` — hasil pemeriksaan (misalnya observasi diabetes)
- `Condition` — diagnosis/kondisi pasien (misalnya obesitas)

Setiap transaksi pengiriman dan respons dari SATUSEHAT dicatat dalam log sistem untuk keperluan audit dan penelusuran kesalahan.

### 3. Laporan Klaster 3 PTM
Modul pelaporan yang menyusun data hasil skrining sesuai format pelaporan klaster 3 PTM, digunakan untuk kebutuhan pemantauan dan pelaporan ke dinas kesehatan.

### 4. Register Data Pasien Hipertensi dan Diabetes
Pencatatan dan pengelolaan data pasien yang teridentifikasi berisiko atau terdiagnosis hipertensi dan diabetes melitus, sebagai bagian dari tindak lanjut hasil skrining.

### 5. Dashboard PTM
Ringkasan visual data skrining PTM (jumlah kasus, tren, capaian program) untuk mendukung pemantauan program oleh tenaga kesehatan dan pengelola Puskesmas.

## Metode Pengembangan

Pengembangan modul ini dilakukan dengan pendekatan **Iterative Incremental**, dengan siklus umum sebagai berikut:

1. **Perencanaan Iterasi** — menentukan fitur/bagian yang akan dikembangkan pada iterasi berjalan
2. **Desain & Implementasi** — pengembangan komponen backend (Laravel) dan frontend (Vue + Inertia) secara paralel
3. **Integrasi** — penggabungan fitur baru dengan modul yang sudah ada, termasuk pengujian integrasi dengan SATUSEHAT Sandbox
4. **Pengujian** — verifikasi fungsional dan penyesuaian berdasarkan hasil uji
5. **Evaluasi & Iterasi Berikutnya** — peninjauan hasil sebagai dasar perencanaan iterasi selanjutnya

## Cara Pemasangan

### Prasyarat

- PHP >= 8.1
- Composer
- Node.js >= 18 & NPM
- Database (MySQL/MariaDB)
- Git

### Langkah Instalasi

1. **Clone repository**
   ```bash
   git clone https://github.com/skrtaldi/simpuswangi-ptm.git
   cd newsimpuswangi
   ```

2. **Install dependency backend (Laravel)**
   ```bash
   composer install
   ```

3. **Install dependency frontend (Vue 3 + Inertia)**
   ```bash
   npm install
   ```

4. **Salin file environment dan konfigurasi**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Sesuaikan konfigurasi berikut pada file `.env`:
   - Koneksi database (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`)
   - Kredensial SATUSEHAT Sandbox (`SATUSEHAT_CLIENT_ID`, `SATUSEHAT_CLIENT_SECRET`, `SATUSEHAT_BASE_URL`, `SATUSEHAT_ORGANIZATION_ID`, dll.)

5. **Migrasi dan seeding database**
   ```bash
   php artisan migrate --seed
   ```

6. **Run asset frontend**
   ```bash
   npm run dev     # otomatis run php artisan serve
   ```

### Verifikasi Instalasi

- Pastikan koneksi ke SATUSEHAT Sandbox berhasil dengan melakukan uji kirim data skrining pada lingkungan sandbox terlebih dahulu sebelum digunakan pada lingkungan produksi.
- Periksa log SATUSEHAT (`SatuSehatLog`) untuk memastikan request/response tersimpan dengan benar.

## Pengembang

> Moh Aldi Rohmatulloh, NIM 362258302125, Program Studi Teknologi Rekayasa Perangkat Lunak, Jurusan Bisnis dan Informatika


## Status Pengembangan

> Modul ini masih dalam tahap pengembangan aktif. Struktur fitur dan integrasi dapat berubah mengikuti hasil iterasi berjalan.

---

**Catatan:** README ini merupakan dokumentasi tingkat modul untuk kebutuhan pengembangan internal dan repository GitHub proyek Simpuswangi.
