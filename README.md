
# EAZY DELIVERY

Aplikasi web PHP + MySQL untuk admin delivery sederhana.

## Fitur
- Login multiuser (admin / driver)
- Data member dengan saldo dan poin
- Prioritas member di daftar pesanan
- Input pesanan cash / non-cash / saldo member
- Tambah titik pengantaran tambahan
- Update saldo member otomatis
- Driver target 20 order/hari
- Bonus Rp1.000/order setelah target tercapai
- Penolakan driver mingguan:
  - 2 kali = peringatan
  - 5 kali = non-aktif minggu itu
- Absensi dengan upload foto
- Laporan mingguan / bulanan
- Invoice siap cetak / simpan PDF dari browser

## Login awal
Setelah import schema, login:
- Admin: `admin`
- Driver: `driver1`
- Password: `password123`

> Jika password awal tidak cocok karena environment import tertentu, cukup ubah password driver/admin di database atau reinsert hash baru.

## Cara menjalankan
1. Siapkan server PHP + MySQL (XAMPP / Laragon / hosting).
2. Buat database `eazy_delivery`.
3. Import file `schema.sql` ke MySQL.
4. Taruh `index.php` di folder web server.
5. Edit koneksi database di bagian atas `index.php`:
   - DB_HOST
   - DB_NAME
   - DB_USER
   - DB_PASS
6. Jalankan lewat browser:
   - `http://localhost/eazy_delivery/`

## Catatan
- Invoice dan laporan dibuat dalam halaman siap cetak. Gunakan tombol **Cetak / PDF** lalu simpan sebagai PDF dari browser.
- Folder `uploads/` dipakai untuk bukti foto absensi.
