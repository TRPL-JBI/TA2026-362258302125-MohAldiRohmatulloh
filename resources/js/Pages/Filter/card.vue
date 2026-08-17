<template>
  <div class="container report-page py-4 py-lg-5">
    <div class="panel">
      <!-- Toolbar: judul + filter -->
      <div class="panel-toolbar">
        <div class="toolbar-heading">
          <h1 class="panel-title">Data Laporan Pasien</h1>
          <span class="title-rule"></span>
        </div>

        <div class="toolbar-controls">
          <div class="control-group">
            <label class="control-label">Tanggal kunjungan</label>
            <div class="date-range">
              <input type="date" v-model="startDate" class="control-input" />
              <span class="date-sep">–</span>
              <input type="date" v-model="endDate" class="control-input" />
            </div>
            <div v-if="dateRangeInvalid" class="control-error">
              Tanggal akhir tidak boleh sebelum tanggal mulai.
            </div>
          </div>

          <div class="control-group control-search">
            <label class="control-label">Cari pasien</label>
            <div class="search-input">
              <i class="bi bi-search"></i>
              <input
                type="text"
                placeholder="Nama, NIK, NO RM, alamat…"
                v-model="searchQuery"
              />
            </div>
          </div>

          <div class="control-group control-total">
            <span class="total-figure">{{ filteredLaporan.length }}</span>
            <span class="total-label">pasien ditemukan</span>
          </div>
        </div>
      </div>

      <div class="panel-body">
        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th class="col-num">No.</th>
                <th>Tanggal Kunjungan</th>
                <th>NIK</th>
                <th>NO RM</th>
                <th>Nama</th>
                <th>Alamat</th>
                <th>Kecamatan</th>
                <th>Desa</th>
                <th>Sex</th>
                <th>Tanggal Lahir</th>
                <th>Umur</th>
                <th>Kelompok Umur</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!paginatedLaporan.length">
                <td colspan="12" class="empty-state">
                  Data tidak ditemukan. Ubah kriteria pencarian atau tanggal filter.
                </td>
              </tr>
              <tr v-else v-for="(filter, index) in paginatedLaporan" :key="filter.idLoket || index">
                <td class="col-num">{{ (currentPage - 1) * itemsPerPage + index + 1 }}</td>
                <td>{{ filter.tglKunjungan || '-' }}</td>
                <td>{{ filter.pasien?.NIK || '-' }}</td>
                <td>{{ filter.pasien?.NO_MR || '-' }}</td>
                <td class="col-name">{{ filter.pasien?.NAMA_LGKP || '-' }}</td>
                <td>{{ filter.pasien?.ALAMAT || '-' }}</td>
                <td>{{ filter.nama_kecamatan || filter.pasien?.NO_KEC || '-' }}</td>
                <td>{{ filter.nama_kelurahan || filter.pasien?.NO_KEL || '-' }}</td>
                <td>{{ filter.pasien?.JENIS_KLMIN || '-' }}</td>
                <td>{{ filter.pasien?.TGL_LHR || '-' }}</td>
                <td>{{ filter.umur || '-' }}</td>
                <td>{{ filter.pasien?.KEL_UMUR || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Footer: pagination + aksi -->
        <div class="panel-footer">
          <nav v-if="totalPages > 1" class="pager" aria-label="Navigasi halaman laporan">
            <button class="pager-btn" @click="goToPage(currentPage - 1)" :disabled="currentPage === 1">
              ‹ Sebelumnya
            </button>

            <button v-if="pageWindow[0] > 1" class="pager-num" @click="goToPage(1)">1</button>
            <span v-if="pageWindow[0] > 2" class="pager-ellipsis">…</span>

            <button
              v-for="page in pageWindow"
              :key="page"
              class="pager-num"
              :class="{ 'is-current': currentPage === page }"
              @click="goToPage(page)"
            >
              {{ page }}
            </button>

            <span v-if="pageWindow[pageWindow.length - 1] < totalPages - 1" class="pager-ellipsis">…</span>
            <button
              v-if="pageWindow[pageWindow.length - 1] < totalPages"
              class="pager-num"
              @click="goToPage(totalPages)"
            >
              {{ totalPages }}
            </button>

            <button
              class="pager-btn"
              @click="goToPage(currentPage + 1)"
              :disabled="currentPage === totalPages"
            >
              Berikutnya ›
            </button>
          </nav>

          <div class="rows-per-page">
            <label for="itemsPerPage">Baris / halaman</label>
            <select id="itemsPerPage" v-model.number="itemsPerPage">
              <option :value="5">5</option>
              <option :value="10">10</option>
              <option :value="20">20</option>
              <option :value="50">50</option>
            </select>
          </div>

          <div class="panel-actions">
            <button class="btn-ghost" :disabled="!filteredLaporan.length" @click="showHtml">
              <i class="bi bi-eye"></i> Tampilkan HTML
            </button>
            <button class="btn-solid" :disabled="!filteredLaporan.length" @click="exportExcel">
              <i class="bi bi-download"></i> Unduh Excel
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { ref, computed, watch } from 'vue';
  import AppLayout from '@/Components/Layouts/AppLayouts.vue';
  defineOptions({ layout: AppLayout });
  import ExcelJS from 'exceljs';
  import { saveAs } from 'file-saver';

  const props = defineProps({
    rekamMedis: Array,
    dataPasien: Array,
  });

  const searchQuery = ref('');
  const startDate = ref('');
  const endDate = ref('');

  const currentPage = ref(1);
  const itemsPerPage = ref(10);

  // FIX: tanggal "s/d" lebih awal dari tanggal mulai tidak divalidasi sebelumnya
  const dateRangeInvalid = computed(() => {
    return !!(startDate.value && endDate.value && endDate.value < startDate.value);
  });

  // FIX: sebelumnya hanya watch searchQuery/startDate/endDate; ganti itemsPerPage
  // bisa membuat currentPage melewati totalPages baru dan tabel tampak kosong.
  watch([searchQuery, startDate, endDate, itemsPerPage], () => {
    currentPage.value = 1;
  });

  const filteredLaporan = computed(() => {
    let results = Array.isArray(props.rekamMedis) ? props.rekamMedis : [];

    if (dateRangeInvalid.value) {
      return [];
    }

    if (startDate.value && endDate.value) {
      results = results.filter((item) => {
        const tgl = (item.tglKunjungan || '').slice(0, 10);
        return tgl >= startDate.value && tgl <= endDate.value;
      });
    } else if (startDate.value) {
      results = results.filter((item) => (item.tglKunjungan || '').slice(0, 10) >= startDate.value);
    } else if (endDate.value) {
      results = results.filter((item) => (item.tglKunjungan || '').slice(0, 10) <= endDate.value);
    }

    if (searchQuery.value) {
      const query = searchQuery.value.toLowerCase();
      results = results.filter(
        (item) =>
          (item.pasien?.NO_MR || '').toLowerCase().includes(query) ||
          (item.pasien?.NO_KK || '').toLowerCase().includes(query) ||
          (item.pasien?.NAMA_LGKP || '').toLowerCase().includes(query) ||
          (item.pasien?.NIK || '').toLowerCase().includes(query) ||
          (item.pasien?.ALAMAT || '').toLowerCase().includes(query) ||
          (item.nama_kecamatan || '').toLowerCase().includes(query) ||
          (item.nama_kelurahan || '').toLowerCase().includes(query) ||
          (item.noKartu || '').toLowerCase().includes(query) ||
          (item.kdProvider || '').toLowerCase().includes(query)
      );
    }

    return results;
  });

  const paginatedLaporan = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage.value;
    const end = start + itemsPerPage.value;
    return filteredLaporan.value.slice(start, end);
  });

  const totalPages = computed(() => {
    return Math.ceil(filteredLaporan.value.length / itemsPerPage.value) || 1;
  });

  const goToPage = (page) => {
    const normalized = Math.max(1, Math.min(page, totalPages.value || 1));
    currentPage.value = normalized;
  };

  // FIX: sebelumnya me-render SEMUA nomor halaman sekaligus (pageNumbers).
  // Untuk data pasien yang banyak ini bisa jadi ratusan tombol. Sekarang
  // hanya menampilkan jendela +/-2 halaman di sekitar halaman aktif,
  // dengan tombol pertama/terakhir + ellipsis di luar jendela itu.
  const pageWindow = computed(() => {
    const total = totalPages.value;
    const current = currentPage.value;
    const delta = 2;
    let start = Math.max(1, current - delta);
    let end = Math.min(total, current + delta);

    // perluas jendela di ujung supaya jumlah tombol tetap konsisten
    if (current - delta < 1) end = Math.min(total, end + (delta - (current - 1)));
    if (current + delta > total) start = Math.max(1, start - (current + delta - total));

    const pages = [];
    for (let p = start; p <= end; p++) pages.push(p);
    return pages;
  });

  watch(filteredLaporan, () => {
    if (currentPage.value > totalPages.value) {
      currentPage.value = totalPages.value || 1;
    }
  });

  // FIX: kolom Kecamatan/Desa di Excel sebelumnya hanya membaca kode wilayah
  // (pasien.NO_KEC / NO_KEL), tidak sinkron dengan tabel di layar yang
  // menampilkan nama_kecamatan/nama_kelurahan bila tersedia.
  const exportExcel = async () => {
    const workbook = new ExcelJS.Workbook();
    const worksheet = workbook.addWorksheet('Laporan Pasien');

    worksheet.columns = [
      { header: 'No', key: 'no', width: 5 },
      { header: 'Tanggal Kunjungan', key: 'tglKunjungan', width: 20 },
      { header: 'NIK', key: 'nik', width: 20 },
      { header: 'NO RM', key: 'noRm', width: 15 },
      { header: 'Nama', key: 'nama', width: 25 },
      { header: 'Alamat', key: 'alamat', width: 30 },
      { header: 'Kecamatan', key: 'kec', width: 20 },
      { header: 'Desa', key: 'desa', width: 20 },
      { header: 'Sex', key: 'sex', width: 10 },
      { header: 'Tanggal Lahir', key: 'tglLahir', width: 15 },
      { header: 'Umur', key: 'umur', width: 10 },
      { header: 'Kelompok Umur', key: 'kelompokUmur', width: 15 },
    ];

    const headerRow = worksheet.getRow(1);
    headerRow.eachCell((cell) => {
      cell.fill = {
        type: 'pattern',
        pattern: 'solid',
        fgColor: { argb: '3B82F6' }, // biru (Tailwind blue-500)
      };
      cell.font = {
        color: { argb: 'FFFFFFFF' }, // teks putih
        bold: true,
      };
      cell.alignment = { vertical: 'middle', horizontal: 'center' };
      cell.border = {
        top: { style: 'thin' },
        left: { style: 'thin' },
        bottom: { style: 'thin' },
        right: { style: 'thin' },
      };
    });

    filteredLaporan.value.forEach((laporan, i) => {
      worksheet.addRow({
        no: i + 1,
        tglKunjungan: laporan.tglKunjungan || '-',
        nik: laporan.pasien?.NIK || '-',
        noRm: laporan.pasien?.NO_MR || '-',
        nama: laporan.pasien?.NAMA_LGKP || '-',
        alamat: laporan.pasien?.ALAMAT || '-',
        kec: laporan.nama_kecamatan || laporan.pasien?.NO_KEC || '-',
        desa: laporan.nama_kelurahan || laporan.pasien?.NO_KEL || '-',
        sex: laporan.pasien?.JENIS_KLMIN || '-',
        tglLahir: laporan.pasien?.TGL_LHR || '-',
        umur: laporan.umur || '-',
        kelompokUmur: laporan.pasien?.KEL_UMUR || '-',
      });
    });

    const buffer = await workbook.xlsx.writeBuffer();
    saveAs(new Blob([buffer]), 'laporan_pasien.xlsx');
  };

  // FIX: escape sederhana untuk mencegah string pasien (nama/alamat, dll.)
  // disuntikkan sebagai HTML mentah ke jendela preview (celah XSS).
  const escapeHtml = (value) => {
    return String(value ?? '-').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[ch]));
  };

  // FIX: kolom preview HTML sebelumnya hanya No/Nama/Usia/Diagnosa dan field
  // "diagnosa" itu sendiri tidak ada di manapun di data laporan (tidak
  // sinkron dengan tabel & Excel). Sekarang kolomnya disamakan dengan
  // tabel utama supaya konsisten dengan data yang sedang difilter.
  function showHtml() {
    const rows = filteredLaporan.value
      .map(
        (laporan, i) => `
          <tr>
            <td>${i + 1}</td>
            <td>${escapeHtml(laporan.tglKunjungan)}</td>
            <td>${escapeHtml(laporan.pasien?.NIK)}</td>
            <td>${escapeHtml(laporan.pasien?.NO_MR)}</td>
            <td>${escapeHtml(laporan.pasien?.NAMA_LGKP)}</td>
            <td>${escapeHtml(laporan.pasien?.ALAMAT)}</td>
            <td>${escapeHtml(laporan.nama_kecamatan || laporan.pasien?.NO_KEC)}</td>
            <td>${escapeHtml(laporan.nama_kelurahan || laporan.pasien?.NO_KEL)}</td>
            <td>${escapeHtml(laporan.pasien?.JENIS_KLMIN)}</td>
            <td>${escapeHtml(laporan.pasien?.TGL_LHR)}</td>
            <td>${escapeHtml(laporan.umur)}</td>
            <td>${escapeHtml(laporan.pasien?.KEL_UMUR)}</td>
          </tr>
        `
      )
      .join('');

    const htmlContent = `
    <html>
      <head>
        <title>Data Laporan Pasien</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 20px; }
          h3 { text-align: center; margin-bottom: 16px; }
          table { border-collapse: collapse; width: 100%; }
          th, td { border: 1px solid black; padding: 8px; text-align: left; white-space: nowrap; }
          th { background: #3b82f6; color: white; }
          tr:nth-child(even) { background: #f9f9f9; }
        </style>
      </head>
      <body>
        <h3>Data Laporan Pasien</h3>
        <table>
          <thead>
            <tr>
              <th>No</th>
              <th>Tanggal Kunjungan</th>
              <th>NIK</th>
              <th>NO RM</th>
              <th>Nama</th>
              <th>Alamat</th>
              <th>Kecamatan</th>
              <th>Desa</th>
              <th>Sex</th>
              <th>Tanggal Lahir</th>
              <th>Umur</th>
              <th>Kelompok Umur</th>
            </tr>
          </thead>
          <tbody>
            ${rows}
          </tbody>
        </table>
      </body>
    </html>
  `;

    const newWindow = window.open('', '_blank');
    if (!newWindow) return;
    newWindow.document.write(htmlContent);
    newWindow.document.close();
  }
</script>

<style scoped>
  /* Token: warna, tipe, radius — lihat catatan desain di ringkasan chat */
  .report-page {
    --bg-canvas: #f5f6f8;
    --surface: #ffffff;
    --border: #e4e7ec;
    --text-primary: #101828;
    --text-secondary: #667085;
    --text-muted: #98a2b3;
    --accent: #2563eb;
    --accent-soft: #eff4ff;
    --accent-export: #0f766e;
    --accent-export-soft: #ecfbf8;
    --danger: #dc2626;

    max-width: 1400px;
    font-family:
      'Inter',
      ui-sans-serif,
      -apple-system,
      BlinkMacSystemFont,
      'Segoe UI',
      Roboto,
      sans-serif;
    font-variant-numeric: tabular-nums;
    color: var(--text-primary);
    background: var(--bg-canvas);
    border-radius: 1rem;
    padding: 1.25rem;
  }

  /* ===== Panel: satu permukaan datar, bukan dua kartu melayang ===== */
  .panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 0.75rem;
    overflow: hidden;
  }

  .panel-toolbar {
    padding: 1.5rem 1.5rem 1.25rem;
    border-bottom: 1px solid var(--border);
  }

  .toolbar-heading {
    margin-bottom: 1.25rem;
  }

  .panel-title {
    font-size: 1.25rem;
    font-weight: 600;
    letter-spacing: -0.01em;
    margin: 0;
  }

  /* Signature: garis aksen tipis di bawah judul — kesan "stempel registrasi" */
  .title-rule {
    display: block;
    width: 2rem;
    height: 3px;
    margin-top: 0.5rem;
    background: var(--accent);
    border-radius: 2px;
  }

  .toolbar-controls {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: end;
    gap: 1.5rem;
  }

  .control-group {
    display: flex;
    flex-direction: column;
  }

  .control-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 0.4rem;
  }

  .date-range {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .date-sep {
    color: var(--text-muted);
  }

  .control-input,
  .search-input input,
  .rows-per-page select {
    font-family: inherit;
    font-size: 0.875rem;
    color: var(--text-primary);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    padding: 0.5rem 0.7rem;
    min-height: 38px;
  }

  .control-input:focus,
  .search-input input:focus,
  .rows-per-page select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  .control-error {
    font-size: 0.75rem;
    color: var(--danger);
    margin-top: 0.4rem;
  }

  .control-search {
    max-width: 360px;
  }

  .search-input {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    padding: 0 0.7rem;
    background: var(--surface);
  }

  .search-input i {
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  .search-input input {
    border: 0;
    padding: 0.5rem 0;
    flex: 1;
    min-width: 0;
  }

  .search-input input:focus {
    box-shadow: none;
  }

  .search-input:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  .control-total {
    text-align: right;
  }

  .total-figure {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1;
    color: var(--accent);
  }

  .total-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
  }

  /* ===== Tabel ===== */
  .panel-body {
    padding: 0 1.5rem 1.5rem;
  }

  .table-scroll {
    max-height: 65vh;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    margin-top: 1.25rem;
  }

  .data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
  }

  .data-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface);
    color: var(--text-secondary);
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    text-align: left;
    padding: 0.7rem 0.85rem;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }

  .data-table tbody td {
    padding: 0.65rem 0.85rem;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
    color: var(--text-primary);
  }

  .data-table tbody tr:last-child td {
    border-bottom: 0;
  }

  .data-table tbody tr:hover td {
    background: var(--bg-canvas);
  }

  .col-num {
    color: var(--text-muted);
    font-variant-numeric: tabular-nums;
  }

  .col-name {
    font-weight: 500;
  }

  .empty-state {
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--text-secondary);
  }

  /* ===== Footer: pagination + aksi ===== */
  .panel-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 1.25rem;
  }

  .pager {
    display: flex;
    align-items: center;
    gap: 0.15rem;
  }

  .pager-btn,
  .pager-num {
    background: transparent;
    border: 0;
    border-radius: 0.4rem;
    color: var(--text-secondary);
    font-size: 0.8125rem;
    font-weight: 500;
    padding: 0.4rem 0.6rem;
    cursor: pointer;
  }

  .pager-btn:hover:not(:disabled),
  .pager-num:hover:not(.is-current) {
    background: var(--bg-canvas);
    color: var(--text-primary);
  }

  .pager-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .pager-num.is-current {
    background: var(--accent-soft);
    color: var(--accent);
    font-weight: 700;
  }

  .pager-ellipsis {
    color: var(--text-muted);
    padding: 0 0.3rem;
    font-size: 0.8125rem;
  }

  .rows-per-page {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.8125rem;
    color: var(--text-secondary);
  }

  .rows-per-page select {
    min-height: 34px;
    padding: 0.3rem 0.5rem;
  }

  .panel-actions {
    display: flex;
    gap: 0.6rem;
  }

  .btn-ghost,
  .btn-solid {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8125rem;
    font-weight: 600;
    border-radius: 0.5rem;
    padding: 0.55rem 0.9rem;
    border: 1px solid transparent;
    cursor: pointer;
    transition:
      background 0.15s ease,
      border-color 0.15s ease;
  }

  .btn-ghost {
    background: var(--surface);
    border-color: var(--border);
    color: var(--text-secondary);
  }

  .btn-ghost:hover:not(:disabled) {
    border-color: var(--accent);
    color: var(--accent);
  }

  .btn-solid {
    background: var(--accent-export);
    color: #fff;
  }

  .btn-solid:hover:not(:disabled) {
    background: #0b5c56;
  }

  .btn-ghost:disabled,
  .btn-solid:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  @media (max-width: 767.98px) {
    .report-page {
      padding: 0.75rem;
    }

    .toolbar-controls {
      grid-template-columns: 1fr;
    }

    .control-search {
      max-width: none;
    }

    .control-total {
      text-align: left;
    }

    .panel-footer {
      flex-direction: column;
      align-items: stretch;
    }

    .panel-actions {
      flex-direction: column;
    }

    .panel-actions button {
      width: 100%;
      justify-content: center;
    }
  }
</style>